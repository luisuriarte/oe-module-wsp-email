<?php
/**
 * get_appt_statuses.php — Returns active statuses from list_options.apptstat.
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

// Fetch active statuses
$res = sqlStatement("SELECT option_id, title FROM list_options WHERE list_id = 'apptstat' AND activity = 1 ORDER BY seq");
$statuses = [];
while ($row = sqlFetchArray($res)) {
    $statuses[] = [
        'id'    => $row['option_id'],
        'title' => $row['title']
    ];
}

echo json_encode(['statuses' => $statuses]);
