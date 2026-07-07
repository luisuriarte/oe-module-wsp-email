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
define('OPENWA_WEBHOOK_LOG', $openemrRoot . '/webhook/logs/openwa_webhook.log');
function openwaLog(string $message): void
{
    @file_put_contents(OPENWA_WEBHOOK_LOG, date('Y-m-d H:i:s') . ' — ' . $message . "\n", FILE_APPEND | LOCK_EX);
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

// --- Validate token from URL ---
$receivedToken = $_GET['token'] ?? '';

if (empty($receivedToken)) {
    openwaLog('Rejected: missing token in URL.');
    http_response_code(401);
    exit;
}

// Find matching facility by sessionId
$facilityConfig  = new FacilityConfig();
$allFacilities   = $facilityConfig->getAllFacilitiesWithConfig();
$expectedSecret  = '';
$matchedFacility = null;

foreach ($allFacilities as $facility) {
    $instance = $facility['openwa_instance'] ?? $facility['instance'] ?? '';
    $secret   = $facility['openwa_webhook_secret'] ?? '';
    if (!empty($instance) && $instance === $sessionId) {
        $expectedSecret  = $secret;
        $matchedFacility = $facility;
        break;
    }
}

// Fallback: first facility with a secret
if (empty($expectedSecret)) {
    foreach ($allFacilities as $facility) {
        $secret = $facility['openwa_webhook_secret'] ?? '';
        if (!empty($secret)) {
            $expectedSecret  = $secret;
            $matchedFacility = $facility;
            break;
        }
    }
}

if (empty($expectedSecret)) {
    openwaLog('Warning: no openwa_webhook_secret configured. Skipping token validation.');
} elseif (!hash_equals($expectedSecret, $receivedToken)) {
    openwaLog("Rejected: invalid token. Received: '$receivedToken'");
    http_response_code(401);
    exit;
} else {
    openwaLog('Token validated successfully.');
}

// --- Handle events ---
$supportedEvents = ['message.sent', 'message.ack', 'message.revoked'];

if (in_array($event, $supportedEvents, true)) {
    // For message.revoked (since OpenWA v0.7.18): data.revokedId holds the original
    // deleted message ID. data.id is the revocation-notification ID (a different message),
    // which never matches the stored row — so we must check revokedId first.
    // For all other events, fall back to the standard messageId / id fields.
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
        } elseif ($event === 'message.revoked') {
            $rawStatus = 'revoked';
        } elseif (isset($webhookData['data']['status'])) {
            $rawStatus = (string)$webhookData['data']['status'];
        } elseif (isset($webhookData['data']['ack'])) {
            $rawStatus = (string)$webhookData['data']['ack'];
        } else {
            $rawStatus = 'unknown';
        }

        // Idempotency: skip if same or higher priority status already recorded
        $idempotencyKey = $headers['X-Openwa-Idempotency-Key'] ?? '';
        $notifLog = new NotificationLog();

        $incomingPriority = StatusNormalizer::getPriority(
            StatusNormalizer::normalize('openwa', $rawStatus)
        );

        $existing = sqlQuery(
            "SELECT iLogId, status_priority, status_current FROM notification_log WHERE msg_id = ?",
            [$msgId]
        );

        // Auto-create minimal record if msg_id doesn't exist in notification_log
        // (e.g. message was sent manually from wa.origen.ar, not via module cron)
        if (!$existing || empty($existing['iLogId'])) {
            $chatId  = $webhookData['data']['chatId'] ?? $webhookData['data']['from'] ?? $webhookData['data']['to'] ?? '';
            $phone   = preg_replace('/\D/', '', explode('@', $chatId)[0] ?? '');
            $canonical = StatusNormalizer::normalize('openwa', $rawStatus);
            $priority  = StatusNormalizer::getPriority($canonical);

            sqlStatement(
                "INSERT INTO notification_log
                    (msg_id, type, sms_gateway_type, status, status_current, status_priority,
                     provider_raw_status, provider_payload, patient_info, smsgateway_info,
                     notification_seq, dSentDateTime)
                 VALUES (?, 'WSP', 'openwa', ?, ?, ?, ?, ?, ?, ?, 0, NOW())",
                [
                    $msgId,
                    $rawStatus,
                    $canonical,
                    $priority,
                    $rawStatus,
                    $webhookData ? json_encode($webhookData, JSON_UNESCAPED_UNICODE) : null,
                    $phone ? "Webhook auto-creado |||{$phone}" : 'Webhook auto-creado',
                    $sessionId,
                ]
            );
            $newId = (int)sqlGetLastInsertId();
            if ($newId > 0) {
                $notifLog->addStatusHistory($newId, $canonical, $rawStatus, 'openwa', $webhookData);
                openwaLog("Auto-created notification_log entry: iLogId=$newId, msgId=$msgId, phone=$phone, canonical=$canonical");
            }
            // Re-fetch so the priority check below works
            $existing = sqlQuery(
                "SELECT iLogId, status_priority, status_current FROM notification_log WHERE msg_id = ?",
                [$msgId]
            );
        }

        if ($existing && isset($existing['status_priority'])) {
            $existingPriority = (int)$existing['status_priority'];
            // Skip if existing status is same or higher priority (duplicate/reordered event)
            if ($existingPriority >= $incomingPriority && $existingPriority > 0) {
                openwaLog("Skipped: msgId=$msgId already has priority $existingPriority >= $incomingPriority" .
                    ($idempotencyKey ? " (key=$idempotencyKey)" : ''));
                http_response_code(200);
                echo json_encode(['status' => 'ok', 'skipped' => 'duplicate']);
                exit;
            }
        }

        $notifLog->updateStatus($msgId, $rawStatus, 'openwa', $webhookData);

        $normalized = StatusNormalizer::normalize('openwa', $rawStatus);
        openwaLog("Updated: msgId=$msgId → raw=$rawStatus, canonical=$normalized" .
            ($idempotencyKey ? " (key=$idempotencyKey)" : ''));
    } else {
        openwaLog('Warning: message ID (data.id) is missing in payload.');
    }
} else {
    openwaLog("Event '$event' is not supported — ignoring.");
}

http_response_code(200);
echo json_encode(['status' => 'ok']);
