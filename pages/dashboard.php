<?php
/**
 * dashboard.php — Main UI for the WSP/Email Notification Module.
 *
 * Presents 4 Bootstrap tabs:
 *   1. Dashboard  — Charts + summary cards (Chart.js)
 *   2. Patients   — Search patient notification history + webhook status
 *   3. Facilities — Per-facility extended configuration form
 *   4. Config     — Notification templates and settings per facility
 *
 * @package   OpenEMR\Modules\WspEmail
 * @author    Luis A. Uriarte <luis.uriarte@gmail.com>
 * @copyright Copyright (c) 2024-2025 Luis A. Uriarte
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

$ignoreAuth = false;
$fake_session_allow_write = true; // backward compat
require_once __DIR__ . '/../../../../globals.php';

require_once __DIR__ . '/../src/FacilityConfig.php';
require_once __DIR__ . '/../src/NotificationLog.php';
require_once $GLOBALS['srcdir'] . '/formatting.inc.php';

use OpenEMR\Core\Header;
use OpenEMR\Common\Acl\AclMain;
use OpenEMR\Modules\WspEmail\FacilityConfig;
use OpenEMR\Modules\WspEmail\NotificationLog;

// ACL check
if (!AclMain::aclCheckCore('patients', 'demo')) {
    die(xlt('Access Denied'));
}

$facilityConfig = new FacilityConfig();
$notifLog       = new NotificationLog();
$facilities     = $facilityConfig->getAllFacilitiesWithConfig();

$activeTab = $_GET['tab'] ?? 'dashboard';
$today     = date('Y-m-d');
$weekAgo   = date('Y-m-d', strtotime('-7 days'));
$totals    = $notifLog->getSummaryTotals($weekAgo, $today);

$moduleRoot = $GLOBALS['webroot'] . '/interface/modules/custom_modules/oe-module-wsp-email';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?php echo xlt('WSP / Email Notifications'); ?></title>
    <?php Header::setupHeader(['bootstrap', 'jquery', 'fontawesome', 'datetime-picker', 'datetime-picker-translated']); ?>
    <link rel="stylesheet" href="<?php echo $moduleRoot; ?>/styles.css">
    <script src="<?php echo $moduleRoot; ?>/vendor/chart.js/dist/chart.umd.min.js"></script>
    <!-- Leaflet for Map Picker -->
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" integrity="sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY=" crossorigin=""/>
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js" integrity="sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo=" crossorigin=""></script>
</head>
<body>
<div class="container-fluid px-3">

    <!-- Module header -->
    <div class="module-header d-flex align-items-center gap-3">
        <i class="fab fa-whatsapp fa-2x"></i>
        <div>
            <h1><?php echo xlt('WSP / Email Notification Center'); ?></h1>
            <p><?php echo xlt('Automatic appointment reminders via WhatsApp and Email'); ?></p>
        </div>
    </div>

    <!-- Tabs -->
    <ul class="nav nav-tabs mb-3" id="mainTabs">
        <li class="nav-item">
            <a class="nav-link <?php echo $activeTab === 'dashboard' ? 'active' : ''; ?>" href="?tab=dashboard">
                <i class="fas fa-chart-bar me-1"></i><?php echo xlt('Dashboard'); ?>
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link <?php echo $activeTab === 'patients' ? 'active' : ''; ?>" href="?tab=patients">
                <i class="fas fa-user-clock me-1"></i><?php echo xlt('Patient Status'); ?>
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link <?php echo in_array($activeTab, ['facility','config']) ? 'active' : ''; ?>" href="?tab=facility">
                <i class="fas fa-hospital me-1"></i><?php echo xlt('Facilities'); ?>
            </a>
        </li>
    </ul>

    <!-- ===================================================================
         TAB: DASHBOARD
    ==================================================================== -->
    <div id="tab-dashboard" class="<?php echo $activeTab === 'dashboard' ? '' : 'd-none'; ?>">

        <!-- Summary cards -->
        <div class="row g-3 mb-4">
            <div class="col-6 col-md-3">
                <div class="stat-card stat-wsp">
                    <div class="number"><?php echo (int)($totals['total_wsp'] ?? 0); ?></div>
                    <div class="label"><?php echo xlt('WhatsApp sent'); ?><br><small><?php echo xlt('last 7 days'); ?></small></div>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div class="stat-card stat-email">
                    <div class="number"><?php echo (int)($totals['total_email'] ?? 0); ?></div>
                    <div class="label"><?php echo xlt('Emails sent'); ?><br><small><?php echo xlt('last 7 days'); ?></small></div>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div class="stat-card stat-pending">
                    <div class="number"><?php echo (int)($totals['pending'] ?? 0); ?></div>
                    <div class="label"><?php echo xlt('Pending'); ?></div>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div class="stat-card stat-failed">
                    <div class="number"><?php echo (int)($totals['failed'] ?? 0); ?></div>
                    <div class="label"><?php echo xlt('Errors'); ?></div>
                </div>
            </div>
        </div>

        <!-- Date range filter -->
        <div class="chart-card">
            <div class="row align-items-end mb-3">
                <div class="col-auto">
                    <label class="form-label mb-1"><?php echo xlt('From'); ?></label>
                    <input type="text" id="statsFrom" class="form-control form-control-sm datepicker" value="<?php echo attr(oeFormatShortDate($weekAgo)); ?>">
                </div>
                <div class="col-auto">
                    <label class="form-label mb-1"><?php echo xlt('To'); ?></label>
                    <input type="text" id="statsTo" class="form-control form-control-sm datepicker" value="<?php echo attr(oeFormatShortDate($today)); ?>">
                </div>
                <div class="col-auto">
                    <label class="form-label mb-1"><?php echo xlt('Facility'); ?></label>
                    <select id="statsFacility" class="form-select form-select-sm">
                        <option value=""><?php echo xlt('All Facilities'); ?></option>
                        <?php foreach ($facilities as $sf): ?>
                        <option value="<?php echo attr((string)$sf['facility_id']); ?>" 
                                data-inactive="<?php echo (int)($sf['inactive'] ?? 0); ?>">
                            <?php echo text($sf['facility_name']); ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-auto">
                    <button id="btnLoadStats" class="btn btn-sm btn-success">
                        <i class="fas fa-sync me-1"></i><?php echo xlt('Refresh'); ?>
                    </button>
                </div>
            </div>
            <canvas id="chartNotifications" height="90"></canvas>
        </div>
    </div><!-- /tab-dashboard -->

    <!-- ===================================================================
         TAB: PATIENT STATUS
    ==================================================================== -->
    <div id="tab-patients" class="<?php echo $activeTab === 'patients' ? '' : 'd-none'; ?>">
        <div class="chart-card">
            <div class="row align-items-end mb-3 g-3">
                <div class="col-md-4">
                    <label class="form-label small mb-1"><?php echo xlt('Search Patient'); ?></label>
                    <input type="text" id="patientSearch" class="form-control form-control-sm"
                           placeholder="<?php echo attr(xlt('Name, Phone or PID...')); ?>">
                </div>
                <div class="col-md-2">
                    <label class="form-label small mb-1"><?php echo xlt('From'); ?></label>
                    <input type="text" id="patientFrom" class="form-control form-control-sm datepicker" value="<?php echo attr(oeFormatShortDate($weekAgo)); ?>">
                </div>
                <div class="col-md-2">
                    <label class="form-label small mb-1"><?php echo xlt('To'); ?></label>
                    <input type="text" id="patientTo" class="form-control form-control-sm datepicker" value="<?php echo attr(oeFormatShortDate($today)); ?>">
                </div>
                <div class="col-auto">
                    <label class="form-label small mb-1 d-block"><?php echo xlt('Channels'); ?></label>
                    <div class="d-flex gap-4 pt-1 align-items-center">
                        <div class="d-flex align-items-center gap-2">
                            <label class="custom-checkbox">
                                <input type="checkbox" id="filterWsp" checked onchange="searchPatients()">
                                <span class="slider"></span>
                            </label>
                            <span class="small">WhatsApp</span>
                        </div>
                        <div class="d-flex align-items-center gap-2">
                            <label class="custom-checkbox">
                                <input type="checkbox" id="filterEmail" checked onchange="searchPatients()">
                                <span class="slider"></span>
                            </label>
                            <span class="small">E-Mail</span>
                        </div>
                    </div>
                </div>
                <div class="col-auto">
                    <button id="btnSearch" class="btn btn-sm btn-success px-4">
                        <i class="fas fa-search me-1"></i><?php echo xlt('Search'); ?>
                    </button>
                </div>
            </div>

            <div class="table-responsive">
                <table class="table table-sm table-hover mb-0" id="patientTable">
                    <thead class="table-light">
                        <tr>
                            <th><?php echo xlt('Patient'); ?></th>
                            <th><?php echo xlt('Phone'); ?></th>
                            <th><?php echo xlt('Appt. Details'); ?></th>
                            <th class="text-center"><?php echo xlt('Channel'); ?></th>
                            <th><?php echo xlt('Sent Date'); ?></th>
                            <th><?php echo xlt('Last Status'); ?></th>
                            <th class="text-center"><?php echo xlt('Actions'); ?></th>
                        </tr>
                    </thead>
                    <tbody id="patientTableBody">
                        <tr><td colspan="7" class="text-center text-muted py-4"><?php echo xlt('Enter a search term above.'); ?></td></tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div><!-- /tab-patients -->

    <!-- ===================================================================
         TAB: FACILITIES CONFIG
    ==================================================================== -->
    <div id="tab-facility" class="<?php echo in_array($activeTab, ['facility','config']) ? '' : 'd-none'; ?>">

        <?php if (AclMain::aclCheckCore('admin', 'super')): ?>
        <div class="row">
            <!-- Facility list -->
            <div class="col-md-4" id="facilityList">
                <h6 class="text-muted mb-2"><i class="fas fa-hospital me-1"></i><?php echo xlt('Select a facility to configure'); ?></h6>
                <?php foreach ($facilities as $f): 
                    $isInactive = (int)($f['inactive'] ?? 0) === 1;
                ?>
                <div class="facility-card <?php echo $isInactive ? 'inactive' : ''; ?>" 
                     onclick="loadFacilityConfig(<?php echo attr_js((string)$f['facility_id']); ?>)">
                    <strong><?php echo text($f['facility_name']); ?></strong><br>
                    <small class="text-muted"><?php echo text($f['facility_address'] ?? ''); ?></small><br>
                    
                    <?php if ($isInactive): ?>
                        <span class="inactive-legend">
                            <i class="fas fa-exclamation-circle me-1"></i><?php echo xlt('Centro desactivado. Activelo para su uso'); ?>
                        </span>
                    <?php endif; ?>

                    <?php if (!empty($f['vendor'])): ?>
                    <span class="badge bg-success mt-1"><?php echo text($f['vendor']); ?></span>
                    <?php else: ?>
                    <span class="badge bg-secondary mt-1"><?php echo xlt('Not configured'); ?></span>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
            </div>

            <!-- Config form -->
            <div class="col-md-8">
                <div id="facilityConfigCard" class="chart-card" style="display:none;">
                    <h5 id="facilityConfigTitle" class="mb-3"></h5>
                    <form id="facilityConfigForm" enctype="multipart/form-data">
                        <input type="hidden" id="cfgFacilityId" name="facility_id">

                        <div id="inactiveWarning" class="alert alert-warning py-2 mb-3" style="display:none;">
                            <i class="fas fa-lock me-1"></i>
                            <?php echo xlt('Este centro está desactivado. No se permite editar su configuración.'); ?>
                        </div>

                        <!-- Vendor settings -->
                        <h6 class="text-success"><?php echo xlt('WhatsApp Gateway'); ?></h6>
                        <div class="row g-2 mb-3">
                            <div class="col-md-4">
                                <label class="form-label"><?php echo xlt('Vendor'); ?></label>
                                <select name="vendor" id="cfgVendor" class="form-select form-select-sm">
                                    <option value="wasenderapi">WaSenderAPI</option>
                                    <option value="ultramsg">UltraMsg</option>
                                </select>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label"><?php echo xlt('Instance ID'); ?></label>
                                <input type="text" name="vendor_instance" id="cfgInstance" class="form-control form-control-sm">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label"><?php echo xlt('API Key / Token'); ?></label>
                                <input type="text" name="vendor_api_key" id="cfgApiKey" class="form-control form-control-sm">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label"><?php echo xlt('Webhook Secret'); ?></label>
                                <input type="text" name="webhook_secret" id="cfgWebhookSecret" class="form-control form-control-sm"
                                       placeholder="<?php echo attr(xlt('Bridge / vendor webhook secret')); ?>">
                            </div>
                            <div class="col-md-6" id="logoWspContainer">
                                <label class="form-label"><?php echo xlt('WSP Logo'); ?> <small class="text-muted">(Logo Actual: <span id="currentLogoWspName">None</span>)</small></label>
                                <input type="file" name="logo_wsp" id="cfgLogoWsp" class="form-control form-control-sm" accept="image/*">
                                <div id="previewWsp" class="mt-2" style="max-height: 100px; display: none;"></div>
                            </div>
                        </div>

                        <hr>
                        <h6 class="text-primary"><?php echo xlt('Email'); ?></h6>
                        <div class="row g-2 mb-3">
                            <div class="col-md-12" id="logoEmailContainer">
                                <label class="form-label"><?php echo xlt('Email Logo'); ?> <small class="text-muted">(Logo Actual: <span id="currentLogoEmailName">None</span>)</small></label>
                                <input type="file" name="logo_email" id="cfgLogoEmail" class="form-control form-control-sm" accept="image/*">
                                <div id="previewEmail" class="mt-2" style="max-height: 100px; display: none;"></div>
                            </div>
                            <div class="col-md-12 text-muted small">
                                <i class="fas fa-info-circle me-1"></i><?php echo xlt('Logos will be saved in images/logo_wsp and images/logo_email/'); ?>
                            </div>
                        </div>

                        <hr>
                        <h6 class="text-secondary"><?php echo xlt('Facility Details'); ?></h6>
                        <div class="row g-2 mb-3">
                             <div class="col-md-12 mb-3">
                                <label class="form-label d-flex justify-content-between">
                                    <span><i class="fas fa-map-marker-alt me-1"></i><?php echo xlt('Location Picker'); ?></span>
                                    <small class="text-muted"><?php echo xlt('Click on the map to select coordinates'); ?></small>
                                </label>
                                <div class="input-group input-group-sm mb-2">
                                    <input type="text" id="mapSearchInput" class="form-control" placeholder="<?php echo attr(xlt('Search address or city...')); ?>">
                                    <button class="btn btn-outline-secondary" type="button" id="btnMapSearch">
                                        <i class="fas fa-search"></i>
                                    </button>
                                </div>
                                <div id="facilityMap" style="height: 300px; border-radius: 8px; border: 1px solid #ddd; z-index: 1;"></div>
                            </div>
                             <div class="col-md-6 text-muted py-2 small">
                                <i class="fas fa-info-circle me-1"></i> <?php echo xlt('Website URL is taken from the standard facility configuration.'); ?>
                            </div>
                             <div class="col-md-3">
                                <label class="form-label"><?php echo xlt('Latitude'); ?></label>
                                <input type="number" step="0.000001" name="latitude" id="cfgLat" class="form-control form-control-sm" placeholder="-34.6037">
                            </div>
                            <div class="col-md-3">
                                <label class="form-label"><?php echo xlt('Longitude'); ?></label>
                                <input type="number" step="0.000001" name="longitude" id="cfgLon" class="form-control form-control-sm" placeholder="-58.3816">
                            </div>
                        </div>

                        <hr>
                        <h6><?php echo xlt('Channel Enable/Disable'); ?></h6>
                         <div class="row g-2 mb-3">
                            <div class="col-md-4">
                                <div class="d-flex align-items-center gap-2">
                                    <label class="custom-checkbox">
                                        <input type="checkbox" name="enabled_wsp" id="cfgEnabledWsp" value="1" checked>
                                        <span class="slider"></span>
                                    </label>
                                    <label class="form-label mb-0" for="cfgEnabledWsp"><?php echo xlt('WhatsApp enabled'); ?></label>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="d-flex align-items-center gap-2">
                                    <label class="custom-checkbox">
                                        <input type="checkbox" name="enabled_email" id="cfgEnabledEmail" value="1" checked>
                                        <span class="slider"></span>
                                    </label>
                                    <label class="form-label mb-0" for="cfgEnabledEmail"><?php echo xlt('Email enabled'); ?></label>
                                </div>
                            </div>
                        </div>

                        <hr>
                        <h6><?php echo xlt('Notification Schedule'); ?></h6>
                        <p class="text-muted small mb-2">
                            <?php echo xlt('Define how many notifications are sent per appointment and when.'); ?>
                            <?php echo xlt('"Send on booking" fires immediately when the appointment is created.'); ?>
                            <?php echo xlt('Other slots fire N hours before the appointment (via cron).'); ?>
                        </p>
                        <div class="table-responsive mb-2">
                        <table class="table table-sm table-bordered align-middle" id="scheduleTable">
                            <thead class="table-light">
                                <tr>
                                    <th style="width:40px">#</th>
                                    <th><?php echo xlt('Send on booking?'); ?></th>
                                    <th><?php echo xlt('Hours before appt.'); ?></th>
                                    <th style="width:70px"><?php echo xlt('Via WSP'); ?></th>
                                    <th style="width:70px"><?php echo xlt('Via Email'); ?></th>
                                    <th style="width:40px"></th>
                                </tr>
                            </thead>
                            <tbody id="scheduleBody">
                                <!-- Rows injected by loadFacilityConfig() -->
                            </tbody>
                        </table>
                        </div>
                        <button type="button" class="btn btn-sm btn-outline-success mb-3" onclick="addScheduleRow()">
                            <i class="fas fa-plus me-1"></i><?php echo xlt('Add notification slot'); ?>
                        </button>

                        <hr>
                        <h6><?php echo xlt('Message Templates'); ?></h6>
                        <p class="text-muted small mb-1">
                            <strong><?php echo xlt('Available tokens'); ?>:</strong><br>
                            <code>***NAME***</code> &mdash; <?php echo xlt('Patient full name'); ?><br>
                            <code>***PID***</code> &mdash; <?php echo xlt('Patient ID'); ?><br>
                            <code>***PROVIDER***</code> &mdash; <?php echo xlt('Provider name'); ?><br>
                            <code>***USER_PREFFIX***</code> &mdash; <?php echo xlt('Provider title / suffix (from Users)'); ?><br>
                            <code>***DATE***</code> &mdash; <?php echo xlt('Appointment date'); ?><br>
                            <code>***STARTTIME***</code> / <code>***ENDTIME***</code> &mdash; <?php echo xlt('Appointment start/end time'); ?><br>
                            <code>***TITLE***</code> / <code>***REASON***</code> &mdash; <?php echo xlt('Appointment title and reason'); ?><br>
                            <code>***FACILITY_NAME***</code> / <code>***FACILITY_ADDRESS***</code> /
                             <code>***FACILITY_PHONE***</code> / <code>***FACILITY_EMAIL***</code> /<br>
                             <code>***FACILITY_MAP_LINK***</code> / <code>***FACILITY_WEBSITE***</code>
                            &mdash; <?php echo xlt('Taken from the Facility record in OpenEMR / coordinates'); ?>
                        </p>
                        <div class="mb-3">
                            <label class="form-label"><?php echo xlt('WhatsApp message template'); ?></label>
                            <textarea name="wsp_message" id="cfgWspMsg" rows="5" class="form-control mono"></textarea>
                        </div>
                        <div class="mb-2">
                            <label class="form-label"><?php echo xlt('Email subject'); ?></label>
                            <input type="text" name="email_subject" id="cfgEmailSubject" class="form-control form-control-sm">
                        </div>
                        <div class="mb-3">
                            <label class="form-label"><?php echo xlt('Email message template'); ?></label>
                            <textarea name="email_message" id="cfgEmailMsg" rows="6" class="form-control mono"></textarea>
                        </div>

                        <div class="d-flex gap-2">
                            <button type="submit" class="btn btn-success btn-save">
                                <i class="fas fa-save me-1"></i><?php echo xlt('Save Configuration'); ?>
                            </button>
                            <button type="button" id="btnCancelConfig" class="btn btn-outline-secondary btn-cancel">
                                <i class="fas fa-times me-1"></i><?php echo xlt('Cancel'); ?>
                            </button>
                            <span id="cfgSaveMsg" class="align-self-center text-success" style="display:none;">
                                <i class="fas fa-check-circle"></i> <?php echo xlt('Saved!'); ?>
                            </span>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <?php else: ?>
        <div class="alert alert-warning"><?php echo xlt('Access to Facility Configuration requires administrator permissions.'); ?></div>
        <?php endif; ?>

    </div><!-- /tab-facility -->

</div><!-- /container-fluid -->

<script>
/* =========================================================================
   Dashboard — Chart.js
   ========================================================================= */
const moduleRoot = <?php echo js_escape($moduleRoot); ?>;
let chart = null;

function buildChart(stats) {
    const ctx = document.getElementById('chartNotifications').getContext('2d');
    // Collect unique dates
    const dates = [...new Set(stats.map(r => r.send_date))].sort();

    const wspData   = dates.map(d => { const r = stats.find(s => s.send_date === d && s.type === 'WSP');   return r ? parseInt(r.total) : 0; });
    const emailData = dates.map(d => { const r = stats.find(s => s.send_date === d && s.type === 'Email'); return r ? parseInt(r.total) : 0; });

    if (chart) chart.destroy();
    chart = new Chart(ctx, {
        type: 'bar',
        data: {
            labels: dates,
            datasets: [
                { label: 'WhatsApp', data: wspData,   backgroundColor: '#25D366CC', borderColor: '#128C7E', borderWidth: 1.5, borderRadius: 5 },
                { label: 'Email',    data: emailData, backgroundColor: '#2196F3CC', borderColor: '#1565C0', borderWidth: 1.5, borderRadius: 5 },
            ]
        },
        options: {
            responsive: true,
            plugins: { legend: { position: 'top' }, tooltip: { mode: 'index', intersect: false } },
            scales: { y: { beginAtZero: true, ticks: { precision: 0 } } }
        }
    });
}

function loadStats() {
    const from     = document.getElementById('statsFrom').value;
    const to       = document.getElementById('statsTo').value;
    const facilitySelect = document.getElementById('statsFacility');
    const facility = facilitySelect.value;
    
    // Check if selected facility is inactive
    const selectedOption = facilitySelect.options[facilitySelect.selectedIndex];
    const isInactive = selectedOption?.dataset.inactive === '1';
    const chartCanvas = document.getElementById('chartNotifications');
    
    if (isInactive) {
        chartCanvas.classList.add('chart-inactive');
    } else {
        chartCanvas.classList.remove('chart-inactive');
    }

    fetch(`${moduleRoot}/pages/ajax/get_stats.php?from=${encodeURIComponent(from)}&to=${encodeURIComponent(to)}&facility_id=${encodeURIComponent(facility)}`)
        .then(r => r.json())
        .then(data => buildChart(data.stats || []))
        .catch(e => console.error('Stats load error', e));
}

document.getElementById('btnLoadStats')?.addEventListener('click', loadStats);

/* =========================================================================
   Patient Status Tab
   ========================================================================= */
function renderStatus(status) {
    status = (status || 'pending').toLowerCase();
    let badgeClass = 'bg-secondary';
    let icon = 'fa-clock';
    let label = status;

    if (status === 'delivered' || status === 'read' || status === 'sent' || status === 'success') {
        badgeClass = 'bg-success';
        icon = 'fa-check-circle';
    } else if (status === 'error' || status === 'failed' || status === 'invalid' || status === 'unsent') {
        badgeClass = 'bg-danger';
        icon = 'fa-exclamation-triangle';
    } else if (status === 'in_progress' || status === 'pending' || status === 'queue') {
        badgeClass = 'bg-warning text-dark';
        icon = 'fa-spinner fa-spin';
    }

    return `<span class="badge ${badgeClass} d-inline-flex align-items-center gap-1 shadow-sm">
                <i class="fas ${icon}"></i> ${label.toUpperCase()}
            </span>`;
}

function searchPatients() {
    const q     = document.getElementById('patientSearch').value.trim();
    const from  = document.getElementById('patientFrom').value;
    const to    = document.getElementById('patientTo').value;
    const wsp   = document.getElementById('filterWsp').checked;
    const email = document.getElementById('filterEmail').checked;
    const tbody = document.getElementById('patientTableBody');

    // If both unchecked, show nothing or show both? Usually both.
    // But if they clicked some, we filter.
    let channel = '';
    if (wsp && !email) channel = 'WSP';
    else if (!wsp && email) channel = 'Email';
    else if (!wsp && !email) {
        tbody.innerHTML = '<tr><td colspan="7" class="text-center text-muted py-4"><?php echo js_escape(xlt('Please select at least one channel.')); ?></td></tr>';
        return;
    }

    tbody.innerHTML = '<tr><td colspan="7" class="text-center py-4"><i class="fas fa-spinner fa-spin fa-2x text-success"></i></td></tr>';

    const params = new URLSearchParams({
        q: q,
        from: from,
        to: to,
        channel: channel
    });

    fetch(`${moduleRoot}/pages/ajax/get_patient_logs.php?${params.toString()}`)
        .then(r => r.json())
        .then(data => {
            const rows = data.rows || [];
            if (!rows.length) {
                tbody.innerHTML = '<tr><td colspan="7" class="text-center text-muted py-4"><?php echo js_escape(xlt('No records found for the selected criteria.')); ?></td></tr>';
                return;
            }
            tbody.innerHTML = rows.map(r => {
                const typeIcon = r.type === 'WSP' 
                    ? '<i class="fab fa-whatsapp text-success fa-lg" title="WhatsApp"></i>' 
                    : '<i class="fas fa-envelope text-primary fa-lg" title="Email"></i>';
                
                const apptInfo = `<strong>${escHtml(r.pc_title || 'Appt')}</strong><br><small class="text-muted">${escHtml(r.pc_eventDate)} ${r.pc_startTime}</small>`;
                
                return `
                <tr>
                    <td>
                        <div class="fw-bold text-dark">${escHtml(r.fname + ' ' + r.lname)}</div>
                        <div class="small text-muted">PID: ${r.pid}</div>
                    </td>
                    <td>${escHtml(r.phone_cell || '-')}</td>
                    <td>${apptInfo}</td>
                    <td class="text-center">${typeIcon}</td>
                    <td><small class="text-muted">${escHtml(r.dSentDateTime || '')}</small></td>
                    <td>${renderStatus(r.status)}</td>
                    <td class="text-center">
                        <div class="btn-group">
                            <button class="btn btn-xs btn-outline-info" onclick="viewLogDetail(${r.iLogId})" title="<?php echo attr(xlt('View Details')); ?>">
                                <i class="fas fa-eye"></i>
                            </button>
                            <button class="btn btn-xs btn-outline-warning" onclick="syncStatus(${r.iLogId})" id="syncBtn_${r.iLogId}" title="<?php echo attr(xlt('Sync Status')); ?>">
                                <i class="fas fa-sync-alt"></i>
                            </button>
                            <button class="btn btn-xs btn-outline-secondary" onclick="resend(${r.iLogId})" title="<?php echo attr(xlt('Resend')); ?>">
                                <i class="fas fa-redo"></i>
                            </button>
                        </div>
                    </td>
                </tr>`;
            }).join('');
        })
        .catch(err => {
            tbody.innerHTML = `<tr><td colspan="7" class="text-center text-danger py-4">Error: ${err}</td></tr>`;
        });
}

function resend(logId) {
    if (!confirm('Resend this notification?')) return;
    fetch(`${moduleRoot}/pages/ajax/resend_notification.php?log_id=${logId}`, { method: 'POST' })
        .then(r => r.json())
        .then(d => {
            alert(d.message || 'Done');
            searchPatients();
        });
}

function syncStatus(logId) {
    const btn = document.getElementById(`syncBtn_${logId}`);
    const icon = btn.querySelector('i');
    
    icon.classList.add('fa-spin');
    btn.disabled = true;

    fetch(`${moduleRoot}/pages/ajax/sync_status.php?log_id=${logId}`)
        .then(r => r.json())
        .then(d => {
            if (d.success) {
                searchPatients(); // Refresh list to see new status
            } else {
                alert(d.message || 'Sync failed');
            }
        })
        .finally(() => {
            icon.classList.remove('fa-spin');
            btn.disabled = false;
        });
}

function viewLogDetail(logId) {
    const modal = new bootstrap.Modal(document.getElementById('modalLogDetail'));
    const container = document.getElementById('logHistoryTimeline');
    container.innerHTML = '<div class="text-center p-4"><i class="fas fa-spinner fa-spin fa-2x"></i></div>';
    modal.show();

    fetch(`${moduleRoot}/pages/ajax/get_status_history.php?log_id=${logId}`)
        .then(r => r.json())
        .then(data => {
            const history = data.history || [];
            if (!history.length) {
                container.innerHTML = '<div class="alert alert-info"><?php echo js_escape(xlt('No history recorded for this notification.')); ?></div>';
                return;
            }

            let html = '<div class="timeline-v2">';
            history.forEach(h => {
                const status = h.status.toLowerCase();
                let icon = 'fa-circle';
                let color = 'text-secondary';
                if (['delivered','read','sent','success'].includes(status)) { icon = 'fa-check-circle'; color = 'text-success'; }
                if (['error','failed','invalid','unsent'].includes(status)) { icon = 'fa-exclamation-circle'; color = 'text-danger'; }
                if (['queue','pending'].includes(status)) { icon = 'fa-clock'; color = 'text-warning'; }

                html += `
                <div class="d-flex gap-3 mb-3">
                    <div class="${color} pt-1"><i class="fas ${icon} fa-lg"></i></div>
                    <div>
                        <div class="fw-bold text-uppercase small">${escHtml(h.status)}</div>
                        <div class="text-muted extra-small">${escHtml(h.created_at)}</div>
                    </div>
                </div>`;
            });
            html += '</div>';
            container.innerHTML = html;
        })
        .catch(err => {
            container.innerHTML = `<div class="alert alert-danger">Error: ${err}</div>`;
        });
}

document.getElementById('btnSearch')?.addEventListener('click', searchPatients);
document.getElementById('patientSearch')?.addEventListener('keydown', e => { if (e.key === 'Enter') searchPatients(); });

/* =========================================================================
   Facility Configuration Tab
   ========================================================================= */
function loadFacilityConfig(facilityId) {
    fetch(`${moduleRoot}/pages/ajax/get_facility_config.php?facility_id=${encodeURIComponent(facilityId)}`)
        .then(r => r.json())
        .then(data => {
            const c = data.config || {};
            document.getElementById('facilityConfigCard').style.display = 'block';
            // Update lockout state
            const isInactive = parseInt(data.inactive ?? 0) === 1;
            const card       = document.getElementById('facilityConfigCard');
            const title      = document.getElementById('facilityConfigTitle');
            const form       = document.getElementById('facilityConfigForm');
            const warn       = document.getElementById('inactiveWarning');
            const saveBtn    = form.querySelector('button[type="submit"]');
            const cancelBtn  = document.getElementById('btnCancelConfig');
            
            title.textContent = data.facility_name || '';
            if (isInactive) {
                title.innerHTML += ` <span class="badge bg-danger ms-2 small" style="font-size:0.65em;">${<?php echo js_escape(xlt('INACTIVO')); ?>}</span>`;
                card.classList.add('facility-config-inactive');
                warn.style.display = 'block';
                // Recursive disable
                form.querySelectorAll('input, select, textarea, button').forEach(el => {
                    if (el !== cancelBtn) el.disabled = true;
                });
            } else {
                card.classList.remove('facility-config-inactive');
                warn.style.display = 'none';
                form.querySelectorAll('input, select, textarea, button').forEach(el => {
                    el.disabled = false;
                });
            }

            document.getElementById('cfgFacilityId').value      = facilityId;
            document.getElementById('cfgVendor').value          = c.vendor              || 'wasenderapi';
            document.getElementById('cfgInstance').value        = c.vendor_instance     || '';
            document.getElementById('cfgApiKey').value          = c.vendor_api_key      || '';
            document.getElementById('cfgWebhookSecret').value   = c.webhook_secret      || '';
            
            // Logo previews
            const prevWsp = document.getElementById('previewWsp');
            const nameWsp = document.getElementById('currentLogoWspName');
            if (c.logo_wsp) {
                nameWsp.textContent = c.logo_wsp;
                prevWsp.innerHTML = `<img src="${moduleRoot}/public/images/logo_wsp/${c.logo_wsp}" style="max-height:60px; border:1px solid #ddd; padding:2px;">`;
                prevWsp.style.display = 'block';
            } else {
                nameWsp.textContent = 'None';
                prevWsp.style.display = 'none';
            }

            const prevEmail = document.getElementById('previewEmail');
            const nameEmail = document.getElementById('currentLogoEmailName');
            if (c.logo_email) {
                nameEmail.textContent = c.logo_email;
                prevEmail.innerHTML = `<img src="${moduleRoot}/public/images/logo_email/${c.logo_email}" style="max-height:60px; border:1px solid #ddd; padding:2px;">`;
                prevEmail.style.display = 'block';
            } else {
                nameEmail.textContent = 'None';
                prevEmail.style.display = 'none';
            }

            document.getElementById('cfgLat').value             = c.latitude            || '';
            document.getElementById('cfgLon').value             = c.longitude           || '';
            
            // Initialize or update map
            initFacilityMap(c.latitude || -34.6037, c.longitude || -58.3816);
            document.getElementById('cfgEnabledWsp').checked    = parseInt(c.enabled_wsp   ?? 1) === 1;
            document.getElementById('cfgEnabledEmail').checked  = parseInt(c.enabled_email ?? 1) === 1;
            document.getElementById('cfgWspMsg').value          = c.wsp_message         || '';
            document.getElementById('cfgEmailSubject').value    = c.email_subject       || '';
            document.getElementById('cfgEmailMsg').value        = c.email_message       || '';
            document.getElementById('facilityConfigForm').style.display = 'block';


            // Load notification schedule slots
            const slots  = data.schedule || [];
            const tbody  = document.getElementById('scheduleBody');
            tbody.innerHTML = '';
            if (slots.length) {
                slots.forEach(s => appendScheduleRow(s));
            } else {
                // Default: one slot, 48h before, no on-booking
                appendScheduleRow({ seq: 1, hours_before: 48, send_on_booking: 0, enabled_wsp: 1, enabled_email: 1 });
            }

            // Map interactions
            if (isInactive && facilityMap) {
                facilityMap.dragging.disable();
                facilityMap.touchZoom.disable();
                facilityMap.doubleClickZoom.disable();
                facilityMap.scrollWheelZoom.disable();
                document.getElementById('facilityMap').style.opacity = '0.6';
            } else if (facilityMap) {
                facilityMap.dragging.enable();
                facilityMap.touchZoom.enable();
                facilityMap.doubleClickZoom.enable();
                facilityMap.scrollWheelZoom.enable();
                document.getElementById('facilityMap').style.opacity = '1';
            }
        });
}

document.getElementById('btnCancelConfig')?.addEventListener('click', function() {
    document.getElementById('facilityConfigCard').style.display = 'none';
    window.scrollTo({ top: 0, behavior: 'smooth' });
});

/* =========================================================================
   Notification Schedule helpers
   ========================================================================= */
let scheduleSeq = 0;

function appendScheduleRow(slot) {
    scheduleSeq++;
    const n   = scheduleSeq;
    const sob = parseInt(slot.send_on_booking ?? 0) === 1;
    const wsp = parseInt(slot.enabled_wsp     ?? 1) === 1;
    const em  = parseInt(slot.enabled_email   ?? 1) === 1;
    const h   = slot.hours_before ?? 48;

    const tr = document.createElement('tr');
    tr.dataset.seq = n;
    tr.innerHTML = `
        <td class="text-center fw-bold text-muted">${n}</td>
        <td class="text-center">
            <label class="custom-checkbox">
                <input class="sob-check" type="checkbox" name="schedule[${n}][send_on_booking]" value="1"
                       ${sob ? 'checked' : ''} onchange="toggleHoursCell(this)">
                <span class="slider"></span>
            </label>
        </td>
        <td>
            <input type="number" class="form-control form-control-sm hours-input" min="1" max="8760"
                   name="schedule[${n}][hours_before]" value="${h}"
                   ${sob ? 'disabled style="opacity:.4"' : ''}>
        </td>
        <td class="text-center">
            <label class="custom-checkbox">
                <input type="checkbox" name="schedule[${n}][enabled_wsp]" value="1" ${wsp ? 'checked' : ''}>
                <span class="slider"></span>
            </label>
        </td>
        <td class="text-center">
            <label class="custom-checkbox">
                <input type="checkbox" name="schedule[${n}][enabled_email]" value="1" ${em ? 'checked' : ''}>
                <span class="slider"></span>
            </label>
        </td>
        <td class="text-center">
            <button type="button" class="btn btn-xs btn-outline-danger" onclick="this.closest('tr').remove(); renumberSchedule();">
                <i class="fas fa-times"></i>
            </button>
        </td>`;
    document.getElementById('scheduleBody').appendChild(tr);
}

function addScheduleRow() {
    appendScheduleRow({ seq: scheduleSeq + 1, hours_before: 48, send_on_booking: 0, enabled_wsp: 1, enabled_email: 1 });
}

function toggleHoursCell(checkbox) {
    const input = checkbox.closest('tr').querySelector('.hours-input');
    input.disabled = checkbox.checked;
    input.style.opacity = checkbox.checked ? '.4' : '1';
}

function renumberSchedule() {
    document.querySelectorAll('#scheduleBody tr').forEach((tr, i) => {
        tr.cells[0].textContent = i + 1;
    });
}

document.getElementById('facilityConfigForm')?.addEventListener('submit', function(e) {
    e.preventDefault();
    const btn = e.target.querySelector('button[type="submit"]');
    const oldHtml = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i> Saving...';

    const fd = new FormData(this);
    fd.set('enabled_wsp',   document.getElementById('cfgEnabledWsp').checked   ? 1 : 0);
    fd.set('enabled_email', document.getElementById('cfgEnabledEmail').checked ? 1 : 0);

    // Collect schedule rows and append as JSON
    const scheduleRows = [];
    document.querySelectorAll('#scheduleBody tr').forEach((tr, i) => {
        const sob = tr.querySelector('.sob-check')?.checked ? 1 : 0;
        const h   = parseInt(tr.querySelector('.hours-input')?.value || 48);
        const wsp = tr.querySelector('input[name$="[enabled_wsp]"]')?.checked ? 1 : 0;
        const em  = tr.querySelector('input[name$="[enabled_email]"]')?.checked ? 1 : 0;
        scheduleRows.push({ seq: i + 1, hours_before: h, send_on_booking: sob, enabled_wsp: wsp, enabled_email: em });
    });
    fd.set('schedule_json', JSON.stringify(scheduleRows));

    fetch(`${moduleRoot}/pages/ajax/save_facility_config.php`, { method: 'POST', body: fd })
        .then(r => r.json())
        .then(d => {
            btn.disabled = false;
            btn.innerHTML = oldHtml;
            const msg = document.getElementById('cfgSaveMsg');
            msg.style.display = d.success ? 'inline' : 'none';
            if (!d.success) alert('Error saving: ' + (d.error || 'Unknown'));
            else {
                // Refresh to show new logo previews if uploaded
                loadFacilityConfig(document.getElementById('cfgFacilityId').value);
            }
            setTimeout(() => msg.style.display = 'none', 3000);
        })
        .catch(err => {
            btn.disabled = false;
            btn.innerHTML = oldHtml;
            alert('Error: ' + err);
        });
});

/* =========================================================================
   Utilities
   ========================================================================= */
function escHtml(s) {
    return String(s || '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

// Auto-load stats on dashboard tab
<?php if ($activeTab === 'dashboard'): ?>
loadStats();
<?php endif; ?>
/**
 * Leaflet Map Picker Initialization
 */
let facilityMap = null;
let facilityMarker = null;

function initFacilityMap(lat, lon) {
    lat = parseFloat(lat);
    lon = parseFloat(lon);

    if (!facilityMap) {
        facilityMap = L.map('facilityMap').setView([lat, lon], 13);
        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors'
        }).addTo(facilityMap);

        facilityMap.on('click', function(e) {
            // Block map updates if facility is inactive
            if (document.getElementById('inactiveWarning')?.style.display === 'block') return;
            updateMarker(e.latlng.lat, e.latlng.lng);
        });

        // Search button event
        document.getElementById('btnMapSearch').addEventListener('click', function() {
            const query = document.getElementById('mapSearchInput').value;
            if (!query) return;

            fetch(`https://nominatim.openstreetmap.org/search?format=json&q=${encodeURIComponent(query)}`)
                .then(r => r.json())
                .then(data => {
                    if (data && data.length > 0) {
                        const res = data[0];
                        updateMarker(res.lat, res.lon, true);
                    } else {
                        alert(<?php echo js_escape(xlt('Location not found')); ?>);
                    }
                });
        });

        // Input sync
        ['cfgLat', 'cfgLon'].forEach(id => {
            document.getElementById(id).addEventListener('change', () => {
                const newLat = document.getElementById('cfgLat').value;
                const newLon = document.getElementById('cfgLon').value;
                updateMarker(newLat, newLon, true);
            });
        });
    } else {
        facilityMap.setView([lat, lon], 13);
    }

    updateMarker(lat, lon);
    
    // Fix for Leaflet in tabs
    setTimeout(() => {
        facilityMap.invalidateSize();
    }, 200);
}

function updateMarker(lat, lon, center = false) {
    if (!lat || !lon) return;
    
    document.getElementById('cfgLat').value = parseFloat(lat).toFixed(6);
    document.getElementById('cfgLon').value = parseFloat(lon).toFixed(6);

    if (facilityMarker) {
        facilityMarker.setLatLng([lat, lon]);
    } else {
        facilityMarker = L.marker([lat, lon]).addTo(facilityMap);
    }

    if (center) {
        facilityMap.setView([lat, lon], 15);
    }
}

$(function() {
    // Initial load
    searchPatients();

    // Standard OpenEMR mouseover datepicker initialization
    // Required because datetime-picker assets can sometimes be finicky with static initialization
    $(document).on('mouseover', '.datepicker', function() {
        if (!$(this).data('initialized')) {
            const inputId = $(this).attr('id');
            $(this).datetimepicker({
                <?php $datetimepicker_timepicker = false; ?>
                <?php $datetimepicker_showseconds = false; ?>
                <?php $datetimepicker_formatInput = true; ?>
                <?php require($GLOBALS['srcdir'] . '/js/xl/jquery-datetimepicker-2-5-4.js.php'); ?>
                , onSelectDate: function() {
                    if (inputId === 'statsFrom' || inputId === 'statsTo') {
                        setTimeout(loadStats, 200);
                    } else {
                        setTimeout(searchPatients, 200);
                    }
                }
            });
            $(this).data('initialized', true);
        }
    });

    // Fallback/Standard initialization attempt
    if (typeof datetimepickerTranslated !== 'undefined') {
        datetimepickerTranslated('.datepicker', {
            timepicker: false,
            formatInput: true,
            onSelectDate: function() {
                const inputId = $(this).attr('id');
                if (inputId === 'statsFrom' || inputId === 'statsTo') {
                    setTimeout(loadStats, 200);
                } else {
                    setTimeout(searchPatients, 200);
                }
            }
        });
    }

    // Auto-search on typing (with debounce)
    let searchTimer;
    document.getElementById('patientSearch')?.addEventListener('input', function() {
        clearTimeout(searchTimer);
        searchTimer = setTimeout(searchPatients, 500);
    });
});
</script>

<!-- Modal: Notification Details / Timeline -->
<div class="modal fade" id="modalLogDetail" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header border-0 pb-0">
                <h5 class="modal-title fw-bold"><?php echo xlt('Notification Lifecycle'); ?></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body py-4">
                <div id="logHistoryTimeline">
                    <!-- Loaded via AJAX -->
                </div>
            </div>
            <div class="modal-footer border-0 pt-0">
                <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal"><?php echo xlt('Close'); ?></button>
            </div>
        </div>
    </div>
</div>

<style>
.extra-small { font-size: 0.75rem; }
.timeline-v2 { position: relative; padding-left: 5px; }
</style>

</body>
</html>
