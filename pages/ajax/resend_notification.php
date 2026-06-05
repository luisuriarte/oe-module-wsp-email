<?php
/**
 * resend_notification.php — Retriggers a notification from the patient status tab.
 *
 * POST params: log_id (int)
 * Returns: JSON { success: bool, message: string }
 *
 * @package   OpenEMR\Modules\WspEmail
 */

$sessionAllowWrite = true;
require_once __DIR__ . '/../../../../../globals.php';
require_once __DIR__ . '/../../src/NotificationLog.php';
require_once __DIR__ . '/../../src/FacilityConfig.php';
require_once __DIR__ . '/../../src/WspSender.php';
require_once __DIR__ . '/../../src/EmailSender.php';
require_once __DIR__ . '/../../src/NotificationService.php';
require_once __DIR__ . '/../../src/Blacklist.php';

use OpenEMR\Modules\WspEmail\NotificationLog;
use OpenEMR\Modules\WspEmail\FacilityConfig;
use OpenEMR\Modules\WspEmail\WspSender;
use OpenEMR\Modules\WspEmail\EmailSender;
use OpenEMR\Modules\WspEmail\NotificationService;
use OpenEMR\Modules\WspEmail\Blacklist;

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

$logId = (int)($_POST['log_id'] ?? $_GET['log_id'] ?? 0);
if ($logId === 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid log_id']);
    exit;
}

// Load the original log entry to get patient/appointment info
$row = sqlQuery(
    "SELECT nl.*, pd.fname, pd.lname, pd.phone_cell, pd.email,
            pd.hipaa_allowsms, pd.hipaa_allowemail,
            ope.pc_eventDate, ope.pc_endDate, ope.pc_startTime, ope.pc_endTime,
            ope.pc_facility, ope.pc_eid,
            CONCAT(u.fname,' ',u.lname) AS user_name, u.suffix AS user_preffix
     FROM notification_log nl
     LEFT JOIN patient_data pd ON pd.pid = nl.pid
     LEFT JOIN openemr_postcalendar_events ope ON ope.pc_eid = nl.pc_eid
     LEFT JOIN users u ON u.id = ope.pc_aid
     WHERE nl.iLogId = ?",
    [$logId]
);

if (!$row) {
    echo json_encode(['success' => false, 'message' => 'Log entry not found']);
    exit;
}

$notifLog = new NotificationLog();
$facCfg   = new FacilityConfig();
$config   = $facCfg->getByFacilityId((int)$row['pc_facility']);

if (empty($config)) {
    echo json_encode(['success' => false, 'message' => 'Facility not configured for notifications']);
    exit;
}

$row['facility_name']    = $config['facility_name']    ?? '';
$row['facility_address'] = $config['facility_address'] ?? '';
$row['facility_phone']   = $config['facility_phone']   ?? '';
$row['facility_email']   = $config['facility_email']   ?? '';

$type = $row['type'];

if ($type === 'WSP') {
    $phone  = $row['phone_cell'] ?? '';
    $vendor = strtolower($config['current_vendor'] ?? $config['vendor'] ?? 'wasenderapi');
    $facilityId = (int)($config['facility_id'] ?? $row['pc_facility'] ?? 0);

    $blacklist = new Blacklist();
    if ($blacklist->isBlacklisted($phone, $facilityId, $vendor)) {
        echo json_encode(['success' => false, 'message' => 'Failed to resend: This number is blacklisted due to delivery failures.']);
        exit;
    }

    $template        = $config['wsp_message'] ?? '';
    $row['_message'] = WspSender::buildMessage($template, $row);
    $sender          = new WspSender();
    $result          = $sender->send($config, $row);
    $success         = $result['status'] === 'success';

    // Update blacklist status based on the result
    $blacklist->processResult($phone, $facilityId, $vendor, $result);

    $msgId           = $result['msgId'] ?? null;
    $notifLog->updateMsgId($logId, $msgId ?? '', $success ? 'in_progress' : 'error');
} else {
    $template        = $config['email_message'] ?? '';
    $row['_message'] = WspSender::buildMessage($template, $row);
    $sender          = new EmailSender();
    $success         = $sender->send($config, $row);
    $notifLog->updateMsgId($logId, '', $success ? 'sent' : 'error');
}

echo json_encode(['success' => $success, 'message' => $success ? 'Notification resent successfully.' : 'Failed to resend notification.']);
