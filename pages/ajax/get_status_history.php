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

$log        = new NotificationLog();
$rawHistory = $log->getStatusHistory($logId);

$history = array_map(function (array $h): array {
    $statusUpper = strtoupper($h['status'] ?? '');
    $labels = [
        'QUEUED'                 => xl('Queued'),
        'SENT'                   => xl('Sent'),
        'DELIVERED'              => xl('Delivered'),
        'READ'                   => xl('Read'),
        'FAILED'                 => xl('Failed'),
        'INVALID'                => xl('Invalid'),
        'ERROR'                  => xl('Error'),
        'UNSENT'                 => xl('Not Sent'),
        'WSP_NOT_ON_WA'          => xl('Not on WhatsApp'),
        'WSP_CHECK_UNAVAILABLE'  => xl('Verification Pending'),
    ];
    $h['status_label'] = $labels[$statusUpper] ?? xl($h['status'] ?? '');
    return $h;
}, $rawHistory);

echo json_encode(['history' => $history]);
