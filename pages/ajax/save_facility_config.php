<?php
/**
 * save_facility_config.php — Saves the extended configuration for a facility.
 *
 * POST body: facility_id + fields
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

$facilityId = (int)($_POST['facility_id'] ?? 0);
if ($facilityId === 0) {
    echo json_encode(['success' => false, 'error' => 'Missing facility_id']);
    exit;
}

$fc = new FacilityConfig();
$current = $fc->getByFacilityId($facilityId);

// -- Non-credential config data (saved to wsp_email_facility_config) --
$data = [
    'facility_id'         => $facilityId,
    'current_vendor'      => trim($_POST['current_vendor']       ?? 'wasenderapi'),
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
    'notify_cancelled'    => (int)($_POST['notify_cancelled']    ?? 0),
    'send_weekday_start'    => isset($_POST['send_weekday_start'])    ? (int)$_POST['send_weekday_start']    : 7,
    'send_weekday_end'      => isset($_POST['send_weekday_end'])      ? (int)$_POST['send_weekday_end']      : 21,
    'send_saturday_enabled' => isset($_POST['send_saturday_enabled']) ? (int)$_POST['send_saturday_enabled'] : 0,
    'send_saturday_start'   => isset($_POST['send_saturday_start'])   ? (int)$_POST['send_saturday_start']   : 8,
    'send_saturday_end'     => isset($_POST['send_saturday_end'])     ? (int)$_POST['send_saturday_end']     : 13,
    'send_sunday_enabled'   => isset($_POST['send_sunday_enabled'])   ? (int)$_POST['send_sunday_enabled']   : 0,
    'send_sunday_start'     => isset($_POST['send_sunday_start'])     ? (int)$_POST['send_sunday_start']     : 9,
    'send_sunday_end'       => isset($_POST['send_sunday_end'])       ? (int)$_POST['send_sunday_end']       : 12,
];

// -- Gateway credentials (saved to wsp_email_gateways_config) --
// Helper: read a masked credential field
function resolveCredential(string $field, array $post, ?array $current): string
{
    if (!empty($post[$field]) && !str_contains($post[$field], '•')) {
        return trim($post[$field]);
    }
    return $current[$field] ?? '';
}

// UltraMsg (also stores legacy webhook_secret for webhook validation)
$data['gateway_config_ultramsg'] = [
    'instance'        => !empty($_POST['ultramsg_instance']) ? trim($_POST['ultramsg_instance']) : ($current['ultramsg_instance'] ?? ''),
    'api_key'         => resolveCredential('ultramsg_api_key', $_POST, $current),
    'webhook_secret'  => resolveCredential('webhook_secret', $_POST, $current),
];

// WaSenderAPI
$data['gateway_config_wasenderapi'] = [
    'api_key'         => resolveCredential('wasenderapi_api_key', $_POST, $current),
    'webhook_secret'  => resolveCredential('wasenderapi_webhook_secret', $_POST, $current),
];

// OpenWA
$data['gateway_config_openwa'] = [
    'instance'        => !empty($_POST['openwa_instance']) ? trim($_POST['openwa_instance']) : ($current['openwa_instance'] ?? ''),
    'api_key'         => resolveCredential('openwa_api_key', $_POST, $current),
    'webhook_secret'  => resolveCredential('openwa_webhook_secret', $_POST, $current),
];

// Evolution-Go
$data['gateway_config_evolution-go'] = [
    'base_url'        => !empty($_POST['evolution_go_base_url']) ? trim($_POST['evolution_go_base_url']) : ($current['evolution_go_base_url'] ?? ''),
    'api_key'         => resolveCredential('evolution_go_api_key', $_POST, $current),
    'instance_name'   => !empty($_POST['evolution_go_instance_name']) ? trim($_POST['evolution_go_instance_name']) : ($current['evolution_go_instance_name'] ?? ''),
    'webhook_secret'  => resolveCredential('evolution_go_webhook_secret', $_POST, $current),
];

// -- Handle File Uploads --
$uploadDir = $GLOBALS['fileroot'] . '/public/images/wsp_email/';
$types = ['logo_wsp', 'logo_email'];
$uploadErrors = [];

foreach ($types as $type) {
    if (isset($_FILES[$type]) && $_FILES[$type]['error'] === UPLOAD_ERR_OK) {
        $typeDir = $uploadDir . $type;
        if (!is_dir($typeDir)) {
            @mkdir($typeDir, 0755, true);
        }

        $ext = pathinfo($_FILES[$type]['name'], PATHINFO_EXTENSION);
        $filename = "{$type}_f{$facilityId}." . $ext;
        $targetPath = $typeDir . '/' . $filename;

        if (move_uploaded_file($_FILES[$type]['tmp_name'], $targetPath)) {
            $data[$type] = $filename;
        } else {
            $uploadErrors[] = "Failed to move uploaded file for {$type}. Check directory permissions.";
        }
    } else {
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
$allowedVendors = ['wasenderapi', 'ultramsg', 'openwa', 'evolution-go'];
if (!in_array($data['current_vendor'], $allowedVendors, true)) {
    echo json_encode(['success' => false, 'error' => 'Invalid vendor']);
    exit;
}

$success = $fc->save($data);

// Save notification schedule if provided
if ($success && isset($_POST['schedule_json'])) {
    $scheduleRows = json_decode($_POST['schedule_json'], true);
    if (is_array($scheduleRows)) {
        $fc->saveSchedule($facilityId, $scheduleRows);
    }
}

echo json_encode(['success' => $success, 'debug_data' => $data]);
