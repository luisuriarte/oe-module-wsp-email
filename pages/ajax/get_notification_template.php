<?php
/**
 * get_notification_template.php — Fetches notification template by context.
 *
 * GET params:
 *   - pc_catid (int): Appointment category ID (5=Ambulatorio, 70/71=HBC, 80=Telehealth)
 *   - pc_apptstatus (string): Appointment status (-scheduled, -cancelled, etc.)
 *   - type (string): WSP or Email
 *   - recipient (string): patient or provider
 *   - facility_id (int): Facility ID
 *
 * @package   OpenEMR\Modules\WspEmail
 */

$sessionAllowWrite = false;
require_once __DIR__ . '/../../../../../globals.php';

header('Content-Type: application/json');

use OpenEMR\Common\Acl\AclMain;
if (!AclMain::aclCheckCore('patients', 'demo')) {
    http_response_code(403);
    echo json_encode(['error' => 'Access denied']);
    exit;
}

$pcCatId     = (int)($_GET['pc_catid'] ?? 0);
$apptStatus  = $_GET['pc_apptstatus'] ?? '-scheduled';
$recipient   = $_GET['recipient'] ?? 'patient';
$facilityId  = (int)($_GET['facility_id'] ?? 0);

if (!$pcCatId) {
    echo json_encode(['error' => 'Missing pc_catid']);
    exit;
}

// Fetch template from wsp_email_notification_templates
$sql = "SELECT * FROM wsp_email_notification_templates 
        WHERE facility_id = ? AND pc_catid = ? AND pc_apptstatus = ? AND recipient_type = ? AND enabled = 1
        LIMIT 1";

$tpl = sqlQuery($sql, [$facilityId, $pcCatId, $apptStatus, $recipient]);

if ($tpl && (!empty($tpl['wsp_message']) || !empty($tpl['email_message']) || !empty($tpl['email_subject']))) {
    echo json_encode([
        'success' => true,
        'wsp_message' => $tpl['wsp_message'] ?? '',
        'email_subject' => $tpl['email_subject'] ?? '',
        'email_message' => $tpl['email_message'] ?? ''
    ]);
} else {
    // Fallback: try with default facility_id=0 or generic template
    $sqlFallback = "SELECT * FROM wsp_email_notification_templates 
                    WHERE facility_id = 0 AND pc_catid = ? AND pc_apptstatus = ? AND recipient_type = ? AND enabled = 1
                    LIMIT 1";
    $tplFallback = sqlQuery($sqlFallback, [$pcCatId, $apptStatus, $recipient]);
    
    if ($tplFallback) {
        echo json_encode([
            'success' => true,
            'wsp_message' => $tplFallback['wsp_message'] ?? '',
            'email_subject' => $tplFallback['email_subject'] ?? '',
            'email_message' => $tplFallback['email_message'] ?? ''
        ]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Template not found']);
    }
}
