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
    sqlStatementThrowException("CREATE TABLE IF NOT EXISTS `wsp_email_recall` (
        `id`            int(11)      NOT NULL AUTO_INCREMENT,
        `recall_id`     int(11)      NOT NULL,
        `facility_id`   int(11)      NOT NULL,
        `pid`           int(11)      NOT NULL,
        `seq`           tinyint(3)   NOT NULL,
        `channel`       enum('WSP','Email','SMS','All') NOT NULL DEFAULT 'WSP',
        `log_id`        int(11)      DEFAULT NULL,
        `status`        enum('PENDING','SENT','FAILED','SKIPPED') NOT NULL DEFAULT 'PENDING',
        `skip_reason`   varchar(100) DEFAULT NULL,
        `scheduled_for` date         NOT NULL,
        `sent_at`       datetime     DEFAULT NULL,
        `created_at`    timestamp    DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        UNIQUE KEY `uq_recall_seq_channel` (`recall_id`, `seq`, `channel`),
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

    // Build WHERE clauses for medex_recalls + patient filter
    $mWhere  = [];
    $mParams = [];
    // Build WHERE clauses for wsp_email_recall_entries + patient filter
    $eWhere  = [];
    $eParams = [];

    if ($facilityId > 0) {
        $mWhere[]  = 'mr.r_facility = ?';
        $mParams[] = $facilityId;
        $eWhere[]  = 'we.facility_id = ?';
        $eParams[] = $facilityId;
    }

    if (!empty($dateFrom)) {
        $mWhere[]  = 'mr.r_eventDate >= ?';
        $mParams[] = $dateFrom;
        $eWhere[]  = 'we.event_date >= ?';
        $eParams[] = $dateFrom;
    }
    if (!empty($dateTo)) {
        $mWhere[]  = 'mr.r_eventDate <= ?';
        $mParams[] = $dateTo;
        $eWhere[]  = 'we.event_date <= ?';
        $eParams[] = $dateTo;
    }

    $likePatient = '';
    $patientLikeParams = [];
    if (!empty($patient)) {
        $like     = '%' . $patient . '%';
        $likeCondition = "(pd.fname LIKE ? OR pd.lname LIKE ? OR CONCAT(pd.fname,' ',pd.lname) LIKE ?)";
        $mWhere[]  = $likeCondition;
        $mParams[] = $like;
        $mParams[] = $like;
        $mParams[] = $like;
        $eWhere[]  = $likeCondition;
        $eParams[] = $like;
        $eParams[] = $like;
        $eParams[] = $like;
    }

    if ($status === 'UNSENT') {
        $mWhere[] = 'NOT EXISTS (SELECT 1 FROM wsp_email_recall wr WHERE wr.recall_id = mr.r_ID)';
        $eWhere[] = 'NOT EXISTS (SELECT 1 FROM wsp_email_recall wr WHERE wr.recall_id = (-we.id))';
    } elseif (!empty($status)) {
        $mWhere[]  = "EXISTS (SELECT 1 FROM wsp_email_recall wr WHERE wr.recall_id = mr.r_ID AND wr.status = ?)";
        $mParams[] = $status;
        $eWhere[]  = "EXISTS (SELECT 1 FROM wsp_email_recall wr WHERE wr.recall_id = (-we.id) AND wr.status = ?)";
        $eParams[] = $status;
    }

    $mConditions = $mWhere ? implode(' AND ', $mWhere) : '1';
    $eConditions = $eWhere ? implode(' AND ', $eWhere) : '1';

    // ── Count (medex + entries via UNION) ──────────────────────────────
    $countSql = "SELECT COUNT(*) AS total FROM (
        SELECT mr.r_ID FROM medex_recalls mr
        INNER JOIN patient_data pd ON pd.pid = mr.r_pid
        WHERE {$mConditions}
        UNION ALL
        SELECT we.id FROM wsp_email_recall_entries we
        INNER JOIN patient_data pd ON pd.pid = we.pid
        WHERE {$eConditions}
    ) cnt";
    $countParams = array_merge($mParams, $eParams);
    $countRes = sqlStatementThrowException($countSql, $countParams);
    $countRow = sqlFetchArray($countRes);
    $total    = (int)($countRow['total'] ?? 0);

    // ── Data: UNION ALL medex + entries ────────────────────────────────
    $dataSql = "SELECT mr.r_ID AS recall_id, mr.r_pid AS pid,
                       mr.r_eventDate, mr.r_facility, mr.r_provider,
                       mr.r_reason, mr.r_created,
                       'medex' AS source,
                       pd.fname, pd.lname, pd.phone_cell, pd.email,
                       f.name AS facility_name,
                       CONCAT(u.fname,' ',IFNULL(u.mname,''),' ',u.lname) AS provider_name,
                       (SELECT wr.status
                        FROM wsp_email_recall wr
                        WHERE wr.recall_id = mr.r_ID
                        ORDER BY FIELD(wr.status,'FAILED','SKIPPED','PENDING','SENT') ASC
                        LIMIT 1
                       ) AS notif_status,
                       (SELECT COUNT(*) FROM wsp_email_recall wr WHERE wr.recall_id = mr.r_ID) AS seq_count,
                       (SELECT GROUP_CONCAT(wr.status ORDER BY wr.id SEPARATOR ' | ')
                        FROM wsp_email_recall wr WHERE wr.recall_id = mr.r_ID
                       ) AS seq_detail
                FROM medex_recalls mr
                INNER JOIN patient_data pd ON pd.pid = mr.r_pid
                LEFT JOIN facility f ON f.id = mr.r_facility
                LEFT JOIN users u ON u.id = mr.r_provider
                WHERE {$mConditions}
                UNION ALL
                SELECT (-we.id) AS recall_id, we.pid,
                       we.event_date AS r_eventDate, we.facility_id AS r_facility,
                       we.provider_id AS r_provider,
                       we.reason AS r_reason, we.created_at AS r_created,
                       'entry' AS source,
                       pd.fname, pd.lname, pd.phone_cell, pd.email,
                       f.name AS facility_name,
                       CONCAT(u.fname,' ',IFNULL(u.mname,''),' ',u.lname) AS provider_name,
                       (SELECT wr.status
                        FROM wsp_email_recall wr
                        WHERE wr.recall_id = (-we.id)
                        ORDER BY FIELD(wr.status,'FAILED','SKIPPED','PENDING','SENT') ASC
                        LIMIT 1
                       ) AS notif_status,
                       (SELECT COUNT(*) FROM wsp_email_recall wr WHERE wr.recall_id = (-we.id)) AS seq_count,
                       (SELECT GROUP_CONCAT(wr.status ORDER BY wr.id SEPARATOR ' | ')
                        FROM wsp_email_recall wr WHERE wr.recall_id = (-we.id)
                       ) AS seq_detail
                FROM wsp_email_recall_entries we
                INNER JOIN patient_data pd ON pd.pid = we.pid
                LEFT JOIN facility f ON f.id = we.facility_id
                LEFT JOIN users u ON u.id = we.provider_id
                WHERE {$eConditions}
                ORDER BY r_eventDate ASC
                LIMIT ? OFFSET ?";

    $dataParams = array_merge($mParams, $eParams, [$limit, $offset]);
    $res        = sqlStatementThrowException($dataSql, $dataParams);
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
