<?php
/**
 * get_stats.php — Returns notification stats for Chart.js dashboard.
 *
 * GET params: from (date), to (date), facility_id (int, optional)
 * Returns: JSON { stats: [...] }
 *
 * @package   OpenEMR\Modules\WspEmail
 */

$sessionAllowWrite = false;
require_once __DIR__ . '/../../../../../globals.php';
require_once __DIR__ . '/../../src/NotificationLog.php';

use OpenEMR\Modules\WspEmail\NotificationLog;

header('Content-Type: application/json');

use OpenEMR\Common\Acl\AclMain;
if (!AclMain::aclCheckCore('patients', 'demo')) {
    http_response_code(403);
    echo json_encode(['error' => 'Access denied']);
    exit;
}

$from       = $_GET['from']        ?? date('Y-m-d', strtotime('-7 days'));
$to         = $_GET['to']          ?? date('Y-m-d');
$facilityId = isset($_GET['facility_id']) && $_GET['facility_id'] !== '' ? (int)$_GET['facility_id'] : null;

// Basic date validation
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) $from = date('Y-m-d', strtotime('-7 days'));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $to))   $to   = date('Y-m-d');

$log   = new NotificationLog();
$stats = $log->getStats($from, $to, $facilityId);

echo json_encode(['stats' => $stats, 'from' => $from, 'to' => $to]);
