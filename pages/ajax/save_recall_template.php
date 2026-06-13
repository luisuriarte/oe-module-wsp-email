<?php
/**
 * save_recall_template.php — Saves the recall notification template for a facility.
 *
 * POST body: facility_id, wsp_message, email_subject, email_message, enabled
 * Returns: JSON { success: bool, error?: string }
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

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        echo json_encode(['success' => false, 'error' => 'POST required']);
        exit;
    }

    $facilityId = (int)($_POST['facility_id'] ?? 0);
    if ($facilityId === 0) {
        echo json_encode(['success' => false, 'error' => 'Missing facility_id']);
        exit;
    }

    // Ensure notification_type column exists (auto-migrate for existing installs)
    $colCheck = sqlQuery(
        "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME   = 'wsp_email_notification_templates'
           AND COLUMN_NAME  = 'notification_type'"
    );
    if (empty($colCheck)) {
        // Add the column if it doesn't exist yet
        sqlStatement(
            "ALTER TABLE `wsp_email_notification_templates`
             ADD COLUMN `notification_type` varchar(20) NOT NULL DEFAULT 'appointment'
             COMMENT 'Tipo: appointment | recall'
             AFTER `facility_id`"
        );
        // Update the unique key to include notification_type
        // (ignore error if index already correct)
        try {
            sqlStatement("ALTER TABLE `wsp_email_notification_templates` DROP INDEX `uq_template`");
        } catch (\Throwable $ignored) {}
        sqlStatement(
            "ALTER TABLE `wsp_email_notification_templates`
             ADD UNIQUE KEY `uq_template` (`facility_id`, `notification_type`, `pc_catid`, `pc_apptstatus`, `recipient_type`)"
        );
    }

    $data = [
        'wsp_message'   => $_POST['wsp_message']   ?? '',
        'email_subject' => trim($_POST['email_subject'] ?? ''),
        'email_message' => $_POST['email_message'] ?? '',
        'enabled'       => (int)($_POST['enabled'] ?? 1),
    ];

    $fc      = new FacilityConfig();
    $success = $fc->saveRecallTemplate($facilityId, $data);

    ob_end_clean();
    echo json_encode(['success' => $success]);

} catch (\Throwable $e) {
    ob_end_clean();
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
