<?php
/**
 * get_schedules.php — Fetches appointments for manual notifications.
 *
 * @package   OpenEMR\Modules\WspEmail
 */

$sessionAllowWrite = false;
require_once __DIR__ . '/../../../../../globals.php';
require_once $GLOBALS['srcdir'] . '/formatting.inc.php';

header('Content-Type: application/json');

use OpenEMR\Common\Acl\AclMain;
if (!AclMain::aclCheckCore('patients', 'demo')) {
    http_response_code(403);
    echo json_encode(['error' => 'Access denied']);
    exit;
}

// Get parameters
$fromDateRaw = $_GET['from_date'] ?? '';
$toDateRaw   = $_GET['to_date'] ?? '';
$patient     = trim($_GET['patient'] ?? '');
$apptStatus  = $_GET['appt_status'] ?? '';
$facilityId  = (int)($_GET['facility_id'] ?? 0);

// Convert localized dates to YYYY-MM-DD
// DateToYYYYMMDD handles DD/MM/YYYY -> YYYY-MM-DD conversion
$fromDate = !empty($fromDateRaw) ? DateToYYYYMMDD($fromDateRaw) : date('Y-m-d', strtotime('-30 days'));
$toDate   = !empty($toDateRaw) ? DateToYYYYMMDD($toDateRaw) : date('Y-m-d', strtotime('+60 days'));

// Validate dates (fallback to defaults if conversion failed)
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fromDate)) {
    $fromDate = date('Y-m-d', strtotime('-30 days'));
}
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $toDate)) {
    $toDate = date('Y-m-d', strtotime('+60 days'));
}

error_log("WspEmail Schedules Query: from=$fromDate, to=$toDate, patient='$patient', facility=$facilityId");

// Build WHERE clause
$where = "WHERE ope.pc_eventDate >= ? AND ope.pc_eventDate <= ?";
$params = [$fromDate, $toDate];

if (!empty($patient) && strlen($patient) >= 2) {
    $like = '%' . $patient . '%';
    $where .= " AND (pd.fname LIKE ? OR pd.lname LIKE ? OR CONCAT_WS(' ', pd.fname, pd.lname) LIKE ?)";
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
}

if ($facilityId > 0) {
    $where .= " AND ope.pc_facility = ?";
    $params[] = $facilityId;
}

if (!empty($apptStatus)) {
    $where .= " AND ope.pc_apptstatus = ?";
    $params[] = $apptStatus;
}

// REMOVED pc_eventstatus filter - it was blocking valid appointments
// $where .= " AND ope.pc_eventstatus = 1";

$sql = "SELECT 
            ope.pc_eid,
            ope.pc_pid,
            ope.pc_catid,
            ope.pc_eventDate,
            ope.pc_startTime,
            ope.pc_endTime,
            ope.pc_apptstatus,
            ope.pc_title,
            ope.pc_hometext,
            ope.pc_facility,
            CONCAT_WS(' ', pd.fname, pd.lname) AS patient_name,
            pd.fname,
            pd.lname,
            pd.street,
            pd.city,
            pd.state,
            pd.phone_cell,
            pd.email,
            pd.hipaa_allowsms,
            pd.hipaa_allowemail,
            CONCAT_WS(' ', u.fname, u.lname) AS provider_name,
            f.name AS facility_name,
            lot.title AS status_title,
            pte.status AS tracker_status
        FROM openemr_postcalendar_events ope
        LEFT JOIN patient_data pd ON pd.pid = ope.pc_pid
        LEFT JOIN users u ON u.id = ope.pc_aid
        LEFT JOIN facility f ON f.id = ope.pc_facility
        LEFT JOIN list_options lot ON lot.list_id = 'apptstat' 
            AND lot.option_id = ope.pc_apptstatus
        LEFT JOIN patient_tracker pt ON pt.eid = ope.pc_eid
        LEFT JOIN patient_tracker_element pte ON pte.pt_tracker_id = pt.id
            AND pte.seq = (
                SELECT MAX(seq) FROM patient_tracker_element 
                WHERE pt_tracker_id = pt.id
            )
        $where
        ORDER BY ope.pc_eventDate ASC, ope.pc_startTime ASC
        LIMIT 500";

error_log("WspEmail Schedules SQL: $sql");
error_log("WspEmail Schedules Params: " . print_r($params, true));

$res = sqlStatement($sql, $params);
$rows = [];
while ($row = sqlFetchArray($res)) {
    // 1. Priorizar el estado del Patient Tracker (tiempo real)
    $rawStatus = $row['tracker_status'] ?: $row['pc_apptstatus'] ?: '';
    
    // 2. Intentar obtener el título de list_options.apptstat
    $statusTitle = $row['status_title'];
    
    // Si no hay título o el status cambió en el tracker, buscamos el título correcto
    if (empty($statusTitle) && !empty($rawStatus)) {
        $lotRow = sqlQuery("SELECT title FROM list_options WHERE list_id = 'apptstat' AND option_id = ?", [$rawStatus]);
        $statusTitle = $lotRow['title'] ?: $rawStatus;
    }
    
    // Si el status del tracker es diferente al del evento, actualizamos el título
    if ($row['tracker_status'] && $row['tracker_status'] != $row['pc_apptstatus']) {
         $lotRow = sqlQuery("SELECT title FROM list_options WHERE list_id = 'apptstat' AND option_id = ?", [$row['tracker_status']]);
         $statusTitle = $lotRow['title'] ?: $row['tracker_status'];
    }

    $rows[] = [
        'pc_eid'           => (int)$row['pc_eid'],
        'pc_pid'           => (int)$row['pc_pid'],
        'pc_catid'         => (int)($row['pc_catid'] ?? 0),
        'pc_eventDate'     => !empty($row['pc_eventDate']) ? oeFormatShortDate($row['pc_eventDate']) : '',
        'pc_eventDateRaw'  => $row['pc_eventDate'] ?? '',
        'pc_startTime'     => $row['pc_startTime'] ?? '',
        'pc_endTime'       => $row['pc_endTime'] ?? '',
        'pc_apptstatus'    => $rawStatus,
        'status_title'     => $statusTitle ?: 'Programada',
        'pc_title'         => $row['pc_title'] ?? '',
        'pc_hometext'      => $row['pc_hometext'] ?? '',
        'pc_facility'      => (int)$row['pc_facility'],
        'patient_name'     => $row['patient_name'] ?? '',
        'street'           => $row['street'] ?? '',
        'city'             => $row['city'] ?? '',
        'phone_cell'       => $row['phone_cell'] ?? '',
        'email'            => $row['email'] ?? '',
        'hipaa_allowsms'   => $row['hipaa_allowsms'] ?? 'NO',
        'hipaa_allowemail' => $row['hipaa_allowemail'] ?? 'NO',
        'provider_name'    => $row['provider_name'] ?? '',
        'facility_name'    => $row['facility_name'] ?? '',
    ];
}

error_log("WspEmail Schedules Result: " . count($rows) . " rows found");

echo json_encode(['rows' => $rows, 'debug' => ['from' => $fromDate, 'to' => $toDate, 'count' => count($rows)]]);
