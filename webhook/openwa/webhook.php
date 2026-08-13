<?php
/**
 * webhook.php (OpenWA) — Receives delivery status updates from the OpenWA gateway.
 *
 * This endpoint is called by OpenWA (wa.origen.ar) when the delivery status of
 * a message changes (e.g. message.sent, message.ack, message.revoked).
 * It validates the request using X-OpenWA-Signature HMAC SHA256 and updates notification_log.
 * Uses X-Openwa-Idempotency-Key for deduplication (msg_{sessionId}_{waMessageId} or
 * rev_{sessionId}_{waMessageId}).
 *
 * Public URL:
 *   https://tudominio.openemr.com/webhook/openwa/webhook.php
 
 * @package   OpenEMR\Modules\WspEmail
 * @author    Luis A. Uriarte <luis.uriarte@gmail.com>
 * @copyright Copyright (c) 2024-2026 Luis A. Uriarte
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);
// Webhook must be accessible without browser session
$ignoreAuth = true;

$openemrRoot = realpath(__DIR__ . '/../../');  // sube a /var/www/html/origen.ar/demo
$moduleRoot  = $openemrRoot . '/interface/modules/custom_modules/oe-module-wsp-email';

require_once $openemrRoot . '/interface/globals.php';

require_once $moduleRoot . '/src/NotificationLog.php';
require_once $moduleRoot . '/src/FacilityConfig.php';
require_once $moduleRoot . '/src/StatusNormalizer.php';

use OpenEMR\Modules\WspEmail\NotificationLog;
use OpenEMR\Modules\WspEmail\FacilityConfig;
use OpenEMR\Modules\WspEmail\StatusNormalizer;

// Define a dedicated log file
function openwaLog(string $message): void
{
    $line = date('Y-m-d H:i:s') . ' — ' . $message . "\n";
    
    // 1. Webhook logs folder (/webhook/logs/)
    $whLogDir = __DIR__ . '/../logs';
    if (!is_dir($whLogDir)) {
        @mkdir($whLogDir, 0755, true);
    }
    @file_put_contents($whLogDir . '/openwa_webhook.log', $line, FILE_APPEND | LOCK_EX);

    // 2. Main module logs folder (/logs/)
    $modLogDir = realpath(__DIR__ . '/../../') . '/logs';
    if ($modLogDir && is_dir($modLogDir)) {
        @file_put_contents($modLogDir . '/openwa_webhook.log', $line, FILE_APPEND | LOCK_EX);
    }
}

// Log incoming request
openwaLog('Request received. Method: ' . ($_SERVER['REQUEST_METHOD'] ?? 'UNKNOWN'));

// Only accept POST requests
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    openwaLog('Rejected: not a POST request.');
    http_response_code(405);
    exit;
}

$headers = getallheaders() ?: [];
openwaLog('Headers: ' . json_encode($headers));

// Validate Content-Type
$contentType = $headers['Content-Type'] ?? $headers['content-type'] ?? '';
if (stripos($contentType, 'application/json') === false) {
    openwaLog("Rejected: invalid Content-Type ($contentType).");
    http_response_code(400);
    exit;
}

// Read raw body
$rawInput = file_get_contents('php://input');
openwaLog('Raw Body: ' . $rawInput);

$webhookData = json_decode($rawInput, true);
if (!is_array($webhookData)) {
    openwaLog('Rejected: invalid JSON payload.');
    http_response_code(400);
    exit;
}

$event = $webhookData['event'] ?? '';
$sessionId = $webhookData['sessionId'] ?? '';
openwaLog("Event: $event, SessionId: $sessionId");

// Test event — OpenWA dashboard ping, always respond 200
if ($event === 'test') {
    openwaLog('Test event acknowledged.');
    http_response_code(200);
    echo json_encode(['status' => 'ok', 'message' => 'Webhook is reachable']);
    exit;
}

// --- Validate signature / token ---
$receivedSignature = $headers['X-Openwa-Signature'] ?? $headers['X-OpenWA-Signature'] ?? '';
$receivedToken     = $_GET['token'] ?? '';

// Find matching facility by sessionId
$facilityConfig  = new FacilityConfig();
$allFacilities   = $facilityConfig->getAllFacilitiesWithConfig();
$expectedSecret  = '';
$matchedFacility = null;

foreach ($allFacilities as $facility) {
    $instance = $facility['openwa_instance'] ?? $facility['instance'] ?? '';
    $secret   = $facility['openwa_webhook_secret'] ?? $facility['webhook_secret'] ?? '';
    
    if ((!empty($instance) && ($instance === $sessionId || $instance === $receivedToken))
        || (!empty($secret) && $secret === $receivedToken)) {
        $expectedSecret  = $secret;
        $matchedFacility = $facility;
        break;
    }
}

$isAuthorized = false;

// 1. Authorize if matched to a facility by instance ID, sessionId, or token
if ($matchedFacility !== null) {
    $isAuthorized = true;
}

// 2. Check X-Openwa-Signature header
if (!$isAuthorized && !empty($receivedSignature) && !empty($expectedSecret)) {
    $expectedSignature = 'sha256=' . hash_hmac('sha256', $rawInput, $expectedSecret);
    if (hash_equals($expectedSignature, $receivedSignature)) {
        $isAuthorized = true;
    }
}

if (!$isAuthorized) {
    openwaLog("Rejected: unauthorized webhook request for sessionId='$sessionId', token='$receivedToken'.");
    http_response_code(401);
    exit;
}

openwaLog('Webhook request authorized successfully.');

// --- Handle events ---
$supportedEvents = ['message.sent', 'message.ack', 'message.failed', 'message.revoked'];

if (in_array($event, $supportedEvents, true)) {
    if ($event === 'message.revoked') {
        $revokedIdSource = isset($webhookData['data']['revokedId']) ? 'revokedId'
            : (isset($webhookData['data']['messageId']) ? 'messageId' : 'id');
        $msgId = $webhookData['data']['revokedId']
              ?? $webhookData['data']['messageId']
              ?? $webhookData['data']['id']
              ?? '';
        openwaLog("message.revoked: resolved msgId='$msgId' from field='$revokedIdSource'.");
    } else {
        $msgId = $webhookData['data']['messageId'] ?? $webhookData['data']['id'] ?? '';
    }

    // Ignore group chats
    $chatId = $webhookData['data']['chatId'] ?? $webhookData['data']['from'] ?? $webhookData['data']['to'] ?? '';
    if (strpos($chatId, '@g.us') !== false) {
        openwaLog("Ignored: group message ($chatId).");
        http_response_code(200);
        exit;
    }

    if (!empty($msgId)) {
        // Extract native status
        $rawStatus = '';
        if ($event === 'message.sent') {
            $rawStatus = 'sent';
        } elseif ($event === 'message.failed') {
            $rawStatus = 'failed';
        } elseif ($event === 'message.revoked') {
            $rawStatus = 'revoked';
        } elseif ($event === 'message.ack') {
            $rawStatus = (string)($webhookData['data']['status'] ?? $webhookData['data']['ack'] ?? 'ack');
        } else {
            $rawStatus = (string)($webhookData['data']['status'] ?? 'unknown');
        }

        // Idempotency: skip if same or higher priority status already recorded
        $idempotencyKey = $headers['X-Openwa-Idempotency-Key'] ?? '';
        $notifLog = new NotificationLog();

        $incomingPriority = StatusNormalizer::getPriority(
            StatusNormalizer::normalize('openwa', $rawStatus)
        );

        $existing = sqlQuery(
            "SELECT iLogId, pc_eid, pid, status_priority, status_current FROM notification_log WHERE msg_id = ?",
            [$msgId]
        );

        // Fallback search 1: if full prefixed msgId (e.g. false_5493404540440@c.us_3EB0...) is received, try matching suffix
        if ((!$existing || empty($existing['iLogId'])) && strpos($msgId, '_') !== false) {
            $parts = explode('_', $msgId);
            $shortId = end($parts);
            if (!empty($shortId) && strlen($shortId) >= 10) {
                $matchedLog = sqlQuery(
                    "SELECT iLogId, pc_eid, pid, status_priority, status_current FROM notification_log WHERE msg_id = ? OR msg_id LIKE ? LIMIT 1",
                    [$shortId, '%' . $shortId]
                );
                if ($matchedLog && !empty($matchedLog['iLogId'])) {
                    $existing = $matchedLog;
                    openwaLog("Matched short msgId='$shortId' for incoming msgId='$msgId' -> iLogId={$existing['iLogId']}");
                }
            }
        }

        // Fallback search 2: match recent log entry sent to the same phone number in the last 15 minutes
        if (!$existing || empty($existing['iLogId'])) {
            $chatId = $webhookData['data']['chatId'] ?? $webhookData['data']['from'] ?? $webhookData['data']['to'] ?? '';
            $phone  = preg_replace('/\D/', '', explode('@', $chatId)[0] ?? '');
            if (!empty($phone) && strlen($phone) >= 8) {
                $recentLog = sqlQuery(
                    "SELECT iLogId, pc_eid, pid, status_priority, status_current
                     FROM notification_log
                     WHERE type = 'WSP'
                       AND (patient_info LIKE ? OR smsgateway_info LIKE ?)
                       AND dSentDateTime >= NOW() - INTERVAL 15 MINUTE
                     ORDER BY iLogId DESC LIMIT 1",
                    ["%{$phone}%", "%{$phone}%"]
                );
                if ($recentLog && !empty($recentLog['iLogId'])) {
                    $existing = $recentLog;
                    openwaLog("Matched recent log entry by phone={$phone} -> iLogId={$existing['iLogId']}");
                }
            }
        }

        // If msgId is completely unrecognized, skip auto-creating PID:0 records to prevent ghost entries
        if (!$existing || empty($existing['iLogId'])) {
            openwaLog("Unrecognized msgId='$msgId' — skipping auto-creation of PID:0 entry.");
            http_response_code(200);
            echo json_encode(['status' => 'ok', 'skipped' => 'unrecognized_msg_id']);
            exit;
        }

        if ($existing && isset($existing['status_priority'])) {
            $existingPriority = (int)$existing['status_priority'];
            // Skip if existing status is higher priority
            if ($existingPriority > $incomingPriority && $existingPriority > 0) {
                openwaLog("Skipped: msgId=$msgId already has priority $existingPriority > $incomingPriority" .
                    ($idempotencyKey ? " (key=$idempotencyKey)" : ''));
                http_response_code(200);
                echo json_encode(['status' => 'ok', 'skipped' => 'duplicate']);
                exit;
            }
        }

        $targetMsgId = !empty($existing['msg_id']) ? $existing['msg_id'] : $msgId;
        $notifLog->updateStatus($targetMsgId, $rawStatus, 'openwa', $webhookData);

        $normalized = StatusNormalizer::normalize('openwa', $rawStatus);
        openwaLog("Updated: targetMsgId=$targetMsgId (rawId=$msgId) -> raw=$rawStatus, canonical=$normalized" .
            ($idempotencyKey ? " (key=$idempotencyKey)" : ''));

        // Update calendar event & patient tracker status if DELIVERED or READ
        if ($existing && !empty($existing['pc_eid']) && !empty($existing['pid'])) {
            $pcEid = (int)$existing['pc_eid'];
            $pid   = (int)$existing['pid'];
            $newApptStatus = null;
            if ($normalized === 'READ') {
                $newApptStatus = 'wsp-read';
            } elseif ($normalized === 'DELIVERED') {
                $newApptStatus = 'wsp-deliv';
            }

            if ($newApptStatus !== null) {
                sqlStatement(
                    "UPDATE openemr_postcalendar_events SET pc_apptstatus = ? WHERE pc_eid = ? AND pc_pid = ?",
                    [$newApptStatus, $pcEid, $pid]
                );
                sqlStatement(
                    "UPDATE patient_tracker_element pte
                     INNER JOIN patient_tracker pt ON pt.id = pte.pt_tracker_id
                     SET pte.status = ?
                     WHERE pt.eid = ? AND pte.seq = (
                         SELECT MAX(seq) FROM patient_tracker_element WHERE pt_tracker_id = pt.id
                     )",
                    [$newApptStatus, $pcEid]
                );
                openwaLog("Updated calendar & tracker for pc_eid=$pcEid to status='$newApptStatus'");
            }
        }
    } else {
        openwaLog('Warning: message ID (data.id) is missing in payload.');
    }
} else {
    openwaLog("Event '$event' is not supported — ignoring.");
}

http_response_code(200);
echo json_encode(['status' => 'ok']);
