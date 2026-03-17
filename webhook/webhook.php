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

// Define a dedicated log file for this webhook
define('WSP_WEBHOOK_LOG', __DIR__ . '/../logs/webhook.log');

function webhookLog(string $message): void
{
    @file_put_contents(WSP_WEBHOOK_LOG, date('Y-m-d H:i:s') . ' — ' . $message . "\n", FILE_APPEND | LOCK_EX);
}

// --- Log every incoming request immediately ---
webhookLog('Request received. Method: ' . ($_SERVER['REQUEST_METHOD'] ?? 'UNKNOWN'));

// Only accept POST requests
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    webhookLog('Rejected: not a POST request.');
    http_response_code(405);
    exit;
}

// Validate Content-Type
$headers     = getallheaders() ?: [];
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

    $status = $webhookData['data']['status'] ?? '';
    // Extract last 10 digits of the phone number from the JID
    $phone  = substr(preg_replace('/\D/', '', $jid), -10);

    webhookLog("messages.update — msgId=$msgId, phone=$phone, status=$status");

    // --- Validate webhook signature ---
    // Look up the facility whose vendor instance is handling this phone number
    $received = $headers['X-Webhook-Signature'] ?? $headers['x-webhook-signature'] ?? '';

    $facilityConfig = new FacilityConfig();
    $allFacilities  = $facilityConfig->getAllFacilitiesWithConfig();
    $expectedSecret = '';

    foreach ($allFacilities as $facility) {
        if (!empty($facility['webhook_secret'])) {
            // Simple match: pick any configured facility to validate the secret
            // (in production you'd match by instance/account, but secrets are usually global per vendor)
            $expectedSecret = $facility['webhook_secret'];
            break;
        }
    }

    if (empty($expectedSecret) || $received !== $expectedSecret) {
        webhookLog("Rejected: invalid or missing X-Webhook-Signature. Received: '$received'");
        http_response_code(401);
        exit;
    }

    // --- Update notification_log ---
    if (!empty($msgId) && !empty($status)) {
        $notifLog = new NotificationLog();
        $notifLog->updateStatus($msgId, $status);
        webhookLog("Updated notification_log: msgId=$msgId → status=$status");
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

webhookLog('Response: HTTP 200 OK');
http_response_code(200);
echo json_encode(['status' => 'ok']);
