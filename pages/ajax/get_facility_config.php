<?php
/**
 * get_facility_config.php — Returns the extended config for a single facility.
 *
 * GET params: facility_id (int)
 * Returns: JSON { config: {...}, facility_name: string }
 *
 * @package   OpenEMR\Modules\WspEmail
 */

$sessionAllowWrite = false;
require_once __DIR__ . '/../../../../../globals.php';
require_once __DIR__ . '/../../src/FacilityConfig.php';

use OpenEMR\Modules\WspEmail\FacilityConfig;

header('Content-Type: application/json');

if (!acl_check('admin', 'docs')) {
    http_response_code(403);
    echo json_encode(['error' => 'Access denied — admin required']);
    exit;
}

$facilityId = (int)($_GET['facility_id'] ?? 0);
if ($facilityId === 0) {
    echo json_encode(['error' => 'Invalid facility_id']);
    exit;
}

$fc     = new FacilityConfig();
$config = $fc->getByFacilityId($facilityId);

echo json_encode([
    'config'        => $config,
    'facility_name' => $config['facility_name'] ?? '',
]);
