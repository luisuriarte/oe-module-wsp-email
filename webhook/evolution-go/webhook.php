<?php
/**
 * webhook.php (Evolution-Go) — Receives delivery status updates from Evolution-Go gateway.
 *
 * This endpoint is called by Evolution-Go (go.origen.ar) when message status changes:
 *   - messages.update   → ack transitions (SENT / DELIVERED / READ / PLAYED)
 *   - messages.upsert   → message created (outgoing initial status)
 *   - connection.update → instance connection state (log only)
 *
 * Handles multiple Evolution API payload generations:
 *   v2 flattened : data.keyId + data.status
 *   nested       : data.key.id + data.status
 *   v1 batched   : data[].key.id + data[].update.status
 *
 * Public URLs:
 *   Development: /webhook/evolution-go/webhook.php
 *   Production:  https://hcd.origen.ar/webhook/evolution-go/webhook.php
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

// 2. Locate module root (supports both /webhook/evolution-go/ and custom_modules execution paths)
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

// Dedicated log writer: module logs, local logs, and parent logs
function evoLog(string $message): void
{
    global $moduleRoot;
    $line = date('Y-m-d H:i:s') . ' — ' . $message . "\n";
    error_log("EVOGO_WEBHOOK: " . $message);

    // 1. Module main logs folder
    if (!empty($moduleRoot)) {
        $modLogDir = $moduleRoot . '/logs';
        if (!is_dir($modLogDir)) {
            @mkdir($modLogDir, 0777, true);
        }
        @file_put_contents($modLogDir . '/evolution_go_webhook.log', $line, FILE_APPEND | LOCK_EX);
    }

    // 2. Webhook local logs folder (__DIR__/logs/)
    $localLogDir = __DIR__ . '/logs';
    if (!is_dir($localLogDir)) {
        @mkdir($localLogDir, 0777, true);
    }
    @file_put_contents($localLogDir . '/evolution_go_webhook.log', $line, FILE_APPEND | LOCK_EX);

    // 3. Webhook parent logs folder (__DIR__/../logs/)
    $whLogDir = __DIR__ . '/../logs';
    if (!is_dir($whLogDir)) {
        @mkdir($whLogDir, 0777, true);
    }
    @file_put_contents($whLogDir . '/evolution_go_webhook.log', $line, FILE_APPEND | LOCK_EX);
}

// Log incoming request
evoLog('Request received. Method: ' . ($_SERVER['REQUEST_METHOD'] ?? 'UNKNOWN'));

// Only accept POST requests
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    evoLog('Rejected: not a POST request.');
    http_response_code(405);
    exit;
}

$headers = getallheaders() ?: [];
evoLog('Headers: ' . json_encode($headers));

// Validate Content-Type
$contentType = $headers['Content-Type'] ?? $headers['content-type'] ?? '';
if (stripos($contentType, 'application/json') === false) {
    evoLog("Rejected: invalid Content-Type ($contentType).");
    http_response_code(400);
    exit;
}

$rawInput    = file_get_contents('php://input');
evoLog('Raw Body: ' . $rawInput);

$webhookData = json_decode($rawInput, true);
if (!is_array($webhookData)) {
    evoLog('Rejected: invalid JSON payload.');
    http_response_code(400);
    exit;
}

$event    = $webhookData['event']   ?? '';
$instance = $webhookData['instance'] ?? '';
evoLog("Event: $event, Instance: $instance");

// Connection state changes — log only
if ($event === 'connection.update') {
    $state = $webhookData['data']['state'] ?? 'unknown';
    evoLog("Connection state: instance=$instance, state=$state");
    http_response_code(200);
    echo json_encode(['status' => 'ok']);
    exit;
}

$supportedEvents = ['messages.update', 'messages.upsert'];
if (!in_array($event, $supportedEvents, true)) {
    evoLog("Event '$event' is not supported — ignoring.");
    http_response_code(200);
    echo json_encode(['status' => 'ok']);
    exit;
}

// --- Authentication: apikey header or token query param ---
$receivedApiKey = $headers['X-API-Key']
               ?? $headers['x-api-key']
               ?? $headers['apikey']
               ?? $headers['Api-Key']
               ?? '';
$receivedToken = $_GET['token'] ?? '';

$facilityConfig  = new FacilityConfig();
$allFacilities   = $facilityConfig->getAllFacilitiesWithConfig();
$expectedSecret  = '';
$matchedFacility = null;

// Match facility by instance name first
foreach ($allFacilities as $facility) {
    $evoInstance = $facility['evolution_go_instance_name'] ?? '';
    if (!empty($evoInstance) && $evoInstance === $instance) {
        $expectedSecret  = $facility['evolution_go_webhook_secret'] ?? '';
        $matchedFacility = $facility;
        break;
    }
}

// Fallback: first facility with an evolution-go secret configured
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

$isAuthorized = false;

// 1. Authorized if matched to a facility by instance name
if ($matchedFacility !== null) {
    $isAuthorized = true;
}

// 2. Authorized if token or API key matches expected secret
if (!empty($expectedSecret) && ($receivedToken === $expectedSecret || $receivedApiKey === $expectedSecret)) {
    $isAuthorized = true;
}

// 3. If no facility configured a secret, allow requests to prevent setup lockouts
if (!$isAuthorized && empty($expectedSecret)) {
    $hasAnySecret = false;
    foreach ($allFacilities as $f) {
        if (!empty($f['evolution_go_webhook_secret'])) {
            $hasAnySecret = true;
            break;
        }
    }
    if (!$hasAnySecret) {
        $isAuthorized = true;
        evoLog('Warning: no evolution-go secrets configured in facilities. Allowing request.');
    } else {
        evoLog("Rejected: unauthorized request (bad token) for instance='$instance'.");
        http_response_code(401);
        exit;
    }
} elseif (!$isAuthorized) {
    evoLog("Rejected: unauthorized request for instance='$instance'.");
    http_response_code(401);
    exit;
}

evoLog('Webhook request authorized successfully.');

// --- Normalize payload across Evolution API generations ---
// Returns: ['id' => string, 'jid' => string, 'fromMe' => ?bool, 'status' => string]
function evoExtract(array $webhookData, string $event): array
{
    $data = $webhookData['data'] ?? [];

    // v1 batched shape: data is a list of update objects
    if (isset($data[0]) && is_array($data[0])) {
        $first = $data[0];
        return [
            'id'     => (string)($first['key']['id'] ?? $first['keyId'] ?? ''),
            'jid'    => (string)($first['key']['remoteJid'] ?? $first['remoteJid'] ?? ''),
            'fromMe' => isset($first['key']['fromMe']) ? (bool)$first['key']['fromMe'] : null,
            'status' => (string)($first['update']['status'] ?? $first['status'] ?? ''),
        ];
    }

    // messages.update may arrive flattened (v2): keyId + status
    $id = (string)($data['key']['id'] ?? $data['keyId'] ?? $data['messageId'] ?? '');

    // messages.upsert nests messages under data.messages
    if ($event === 'messages.upsert') {
        $records = $data['messages'] ?? [$data];
        if (isset($records[0]) && is_array($records[0])) {
            $rec = $records[0];
            return [
                'id'     => (string)($rec['key']['id'] ?? $rec['id'] ?? ''),
                'jid'    => (string)($rec['key']['remoteJid'] ?? $rec['remoteJid'] ?? ''),
                'fromMe' => isset($rec['key']['fromMe']) ? (bool)$rec['key']['fromMe'] : null,
                'status' => (string)($rec['status'] ?? $rec['update']['status'] ?? ''),
            ];
        }
    }

    return [
        'id'     => $id,
        'jid'    => (string)($data['key']['remoteJid'] ?? $data['remoteJid'] ?? ''),
        'fromMe' => isset($data['key']['fromMe']) ? (bool)$data['key']['fromMe'] : null,
        'status' => (string)($data['status'] ?? $data['update']['status'] ?? ''),
    ];
}

$msg = evoExtract($webhookData, $event);
$msgId = $msg['id'];
$jid   = $msg['jid'];

// Ignore group / newsletter / broadcast targets
if (strpos($jid, '@g.us') !== false || strpos($jid, '@newsletter') !== false || strpos($jid, '@broadcast') !== false) {
    evoLog("Ignored: group/newsletter/broadcast message ($jid).");
    http_response_code(200);
    echo json_encode(['status' => 'ok', 'skipped' => 'group_or_newsletter']);
    exit;
}

// messages.upsert fires for ALL messages: only track OUR outgoing ones carrying status info
if ($event === 'messages.upsert' && $msg['fromMe'] !== true) {
    evoLog('Ignored: messages.upsert for incoming message (patient reply).');
    http_response_code(200);
    echo json_encode(['status' => 'ok', 'skipped' => 'incoming_message']);
    exit;
}

// Never feed empty/unknown statuses into the normalizer — they would map to
// ERROR (priority 5) and could clobber a valid DELIVERED/READ state.
$rawStatus = trim($msg['status']);
if ($rawStatus === '' || strtolower($rawStatus) === 'unknown') {
    evoLog("Ignored: no status info in '$event' payload.");
    http_response_code(200);
    echo json_encode(['status' => 'ok', 'skipped' => 'no_status_info']);
    exit;
}

if (empty($msgId)) {
    evoLog('Warning: message ID is missing in payload.');
    http_response_code(200);
    echo json_encode(['status' => 'ok', 'skipped' => 'missing_msg_id']);
    exit;
}

$notifLog = new NotificationLog();

$canonical        = StatusNormalizer::normalize('evolution-go', $rawStatus);
$incomingPriority = StatusNormalizer::getPriority($canonical);

// Track HOW the log entry was matched — determines whether msg_id may be rewritten
$matchedBy = '';

$existing = sqlQuery(
    "SELECT iLogId, pc_eid, pid, msg_id, status_priority, status_current FROM notification_log WHERE msg_id = ?",
    [$msgId]
);
if ($existing && !empty($existing['iLogId'])) {
    $matchedBy = 'exact';
}

// Fallback search 1: try suffix match if the id carries a prefix (e.g. false_..._ABC123)
if ((!$existing || empty($existing['iLogId'])) && strpos($msgId, '_') !== false) {
    $parts   = explode('_', $msgId);
    $shortId = end($parts);
    if (!empty($shortId) && strlen($shortId) >= 10) {
        $matchedLog = sqlQuery(
            "SELECT iLogId, pc_eid, pid, msg_id, status_priority, status_current FROM notification_log WHERE msg_id = ? OR msg_id LIKE ? LIMIT 1",
            [$shortId, '%' . $shortId]
        );
        if ($matchedLog && !empty($matchedLog['iLogId'])) {
            $existing  = $matchedLog;
            $matchedBy = 'shortid';
            evoLog("Matched short msgId='$shortId' for incoming msgId='$msgId' -> iLogId={$existing['iLogId']}");
        }
    }
}

// Fallback search 2: match recent log entry sent to the same phone number in the last 15 minutes
if (!$existing || empty($existing['iLogId'])) {
    $phone = preg_replace('/\D/', '', explode('@', $jid)[0] ?? '');
    if (!empty($phone) && strlen($phone) >= 8) {
        $shortPhone = substr($phone, -10);
        $recentLog  = sqlQuery(
            "SELECT iLogId, pc_eid, pid, msg_id, status_priority, status_current
             FROM notification_log
             WHERE type = 'WSP'
               AND (patient_info LIKE ? OR smsgateway_info LIKE ?)
               AND dSentDateTime >= NOW() - INTERVAL 15 MINUTE
             ORDER BY iLogId DESC LIMIT 1",
            ["%{$shortPhone}%", "%{$shortPhone}%"]
        );
        if ($recentLog && !empty($recentLog['iLogId'])) {
            $existing  = $recentLog;
            $matchedBy = 'phone';
            evoLog("Matched recent log entry by shortPhone={$shortPhone} -> iLogId={$existing['iLogId']}");
        }
    }
}

// If msgId is completely unrecognized, skip auto-creating records
if (!$existing || empty($existing['iLogId'])) {
    evoLog("Unrecognized msgId='$msgId' — skipping.");
    http_response_code(200);
    echo json_encode(['status' => 'ok', 'skipped' => 'unrecognized_msg_id']);
    exit;
}

// Skip if existing status has higher priority (prevents out-of-order downgrades)
if (isset($existing['status_priority'])) {
    $existingPriority = (int)$existing['status_priority'];
    if ($existingPriority > $incomingPriority && $existingPriority > 0) {
        evoLog("Skipped: msgId=$msgId already has priority $existingPriority > $incomingPriority");
        http_response_code(200);
        echo json_encode(['status' => 'ok', 'skipped' => 'lower_priority']);
        exit;
    }
}

$targetMsgId = !empty($existing['msg_id']) ? $existing['msg_id'] : $msgId;

// Reconcile message ID ONLY on high-confidence matches (exact / shortId).
// Phone-based matches are ambiguous: rewriting their msg_id would hijack
// the record's original vendor ID.
if (in_array($matchedBy, ['exact', 'shortid'], true)
    && !empty($msgId) && $msgId !== $targetMsgId && !empty($existing['iLogId'])
) {
    sqlStatement(
        "UPDATE notification_log SET msg_id = ? WHERE iLogId = ?",
        [$msgId, $existing['iLogId']]
    );
    evoLog("Reconciled msg_id: iLogId={$existing['iLogId']} old='$targetMsgId' -> new='$msgId'");
    $targetMsgId = $msgId;
}

$notifLog->updateStatus($targetMsgId, $rawStatus, 'evolution-go', $webhookData);
evoLog("Updated: targetMsgId=$targetMsgId (rawId=$msgId) -> raw=$rawStatus, canonical=$canonical");

// Update calendar event & patient tracker status if DELIVERED or READ
if (!empty($existing['pc_eid']) && !empty($existing['pid'])) {
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
        evoLog("Updated calendar & tracker for pc_eid=$pcEid to status='$newApptStatus'");
    }
}

http_response_code(200);
echo json_encode(['status' => 'ok']);
