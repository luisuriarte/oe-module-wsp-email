<?php
/**
 * run_now.php — Triggers WSP and Email notifications immediately.
 *
 * POST only. Returns JSON with execution logs.
 *
 * @package   OpenEMR\Modules\WspEmail
 */

$sessionAllowWrite = true;
require_once __DIR__ . '/../../../../../globals.php';
require_once __DIR__ . '/../../../../../../library/patient_tracker.inc.php';

use OpenEMR\Common\Acl\AclMain;
if (!AclMain::aclCheckCore('patients', 'demo')) {
    http_response_code(403);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Access denied']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'POST required']);
    exit;
}

// Register module autoloader
$moduleDir = __DIR__ . '/../../src/';
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

// Capture all output
ob_start();

try {
    echo "=== Manual send — " . date('Y-m-d H:i:s') . " ===\n\n";

    $service = new NotificationService();

    echo "--- WhatsApp ---\n";
    $service->runWsp(false);

    echo "\n--- Email ---\n";
    $service->runEmail(false);

    echo "\n=== Done — " . date('Y-m-d H:i:s') . " ===\n";

    $log = ob_get_clean();

    // Clear any previous output and send JSON
    while (ob_get_level()) ob_end_clean();

    header('Content-Type: application/json');
    echo json_encode([
        'success' => true,
        'log'     => $log,
    ]);
} catch (\Throwable $e) {
    $log = ob_get_clean();
    while (ob_get_level()) ob_end_clean();
    header('Content-Type: application/json');
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error'   => $e->getMessage(),
        'log'     => $log ?: null,
    ]);
}
