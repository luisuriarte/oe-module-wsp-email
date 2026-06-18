<?php
/**
 * get_facility_config.php — Returns the extended config for a single facility.
 *
 * GET params: facility_id (int)
 * Returns: JSON { config: {...}, facility_name: string, gateway_configs: {...} }
 *
 * @package   OpenEMR\Modules\WspEmail
 */

$sessionAllowWrite = false;
require_once __DIR__ . '/../../../../../globals.php';
require_once __DIR__ . '/../../src/FacilityConfig.php';

use OpenEMR\Modules\WspEmail\FacilityConfig;

header('Content-Type: application/json');

use OpenEMR\Common\Acl\AclMain;
if (!AclMain::aclCheckCore('admin', 'super')) {
    http_response_code(403);
    echo json_encode(['error' => 'Access denied — admin required']);
    exit;
}

$facilityId = (int)($_GET['facility_id'] ?? 0);
if ($facilityId === 0) {
    echo json_encode(['error' => 'Invalid facility_id']);
    exit;
}

$fc       = new FacilityConfig();
$config   = $fc->getByFacilityId($facilityId);
$schedule = $fc->getSchedule($facilityId);

// Fetch separate gateway configs for the UI
$gatewayConfigs = [];
$allGw = $fc->getAllGatewayConfigs($facilityId);
foreach ($allGw as $gw) {
    $decoded = json_decode($gw['config_json'] ?? '{}', true);
    $gatewayConfigs[$gw['gateway_name']] = is_array($decoded) ? $decoded : [];
}

echo json_encode([
    'config'          => $config,
    'facility_name'   => $config['facility_name'] ?? '',
    'inactive'        => (int)($config['inactive'] ?? 0),
    'schedule'        => $schedule,
    'gateway_configs' => $gatewayConfigs,
]);
