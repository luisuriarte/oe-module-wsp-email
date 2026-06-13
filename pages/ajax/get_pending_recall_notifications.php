<?php
/**
 * get_pending_recall_notifications.php
 *
 * Returns all upcoming/pending recall notification sequences.
 * For each recall + schedule seq, calculates:
 *   scheduled_for = DATE_SUB(r_eventDate, INTERVAL days_before DAY)
 *
 * Only returns sequences where:
 *   - scheduled_for >= CURDATE()            (not in the past)
 *   - scheduled_for <= CURDATE() + horizon  (within N days ahead)
 *   - No SENT/SKIPPED entry exists in wsp_email_recall for that recall+seq
 *
 * GET params:
 *   facility_id  (optional, 0 = all)
 *   horizon      (optional, default 30 — days ahead to look)
 *
 * Returns: JSON { success: bool, rows: [...], total: int }
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

    $facilityId = (int)($_GET['facility_id'] ?? 0);
    $horizon    = max(1, min((int)($_GET['horizon'] ?? 30), 365));

    // Ensure tables exist
    sqlStatement("CREATE TABLE IF NOT EXISTS `wsp_email_recall_schedule` (
        `id`            int(11)      NOT NULL AUTO_INCREMENT,
        `facility_id`   int(11)      NOT NULL,
        `seq`           tinyint(3)   NOT NULL,
        `days_before`   int(5)       NOT NULL DEFAULT 7,
        `enabled_wsp`   tinyint(1)   NOT NULL DEFAULT 1,
        `enabled_email` tinyint(1)   NOT NULL DEFAULT 1,
        `enabled`       tinyint(1)   NOT NULL DEFAULT 1,
        `created_at`    datetime     DEFAULT CURRENT_TIMESTAMP,
        `updated_at`    datetime     DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        UNIQUE KEY `uq_facility_seq` (`facility_id`, `seq`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

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
        UNIQUE KEY `uq_recall_seq` (`recall_id`, `seq`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

    // Build facility filter
    $facFilter  = $facilityId > 0 ? 'AND mr.r_facility = ?' : '';
    $facParams  = $facilityId > 0 ? [$facilityId] : [];

    // Main query: cross-join recalls × schedule sequences, filter pending
    $sql = "
        SELECT
            mr.r_ID           AS recall_id,
            mr.r_pid          AS pid,
            mr.r_eventDate,
            mr.r_reason,
            mr.r_facility,
            rs.seq,
            rs.days_before,
            rs.enabled_wsp,
            rs.enabled_email,
            DATE_SUB(mr.r_eventDate, INTERVAL rs.days_before DAY) AS scheduled_for,
            DATEDIFF(DATE_SUB(mr.r_eventDate, INTERVAL rs.days_before DAY), CURDATE()) AS days_until_send,
            pd.fname,
            pd.lname,
            pd.phone_cell,
            pd.email,
            pd.hipaa_allowsms,
            pd.hipaa_allowemail,
            f.name            AS facility_name,
            CONCAT(u.fname,' ',IFNULL(u.mname,''),' ',u.lname) AS provider_name,
            wr.status         AS notif_status,
            wr.sent_at
        FROM medex_recalls mr
        INNER JOIN patient_data pd         ON pd.pid = mr.r_pid
        INNER JOIN wsp_email_recall_schedule rs
               ON rs.facility_id = mr.r_facility
              AND rs.enabled     = 1
        LEFT  JOIN wsp_email_recall wr
               ON wr.recall_id   = mr.r_ID
              AND wr.seq         = rs.seq
        LEFT  JOIN facility f              ON f.id = mr.r_facility
        LEFT  JOIN users u                 ON u.id = mr.r_provider
        WHERE rs.enabled = 1
          AND DATE_SUB(mr.r_eventDate, INTERVAL rs.days_before DAY) >= CURDATE()
          AND DATE_SUB(mr.r_eventDate, INTERVAL rs.days_before DAY) <= DATE_ADD(CURDATE(), INTERVAL ? DAY)
          AND (wr.status IS NULL OR wr.status NOT IN ('SENT','SKIPPED'))
          {$facFilter}
        ORDER BY scheduled_for ASC, mr.r_pid ASC, rs.seq ASC
        LIMIT 200";

    $params = array_merge([$horizon], $facParams);
    $res    = sqlStatement($sql, $params);
    $rows   = [];
    while ($row = sqlFetchArray($res)) {
        $rows[] = $row;
    }

    ob_end_clean();
    echo json_encode([
        'success' => true,
        'rows'    => $rows,
        'total'   => count($rows),
    ]);

} catch (\Throwable $e) {
    ob_end_clean();
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage(), 'rows' => [], 'total' => 0]);
}
