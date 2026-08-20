<?php
/**
 * run_recalls_now.php — Manually triggers the recall notification process.
 *
 * POST body:
 *   facility_id  (optional, 0 = all)
 *   channel      (optional: wsp|email|all, default all)
 *   dry_run      (optional: 1|0, default 0)
 *
 * Returns: JSON { success: bool, output: string }
 *
 * @package   OpenEMR\Modules\WspEmail
 */

$sessionAllowWrite = true;
require_once __DIR__ . '/../../../../../globals.php';

require_once __DIR__ . '/../../src/FacilityConfig.php';
require_once __DIR__ . '/../../src/NotificationLog.php';
require_once __DIR__ . '/../../src/StatusNormalizer.php';
require_once __DIR__ . '/../../src/RateLimiter.php';
require_once __DIR__ . '/../../src/Blacklist.php';
require_once __DIR__ . '/../../src/WspSender.php';
require_once __DIR__ . '/../../src/EmailSender.php';
require_once __DIR__ . '/../../src/RecallService.php';

use OpenEMR\Modules\WspEmail\RecallService;
use OpenEMR\Common\Acl\AclMain;

header('Content-Type: application/json');

if (!AclMain::aclCheckCore('admin', 'super')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Access denied']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'error' => 'POST required']);
    exit;
}

$channel = strtolower(trim($_POST['channel'] ?? 'all'));
$dryRun  = (bool)(int)($_POST['dry_run'] ?? 0);
$selectedJson = trim($_POST['selected'] ?? '');

// Capture output
ob_start();

try {
    $service = new RecallService();

    echo "=== Manual Recall Run — " . date('Y-m-d H:i:s') . " ===\n";
    echo "Channel: {$channel} | Dry-run: " . ($dryRun ? 'YES' : 'NO') . "\n\n";

    if (!empty($selectedJson)) {
        // Send only selected rows
        $selected = json_decode($selectedJson, true);
        if (!is_array($selected) || empty($selected)) {
            echo "ERROR: Invalid 'selected' JSON.\n";
        } else {
            echo "Sending " . count($selected) . " selected recall(s)...\n\n";
            $service->sendSelected($selected, $dryRun, $channel);
        }
    } else {
        $forceSend = true; // manual run ignores scheduled date

        if ($channel === 'wsp') {
            $service->runWsp($dryRun, $forceSend);
        } elseif ($channel === 'email') {
            $service->runEmail($dryRun, $forceSend);
        } elseif ($channel === 'sms') {
            $service->runSms($dryRun, $forceSend);
        } else {
            $service->runAll($dryRun, $forceSend);
        }
    }

    echo "\nCompleted successfully.\n";
    $output  = ob_get_clean();
    $success = true;
} catch (\Throwable $e) {
    $output  = ob_get_clean() . "\nFATAL: " . $e->getMessage();
    $success = false;
    error_log("run_recalls_now.php FATAL: " . $e->getMessage());
}

echo json_encode(['success' => $success, 'output' => $output]);
