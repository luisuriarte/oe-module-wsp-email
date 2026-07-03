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

use OpenEMR\Common\Acl\AclMain;
use OpenEMR\Modules\WspEmail\NotificationLog;
use OpenEMR\Modules\WspEmail\FacilityConfig;
use OpenEMR\Modules\WspEmail\WspSender;
use OpenEMR\Modules\WspEmail\EmailSender;
use OpenEMR\Modules\WspEmail\NotificationService;
use OpenEMR\Modules\WspEmail\Blacklist;

header('Content-Type: application/json');

// Helper: write directly to module debug log (not relying on PHP error_log)
function writeDebugLog(string $msg): void
{
    $logFile = __DIR__ . '/../../logs/wsp_debug.log';
    $logDir = dirname($logFile);
    if (!is_dir($logDir)) {
        @mkdir($logDir, 0755, true);
    }
    $line = date('Y-m-d H:i:s') . ' [RESEND] ' . $msg . "\n";
    @file_put_contents($logFile, $line, FILE_APPEND | LOCK_EX);
}

try {
    if (!AclMain::aclCheckCore('patients', 'demo')) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => xlt('Access denied')]);
        exit;
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        echo json_encode(['success' => false, 'message' => xlt('POST required')]);
        exit;
    }

    $logId = (int)($_POST['log_id'] ?? $_GET['log_id'] ?? 0);
    if ($logId === 0) {
        echo json_encode(['success' => false, 'message' => xlt('Invalid log_id')]);
        exit;
    }

    writeDebugLog("Starting resend for log_id=$logId");

    // Load the original log entry to get patient/appointment info
    $row = sqlQuery(
        "SELECT nl.*, pd.fname, pd.lname, pd.phone_cell, pd.email,
                pd.hipaa_allowsms, pd.hipaa_allowemail,
                ope.pc_eventDate, ope.pc_endDate, ope.pc_startTime, ope.pc_endTime,
                ope.pc_facility, ope.pc_eid, ope.pc_catid,
                CONCAT(u.fname,' ',u.lname) AS user_name, u.suffix AS user_preffix
         FROM notification_log nl
         LEFT JOIN patient_data pd ON pd.pid = nl.pid
         LEFT JOIN openemr_postcalendar_events ope ON ope.pc_eid = nl.pc_eid
         LEFT JOIN users u ON u.id = ope.pc_aid
         WHERE nl.iLogId = ?",
        [$logId]
    );

    if (!$row) {
        writeDebugLog("Log entry not found for log_id=$logId");
        echo json_encode(['success' => false, 'message' => xlt('Log entry not found')]);
        exit;
    }

    writeDebugLog("Found log entry: pid={$row['pid']}, type={$row['type']}, facility={$row['pc_facility']}, phone_cell={$row['phone_cell']}");

    $notifLog = new NotificationLog();
    $facCfg   = new FacilityConfig();
    $config   = $facCfg->getByFacilityId((int)$row['pc_facility']);

    if (empty($config)) {
        writeDebugLog("Facility not configured for facility_id={$row['pc_facility']}");
        echo json_encode(['success' => false, 'message' => xlt('Facility not configured for notifications')]);
        exit;
    }

    // Log config keys (mask secrets)
    $configKeys = array_keys($config);
    writeDebugLog("Config loaded. Keys: " . implode(', ', $configKeys));
    writeDebugLog("current_vendor=" . ($config['current_vendor'] ?? 'NOT SET') . ", vendor=" . ($config['vendor'] ?? 'NOT SET'));
    writeDebugLog("wsp_message=" . (empty($config['wsp_message']) ? 'EMPTY' : 'SET (' . strlen($config['wsp_message']) . ' chars)'));

    // Log gateway-specific API key presence
    foreach (['ultramsg_api_key', 'wasenderapi_api_key', 'openwa_api_key', 'evolution_go_api_key'] as $key) {
        writeDebugLog("  $key=" . (empty($config[$key]) ? 'EMPTY' : 'SET'));
    }
    foreach (['ultramsg_instance', 'openwa_instance', 'evolution_go_instance_name'] as $key) {
        writeDebugLog("  $key=" . (empty($config[$key]) ? 'EMPTY' : 'SET'));
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

        writeDebugLog("WSP branch — phone='$phone' (len=" . strlen($phone) . "), vendor=$vendor, facilityId=$facilityId");

        $blacklist = new Blacklist();
        if ($blacklist->isBlacklisted($phone, $facilityId, $vendor)) {
            writeDebugLog("Phone $phone IS BLACKLISTED for facility $facilityId / vendor $vendor");
            echo json_encode(['success' => false, 'message' => xlt('Failed to resend: This number is blacklisted due to delivery failures.')]);
            exit;
        }
        writeDebugLog("Phone $phone not blacklisted — proceeding");

        // Log the original stored message for debugging
        writeDebugLog("notification_log.message='" . substr($row['message'] ?? '', 0, 200) . "'");
        writeDebugLog("pc_catid=" . ($row['pc_catid'] ?? 'NULL'));

        $template        = $config['wsp_message'] ?? '';
        writeDebugLog("Template from config length=" . strlen($template));
        if (!empty($template)) {
            $row['_message'] = WspSender::buildMessage($template, $row);
            writeDebugLog("Built message from template, length=" . strlen($row['_message'] ?? ''));
        } else {
            // Fallback: use the original message stored in notification_log.message
            // Run through buildMessage() to resolve any remaining tokens (e.g. ***PATIENT_NAME***, ***TIME***)
            $originalMsg = $row['message'] ?? '';
            writeDebugLog("Original log message length=" . strlen($originalMsg));
            $row['_message'] = WspSender::buildMessage($originalMsg, $row);
            writeDebugLog("Processed log message length=" . strlen($row['_message'] ?? ''));
            writeDebugLog("Final message content: " . substr($row['_message'] ?? '', 0, 500));
        }

        $sender          = new WspSender();
        $result          = $sender->send($config, $row);
        $success         = $result['status'] === 'success';

        writeDebugLog("Send result — status={$result['status']}, msgId={$result['msgId']}, log=" . ($result['log'] ?? ''));
        error_log("WspEmail Resend: Send result — status={$result['status']}, msgId={$result['msgId']}");

        // Update blacklist status based on the result
        $blacklist->processResult($phone, $facilityId, $vendor, $result);

        $msgId           = $result['msgId'] ?? null;
        $notifLog->updateMsgId($logId, $msgId ?? '', $success ? 'sent' : 'error');
    } else {
        writeDebugLog("Email branch");
        $template        = $config['email_message'] ?? '';
        $row['_message'] = WspSender::buildMessage($template, $row);
        $sender          = new EmailSender();
        $success         = $sender->send($config, $row);
        $notifLog->updateMsgId($logId, '', $success ? 'sent' : 'error');
    }

    echo json_encode(['success' => $success, 'message' => $success ? xlt('Notification resent successfully.') : xlt('Failed to resend notification.')]);
} catch (\Throwable $e) {
    writeDebugLog("EXCEPTION: " . $e->getMessage() . " — " . $e->getTraceAsString());
    error_log("WspEmail Resend EXCEPTION: " . $e->getMessage() . " — " . $e->getTraceAsString());
    echo json_encode(['success' => false, 'message' => xlt('Server error') . ': ' . $e->getMessage()]);
}
