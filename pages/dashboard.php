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
    <?php Header::setupHeader(['bootstrap', 'jquery', 'fontawesome']); ?>
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
    <style>
        body { background: #f5f7fa; }
        .module-header {
            background: linear-gradient(135deg, #25D366 0%, #128C7E 100%);
            color: #fff; padding: 20px 28px; border-radius: 0 0 12px 12px;
            margin-bottom: 26px;
        }
        .module-header h1 { font-size: 1.5rem; margin: 0; }
        .module-header p  { margin: 4px 0 0; opacity: .85; font-size: .9rem; }
        .stat-card {
            border: none; border-radius: 12px; padding: 20px 22px;
            box-shadow: 0 2px 12px rgba(0,0,0,.08);
            background: #fff; transition: transform .15s;
        }
        .stat-card:hover { transform: translateY(-2px); }
        .stat-card .number { font-size: 2.2rem; font-weight: 700; }
        .stat-card .label  { font-size: .82rem; color: #888; text-transform: uppercase; letter-spacing: .04em; }
        .stat-wsp   .number { color: #25D366; }
        .stat-email .number { color: #2196F3; }
        .stat-pending .number { color: #FF9800; }
        .stat-failed  .number { color: #F44336; }
        .nav-tabs .nav-link { color: #555; font-weight: 500; }
        .nav-tabs .nav-link.active { color: #128C7E; border-bottom: 3px solid #128C7E; }
        .chart-card {
            background: #fff; border-radius: 12px;
            box-shadow: 0 2px 12px rgba(0,0,0,.08); padding: 24px; margin-bottom: 24px;
        }
        .table-responsive { border-radius: 10px; overflow: hidden; box-shadow: 0 1px 8px rgba(0,0,0,.07); }
        .badge-status { font-size: .75rem; border-radius: 20px; padding: 3px 10px; }
        .badge-sent      { background: #e8f5e9; color: #388E3C; }
        .badge-delivered { background: #e3f2fd; color: #1976D2; }
        .badge-read      { background: #f3e5f5; color: #7B1FA2; }
        .badge-error     { background: #ffebee; color: #C62828; }
        .badge-pending   { background: #fff8e1; color: #F57F17; }
        .facility-card {
            background: #fff; border-radius: 10px; padding: 18px 20px;
            border-left: 4px solid #25D366; margin-bottom: 16px;
            box-shadow: 0 1px 6px rgba(0,0,0,.06); cursor: pointer;
        }
        .facility-card:hover { box-shadow: 0 3px 14px rgba(0,0,0,.12); }
        #facilityConfigForm { display:none; }
        textarea.mono { font-family: monospace; font-size: .88rem; }
    </style>
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
                    <input type="date" id="statsFrom" class="form-control form-control-sm" value="<?php echo attr($weekAgo); ?>">
                </div>
                <div class="col-auto">
                    <label class="form-label mb-1"><?php echo xlt('To'); ?></label>
                    <input type="date" id="statsTo" class="form-control form-control-sm" value="<?php echo attr($today); ?>">
                </div>
                <div class="col-auto">
                    <label class="form-label mb-1"><?php echo xlt('Facility'); ?></label>
                    <select id="statsFacility" class="form-select form-select-sm">
                        <option value=""><?php echo xlt('All facilities'); ?></option>
                        <?php foreach ($facilities as $f): ?>
                        <option value="<?php echo attr($f['facility_id']); ?>">
                            <?php echo text($f['facility_name']); ?>
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
            <div class="row align-items-end mb-3 g-2">
                <div class="col-md-5">
                    <input type="text" id="patientSearch" class="form-control"
                           placeholder="<?php echo attr(xlt('Search by name, surname or PID')); ?>">
                </div>
                <div class="col-auto">
                    <button id="btnSearch" class="btn btn-success">
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
                            <th><?php echo xlt('Appt. Date'); ?></th>
                            <th><?php echo xlt('Type'); ?></th>
                            <th><?php echo xlt('Sent'); ?></th>
                            <th><?php echo xlt('Status'); ?></th>
                            <th><?php echo xlt('Actions'); ?></th>
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
                <?php foreach ($facilities as $f): ?>
                <div class="facility-card" onclick="loadFacilityConfig(<?php echo attr_js((string)$f['facility_id']); ?>)">
                    <strong><?php echo text($f['facility_name']); ?></strong><br>
                    <small class="text-muted"><?php echo text($f['facility_address'] ?? ''); ?></small><br>
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
                    <form id="facilityConfigForm">
                        <input type="hidden" id="cfgFacilityId" name="facility_id">

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
                            <div class="col-md-6">
                                <label class="form-label"><?php echo xlt('WSP Logo URL'); ?></label>
                                <input type="url" name="logo_wsp" id="cfgLogoWsp" class="form-control form-control-sm"
                                       placeholder="https://your-site/logo_wsp.png">
                            </div>
                        </div>

                        <hr>
                        <h6 class="text-primary"><?php echo xlt('Email'); ?></h6>
                        <div class="row g-2 mb-3">
                            <div class="col-md-8">
                                <label class="form-label"><?php echo xlt('Email Logo (server path)'); ?></label>
                                <input type="text" name="logo_email" id="cfgLogoEmail" class="form-control form-control-sm"
                                       placeholder="/var/www/html/openemr/modules/wsp-email/public/logo_email.png">
                            </div>
                            <div class="col-md-12 text-muted small">
                                <i class="fas fa-info-circle me-1"></i><?php echo xlt('Maps: used to generate Google Maps / OSM links in messages.'); ?>
                            </div>
                        </div>

                        <hr>
                        <h6 class="text-secondary"><?php echo xlt('Facility Details'); ?></h6>
                        <div class="row g-2 mb-3">
                            <div class="col-md-6">
                                <label class="form-label"><?php echo xlt('Website URL'); ?></label>
                                <input type="url" name="website_url" id="cfgWebsite" class="form-control form-control-sm">
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
                                <div class="form-check form-switch">
                                    <input class="form-check-input" type="checkbox" name="enabled_wsp" id="cfgEnabledWsp" value="1" checked>
                                    <label class="form-check-label" for="cfgEnabledWsp"><?php echo xlt('WhatsApp enabled'); ?></label>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="form-check form-switch">
                                    <input class="form-check-input" type="checkbox" name="enabled_email" id="cfgEnabledEmail" value="1" checked>
                                    <label class="form-check-label" for="cfgEnabledEmail"><?php echo xlt('Email enabled'); ?></label>
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
                            <code>***PROVIDER***</code> &mdash; <?php echo xlt('Provider name'); ?><br>
                            <code>***USER_PREFFIX***</code> &mdash; <?php echo xlt('Provider title / suffix (from Users)'); ?><br>
                            <code>***DATE***</code> &mdash; <?php echo xlt('Appointment date'); ?><br>
                            <code>***STARTTIME***</code> / <code>***ENDTIME***</code> &mdash; <?php echo xlt('Appointment start/end time'); ?><br>
                            <code>***FACILITY_NAME***</code> / <code>***FACILITY_ADDRESS***</code> /
                             <code>***FACILITY_PHONE***</code> / <code>***FACILITY_EMAIL***</code> / <code>***FACILITY_MAP_LINK***</code>
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
                            <button type="submit" class="btn btn-success">
                                <i class="fas fa-save me-1"></i><?php echo xlt('Save Configuration'); ?>
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
    const facility = document.getElementById('statsFacility').value;
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
    const map = { sent: 'sent', DELIVERED: 'delivered', READ: 'read', error: 'error', in_progress: 'pending', '': 'pending' };
    const cls = map[status] || 'pending';
    const lbl = status || 'pending';
    return `<span class="badge-status badge-${cls}">${lbl}</span>`;
}

function searchPatients() {
    const q   = document.getElementById('patientSearch').value.trim();
    const tbody = document.getElementById('patientTableBody');
    if (!q) { tbody.innerHTML = '<tr><td colspan="7" class="text-center text-muted py-4">Enter a search term above.</td></tr>'; return; }

    tbody.innerHTML = '<tr><td colspan="7" class="text-center py-3"><i class="fas fa-spinner fa-spin"></i></td></tr>';
    fetch(`${moduleRoot}/pages/ajax/get_patient_logs.php?q=${encodeURIComponent(q)}`)
        .then(r => r.json())
        .then(data => {
            const rows = data.rows || [];
            if (!rows.length) { tbody.innerHTML = '<tr><td colspan="7" class="text-center text-muted py-4">No records found.</td></tr>'; return; }
            tbody.innerHTML = rows.map(r => `
                <tr>
                    <td>${escHtml(r.fname + ' ' + r.lname)}</td>
                    <td>${escHtml(r.phone_cell || '')}</td>
                    <td>${escHtml(r.pc_eventDate || '')}</td>
                    <td><span class="badge ${r.type==='WSP' ? 'bg-success' : 'bg-primary'}">${escHtml(r.type)}</span></td>
                    <td><small>${escHtml(r.dSentDateTime || '')}</small></td>
                    <td>${renderStatus(r.status)}</td>
                    <td>
                        <button class="btn btn-xs btn-outline-secondary" onclick="resend(${r.iLogId})">
                            <i class="fas fa-redo"></i>
                        </button>
                    </td>
                </tr>`).join('');
        });
}

function resend(logId) {
    if (!confirm('Resend this notification?')) return;
    fetch(`${moduleRoot}/pages/ajax/resend_notification.php?log_id=${logId}`, { method: 'POST' })
        .then(r => r.json())
        .then(d => alert(d.message || 'Done'));
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
            document.getElementById('facilityConfigTitle').textContent = data.facility_name || '';
            document.getElementById('cfgFacilityId').value      = facilityId;
            document.getElementById('cfgVendor').value          = c.vendor              || 'wasenderapi';
            document.getElementById('cfgInstance').value        = c.vendor_instance     || '';
            document.getElementById('cfgApiKey').value          = c.vendor_api_key      || '';
            document.getElementById('cfgWebhookSecret').value   = c.webhook_secret      || '';
            document.getElementById('cfgLogoWsp').value         = c.logo_wsp            || '';
            document.getElementById('cfgLogoEmail').value       = c.logo_email          || '';
            document.getElementById('cfgWebsite').value         = c.website_url         || '';
            document.getElementById('cfgLat').value             = c.latitude            || '';
            document.getElementById('cfgLon').value             = c.longitude           || '';
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
        });
}

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
            <div class="form-check form-switch d-flex justify-content-center">
                <input class="form-check-input sob-check" type="checkbox" name="schedule[${n}][send_on_booking]" value="1"
                       ${sob ? 'checked' : ''} onchange="toggleHoursCell(this)">
            </div>
        </td>
        <td>
            <input type="number" class="form-control form-control-sm hours-input" min="1" max="8760"
                   name="schedule[${n}][hours_before]" value="${h}"
                   ${sob ? 'disabled style="opacity:.4"' : ''}>
        </td>
        <td class="text-center">
            <input class="form-check-input" type="checkbox" name="schedule[${n}][enabled_wsp]" value="1" ${wsp ? 'checked' : ''}>
        </td>
        <td class="text-center">
            <input class="form-check-input" type="checkbox" name="schedule[${n}][enabled_email]" value="1" ${em ? 'checked' : ''}>
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
            const msg = document.getElementById('cfgSaveMsg');
            msg.style.display = d.success ? 'inline' : 'none';
            if (!d.success) alert('Error saving: ' + (d.error || 'Unknown'));
            setTimeout(() => msg.style.display = 'none', 3000);
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
</script>
</body>
</html>
