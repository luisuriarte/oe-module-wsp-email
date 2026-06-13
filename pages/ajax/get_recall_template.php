<?php
/**
 * get_recall_template.php — Returns the recall notification template for a facility.
 *
 * GET params: facility_id
 * Returns: JSON { success: bool, data: {...} }
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

    // Auto-migrate: ensure notification_type column exists
    $colCheck = sqlQuery(
        "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME   = 'wsp_email_notification_templates'
           AND COLUMN_NAME  = 'notification_type'"
    );
    if (empty($colCheck)) {
        sqlStatement(
            "ALTER TABLE `wsp_email_notification_templates`
             ADD COLUMN `notification_type` varchar(20) NOT NULL DEFAULT 'appointment'
             COMMENT 'Tipo: appointment | recall'
             AFTER `facility_id`"
        );
        try {
            sqlStatement("ALTER TABLE `wsp_email_notification_templates` DROP INDEX `uq_template`");
        } catch (\Throwable $ignored) {}
        sqlStatement(
            "ALTER TABLE `wsp_email_notification_templates`
             ADD UNIQUE KEY `uq_template` (`facility_id`, `notification_type`, `pc_catid`, `pc_apptstatus`, `recipient_type`)"
        );
    }

    $fc  = new FacilityConfig();
    $tpl = $fc->getRecallTemplate($facilityId);

    ob_end_clean();
    echo json_encode(['success' => true, 'data' => $tpl]);

} catch (\Throwable $e) {
    ob_end_clean();
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage(), 'data' => []]);
}
