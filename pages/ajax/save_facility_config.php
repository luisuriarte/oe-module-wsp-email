<?php
/**
 * save_facility_config.php — Saves the extended configuration for a facility.
 *
 * POST body: facility_id + all fields from wsp_email_facility_config
 * Returns: JSON { success: bool, error?: string }
 *
 * @package   OpenEMR\Modules\WspEmail
 */

$sessionAllowWrite = true;
require_once __DIR__ . '/../../../../../globals.php';
require_once __DIR__ . '/../../src/FacilityConfig.php';

use OpenEMR\Modules\WspEmail\FacilityConfig;

header('Content-Type: application/json');

use OpenEMR\Common\Acl\AclMain;
if (!AclMain::aclCheckCore('admin', 'super')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Access denied — admin required']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'error' => 'POST required']);
    exit;
}

// Collect and sanitise POST fields
$facilityId = (int)($_POST['facility_id'] ?? 0);
if ($facilityId === 0) {
    echo json_encode(['success' => false, 'error' => 'Missing facility_id']);
    exit;
}

// Fetch current config to preserve credentials if not provided
$fc = new FacilityConfig();
$current = $fc->getByFacilityId($facilityId);

$data = [
    'facility_id'         => $facilityId,
    'current_vendor'      => trim($_POST['current_vendor']       ?? 'wasenderapi'),
    // Legacy fields (for backward compatibility)
    'vendor'              => trim($_POST['current_vendor']       ?? 'wasenderapi'),
    'vendor_instance'     => trim($_POST['vendor_instance']      ?? ''),
    'vendor_api_key'      => trim($_POST['vendor_api_key']       ?? ''),
    'webhook_secret'      => trim($_POST['webhook_secret']       ?? ''),
    // UltraMsg specific - only update if provided
    'ultramsg_instance'   => !empty($_POST['ultramsg_instance']) ? trim($_POST['ultramsg_instance']) : ($current['ultramsg_instance'] ?? ''),
    'ultramsg_api_key'    => !empty($_POST['ultramsg_api_key']) ? trim($_POST['ultramsg_api_key']) : ($current['ultramsg_api_key'] ?? ''),
    // WaSenderAPI specific - only update if provided
    'wasenderapi_api_key'      => !empty($_POST['wasenderapi_api_key']) ? trim($_POST['wasenderapi_api_key']) : ($current['wasenderapi_api_key'] ?? ''),
    'wasenderapi_webhook_secret' => !empty($_POST['wasenderapi_webhook_secret']) ? trim($_POST['wasenderapi_webhook_secret']) : ($current['wasenderapi_webhook_secret'] ?? ''),
    // Common configuration
    'logo_wsp'            => $_FILES['logo_wsp']['name'] ?? ($current['logo_wsp'] ?? ''),
    'logo_email'          => $_FILES['logo_email']['name'] ?? ($current['logo_email'] ?? ''),
    'latitude'            => trim($_POST['latitude']         ?? ''),
    'longitude'           => trim($_POST['longitude']        ?? ''),
    'wsp_message'         => $_POST['wsp_message']           ?? '',
    'email_message'       => $_POST['email_message']         ?? '',
    'email_subject'       => trim($_POST['email_subject']    ?? ''),
    'notify_hours_before' => (int)($_POST['notify_hours_before'] ?? 48),
    'enabled_wsp'         => (int)($_POST['enabled_wsp']         ?? 0),
    'enabled_email'       => (int)($_POST['enabled_email']       ?? 0),
];

// Handle File Uploads
$uploadDir = __DIR__ . '/../../public/images/';
$types = ['logo_wsp', 'logo_email'];
$uploadErrors = [];

foreach ($types as $type) {
    if (isset($_FILES[$type]) && $_FILES[$type]['error'] === UPLOAD_ERR_OK) {
        $ext = pathinfo($_FILES[$type]['name'], PATHINFO_EXTENSION);
        // User requested: logo_wsp.png (or jpg). We'll use logo_wsp_f{id}.ext to distinguish facilities.
        $filename = "{$type}_f{$facilityId}." . $ext;
        $targetPath = $uploadDir . $type . '/' . $filename;
        
        if (move_uploaded_file($_FILES[$type]['tmp_name'], $targetPath)) {
            $data[$type] = $filename;
        } else {
            $uploadErrors[] = "Failed to move uploaded file for {$type}. Check directory permissions.";
        }
    } else {
        // Fetch current to keep if not uploaded
        if (!isset($fc)) $fc = new FacilityConfig();
        $current = $fc->getByFacilityId($facilityId);
        if (!empty($current[$type])) {
            $data[$type] = $current[$type];
        } else {
            $data[$type] = null;
        }
    }
}

if (!empty($uploadErrors)) {
    echo json_encode(['success' => false, 'error' => implode(' | ', $uploadErrors)]);
    exit;
}

// Validate vendor value
$allowedVendors = ['wasenderapi', 'ultramsg'];
if (!in_array($data['vendor'], $allowedVendors, true)) {
    echo json_encode(['success' => false, 'error' => 'Invalid vendor']);
    exit;
}

if (!isset($fc)) $fc = new FacilityConfig();
$success = $fc->save($data);

// Save notification schedule if provided
if ($success && isset($_POST['schedule_json'])) {
    $scheduleRows = json_decode($_POST['schedule_json'], true);
    if (is_array($scheduleRows)) {
        $fc->saveSchedule($facilityId, $scheduleRows);
    }
}

echo json_encode(['success' => $success, 'debug_data' => $data]);
