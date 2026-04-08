<?php
/**
 * log_manual_notify.php — Registers a manual notification attempt in history.
 *
 * This endpoint does NOT send the message itself. It only records the intent
 * so the user can then open wa.me or mailto: manually. This keeps the
 * audit trail complete while relying on the browser for the actual delivery.
 *
 * POST params:
 *   - pc_eid (int): Appointment ID
 *   - pid (int): Patient ID
 *   - type (string): 'WSP' or 'Email'
 *   - recipient (string): 'patient' or 'provider'
 *   - message (string): Content sent (for logging purposes)
 *   - phone (string, optional): Phone number used (for WSP)
 *   - email_addr (string, optional): Email address used (for Email)
 *
 * @package   OpenEMR\Modules\WspEmail
 */

$sessionAllowWrite = true;
require_once __DIR__ . '/../../../../../globals.php';
require_once __DIR__ . '/../../src/NotificationLog.php';

use OpenEMR\Modules\WspEmail\NotificationLog;

header('Content-Type: application/json');

use OpenEMR\Common\Acl\AclMain;
if (!AclMain::aclCheckCore('patients', 'demo')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Access denied']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'POST required']);
    exit;
}

$pcEid      = (int)($_POST['pc_eid'] ?? 0);
$pid        = (int)($_POST['pid'] ?? 0);
$type       = trim($_POST['type'] ?? 'WSP'); // WSP or Email
$recipient  = trim($_POST['recipient'] ?? 'patient');
$message    = $_POST['message'] ?? 'Manual notification sent by user';
$phone      = $_POST['phone'] ?? '';
$emailAddr = $_POST['email_addr'] ?? '';

if (!$pcEid || !$pid) {
    echo json_encode(['success' => false, 'message' => 'Missing appointment or patient ID']);
    exit;
}

// Normalize type
$type = strtoupper($type);
if (!in_array($type, ['WSP', 'EMAIL'])) {
    $type = 'WSP';
}

try {
    $notifLog = new NotificationLog();

    // Prepare patient info for logging
    $patientInfo = "Manual notification to {$recipient}. " . ($type === 'WSP' ? "Phone: {$phone}" : "Email: {$emailAddr}");

    // Insert into notification_log
    // notification_seq = 0 indicates it's not part of the automated schedule
    $logId = $notifLog->insert(
        $type,
        $pid,
        $pcEid,
        'MANUAL', // gateway_type
        $message,
        $type === 'EMAIL' ? $emailAddr : '', // email_sender
        '', // email_subject
        $patientInfo,
        '', // gateway_info
        date('Y-m-d'), // pc_eventDate (approximate)
        date('Y-m-d'), // pc_endDate
        date('H:i:s'), // pc_startTime
        date('H:i:s'), // pc_endTime
        null, // msg_id (none for manual)
        'MANUAL_' . $type // status (e.g., MANUAL_WSP)
    );

    echo json_encode([
        'success' => true,
        'logId' => $logId,
        'message' => 'Manual notification logged successfully'
    ]);

} catch (\Exception $e) {
    error_log("WspEmail Manual Notify Error: " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => 'Database error: ' . $e->getMessage()
    ]);
}
