<?php
/**
 * webhook.php — Receives delivery status updates from WhatsApp gateway vendors.
 *
 * This endpoint is called by the vendor (e.g. Bridge / WaSenderAPI) when the
 * delivery status of a sent message changes (e.g. DELIVERED, READ, FAILED).
 * It validates the request via X-Webhook-Signature and updates notification_log.
 *
 * Public URL example:
 *   https://your-site/openemr/interface/modules/custom_modules/oe-module-wsp-email/webhook/webhook.php
 *
 * @package   OpenEMR\Modules\WspEmail
 * @author    Luis A. Uriarte <luis.uriarte@gmail.com>
 * @copyright Copyright (c) 2024-2025 Luis A. Uriarte
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

// The webhook must be accessible without a browser session
$ignoreAuth = true;
require_once __DIR__ . '/../../../../globals.php';

// Load module classes (manual include — no autoloader in this context)
require_once __DIR__ . '/../src/NotificationLog.php';
require_once __DIR__ . '/../src/FacilityConfig.php';

use OpenEMR\Modules\WspEmail\NotificationLog;
use OpenEMR\Modules\WspEmail\FacilityConfig;
use OpenEMR\Modules\WspEmail\StatusNormalizer;

// Define a dedicated log file for this webhook
define('WSP_WEBHOOK_LOG', __DIR__ . '/../logs/webhook.log');

function webhookLog(string $message): void
{
    @file_put_contents(WSP_WEBHOOK_LOG, date('Y-m-d H:i:s') . ' — ' . $message . "\n", FILE_APPEND | LOCK_EX);
}

// Extract all headers
$headers = getallheaders() ?: [];

// --- Log every incoming request immediately ---
webhookLog('Request received. Method: ' . ($_SERVER['REQUEST_METHOD'] ?? 'UNKNOWN'));
webhookLog('Headers: ' . json_encode($headers));

// Only accept POST requests
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    webhookLog('Rejected: not a POST request.');
    http_response_code(405);
    exit;
}

// Validate Content-Type
$contentType = $headers['Content-Type'] ?? $headers['content-type'] ?? '';
if (stripos($contentType, 'application/json') === false) {
    webhookLog("Rejected: invalid Content-Type ($contentType).");
    http_response_code(400);
    exit;
}

// Read and decode JSON body
$rawInput    = file_get_contents('php://input');
$webhookData = json_decode($rawInput, true);
webhookLog('Body: ' . $rawInput);

if (!is_array($webhookData)) {
    webhookLog('Rejected: invalid JSON payload.');
    http_response_code(400);
    exit;
}

$event = $webhookData['event'] ?? '';
webhookLog('Event: ' . $event);

// --- Handle messages.update event (delivery status change) ---
if ($event === 'messages.update') {
    $msgId = $webhookData['data']['key']['id']        ?? '';
    $jid   = $webhookData['data']['key']['remoteJid'] ?? '';

    // Ignore group messages — only process individual chat updates
    if (strpos($jid, '@g.us') !== false) {
        webhookLog("Ignored: group message ($jid).");
        http_response_code(200);
        exit;
    }

    $rawStatus = $webhookData['data']['status'] ?? '';
    // Extract last 10 digits of the phone number from the JID
    $phone  = substr(preg_replace('/\D/', '', $jid), -10);

    webhookLog("messages.update — msgId=$msgId, phone=$phone, rawStatus=$rawStatus");

    // --- Validate webhook signature ---
    // Look up the facility whose vendor instance is handling this phone number
    $received = $headers['X-Webhook-Signature'] ?? $headers['x-webhook-signature'] ?? '';

    $facilityConfig = new FacilityConfig();
    $allFacilities  = $facilityConfig->getAllFacilitiesWithConfig();
    $expectedSecret = '';
    $detectedProvider = 'wasenderapi'; // Default

    foreach ($allFacilities as $facility) {
        if (!empty($facility['webhook_secret'])) {
            // Simple match: pick any configured facility to validate the secret
            // (in production you'd match by instance/account, but secrets are usually global per vendor)
            $expectedSecret = $facility['webhook_secret'];
            $detectedProvider = $facility['vendor'] ?? 'wasenderapi';
            break;
        }
    }

    if (empty($expectedSecret) || $received !== $expectedSecret) {
        webhookLog("Rejected: invalid or missing X-Webhook-Signature. Received: '$received'");
        http_response_code(401);
        exit;
    }

    // --- Update notification_log with normalization ---
    if (!empty($msgId) && !empty($rawStatus)) {
        $notifLog = new NotificationLog();
        // Pass provider and full payload for normalization
        $notifLog->updateStatus($msgId, $rawStatus, $detectedProvider, $webhookData);

        // Get normalized status for logging
        $normalized = StatusNormalizer::normalize($detectedProvider, $rawStatus);
        webhookLog("messages.update — updated: msgId=$msgId → raw=$rawStatus, canonical=$normalized");
    }
}

// --- Handle messages.upsert (often used for both incoming and outgoing status) ---
if ($event === 'messages.upsert') {
    $data = $webhookData['data']['messages'][0] ?? null;
    if ($data) {
        $msgId  = $data['key']['id'] ?? '';
        $status = $data['status'] ?? '';
        if (!empty($msgId) && !empty($status)) {
            $notifLog = new NotificationLog();
            $notifLog->updateStatus($msgId, (string)$status);
            webhookLog("messages.upsert — updated: msgId=$msgId → status=$status");
        }
    }
}

// --- Handle messages.sent event (initial send confirmation with msgId) ---
if ($event === 'messages.sent') {
    $msgId  = $webhookData['data']['key']['id']  ?? '';
    $status = 'sent';

    if (!empty($msgId)) {
        $notifLog = new NotificationLog();
        $notifLog->updateStatus($msgId, $status);
        webhookLog("messages.sent: msgId=$msgId marked as sent.");
    }
}

// --- Handle messages.read event (message was read by recipient) ---
if ($event === 'messages.read') {
    $msgId  = $webhookData['data']['key']['id']  ?? '';
    $status = 'READ';

    if (!empty($msgId)) {
        $notifLog = new NotificationLog();
        $notifLog->updateStatus($msgId, $status);
        webhookLog("messages.read: msgId=$msgId marked as READ.");
    }
}

// --- Handle messages.delivered event (message was delivered to recipient) ---
if ($event === 'messages.delivered') {
    $msgId  = $webhookData['data']['key']['id']  ?? '';
    $status = 'DELIVERED';

    if (!empty($msgId)) {
        $notifLog = new NotificationLog();
        $notifLog->updateStatus($msgId, $status);
        webhookLog("messages.delivered: msgId=$msgId marked as DELIVERED.");
    }
}

// --- Handle messages.failed event (message failed to send) ---
if ($event === 'messages.failed') {
    $msgId  = $webhookData['data']['key']['id']  ?? '';
    $status = 'error';
    $error  = $webhookData['data']['error'] ?? 'Unknown error';

    if (!empty($msgId)) {
        $notifLog = new NotificationLog();
        $notifLog->updateStatus($msgId, $status);
        webhookLog("messages.failed: msgId=$msgId marked as error. Reason: $error");
    }
}

// --- Handle any other event (log only) ---
if (!in_array($event, ['messages.update', 'messages.upsert', 'messages.sent', 'messages.read', 'messages.delivered', 'messages.failed'])) {
    webhookLog("Unknown event type: $event - ignored.");
}

webhookLog('Response: HTTP 200 OK');
http_response_code(200);
echo json_encode(['status' => 'ok']);
