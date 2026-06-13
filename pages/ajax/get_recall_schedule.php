<?php
/**
 * get_recall_schedule.php — Returns the recall notification schedule for a facility.
 *
 * GET params: facility_id
 * Returns: JSON { success: bool, data: [...] }
 *
 * @package   OpenEMR\Modules\WspEmail
 */

ob_start();

$sessionAllowWrite = true;
require_once __DIR__ . '/../../../../../globals.php';
require_once __DIR__ . '/../../src/FacilityConfig.php';

use OpenEMR\Modules\WspEmail\FacilityConfig;
use OpenEMR\Common\Acl\AclMain;

ob_clean();
header('Content-Type: application/json');

try {
    if (!AclMain::aclCheckCore('admin', 'super')) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Access denied']);
        exit;
    }

    $facilityId = (int)($_GET['facility_id'] ?? 0);
    if ($facilityId === 0) {
        echo json_encode(['success' => false, 'error' => 'Missing facility_id']);
        exit;
    }

    // Auto-create table if not yet migrated
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

    $fc       = new FacilityConfig();
    $schedule = $fc->getRecallSchedule($facilityId);

    ob_end_clean();
    echo json_encode(['success' => true, 'data' => $schedule]);

} catch (\Throwable $e) {
    ob_end_clean();
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage(), 'data' => []]);
}
