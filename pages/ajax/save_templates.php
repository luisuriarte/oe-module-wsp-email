<?php
/**
 * save_templates.php — Saves templates for a specific facility.
 *
 * @package   OpenEMR\Modules\WspEmail
 */

$sessionAllowWrite = true;
require_once __DIR__ . '/../../../../../globals.php';

header('Content-Type: application/json');

use OpenEMR\Common\Acl\AclMain;
if (!AclMain::aclCheckCore('patients', 'demo')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Access denied']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$fid = (int)($input['facility_id'] ?? 0);
$templates = $input['templates'] ?? [];

if (!$fid || empty($templates)) {
    echo json_encode(['success' => false, 'message' => 'Missing data']);
    exit;
}

try {
    foreach ($templates as $tpl) {
        $id = (int)$tpl['id'];
        
        // Ensure this template belongs to this facility
        $check = sqlQuery("SELECT facility_id FROM wsp_email_notification_templates WHERE id = ?", [$id]);
        if (!$check || (int)$check['facility_id'] !== $fid) {
            continue; // Skip if not matching facility
        }

        sqlStatement(
            "UPDATE wsp_email_notification_templates SET 
                wsp_message = ?, 
                email_subject = ?, 
                email_message = ?,
                enabled = ?
            WHERE id = ?", 
            [
                $tpl['wsp_message'] ?? '',
                $tpl['email_subject'] ?? '',
                $tpl['email_message'] ?? '',
                isset($tpl['enabled']) ? (int)$tpl['enabled'] : 1,
                $id
            ]
        );
    }
    echo json_encode(['success' => true]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
