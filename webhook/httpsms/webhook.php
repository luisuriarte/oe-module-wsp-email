<?php
/**
 * webhook.php - Receives SMS delivery status updates from HttpSMS.
 *
 * HttpSMS posts events to this URL whenever the status of a sent SMS changes.
 * Events handled:
 *   - message.phone.sent      -> SENT
 *   - message.phone.delivered -> DELIVERED
 *   - message.send.failed     -> FAILED
 *   - message.send.expired    -> FAILED
 *   - message.phone.received  -> Logged only (inbound SMS, not tracked)
 *
 * Signature verification (optional):
 *   Header: X-HttpSms-Signature = HMAC-SHA256(raw_body, signing_key)
 *   Configured per-facility as httpsms_signing_key in wsp_email_gateways_config.
 *
 * Public URL (production):
 *   http://hcd.origen.ar/webhook/httpsms/webhook.php
 *
 * @package   OpenEMR\Modules\WspEmail
 * @author    Luis A. Uriarte <luis.uriarte@gmail.com>
 * @copyright Copyright (c) 2024-2025 Luis A. Uriarte
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

$ignoreAuth = true;

$openemrRoot = realpath(__DIR__ . '/../..');
$moduleRoot  = $openemrRoot . '/interface/modules/custom_modules/oe-module-wsp-email';

require_once $openemrRoot . '/interface/globals.php';
require_once $moduleRoot . '/src/NotificationLog.php';
require_once $moduleRoot . '/src/FacilityConfig.php';
require_once $moduleRoot . '/src/StatusNormalizer.php';

use OpenEMR\Modules\WspEmail\NotificationLog;
use OpenEMR\Modules\WspEmail\FacilityConfig;
use OpenEMR\Modules\WspEmail\StatusNormalizer;

define('HTTPSMS_WEBHOOK_LOG', $openemrRoot . '/webhook/logs/httpsms_webhook.log');

function webhookLog(string $message): void
{
    $logDir = dirname(HTTPSMS_WEBHOOK_LOG);
    if (!is_dir($logDir)) {
        @mkdir($logDir, 0755, true);
    }
    @file_put_contents(HTTPSMS_WEBHOOK_LOG, date('Y-m-d H:i:s') . ' - ' . $message . "\n", FILE_APPEND | LOCK_EX);
}

$headers = getallheaders() ?: [];
webhookLog('Request received. Method: ' . ($_SERVER['REQUEST_METHOD'] ?? 'UNKNOWN'));
webhookLog('Headers: ' . json_encode($headers));

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    webhookLog('Rejected: not a POST request.');
    http_response_code(405);
    exit;
}

$rawInput    = file_get_contents('php://input');
$webhookData = json_decode($rawInput, true);
webhookLog('Body: ' . $rawInput);

if (!is_array($webhookData)) {
    webhookLog('Rejected: invalid JSON payload.');
    http_response_code(400);
    exit;
}

$eventType = $webhookData['type']       ?? '';
$eventId   = $webhookData['event_id']   ?? '';
$msgId     = $webhookData['data']['id'] ?? '';

webhookLog("Event type: {$eventType} | event_id: {$eventId} | msg_id: {$msgId}");

// Optional signature verification
$receivedSig = $headers['X-HttpSms-Signature']
            ?? $headers['x-httpsms-signature']
            ?? $headers['x-httpsms-Signature']
            ?? '';

if (!empty($receivedSig)) {
    $facilityConfig = new FacilityConfig();
    $allFacilities  = $facilityConfig->getAllFacilitiesWithConfig();
    $signingKey     = '';

    foreach ($allFacilities as $facility) {
        $key = $facility['httpsms_signing_key'] ?? '';
        if (!empty($key)) {
            $signingKey = $key;
            break;
        }
    }

    if (!empty($signingKey)) {
        $expectedSig = hash_hmac('sha256', $rawInput, $signingKey);
        if (!hash_equals($expectedSig, $receivedSig)) {
            webhookLog("Rejected: invalid X-HttpSms-Signature. Expected={$expectedSig}, Received={$receivedSig}");
            http_response_code(401);
            exit;
        }
        webhookLog('Signature verified OK.');
    } else {
        webhookLog('WARNING: X-HttpSms-Signature received but no signing_key configured. Skipping validation.');
    }
} else {
    webhookLog('No signature header - proceeding without verification.');
}

if (empty($msgId)) {
    webhookLog('Skipped: no data.id in payload.');
    http_response_code(200);
    header('Content-Type: application/json');
    echo json_encode(['status' => 'ok', 'note' => 'no message id']);
    exit;
}

$notifLog = new NotificationLog();

switch ($eventType) {

    case 'message.phone.sent':
        $rawStatus = $webhookData['data']['status'] ?? 'sent';
        $notifLog->updateStatus($msgId, $rawStatus, 'httpsms', $webhookData);
        $canonical = StatusNormalizer::normalize('httpsms', $rawStatus);
        webhookLog("message.phone.sent: msgId={$msgId} -> raw={$rawStatus}, canonical={$canonical}");
        break;

    case 'message.phone.delivered':
        $rawStatus = $webhookData['data']['status'] ?? 'delivered';
        $notifLog->updateStatus($msgId, $rawStatus, 'httpsms', $webhookData);
        $canonical = StatusNormalizer::normalize('httpsms', $rawStatus);
        webhookLog("message.phone.delivered: msgId={$msgId} -> raw={$rawStatus}, canonical={$canonical}");
        break;

    case 'message.send.failed':
        $rawStatus = $webhookData['data']['status'] ?? 'failed';
        $reason    = $webhookData['data']['failure_reason'] ?? 'Unknown reason';
        $notifLog->updateStatus($msgId, $rawStatus, 'httpsms', $webhookData);
        $canonical = StatusNormalizer::normalize('httpsms', $rawStatus);
        webhookLog("message.send.failed: msgId={$msgId} -> raw={$rawStatus}, canonical={$canonical}. Reason: {$reason}");
        break;

    case 'message.send.expired':
        $rawStatus = $webhookData['data']['status'] ?? 'expired';
        $notifLog->updateStatus($msgId, $rawStatus, 'httpsms', $webhookData);
        $canonical = StatusNormalizer::normalize('httpsms', $rawStatus);
        webhookLog("message.send.expired: msgId={$msgId} -> raw={$rawStatus}, canonical={$canonical}");
        break;

    case 'message.phone.received':
        $from    = $webhookData['data']['contact'] ?? 'unknown';
        $content = $webhookData['data']['content']  ?? '';
        webhookLog("message.phone.received: from={$from}, content=" . mb_substr($content, 0, 80));
        break;

    case 'phone.heartbeat.online':
    case 'phone.heartbeat.offline':
        $phone = $webhookData['data']['phone_number'] ?? 'unknown';
        webhookLog("{$eventType}: phone={$phone}");
        break;

    default:
        webhookLog("Unknown event type: {$eventType} - logged only.");
        break;
}

webhookLog('Response: HTTP 200 OK');
http_response_code(200);
header('Content-Type: application/json');
echo json_encode(['status' => 'ok']);
