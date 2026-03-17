<?php
/**
 * get_status_history.php — Fetches the timeline of status changes for a notification.
 *
 * GET params: log_id (int)
 * Returns: JSON { history: [...] }
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

$logId = (int)($_GET['log_id'] ?? 0);
if (!$logId) {
    echo json_encode(['history' => [], 'error' => 'Invalid Log ID']);
    exit;
}

$log     = new NotificationLog();
$history = $log->getStatusHistory($logId);

echo json_encode(['history' => $history]);
