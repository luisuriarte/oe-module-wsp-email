<?php
/**
 * get_recalls.php — Returns recalls from medex_recalls with their notification status.
 *
 * GET params:
 *   facility_id  (optional, 0 = all facilities)
 *   status       (optional: PENDING|SENT|FAILED|SKIPPED|UNSENT)
 *   date_from    (optional: Y-m-d)
 *   date_to      (optional: Y-m-d)
 *   patient      (optional: name search)
 *   limit        (optional, default 100)
 *   offset       (optional, default 0)
 *
 * Returns: JSON { success: bool, data: [...], total: int }
 *
 * @package   OpenEMR\Modules\WspEmail
 */

ob_start();

$sessionAllowWrite = true;
require_once __DIR__ . '/../../../../../globals.php';

use OpenEMR\Common\Acl\AclMain;

ob_clean();
header('Content-Type: application/json');

try {
    if (!AclMain::aclCheckCore('patients', 'demo')) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Access denied']);
        exit;
    }

    // Auto-create wsp_email_recall table if not migrated yet
    sqlStatement("CREATE TABLE IF NOT EXISTS `wsp_email_recall` (
        `id`            int(11)      NOT NULL AUTO_INCREMENT,
        `recall_id`     int(11)      NOT NULL,
        `facility_id`   int(11)      NOT NULL,
        `pid`           int(11)      NOT NULL,
        `seq`           tinyint(3)   NOT NULL,
        `channel`       enum('WSP','Email','Both') NOT NULL DEFAULT 'WSP',
        `log_id`        int(11)      DEFAULT NULL,
        `status`        enum('PENDING','SENT','FAILED','SKIPPED') NOT NULL DEFAULT 'PENDING',
        `skip_reason`   varchar(100) DEFAULT NULL,
        `scheduled_for` date         NOT NULL,
        `sent_at`       datetime     DEFAULT NULL,
        `created_at`    datetime     DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        UNIQUE KEY `uq_recall_seq` (`recall_id`, `seq`),
        KEY `idx_facility_status_scheduled` (`facility_id`, `status`, `scheduled_for`),
        KEY `idx_pid` (`pid`),
        KEY `idx_log_id` (`log_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

    $facilityId = (int)($_GET['facility_id'] ?? 0);
    $status     = trim($_GET['status']    ?? '');
    $dateFrom   = trim($_GET['date_from'] ?? '');
    $dateTo     = trim($_GET['date_to']   ?? '');
    $patient    = trim($_GET['patient']   ?? '');
    $limit      = min((int)($_GET['limit']  ?? 100), 500);
    $offset     = max((int)($_GET['offset'] ?? 0), 0);

    // Build WHERE clauses
    $where  = [];
    $params = [];

    if ($facilityId > 0) {
        $where[]  = 'mr.r_facility = ?';
        $params[] = $facilityId;
    }

    if (!empty($dateFrom)) {
        $where[]  = 'mr.r_eventDate >= ?';
        $params[] = $dateFrom;
    }
    if (!empty($dateTo)) {
        $where[]  = 'mr.r_eventDate <= ?';
        $params[] = $dateTo;
    }

    if (!empty($patient)) {
        $like     = '%' . $patient . '%';
        $where[]  = "(pd.fname LIKE ? OR pd.lname LIKE ? OR CONCAT(pd.fname,' ',pd.lname) LIKE ?)";
        $params[] = $like;
        $params[] = $like;
        $params[] = $like;
    }

    if ($status === 'UNSENT') {
        $where[] = 'NOT EXISTS (SELECT 1 FROM wsp_email_recall wr WHERE wr.recall_id = mr.r_ID)';
    } elseif (!empty($status)) {
        $where[]  = "EXISTS (SELECT 1 FROM wsp_email_recall wr WHERE wr.recall_id = mr.r_ID AND wr.status = ?)";
        $params[] = $status;
    }

    $whereClause = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

    // Count
    $countSql = "SELECT COUNT(DISTINCT mr.r_ID) AS total
                 FROM medex_recalls mr
                 INNER JOIN patient_data pd ON pd.pid = mr.r_pid
                 {$whereClause}";
    $countRow = sqlQuery($countSql, $params);
    $total    = (int)($countRow['total'] ?? 0);

    // Data
    $dataSql = "SELECT mr.r_ID AS recall_id, mr.r_pid AS pid,
                       mr.r_eventDate, mr.r_facility, mr.r_provider,
                       mr.r_reason, mr.r_created,
                       pd.fname, pd.lname, pd.phone_cell, pd.email,
                       f.name AS facility_name,
                       CONCAT(u.fname,' ',IFNULL(u.mname,''),' ',u.lname) AS provider_name,
                       (SELECT wr.status
                        FROM wsp_email_recall wr
                        WHERE wr.recall_id = mr.r_ID
                        ORDER BY FIELD(wr.status,'SENT','PENDING','FAILED','SKIPPED') ASC
                        LIMIT 1
                       ) AS notif_status,
                       (SELECT COUNT(*) FROM wsp_email_recall wr WHERE wr.recall_id = mr.r_ID) AS seq_count,
                       (SELECT GROUP_CONCAT(CONCAT(wr.seq,':',wr.status) ORDER BY wr.seq SEPARATOR ', ')
                        FROM wsp_email_recall wr WHERE wr.recall_id = mr.r_ID
                       ) AS seq_detail
                FROM medex_recalls mr
                INNER JOIN patient_data pd ON pd.pid = mr.r_pid
                LEFT JOIN facility f ON f.id = mr.r_facility
                LEFT JOIN users u ON u.id = mr.r_provider
                {$whereClause}
                ORDER BY mr.r_eventDate ASC
                LIMIT ? OFFSET ?";

    $dataParams = array_merge($params, [$limit, $offset]);
    $res        = sqlStatement($dataSql, $dataParams);
    $recalls    = [];
    while ($row = sqlFetchArray($res)) {
        $recalls[] = $row;
    }

    ob_end_clean();
    echo json_encode(['success' => true, 'data' => $recalls, 'total' => $total]);

} catch (\Throwable $e) {
    ob_end_clean();
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage(), 'data' => [], 'total' => 0]);
}
