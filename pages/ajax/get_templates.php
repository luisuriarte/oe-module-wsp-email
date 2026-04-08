<?php
/**
 * get_templates.php — Returns all templates for a facility (and global defaults).
 *
 * @package   OpenEMR\Modules\WspEmail
 */

$sessionAllowWrite = false;
require_once __DIR__ . '/../../../../../globals.php';

header('Content-Type: application/json');

use OpenEMR\Common\Acl\AclMain;
if (!AclMain::aclCheckCore('patients', 'demo')) {
    http_response_code(403);
    echo json_encode(['error' => 'Access denied']);
    exit;
}

$fid = (int)($_GET['facility_id'] ?? 0);

// Get facility-specific templates
$sql = "SELECT * FROM wsp_email_notification_templates 
        WHERE facility_id = ? 
        ORDER BY pc_catid, recipient_type, pc_apptstatus";

$res = sqlStatement($sql, [$fid]);
$rows = [];
while ($row = sqlFetchArray($res)) {
    $rows[] = $row;
}

echo json_encode(['rows' => $rows]);
