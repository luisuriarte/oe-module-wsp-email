<?php
/**
 * get_patient_logs.php — Patient notification log search endpoint.
 *
 * GET params: q (string — name, surname or PID)
 * Returns: JSON { rows: [...] }
 *
 * @package   OpenEMR\Modules\WspEmail
 */

$sessionAllowWrite = false;
require_once __DIR__ . '/../../../../../globals.php';
require_once __DIR__ . '/../../src/NotificationLog.php';

use OpenEMR\Modules\WspEmail\NotificationLog;

header('Content-Type: application/json');

if (!acl_check('patients', 'demo')) {
    http_response_code(403);
    echo json_encode(['error' => 'Access denied']);
    exit;
}

$q = trim($_GET['q'] ?? '');
if (strlen($q) < 2) {
    echo json_encode(['rows' => [], 'error' => 'Search term too short']);
    exit;
}

$log  = new NotificationLog();
$rows = $log->searchByPatient($q, 200);

// Sanitise output — remove sensitive fields
$safe = array_map(function (array $row): array {
    return [
        'iLogId'        => (int)$row['iLogId'],
        'pid'           => (int)$row['pid'],
        'fname'         => text($row['fname'] ?? ''),
        'lname'         => text($row['lname'] ?? ''),
        'phone_cell'    => text($row['phone_cell'] ?? ''),
        'pc_eventDate'  => $row['pc_eventDate']  ?? '',
        'pc_startTime'  => $row['pc_startTime']  ?? '',
        'type'          => $row['type']           ?? '',
        'status'        => $row['status']         ?? '',
        'msg_id'        => $row['msg_id']         ?? '',
        'dSentDateTime' => $row['dSentDateTime']  ?? '',
        'pc_title'      => text($row['pc_title'] ?? ''),
    ];
}, $rows);

echo json_encode(['rows' => $safe]);
