<?php
/**
 * get_recall_entries.php — Returns module recall entries.
 *
 * GET params:
 *   id (optional) — single entry
 *   facility_id (optional)
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

    // Auto-create table
    sqlStatement("CREATE TABLE IF NOT EXISTS `wsp_email_recall_entries` (
      `id`          int(11)      NOT NULL AUTO_INCREMENT,
      `pid`         int(11)      NOT NULL,
      `event_date`  date         NOT NULL,
      `facility_id` int(11)      NOT NULL,
      `provider_id` int(11)      DEFAULT NULL,
      `reason`      varchar(255) DEFAULT NULL,
      `created_at`  datetime     DEFAULT CURRENT_TIMESTAMP,
      `updated_at`  datetime     DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      PRIMARY KEY (`id`),
      KEY `idx_pid` (`pid`),
      KEY `idx_facility` (`facility_id`),
      KEY `idx_event_date` (`event_date`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

    $where  = [];
    $params = [];

    $id = (int)($_GET['id'] ?? 0);
    if ($id > 0) {
        $where[]  = 'we.id = ?';
        $params[] = $id;
    }

    $facilityId = (int)($_GET['facility_id'] ?? 0);
    if ($facilityId > 0) {
        $where[]  = 'we.facility_id = ?';
        $params[] = $facilityId;
    }

    $whereClause = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

    $sql = "SELECT we.*, pd.fname, pd.lname,
                   f.name AS facility_name,
                   CONCAT(u.fname,' ',IFNULL(u.mname,''),' ',u.lname) AS provider_name
            FROM wsp_email_recall_entries we
            INNER JOIN patient_data pd ON pd.pid = we.pid
            LEFT JOIN facility f ON f.id = we.facility_id
            LEFT JOIN users u ON u.id = we.provider_id
            {$whereClause}
            ORDER BY we.event_date ASC";

    $res  = sqlStatement($sql, $params);
    $rows = [];
    while ($row = sqlFetchArray($res)) {
        $rows[] = $row;
    }

    ob_end_clean();
    echo json_encode(['success' => true, 'data' => $rows, 'total' => count($rows)]);

} catch (\Throwable $e) {
    ob_end_clean();
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage(), 'data' => [], 'total' => 0]);
}
