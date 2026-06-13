<?php
/**
 * cron_recall.php — Cron script for processing recall notifications.
 *
 * Usage:
 *   php cron_recall.php          → Run all channels (WSP + Email)
 *   php cron_recall.php --dry-run → Log but do NOT send
 *   php cron_recall.php wsp       → WSP only
 *   php cron_recall.php email     → Email only
 *
 * @package   OpenEMR\Modules\WspEmail
 * @author    Luis A. Uriarte <luis.uriarte@gmail.com>
 * @copyright Copyright (c) 2024-2025 Luis A. Uriarte
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

// CLI only
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit("CLI only.\n");
}

$dryRun  = in_array('--dry-run', $argv, true);
$channel = null;
foreach ($argv as $arg) {
    if ($arg === 'wsp')   { $channel = 'wsp';   break; }
    if ($arg === 'email') { $channel = 'email';  break; }
}

// Bootstrap OpenEMR
$ignoreAuth = true;
require_once __DIR__ . '/../../../../globals.php';

require_once __DIR__ . '/../src/FacilityConfig.php';
require_once __DIR__ . '/../src/NotificationLog.php';
require_once __DIR__ . '/../src/StatusNormalizer.php';
require_once __DIR__ . '/../src/RateLimiter.php';
require_once __DIR__ . '/../src/Blacklist.php';
require_once __DIR__ . '/../src/WspSender.php';
require_once __DIR__ . '/../src/EmailSender.php';
require_once __DIR__ . '/../src/RecallService.php';

use OpenEMR\Modules\WspEmail\RecallService;

echo "=== Recall Notification Cron ===\n";
echo "Time: " . date('Y-m-d H:i:s') . "\n";
echo "Dry run: " . ($dryRun ? 'YES' : 'NO') . "\n";
echo "Channel: " . ($channel ?? 'all') . "\n\n";

try {
    $service = new RecallService();

    if ($channel === 'wsp') {
        $service->runWsp($dryRun);
    } elseif ($channel === 'email') {
        $service->runEmail($dryRun);
    } else {
        $service->runAll($dryRun);
    }

    echo "\nDone.\n";
} catch (\Throwable $e) {
    echo "\nFATAL ERROR: " . $e->getMessage() . "\n";
    error_log("cron_recall.php FATAL: " . $e->getMessage() . "\n" . $e->getTraceAsString());
    exit(1);
}
