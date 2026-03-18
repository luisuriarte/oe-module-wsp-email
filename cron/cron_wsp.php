<?php
/**
 * cron_wsp.php — CLI cron entry point for WhatsApp appointment notifications.
 *
 * Usage:
 *   php cron_wsp.php site=default
 *   php cron_wsp.php site=default dryrun=1    (test: logs but does NOT send)
 *
 * Recommended cron schedule (every hour):
 *   0 * * * * php /path/to/openemr/interface/modules/custom_modules/oe-module-wsp-email/cron/cron_wsp.php site=default >> /var/log/wsp_notify.log 2>&1
 *
 * @package   OpenEMR\Modules\WspEmail
 * @author    Luis A. Uriarte <luis.uriarte@gmail.com>
 * @copyright Copyright (c) 2024-2025 Luis A. Uriarte
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

// --- Bootstrap for CLI execution ---
$_SERVER['REQUEST_URI']  = $_SERVER['PHP_SELF'] ?? '/cron_wsp.php';
$_SERVER['SERVER_NAME']  = 'localhost';

// Parse site argument from command line
global $argc, $argv;
if (!empty($argc) && $argc > 1) {
    $args = [];
    foreach (array_slice($argv, 1) as $arg) {
        [$k, $v] = explode('=', $arg, 2) + [1 => ''];
        $args[$k] = $v;
    }
    $_GET['site'] = $args['site'] ?? 'default';
}

if (php_sapi_name() === 'cli') {
    $_SERVER['HTTP_HOST'] = 'localhost';
    $ignoreAuth            = true;
}

// Load OpenEMR core
require_once __DIR__ . '/../../../../globals.php';
require_once __DIR__ . '/../../../../../library/patient_tracker.inc.php';

// Load module autoloader (or fallback to manual includes)
$moduleDir = __DIR__ . '/../src/';
spl_autoload_register(function (string $class) use ($moduleDir): void {
    $prefix = 'OpenEMR\\Modules\\WspEmail\\';
    if (str_starts_with($class, $prefix)) {
        $file = $moduleDir . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
        if (file_exists($file)) {
            require_once $file;
        }
    }
});

use OpenEMR\Modules\WspEmail\NotificationService;

$dryRun = isset($args['dryrun']) && $args['dryrun'] === '1';

echo "\n=== WSP Notification Cron — " . date('Y-m-d H:i:s') . " ===\n";
echo $dryRun ? "[DRY-RUN MODE — no messages will be sent]\n\n" : "\n";

$service = new NotificationService();
$service->runWsp($dryRun);

echo "\n=== Done — " . date('Y-m-d H:i:s') . " ===\n";
