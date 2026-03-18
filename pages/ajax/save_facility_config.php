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

$data = [
    'facility_id'         => $facilityId,
    'vendor'              => trim($_POST['vendor']               ?? 'wasenderapi'),
    'vendor_instance'     => trim($_POST['vendor_instance']      ?? ''),
    'vendor_api_key'      => trim($_POST['vendor_api_key']       ?? ''),
    'webhook_secret'      => trim($_POST['webhook_secret']       ?? ''),
    'latitude'            => trim($_POST['latitude']             ?? ''),
    'longitude'           => trim($_POST['longitude']            ?? ''),
    'wsp_message'         => $_POST['wsp_message']               ?? '',
    'email_message'       => $_POST['email_message']             ?? '',
    'email_subject'       => trim($_POST['email_subject']        ?? ''),
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
