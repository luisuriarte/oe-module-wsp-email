<?php
/**
 * sync_status.php — AJAX endpoint to manually sync a notification status from the vendor.
 *
 * @package   OpenEMR\Modules\WspEmail
 * @author    Luis A. Uriarte <luis.uriarte@gmail.com>
 * @copyright Copyright (c) 2024-2025 Luis A. Uriarte
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

require_once(__DIR__ . "/../../../../globals.php");

use OpenEMR\Modules\WspEmail\NotificationService;
use OpenEMR\Common\Acl\AclMain;

header('Content-Type: application/json');

// --- ACL Check ---
if (!AclMain::aclCheckCore('admin', 'super')) {
    echo json_encode(['success' => false, 'message' => 'Access Denied']);
    exit;
}

$logId = (int)($_GET['log_id'] ?? 0);
if ($logId <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid Log ID']);
    exit;
}

$service = new NotificationService();
$result  = $service->syncLogStatus($logId);

echo json_encode($result);
