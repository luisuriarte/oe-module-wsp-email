<?php
/**
 * save_recall_schedule.php — Saves the recall notification schedule for a facility.
 *
 * POST body: facility_id, schedule_json (JSON array of slots)
 * Returns: JSON { success: bool, error?: string }
 *
 * @package   OpenEMR\Modules\WspEmail
 */

// Buffer output so any stray SQL errors don't break JSON
ob_start();

$sessionAllowWrite = true;
require_once __DIR__ . '/../../../../../globals.php';
require_once __DIR__ . '/../../src/FacilityConfig.php';

use OpenEMR\Modules\WspEmail\FacilityConfig;
use OpenEMR\Common\Acl\AclMain;

// Discard any output produced during bootstrap
ob_clean();
header('Content-Type: application/json');

try {
    if (!AclMain::aclCheckCore('admin', 'super')) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Access denied']);
        exit;
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        echo json_encode(['success' => false, 'error' => 'POST required']);
        exit;
    }

    $facilityId   = (int)($_POST['facility_id'] ?? 0);
    $scheduleJson = $_POST['schedule_json'] ?? '';

    if ($facilityId === 0) {
        echo json_encode(['success' => false, 'error' => 'Missing facility_id']);
        exit;
    }

    $slots = json_decode($scheduleJson, true);
    if (!is_array($slots)) {
        echo json_encode(['success' => false, 'error' => 'Invalid schedule_json']);
        exit;
    }

    // Ensure the recall schedule table exists (auto-migrate)
    sqlStatement("CREATE TABLE IF NOT EXISTS `wsp_email_recall_schedule` (
        `id`            int(11)      NOT NULL AUTO_INCREMENT,
        `facility_id`   int(11)      NOT NULL                   COMMENT 'FK -> facility.id',
        `seq`           tinyint(3)   NOT NULL                   COMMENT 'Orden de envío (1, 2, 3...)',
        `days_before`   int(5)       NOT NULL DEFAULT 7         COMMENT 'Días antes de r_eventDate para enviar',
        `enabled_wsp`   tinyint(1)   NOT NULL DEFAULT 1         COMMENT 'WhatsApp habilitado',
        `enabled_email` tinyint(1)   NOT NULL DEFAULT 1         COMMENT 'Email habilitado',
        `enabled_sms`   tinyint(1)   NOT NULL DEFAULT 1         COMMENT 'SMS habilitado',
        `enabled`       tinyint(1)   NOT NULL DEFAULT 1         COMMENT 'Secuencia activa',
        `created_at`    datetime     DEFAULT CURRENT_TIMESTAMP,
        `updated_at`    datetime     DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        UNIQUE KEY `uq_facility_seq` (`facility_id`, `seq`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

    // Validate and sanitise slots
    $clean = [];
    foreach ($slots as $slot) {
        $seq        = (int)($slot['seq']         ?? 0);
        $daysBefore = (int)($slot['days_before'] ?? 7);

        if ($seq < 1 || $daysBefore < 0) {
            continue;
        }

        $clean[] = [
            'seq'           => $seq,
            'days_before'   => $daysBefore,
            'enabled_wsp'   => (int)($slot['enabled_wsp']   ?? 1),
            'enabled_email' => (int)($slot['enabled_email'] ?? 1),
            'enabled_sms'   => (int)($slot['enabled_sms']   ?? 1),
            'enabled'       => (int)($slot['enabled']       ?? 1),
        ];
    }

    $fc      = new FacilityConfig();
    $success = $fc->saveRecallSchedule($facilityId, $clean);

    ob_end_clean();
    echo json_encode(['success' => $success]);

} catch (\Throwable $e) {
    ob_end_clean();
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
