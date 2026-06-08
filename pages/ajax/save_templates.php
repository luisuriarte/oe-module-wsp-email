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
    // Collect incoming IDs and delete rows that were removed in the UI
    $incomingIds = [];
    foreach ($templates as $tpl) {
        $id = !empty($tpl['id']) ? (int)$tpl['id'] : 0;
        if ($id > 0) $incomingIds[] = $id;
    }
    if (!empty($incomingIds)) {
        $placeholders = implode(',', array_fill(0, count($incomingIds), '?'));
        sqlStatement("DELETE FROM wsp_email_notification_templates WHERE facility_id = ? AND id NOT IN ($placeholders)", array_merge([$fid], $incomingIds));
    } else {
        sqlStatement("DELETE FROM wsp_email_notification_templates WHERE facility_id = ?", [$fid]);
    }

    foreach ($templates as $tpl) {
        $id = !empty($tpl['id']) ? (int)$tpl['id'] : 0;

        if ($id > 0) {
            // Update existing — ensure it belongs to this facility
            $check = sqlQuery("SELECT facility_id FROM wsp_email_notification_templates WHERE id = ?", [$id]);
            if (!$check || (int)$check['facility_id'] !== $fid) {
                continue;
            }
            sqlStatement(
                "UPDATE wsp_email_notification_templates SET 
                    wsp_message = ?, email_subject = ?, email_message = ?, enabled = ?
                WHERE id = ?",
                [
                    $tpl['wsp_message'] ?? '',
                    $tpl['email_subject'] ?? '',
                    $tpl['email_message'] ?? '',
                    isset($tpl['enabled']) ? (int)$tpl['enabled'] : 1,
                    $id
                ]
            );
        } else {
            // Insert new template
            $catId = (int)($tpl['pc_catid'] ?? 0);
            $status = $tpl['pc_apptstatus'] ?? '-scheduled';
            $recipient = $tpl['recipient_type'] ?? 'patient';
            // Avoid duplicate key violation (facility_id, pc_catid, pc_apptstatus, recipient_type)
            $existing = sqlQuery(
                "SELECT id FROM wsp_email_notification_templates
                 WHERE facility_id = ? AND pc_catid = ? AND pc_apptstatus = ?
                   AND recipient_type = ? LIMIT 1",
                [$fid, $catId, $status, $recipient]
            );
            if ($existing) {
                continue; // Already exists, skip
            }
            sqlStatement(
                "INSERT INTO wsp_email_notification_templates
                    (facility_id, pc_catid, pc_apptstatus, recipient_type,
                     category_name, wsp_message, email_subject, email_message, enabled)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)",
                [
                    $fid, $catId, $status, $recipient,
                    $tpl['category_name'] ?? '',
                    $tpl['wsp_message'] ?? '',
                    $tpl['email_subject'] ?? '',
                    $tpl['email_message'] ?? '',
                    isset($tpl['enabled']) ? (int)$tpl['enabled'] : 1
                ]
            );
        }
    }
    echo json_encode(['success' => true]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
