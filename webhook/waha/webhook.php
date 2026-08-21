<?php
/**
 * webhook.php (WAHA) — Receives delivery status updates from the WAHA gateway.
 *
 * This endpoint is called by WAHA (WhatsApp HTTP API - https://waha.devlike.pro)
 * when the delivery status of a message changes (e.g. message.ack, message.status,
 * message.sent, message.failed, message.revoked).
 *
 * Public URLs:
 *   Development: /webhook/waha/webhook.php
 *   Production:  https://hcd.origen.ar/webhook/waha/webhook.php
 *
 * @package   OpenEMR\Modules\WspEmail
 * @author    Luis A. Uriarte <luis.uriarte@gmail.com>
 * @copyright Copyright (c) 2024-2026 Luis A. Uriarte
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

// Webhook must be accessible without browser session
$ignoreAuth = true;

// 1. Dynamically locate OpenEMR interface/globals.php ascending the directory tree
$dir = __DIR__;
$globalsPath = null;
while ($dir && $dir !== '/' && $dir !== '\\' && strlen($dir) > 4) {
    if (file_exists($dir . '/interface/globals.php')) {
        $globalsPath = $dir . '/interface/globals.php';
        break;
    }
    if (file_exists($dir . '/globals.php')) {
        $globalsPath = $dir . '/globals.php';
        break;
    }
    $parent = dirname($dir);
    if ($parent === $dir) {
        break;
    }
    $dir = $parent;
}

if (!$globalsPath) {
    http_response_code(500);
    echo json_encode(['error' => 'OpenEMR globals.php not found']);
    exit;
}

require_once $globalsPath;

// 2. Locate module root
$openemrRoot = dirname(dirname($globalsPath));
if (!file_exists($openemrRoot . '/interface/globals.php') && file_exists(dirname($globalsPath) . '/interface/globals.php')) {
    $openemrRoot = dirname($globalsPath);
}

$moduleRoot = $openemrRoot . '/interface/modules/custom_modules/oe-module-wsp-email';
if (!file_exists($moduleRoot . '/src/NotificationLog.php')) {
    $moduleRoot = realpath(__DIR__ . '/../../');
}

require_once $moduleRoot . '/src/NotificationLog.php';
require_once $moduleRoot . '/src/FacilityConfig.php';
require_once $moduleRoot . '/src/StatusNormalizer.php';

use OpenEMR\Modules\WspEmail\NotificationLog;
use OpenEMR\Modules\WspEmail\FacilityConfig;
use OpenEMR\Modules\WspEmail\StatusNormalizer;

// Define a dedicated log file writing to module logs, local logs, and parent logs
function wahaLog(string $message): void
{
    global $moduleRoot;
    $line = date('Y-m-d H:i:s') . ' — ' . $message . "\n";

    // 1. Module main logs folder
    if (!empty($moduleRoot)) {
        $modLogDir = $moduleRoot . '/logs';
        if (!is_dir($modLogDir)) {
            @mkdir($modLogDir, 0777, true);
        }
        @file_put_contents($modLogDir . '/waha_webhook.log', $line, FILE_APPEND | LOCK_EX);
    }

    // 2. Webhook local logs folder (__DIR__/logs/)
    $localLogDir = __DIR__ . '/logs';
    if (!is_dir($localLogDir)) {
        @mkdir($localLogDir, 0777, true);
    }
    @file_put_contents($localLogDir . '/waha_webhook.log', $line, FILE_APPEND | LOCK_EX);

    // 3. Webhook parent logs folder (__DIR__/../logs/)
    $whLogDir = __DIR__ . '/../logs';
    if (!is_dir($whLogDir)) {
        @mkdir($whLogDir, 0777, true);
    }
    @file_put_contents($whLogDir . '/waha_webhook.log', $line, FILE_APPEND | LOCK_EX);
}

// Log incoming request
wahaLog('Request received. Method: ' . ($_SERVER['REQUEST_METHOD'] ?? 'UNKNOWN'));

// Only accept POST requests
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    wahaLog('Rejected: not a POST request.');
    http_response_code(405);
    exit;
}

$headers = getallheaders() ?: [];
wahaLog('Headers: ' . json_encode($headers));

// Validate Content-Type
$contentType = $headers['Content-Type'] ?? $headers['content-type'] ?? '';
if (stripos($contentType, 'application/json') === false) {
    wahaLog("Rejected: invalid Content-Type ($contentType).");
    http_response_code(400);
    exit;
}

// Read raw body
$rawInput = file_get_contents('php://input');
wahaLog('Raw Body: ' . $rawInput);

$webhookData = json_decode($rawInput, true);
if (!is_array($webhookData)) {
    wahaLog('Rejected: invalid JSON payload.');
    http_response_code(400);
    exit;
}

$event   = $webhookData['event']   ?? '';
$session = $webhookData['session'] ?? $webhookData['sessionId'] ?? $webhookData['instance'] ?? '';
wahaLog("Event: $event, Session: $session");

// Test/ping event
if ($event === 'test' || $event === 'ping') {
    wahaLog('Test/ping event acknowledged.');
    http_response_code(200);
    echo json_encode(['status' => 'ok', 'message' => 'WAHA Webhook is reachable']);
    exit;
}

// --- Validate authentication / token ---
$receivedApiKey = $headers['X-Api-Key']
               ?? $headers['x-api-key']
               ?? $headers['X-API-KEY']
               ?? $headers['X-Webhook-Secret']
               ?? $headers['x-webhook-secret']
               ?? $headers['X-Waha-Signature']
               ?? '';

if (empty($receivedApiKey) && !empty($headers['Authorization'])) {
    $authHeader = $headers['Authorization'];
    if (stripos($authHeader, 'Bearer ') === 0) {
        $receivedApiKey = substr($authHeader, 7);
    }
}

$receivedToken = $_GET['token'] ?? '';

// Match facility by session or credentials
$facilityConfig  = new FacilityConfig();
$allFacilities   = $facilityConfig->getAllFacilitiesWithConfig();
$expectedSecret  = '';
$matchedFacility = null;

foreach ($allFacilities as $facility) {
    $wSession = $facility['waha_session'] ?? $facility['waha_instance'] ?? '';
    $wSecret  = $facility['waha_webhook_secret'] ?? $facility['waha_api_key'] ?? '';

    if (!empty($wSession) && $wSession === $session) {
        $expectedSecret  = $wSecret;
        $matchedFacility = $facility;
        break;
    }
    if (!empty($wSecret) && ($wSecret === $receivedToken || $wSecret === $receivedApiKey)) {
        $expectedSecret  = $wSecret;
        $matchedFacility = $facility;
        break;
    }
}

$isAuthorized = false;

// 1. Authorize if matched to a facility by session/instance
if ($matchedFacility !== null) {
    $isAuthorized = true;
}

// 2. Authorize if token or API key matches expected secret
if (!empty($expectedSecret) && ($receivedToken === $expectedSecret || $receivedApiKey === $expectedSecret)) {
    $isAuthorized = true;
}

// 3. Check HMAC signature if provided in header
$receivedSig = $headers['X-Hub-Signature-256'] ?? $headers['X-Waha-Signature'] ?? '';
if (!$isAuthorized && !empty($receivedSig) && !empty($expectedSecret)) {
    $expectedSig = 'sha256=' . hash_hmac('sha256', $rawInput, $expectedSecret);
    if (hash_equals($expectedSig, $receivedSig)) {
        $isAuthorized = true;
    }
}

// 4. If no facility configured secret, allow requests to prevent setup lockouts
if (!$isAuthorized && empty($expectedSecret)) {
    $hasAnySecret = false;
    foreach ($allFacilities as $f) {
        if (!empty($f['waha_webhook_secret']) || !empty($f['waha_api_key'])) {
            $hasAnySecret = true;
            break;
        }
    }
    if (!$hasAnySecret) {
        $isAuthorized = true;
        wahaLog('Warning: no WAHA secrets configured in facilities. Allowing request.');
    }
}

if (!$isAuthorized) {
    wahaLog("Rejected: unauthorized webhook request for session='$session', token='$receivedToken'.");
    http_response_code(401);
    exit;
}

wahaLog('Webhook request authorized successfully.');

// --- Handle events ---
$supportedEvents = ['message.ack', 'message.status', 'message.sent', 'message.failed', 'message.revoked', 'message'];

if (in_array($event, $supportedEvents, true)) {
    $payload = $webhookData['payload'] ?? $webhookData['data'] ?? [];

    $msgId = '';
    if ($event === 'message.revoked') {
        $msgId = $payload['revokedId'] ?? $payload['id'] ?? $payload['messageId'] ?? '';
    } else {
        $msgId = $payload['id'] ?? $payload['messageId'] ?? ($payload['key']['id'] ?? '');
    }

    // Ignore group messages or channel newsletters
    $chatId = $payload['chatId'] ?? $payload['to'] ?? $payload['from'] ?? ($payload['key']['remoteJid'] ?? '');
    if (strpos($chatId, '@g.us') !== false || strpos($chatId, '@newsletter') !== false) {
        wahaLog("Ignored: group or channel message ($chatId).");
        http_response_code(200);
        echo json_encode(['status' => 'ok', 'skipped' => 'group_or_newsletter']);
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
            $rawStatus = (string)($payload['ackName'] ?? (isset($payload['ack']) ? $payload['ack'] : ($payload['status'] ?? 'ack')));
        } elseif ($event === 'message.status') {
            $rawStatus = (string)($payload['status'] ?? $payload['ackName'] ?? 'status');
        } else {
            $rawStatus = (string)($payload['status'] ?? $payload['ackName'] ?? (isset($payload['ack']) ? $payload['ack'] : 'unknown'));
        }

        $notifLog = new NotificationLog();

        $canonical = StatusNormalizer::normalize('waha', $rawStatus);
        $incomingPriority = StatusNormalizer::getPriority($canonical);

        $existing = sqlQuery(
            "SELECT iLogId, pc_eid, pid, msg_id, status_priority, status_current FROM notification_log WHERE msg_id = ?",
            [$msgId]
        );

        // Fallback search 1: if full serialized msgId (e.g. false_5493404540440@c.us_3EB0...) is received, try matching suffix
        if ((!$existing || empty($existing['iLogId'])) && strpos($msgId, '_') !== false) {
            $parts = explode('_', $msgId);
            $shortId = end($parts);
            if (!empty($shortId) && strlen($shortId) >= 10) {
                $matchedLog = sqlQuery(
                    "SELECT iLogId, pc_eid, pid, msg_id, status_priority, status_current FROM notification_log WHERE msg_id = ? OR msg_id LIKE ? LIMIT 1",
                    [$shortId, '%' . $shortId]
                );
                if ($matchedLog && !empty($matchedLog['iLogId'])) {
                    $existing = $matchedLog;
                    wahaLog("Matched short msgId='$shortId' for incoming msgId='$msgId' -> iLogId={$existing['iLogId']}");
                }
            }
        }

        // Fallback search 2: match recent log entry sent to the same phone number in the last 15 minutes
        if (!$existing || empty($existing['iLogId'])) {
            $phone = preg_replace('/\D/', '', explode('@', $chatId)[0] ?? '');
            if (!empty($phone) && strlen($phone) >= 8) {
                $shortPhone = substr($phone, -10);
                $recentLog = sqlQuery(
                    "SELECT iLogId, pc_eid, pid, msg_id, status_priority, status_current
                     FROM notification_log
                     WHERE type = 'WSP'
                       AND (patient_info LIKE ? OR smsgateway_info LIKE ?)
                       AND dSentDateTime >= NOW() - INTERVAL 15 MINUTE
                     ORDER BY iLogId DESC LIMIT 1",
                    ["%{$shortPhone}%", "%{$shortPhone}%"]
                );
                if ($recentLog && !empty($recentLog['iLogId'])) {
                    $existing = $recentLog;
                    wahaLog("Matched recent log entry by shortPhone={$shortPhone} -> iLogId={$existing['iLogId']}");
                }
            }
        }

        // If msgId is completely unrecognized, skip auto-creating PID:0 records
        if (!$existing || empty($existing['iLogId'])) {
            wahaLog("Unrecognized msgId='$msgId' — skipping auto-creation of PID:0 entry.");
            http_response_code(200);
            echo json_encode(['status' => 'ok', 'skipped' => 'unrecognized_msg_id']);
            exit;
        }

        if ($existing && isset($existing['status_priority'])) {
            $existingPriority = (int)$existing['status_priority'];
            // Skip if existing status has higher priority
            if ($existingPriority > $incomingPriority && $existingPriority > 0) {
                wahaLog("Skipped: msgId=$msgId already has priority $existingPriority > $incomingPriority");
                http_response_code(200);
                echo json_encode(['status' => 'ok', 'skipped' => 'lower_priority']);
                exit;
            }
        }

        $targetMsgId = !empty($existing['msg_id']) ? $existing['msg_id'] : $msgId;

        // Reconcile message ID if needed
        if (!empty($msgId) && $msgId !== $targetMsgId && !empty($existing['iLogId'])) {
            sqlStatement(
                "UPDATE notification_log SET msg_id = ? WHERE iLogId = ?",
                [$msgId, $existing['iLogId']]
            );
            wahaLog("Reconciled msg_id: iLogId={$existing['iLogId']} old='$targetMsgId' -> new='$msgId'");
            $targetMsgId = $msgId;
        }

        $notifLog->updateStatus($targetMsgId, $rawStatus, 'waha', $webhookData);
        wahaLog("Updated: targetMsgId=$targetMsgId (rawId=$msgId) -> raw=$rawStatus, canonical=$canonical");

        // Update calendar event & patient tracker status if DELIVERED or READ
        if ($existing && !empty($existing['pc_eid']) && !empty($existing['pid'])) {
            $pcEid = (int)$existing['pc_eid'];
            $pid   = (int)$existing['pid'];
            $newApptStatus = null;
            if ($canonical === 'READ') {
                $newApptStatus = 'wsp-read';
            } elseif ($canonical === 'DELIVERED') {
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
                wahaLog("Updated calendar & tracker for pc_eid=$pcEid to status='$newApptStatus'");
            }
        }
    } else {
        wahaLog('Warning: message ID is missing in payload.');
    }
} else {
    wahaLog("Event '$event' is not supported — ignoring.");
}

http_response_code(200);
echo json_encode(['status' => 'ok']);
