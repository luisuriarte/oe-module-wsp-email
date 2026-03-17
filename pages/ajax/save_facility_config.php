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

if (!acl_check('admin', 'docs')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Access denied — admin required']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'error' => 'POST required']);
    exit;
}

// Collect and sanitise POST fields
$data = [
    'facility_id'         => (int)($_POST['facility_id']         ?? 0),
    'vendor'              => trim($_POST['vendor']               ?? 'wasenderapi'),
    'vendor_instance'     => trim($_POST['vendor_instance']      ?? ''),
    'vendor_api_key'      => trim($_POST['vendor_api_key']       ?? ''),
    'webhook_secret'      => trim($_POST['webhook_secret']       ?? ''),
    'logo_wsp'            => trim($_POST['logo_wsp']             ?? ''),
    'logo_email'          => trim($_POST['logo_email']           ?? ''),
    'latitude'            => trim($_POST['latitude']             ?? ''),
    'longitude'           => trim($_POST['longitude']            ?? ''),
    'website_url'         => trim($_POST['website_url']          ?? ''),
    'geoapify_key'        => trim($_POST['geoapify_key']         ?? ''),
    'wsp_message'         => $_POST['wsp_message']               ?? '',
    'email_message'       => $_POST['email_message']             ?? '',
    'email_subject'       => trim($_POST['email_subject']        ?? ''),
    'notify_hours_before' => (int)($_POST['notify_hours_before'] ?? 48),
    'enabled_wsp'         => (int)($_POST['enabled_wsp']         ?? 0),
    'enabled_email'       => (int)($_POST['enabled_email']       ?? 0),
];

if ($data['facility_id'] === 0) {
    echo json_encode(['success' => false, 'error' => 'Missing facility_id']);
    exit;
}

// Validate vendor value
$allowedVendors = ['wasenderapi', 'waapi', 'ultramsg'];
if (!in_array($data['vendor'], $allowedVendors, true)) {
    echo json_encode(['success' => false, 'error' => 'Invalid vendor']);
    exit;
}

$fc      = new FacilityConfig();
$success = $fc->save($data);

echo json_encode(['success' => $success]);
