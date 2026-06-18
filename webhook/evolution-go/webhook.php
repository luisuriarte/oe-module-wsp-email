<?php
/**
 * webhook.php (Evolution-Go) — Receives delivery status updates from Evolution-Go gateway.
 *
 * Evolution-Go webhook events: messages.upsert, messages.update, connection.update
 * Authentication: apikey header (X-API-Key) or token query param
 *
 * Public URL:
 *   https://your-site/webhook/evolution-go/webhook.php
 *
 * @package   OpenEMR\Modules\WspEmail
 */

declare(strict_types=1);

$ignoreAuth = true;

$openemrRoot = realpath(__DIR__ . '/../../');
$moduleRoot  = $openemrRoot . '/interface/modules/custom_modules/oe-module-wsp-email';

require_once $openemrRoot . '/interface/globals.php';

require_once $moduleRoot . '/src/NotificationLog.php';
require_once $moduleRoot . '/src/FacilityConfig.php';
require_once $moduleRoot . '/src/StatusNormalizer.php';

use OpenEMR\Modules\WspEmail\NotificationLog;
use OpenEMR\Modules\WspEmail\FacilityConfig;
use OpenEMR\Modules\WspEmail\StatusNormalizer;

define('EVO_WEBHOOK_LOG', $openemrRoot . '/webhook/logs/evolution_go_webhook.log');

function evoLog(string $message): void
{
    @file_put_contents(EVO_WEBHOOK_LOG, date('Y-m-d H:i:s') . ' — ' . $message . "\n", FILE_APPEND | LOCK_EX);
}

evoLog('Request received. Method: ' . ($_SERVER['REQUEST_METHOD'] ?? 'UNKNOWN'));

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    evoLog('Rejected: not a POST request.');
    http_response_code(405);
    exit;
}

$headers = getallheaders() ?: [];
evoLog('Headers: ' . json_encode($headers));

$contentType = $headers['Content-Type'] ?? $headers['content-type'] ?? '';
if (stripos($contentType, 'application/json') === false) {
    evoLog("Rejected: invalid Content-Type ($contentType).");
    http_response_code(400);
    exit;
}

$rawInput    = file_get_contents('php://input');
$webhookData = json_decode($rawInput, true);
evoLog('Body: ' . $rawInput);

if (!is_array($webhookData)) {
    evoLog('Rejected: invalid JSON payload.');
    http_response_code(400);
    exit;
}

$event    = $webhookData['event'] ?? '';
$instance = $webhookData['instance'] ?? '';
evoLog("Event: $event, Instance: $instance");

// --- Authentication: validate via apikey header or token query param ---
$receivedApiKey = $headers['X-API-Key'] ?? $headers['x-api-key'] ?? $headers['apikey'] ?? '';
$receivedToken  = $_GET['token'] ?? '';

$facilityConfig  = new FacilityConfig();
$allFacilities   = $facilityConfig->getAllFacilitiesWithConfig();
$expectedSecret  = '';
$matchedFacility = null;

// Try to match by instance name first
foreach ($allFacilities as $facility) {
    $evoInstance = $facility['evolution_go_instance_name'] ?? '';
    if (!empty($evoInstance) && $evoInstance === $instance) {
        $expectedSecret  = $facility['evolution_go_webhook_secret'] ?? '';
        $matchedFacility = $facility;
        break;
    }
}

// Fallback: first facility with evolution-go secret configured
if (empty($expectedSecret)) {
    foreach ($allFacilities as $facility) {
        $secret = $facility['evolution_go_webhook_secret'] ?? '';
        if (!empty($secret)) {
            $expectedSecret  = $secret;
            $matchedFacility = $facility;
            break;
        }
    }
}

// Validate authentication
$authenticated = false;
if (!empty($expectedSecret)) {
    if (!empty($receivedApiKey) && hash_equals($expectedSecret, $receivedApiKey)) {
        $authenticated = true;
        evoLog('Authenticated via X-API-Key header.');
    } elseif (!empty($receivedToken) && hash_equals($expectedSecret, $receivedToken)) {
        $authenticated = true;
        evoLog('Authenticated via token query param.');
    }
}

if (!$authenticated && !empty($expectedSecret)) {
    evoLog('Rejected: invalid or missing authentication.');
    http_response_code(401);
    exit;
}

if (empty($expectedSecret)) {
    evoLog('Warning: no evolution-go webhook secret configured. Proceeding without validation.');
}

// --- Handle events ---
// messages.upsert — a message was created or updated (includes sent/delivered/read status)
if ($event === 'messages.upsert') {
    $messages = $webhookData['data']['messages'] ?? [$webhookData['data'] ?? []];
    if (isset($messages[0])) {
        $data   = $messages[0];
        $msgId  = $data['key']['id'] ?? '';
        $jid    = $data['key']['remoteJid'] ?? '';

        // Ignore group messages
        if (strpos($jid, '@g.us') !== false) {
            evoLog("Ignored: group message ($jid).");
            http_response_code(200);
            exit;
        }

        $rawStatus = $data['status'] ?? '';
        $phone     = substr(preg_replace('/\D/', '', $jid), -10);

        evoLog("messages.upsert — msgId=$msgId, phone=$phone, rawStatus=$rawStatus");

        if (!empty($msgId) && !empty($rawStatus)) {
            $notifLog = new NotificationLog();
            $notifLog->updateStatus($msgId, $rawStatus, 'evolution-go', $webhookData);
            $normalized = StatusNormalizer::normalize('evolution-go', $rawStatus);
            evoLog("Updated: msgId=$msgId -> raw=$rawStatus, canonical=$normalized");
        }
    }
}

// messages.update — status change for an existing message
if ($event === 'messages.update') {
    $msgId     = $webhookData['data']['key']['id'] ?? '';
    $jid       = $webhookData['data']['key']['remoteJid'] ?? '';

    if (strpos($jid, '@g.us') !== false) {
        evoLog("Ignored: group message ($jid).");
        http_response_code(200);
        exit;
    }

    $rawStatus = $webhookData['data']['status'] ?? '';
    $phone     = substr(preg_replace('/\D/', '', $jid), -10);

    evoLog("messages.update — msgId=$msgId, phone=$phone, rawStatus=$rawStatus");

    if (!empty($msgId) && !empty($rawStatus)) {
        $notifLog = new NotificationLog();
        $notifLog->updateStatus($msgId, $rawStatus, 'evolution-go', $webhookData);
        $normalized = StatusNormalizer::normalize('evolution-go', $rawStatus);
        evoLog("Updated: msgId=$msgId -> raw=$rawStatus, canonical=$normalized");
    }
}

// connection.update — instance connection status (log only)
if ($event === 'connection.update') {
    $state = $webhookData['data']['state'] ?? 'unknown';
    evoLog("connection.update: instance=$instance, state=$state");
}

evoLog('Response: HTTP 200 OK');
http_response_code(200);
echo json_encode(['status' => 'ok']);
