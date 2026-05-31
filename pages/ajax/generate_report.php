<?php
/**
 * generate_report.php — Generates a PDF report of notification activity.
 *
 * GET params: from (date), to (date), facility_id (int, optional)
 * Returns: PDF file (Content-Type: application/pdf)
 *
 * @package   OpenEMR\Modules\WspEmail
 */

$sessionAllowWrite = false;
require_once __DIR__ . '/../../../../../globals.php';
require_once __DIR__ . '/../../src/NotificationLog.php';
require_once $GLOBALS['srcdir'] . '/formatting.inc.php';

use OpenEMR\Modules\WspEmail\NotificationLog;

use OpenEMR\Common\Acl\AclMain;
if (!AclMain::aclCheckCore('patients', 'demo')) {
    die(xlt('Access Denied'));
}

$from       = $_GET['from']        ?? '';
$to         = $_GET['to']          ?? '';
$facilityId = isset($_GET['facility_id']) && $_GET['facility_id'] !== '' ? (int)$_GET['facility_id'] : null;

$from = !empty($from) ? DateToYYYYMMDD($from) : date('Y-m-d', strtotime('-7 days'));
$to   = !empty($to)   ? DateToYYYYMMDD($to)   : date('Y-m-d');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) $from = date('Y-m-d', strtotime('-7 days'));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $to))   $to   = date('Y-m-d');

$log      = new NotificationLog();
$totals   = $log->getSummaryTotals($from, $to);
$byStatus = $log->getSummaryByStatus($from, $to, $facilityId);
$details  = $log->getReportData($from, $to, $facilityId, 500);

// Facility name
$facilityName = 'Todos los centros';
if ($facilityId) {
    $fRes = sqlQuery("SELECT name FROM facility WHERE id = ?", [$facilityId]);
    $facilityName = $fRes ? $fRes['name'] : 'Centro #' . $facilityId;
}

$siteName = $GLOBALS['openemr_name'] ?? 'OpenEMR';
$dateRange = oeFormatShortDate($from) . ' - ' . oeFormatShortDate($to);

// Build HTML for PDF
$html = '<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<style>
body { font-family: "DejaVu Sans", sans-serif; font-size: 10pt; color: #333; }
h1 { font-size: 18pt; color: #1565C0; margin: 0 0 4px; }
h2 { font-size: 14pt; color: #2E7D32; margin: 20px 0 8px; border-bottom: 2px solid #2E7D32; padding-bottom: 4px; }
h3 { font-size: 12pt; color: #555; margin: 0 0 16px; font-weight: normal; }
table { width: 100%; border-collapse: collapse; margin: 8px 0 16px; }
th { background: #1565C0; color: #fff; padding: 6px 8px; text-align: left; font-size: 9pt; }
td { padding: 5px 8px; border-bottom: 1px solid #ddd; font-size: 9pt; }
tr:nth-child(even) td { background: #f5f5f5; }
.summary { margin: 16px 0; }
.summary td { font-weight: bold; text-align: center; padding: 10px; border: 1px solid #ddd; font-size: 10pt; }
.summary .num { font-size: 18pt; display: block; }
.wsp { color: #25D366; }
.email { color: #1565C0; }
.sms { color: #856404; }
.voz { color: #721C24; }
.footer { margin-top: 24px; font-size: 8pt; color: #999; text-align: center; border-top: 1px solid #ddd; padding-top: 8px; }
</style>
</head>
<body>
<h1>' . htmlspecialchars($siteName) . '</h1>
<h3>Reporte de Notificaciones — ' . htmlspecialchars($dateRange) . '<br>' . htmlspecialchars($facilityName) . '</h3>';

// Summary cards
$grandTotal = (int)($totals['grand_total'] ?? 0);
$totalWsp   = (int)($totals['total_wsp'] ?? 0);
$totalEmail = (int)($totals['total_email'] ?? 0);
$pending    = (int)($totals['pending'] ?? 0);
$failed     = (int)($totals['failed'] ?? 0);

$html .= '<table class="summary">
<tr>
    <td style="background:#E8F5E9">WhatsApp<br><span class="num wsp">' . $totalWsp . '</span></td>
    <td style="background:#E3F2FD">Email<br><span class="num email">' . $totalEmail . '</span></td>
    <td style="background:#FFF8E1">Pendientes<br><span class="num" style="color:#F57F17">' . $pending . '</span></td>
    <td style="background:#FFEBEE">Fallidos<br><span class="num" style="color:#D32F2F">' . $failed . '</span></td>
    <td style="background:#F3E5F5">Total<br><span class="num" style="color:#7B1FA2">' . $grandTotal . '</span></td>
</tr>
</table>';

// By status table
$html .= '<h2>Resumen por Estado</h2>';
$html .= '<table>
<tr><th>Canal</th><th>Estado</th><th style="text-align:right">Total</th></tr>';
$statusLabels = [
    'QUEUED' => 'En Cola', 'SENT' => 'Enviado', 'DELIVERED' => 'Entregado',
    'READ' => 'Leído', 'FAILED' => 'Fallido', 'INVALID' => 'Inválido',
    'ERROR' => 'Error', 'UNSENT' => 'No Enviado'
];
foreach ($byStatus as $row) {
    $label = $statusLabels[$row['status']] ?? $row['status'];
    $html .= '<tr><td>' . htmlspecialchars($row['type']) . '</td><td>' . htmlspecialchars($label) . '</td><td style="text-align:right">' . (int)$row['total'] . '</td></tr>';
}
$html .= '</table>';

// Detail table
if (!empty($details)) {
    $html .= '<h2>Detalle de Notificaciones</h2>';
    $html .= '<table>
    <tr>
        <th>Paciente</th>
        <th>Teléfono</th>
        <th>Email</th>
        <th>Canal</th>
        <th>Estado</th>
        <th>Turno</th>
        <th>Fecha Envío</th>
    </tr>';
    foreach ($details as $r) {
        $patientName = htmlspecialchars(trim(($r['fname'] ?? '') . ' ' . ($r['lname'] ?? '')));
        $phone   = htmlspecialchars($r['phone_cell'] ?? '');
        $email   = htmlspecialchars($r['email'] ?? '');
        $type    = htmlspecialchars($r['type'] ?? '');
        $status  = htmlspecialchars($statusLabels[$r['status']] ?? $r['status'] ?? '');
        $appt    = htmlspecialchars(($r['pc_title'] ?? '') . ' ' . ($r['pc_eventDate'] ?? ''));
        $sentAt  = htmlspecialchars($r['dSentDateTime'] ?? '');
        $html .= '<tr>
            <td>' . $patientName . '</td>
            <td>' . $phone . '</td>
            <td>' . $email . '</td>
            <td>' . $type . '</td>
            <td>' . $status . '</td>
            <td>' . $appt . '</td>
            <td>' . $sentAt . '</td>
        </tr>';
    }
    $html .= '</table>';
}

$html .= '<div class="footer">Generado el ' . date('Y-m-d H:i') . ' — ' . htmlspecialchars($siteName) . '</div>';
$html .= '</body></html>';

// Generate PDF with mPDF
try {
    $mpdf = new \Mpdf\Mpdf([
        'tempDir'   => $GLOBALS['temporary_files_dir'] ?? sys_get_temp_dir(),
        'margin_top' => 15,
        'margin_bottom' => 15,
        'margin_left' => 12,
        'margin_right' => 12,
    ]);
    $mpdf->WriteHTML($html);
    $mpdf->Output('reporte_notificaciones_' . $from . '_' . $to . '.pdf', 'I');
} catch (\Exception $e) {
    http_response_code(500);
    echo 'Error generating PDF: ' . htmlspecialchars($e->getMessage());
}
