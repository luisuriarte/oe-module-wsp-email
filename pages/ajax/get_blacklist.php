<?php
/**
 * get_blacklist.php — Returns blacklist entries with optional filters.
 *
 * GET params:
 *   facility_id  int|''   – filter by facility (0 = global)
 *   vendor       string   – filter by vendor slug or '' for all
 *   reason       string   – filter by reason or '' for all
 *   active       0|1|''   – filter by is_active or '' for all
 *   search       string   – partial phone / notes search
 *   limit        int      – max rows (default 200)
 *   offset       int      – pagination offset (default 0)
 *
 * Response JSON:
 *   { rows: [...], total: int }
 */

$sessionAllowWrite = false;
require_once __DIR__ . '/../../../../../globals.php';

use OpenEMR\Common\Acl\AclMain;

header('Content-Type: application/json');

if (!AclMain::aclCheckCore('admin', 'super')) {
    http_response_code(403);
    echo json_encode(['error' => 'Access denied']);
    exit;
}

$facilityId = isset($_GET['facility_id']) && $_GET['facility_id'] !== '' ? (int)$_GET['facility_id'] : null;
$vendor     = trim($_GET['vendor']  ?? '');
$reason     = trim($_GET['reason']  ?? '');
$active     = isset($_GET['active']) && $_GET['active'] !== '' ? (int)$_GET['active'] : null;
$search     = trim($_GET['search']  ?? '');
$limit      = max(1, min(500, (int)($_GET['limit']  ?? 50)));
$offset     = max(0, (int)($_GET['offset'] ?? 0));

$where  = [];
$params = [];

if ($facilityId !== null) {
    $where[]  = 'b.facility_id = ?';
    $params[] = $facilityId;
}
if ($vendor !== '') {
    $where[]  = 'b.vendor = ?';
    $params[] = $vendor;
}
if ($reason !== '') {
    $where[]  = 'b.reason = ?';
    $params[] = $reason;
}
if ($active !== null) {
    $where[]  = 'b.is_active = ?';
    $params[] = $active;
}
if ($search !== '') {
    $where[]  = '(b.phone LIKE ? OR b.notes LIKE ?)';
    $like     = '%' . $search . '%';
    $params[] = $like;
    $params[] = $like;
}

$whereClause = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

// Total count
$countRow = sqlQuery(
    "SELECT COUNT(*) AS total
       FROM wsp_email_blacklist b
       $whereClause",
    $params
);
$total = (int)($countRow['total'] ?? 0);

// Rows with facility name joined
$paramsPage = array_merge($params, [$limit, $offset]);
$rows       = sqlStatement(
    "SELECT b.*,
            COALESCE(f.name, IF(b.facility_id = 0, 'Global', CONCAT('ID:', b.facility_id))) AS facility_name
       FROM wsp_email_blacklist b
  LEFT JOIN facility f ON f.id = b.facility_id
     $whereClause
  ORDER BY b.updated_at DESC
     LIMIT ? OFFSET ?",
    $paramsPage
);

$result = [];
while ($row = sqlFetchArray($rows)) {
    $result[] = [
        'id'            => (int)$row['id'],
        'facility_id'   => (int)$row['facility_id'],
        'facility_name' => $row['facility_name'],
        'vendor'        => $row['vendor'],
        'phone'         => $row['phone'],
        'reason'        => $row['reason'],
        'fail_count'    => (int)$row['fail_count'],
        'is_active'     => (int)$row['is_active'],
        'notes'         => $row['notes'],
        'created_at'    => $row['created_at'],
        'updated_at'    => $row['updated_at'],
    ];
}

echo json_encode(['rows' => $result, 'total' => $total]);
