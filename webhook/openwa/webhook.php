<?php
/**
 * webhook.php (OpenWA) — Receives delivery status updates from the OpenWA gateway.
 *
 * This endpoint is called by OpenWA (wa.origen.ar) when the delivery status of
 * a message changes (e.g. message.sent, message.ack).
 * It validates the request using X-OpenWA-Signature HMAC SHA256 and updates notification_log.
 *
 * Public URL:
 *   https://hcd.origen.ar/interface/modules/custom_modules/oe-module-wsp-email/webhook/openwa/webhook.php
 *
 * @package   OpenEMR\Modules\WspEmail
 * @author    Luis A. Uriarte <luis.uriarte@gmail.com>
 * @copyright Copyright (c) 2024-2026 Luis A. Uriarte
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

// Webhook must be accessible without browser session
$ignoreAuth = true;
require_once __DIR__ . '/../../../../../globals.php';

// Load module classes manually (no autoloader in this context)
require_once __DIR__ . '/../../src/NotificationLog.php';
require_once __DIR__ . '/../../src/FacilityConfig.php';
require_once __DIR__ . '/../../src/StatusNormalizer.php';

use OpenEMR\Modules\WspEmail\NotificationLog;
use OpenEMR\Modules\WspEmail\FacilityConfig;
use OpenEMR\Modules\WspEmail\StatusNormalizer;

// Define a dedicated log file
define('OPENWA_WEBHOOK_LOG', __DIR__ . '/../../logs/openwa_webhook.log');

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

// Extract signature header
$receivedSignature = $headers['X-OpenWA-Signature'] ?? $headers['x-openwa-signature'] ?? '';

// --- Find the corresponding facility configuration & secret ---
$facilityConfig = new FacilityConfig();
$allFacilities  = $facilityConfig->getAllFacilitiesWithConfig();
$expectedSecret = '';
$matchedFacility = null;

// Try to match by sessionId/openwa_instance first
foreach ($allFacilities as $facility) {
    if (!empty($facility['openwa_instance']) && $facility['openwa_instance'] === $sessionId) {
        $expectedSecret = $facility['openwa_webhook_secret'] ?? '';
        $matchedFacility = $facility;
        break;
    }
}

// Fallback: pick the first facility with an OpenWA secret if no session match
if (empty($expectedSecret)) {
    foreach ($allFacilities as $facility) {
        if (!empty($facility['openwa_webhook_secret'])) {
            $expectedSecret = $facility['openwa_webhook_secret'];
            $matchedFacility = $facility;
            break;
        }
    }
}

// --- Validate signature if a secret is configured ---
if (!empty($expectedSecret)) {
    if (empty($receivedSignature)) {
        openwaLog('Rejected: missing X-OpenWA-Signature header.');
        http_response_code(401);
        exit;
    }

    // Strip "sha256=" prefix if present
    $cleanSignature = $receivedSignature;
    if (strpos($cleanSignature, 'sha256=') === 0) {
        $cleanSignature = substr($cleanSignature, 7);
    }

    $computedSignature = hash_hmac('sha256', $rawInput, $expectedSecret);
    if (!hash_equals($computedSignature, $cleanSignature)) {
        openwaLog("Rejected: signature mismatch. Received: '$receivedSignature'");
        http_response_code(401);
        exit;
    }
    openwaLog('Signature verified successfully.');
} else {
    openwaLog('Warning: no openwa_webhook_secret configured. Skipping signature validation.');
}

// --- Handle events ---
if ($event === 'message.sent' || $event === 'message.ack') {
    $msgId = $webhookData['data']['messageId'] ?? $webhookData['data']['id'] ?? '';
    
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
        } else {
            $rawStatus = isset($webhookData['data']['ack']) ? (string)$webhookData['data']['ack'] : 'unknown';
        }

        $notifLog = new NotificationLog();
        $notifLog->updateStatus($msgId, $rawStatus, 'openwa', $webhookData);

        $normalized = StatusNormalizer::normalize('openwa', $rawStatus);
        openwaLog("Updated: msgId=$msgId → raw=$rawStatus, canonical=$normalized");
    } else {
        openwaLog('Warning: message ID (data.id) is missing in payload.');
    }
} else {
    openwaLog("Event '$event' is not message.sent or message.ack — ignoring.");
}

http_response_code(200);
echo json_encode(['status' => 'ok']);
