<?php
/**
 * get_categories.php — Returns appointment categories for template creation.
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

$sql = "SELECT pc_catid, pc_catname AS `category`
        FROM openemr_postcalendar_categories
        WHERE pc_active = 1
        ORDER BY pc_catname";
$res = sqlStatement($sql);
$rows = [];
while ($row = sqlFetchArray($res)) {
    $rows[] = $row;
}
echo json_encode(['rows' => $rows]);
