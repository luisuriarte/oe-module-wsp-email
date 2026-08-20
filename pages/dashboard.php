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
use OpenEMR\Modules\WspEmail\CsrfCompat;
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

// Fetch providers for the recall entry modal
$providers = [];
$provRes = sqlStatement("SELECT id, lname, fname, suffix FROM users WHERE authorized = 1 AND active = 1 ORDER BY lname, fname");
while ($pRow = sqlFetchArray($provRes)) {
    $providers[] = $pRow;
}
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
    <script src="<?php echo $moduleRoot; ?>/public/js/listOptionsManager.js"></script>
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
            <a class="nav-link <?php echo $activeTab === 'schedules' ? 'active' : ''; ?>" href="?tab=schedules">
                <i class="fas fa-calendar-alt me-1"></i><?php echo xlt('Schedules'); ?>
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link <?php echo in_array($activeTab, ['facility','config']) ? 'active' : ''; ?>" href="?tab=facility">
                <i class="fas fa-hospital me-1"></i><?php echo xlt('Facilities'); ?>
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link <?php echo $activeTab === 'recalls' ? 'active' : ''; ?>" href="?tab=recalls">
                <i class="fas fa-redo-alt me-1"></i><?php echo xlt('Recalls'); ?>
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link <?php echo $activeTab === 'blacklist' ? 'active' : ''; ?>" href="?tab=blacklist">
                <i class="fas fa-ban me-1"></i><?php echo xlt('Blacklist'); ?>
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link <?php echo $activeTab === 'catalogs' ? 'active' : ''; ?>" href="?tab=catalogs">
                <i class="fas fa-list me-1"></i><?php echo xlt('Catalogs'); ?>
            </a>
        </li>
    </ul>

    <!-- ===================================================================
         TAB: DASHBOARD
    ==================================================================== -->
    <div id="tab-dashboard" class="<?php echo $activeTab === 'dashboard' ? '' : 'd-none'; ?>">

        <!-- Summary cards -->
        <div class="row g-3 mb-4">
            <div class="col-3">
                <div class="stat-card stat-wsp">
                    <div class="number"><?php echo (int)($totals['total_wsp'] ?? 0); ?></div>
                    <div class="label"><?php echo xlt('WhatsApp sent'); ?><br><small><?php echo xlt('last 7 days'); ?></small></div>
                </div>
            </div>
            <div class="col-3">
                <div class="stat-card stat-email">
                    <div class="number"><?php echo (int)($totals['total_email'] ?? 0); ?></div>
                    <div class="label"><?php echo xlt('Emails sent'); ?><br><small><?php echo xlt('last 7 days'); ?></small></div>
                </div>
            </div>
            <div class="col-3">
                <div class="stat-card stat-pending">
                    <div class="number"><?php echo (int)($totals['pending'] ?? 0); ?></div>
                    <div class="label"><?php echo xlt('Pending'); ?></div>
                </div>
            </div>
            <div class="col-3">
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
                <div class="col-auto">
                    <button id="btnPdfReport" class="btn btn-sm btn-danger">
                        <i class="fas fa-file-pdf me-1"></i><?php echo xlt('PDF Report'); ?>
                    </button>
                </div>
            </div>

            <!-- Status filters for chart -->
            <div class="mb-3 pb-2 border-bottom">
                <label class="form-label small mb-2">
                    <i class="fas fa-filter me-1"></i><?php echo xlt('Filter by Status:'); ?>
                </label>
                <div id="chartStatusFilters" class="d-flex flex-wrap gap-2">
                    <!-- Checkboxes rendered by JavaScript -->
                </div>
            </div>

            <canvas id="chartNotifications" height="90"></canvas>
        </div>

        <!-- Send Now -->
        <div class="chart-card mt-4">
            <div class="d-flex align-items-center gap-3">
                <button id="btnSendNow" class="btn btn-primary">
                    <i class="fas fa-paper-plane me-1"></i><?php echo xlt('Send Now'); ?>
                </button>
                <small class="text-muted"><?php echo xlt('Runs WSP and Email immediately, respecting the schedule.'); ?></small>
            </div>
            <div id="sendNowLog" class="mt-3" style="display:none">
                <div class="d-flex align-items-center gap-2 mb-2">
                    <strong class="small"><?php echo xlt('Log'); ?></strong>
                    <button type="button" class="btn btn-xs btn-outline-secondary" onclick="toggleSendNowLog()">
                        <i class="fas fa-eye-slash"></i>
                    </button>
                </div>
                <pre class="bg-dark text-light p-3 rounded" style="max-height:400px;overflow:auto;font-size:11px;line-height:1.3" id="sendNowLogContent"></pre>
            </div>
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
                    <div class="d-flex gap-4 pt-1 align-items-center flex-wrap">
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
                        <div class="d-flex align-items-center gap-2">
                            <label class="custom-checkbox">
                                <input type="checkbox" id="filterSms" onchange="searchPatients()">
                                <span class="slider"></span>
                            </label>
                            <span class="small">SMS</span>
                        </div>
                        <div class="d-flex align-items-center gap-2">
                            <label class="custom-checkbox">
                                <input type="checkbox" id="filterVoz" onchange="searchPatients()">
                                <span class="slider"></span>
                            </label>
                            <span class="small">Voz</span>
                        </div>
                    </div>
                </div>
                <div class="col-md-2">
                    <label class="form-label small mb-1"><?php echo xlt('Status'); ?></label>
                    <select id="filterStatus" class="form-select form-select-sm" onchange="searchPatients()">
                        <option value=""><?php echo xlt('All Status'); ?></option>
                        <option value="QUEUED"><?php echo xlt('Queued'); ?></option>
                        <option value="SENT"><?php echo xlt('Sent'); ?></option>
                        <option value="DELIVERED"><?php echo xlt('Delivered'); ?></option>
                        <option value="READ"><?php echo xlt('Read'); ?></option>
                        <option value="FAILED"><?php echo xlt('Failed'); ?></option>
                        <option value="INVALID"><?php echo xlt('Invalid'); ?></option>
                        <option value="ERROR"><?php echo xlt('Error'); ?></option>
                        <option value="UNSENT"><?php echo xlt('Unsent'); ?></option>
                    </select>
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
                        <tr><td colspan="8" class="text-center text-muted py-4"><?php echo xlt('Enter a search term above.'); ?></td></tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div><!-- /tab-patients -->

    <!-- ===================================================================
         TAB: SCHEDULES (Manual Notifications)
    ==================================================================== -->
    <div id="tab-schedules" class="<?php echo $activeTab === 'schedules' ? '' : 'd-none'; ?>">
        <div class="chart-card">
            <h5 class="mb-3"><i class="fas fa-calendar-alt me-2 text-success"></i><?php echo xlt('Upcoming Appointments - Manual Notifications'); ?></h5>
            
            <!-- Filters -->
            <div class="row g-3 mb-3">
                <div class="col-md-2">
                    <label class="form-label small mb-1"><?php echo xlt('From Date'); ?></label>
                    <input type="text" id="schedFromDate" class="form-control form-control-sm datepicker" value="<?php echo attr(oeFormatShortDate(date('Y-m-d', strtotime('-7 days')))); ?>">
                </div>
                <div class="col-md-2">
                    <label class="form-label small mb-1"><?php echo xlt('To Date'); ?></label>
                    <input type="text" id="schedToDate" class="form-control form-control-sm datepicker" value="<?php echo attr(oeFormatShortDate(date('Y-m-d', strtotime('+30 days')))); ?>">
                </div>
                <div class="col-md-2">
                    <label class="form-label small mb-1"><?php echo xlt('Patient'); ?></label>
                    <input type="text" id="schedPatient" class="form-control form-control-sm" placeholder="<?php echo attr(xlt('Name...')); ?>">
                </div>
                <div class="col-md-2">
                    <label class="form-label small mb-1"><?php echo xlt('Appt Status'); ?></label>
                    <select id="schedStatus" class="form-select form-select-sm">
                        <option value=""><?php echo xlt('All Statuses'); ?></option>
                        <!-- Options loaded dynamically from list_options.apptstat -->
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label small mb-1"><?php echo xlt('Facility'); ?></label>
                    <select id="schedFacility" class="form-select form-select-sm">
                        <option value=""><?php echo xlt('All Facilities'); ?></option>
                        <?php foreach ($facilities as $sf): ?>
                        <option value="<?php echo attr((string)$sf['facility_id']); ?>"><?php echo text($sf['facility_name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2 d-flex align-items-end">
                    <button id="btnLoadSchedules" class="btn btn-sm btn-success w-100">
                        <i class="fas fa-search me-1"></i><?php echo xlt('Load'); ?>
                    </button>
                </div>
            </div>

            <!-- Table -->
            <div class="table-responsive">
                <table class="table table-sm table-hover mb-0" id="schedulesTable">
                    <thead class="table-light">
                        <tr>
                            <th><?php echo xlt('Date/Time'); ?></th>
                            <th><?php echo xlt('Patient'); ?></th>
                            <th><?php echo xlt('Provider'); ?></th>
                            <th><?php echo xlt('Type'); ?></th>
                            <th><?php echo xlt('Status'); ?></th>
                            <th class="text-center"><?php echo xlt('Pt. Actions'); ?></th>
                            <th class="text-center"><?php echo xlt('Prov. Actions'); ?></th>
                        </tr>
                    </thead>
                    <tbody id="schedulesTableBody">
                        <tr><td colspan="7" class="text-center text-muted py-4"><?php echo xlt('Click Load to fetch appointments.'); ?></td></tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div><!-- /tab-schedules -->

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

                    <?php
                    $vendorBadgeColor = 'bg-success';
                    $vendor = $f['current_vendor'] ?? $f['vendor'] ?? '';
                    if ($vendor === 'evolution-go') $vendorBadgeColor = 'bg-secondary';
                    elseif ($vendor === 'openwa') $vendorBadgeColor = 'bg-warning';
                    elseif ($vendor === 'wasenderapi') $vendorBadgeColor = 'bg-info';
                    elseif ($vendor === 'ultramsg') $vendorBadgeColor = 'bg-primary';
                    elseif ($vendor === 'httpsms') $vendorBadgeColor = 'bg-dark';
                    ?>
                    <?php if (!empty($vendor)): ?>
                    <span class="badge <?php echo $vendorBadgeColor; ?> mt-1"><?php echo text($vendor); ?></span>
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

                        <div id="facility-subtab-config">

                        <!-- Vendor settings -->
                        <h6 class="text-success"><?php echo xlt('WhatsApp Gateway'); ?></h6>

                        <!-- Active Vendor Selector -->
                        <div class="mb-3">
                            <label class="form-label"><?php echo xlt('Select Vendor'); ?></label>
                            <select name="current_vendor" id="cfgCurrentVendor" class="form-select form-select-sm" onchange="handleVendorChange()">
                                <option value="ultramsg">UltraMsg</option>
                                <option value="wasenderapi">WaSenderAPI</option>
                                <option value="openwa">OpenWA</option>
                                <option value="evolution-go">Evolution-Go</option>
                                <option value="httpsms">HttpSMS (SMS)</option>
                            </select>
                            <small class="text-muted"><?php echo xlt('Active vendor for sending WhatsApp messages'); ?></small>
                        </div>

                        <hr>

                        <!-- UltraMsg Configuration -->
                        <div id="ultramsgConfig">
                            <h6 class="text-primary"><?php echo xlt('UltraMsg Credentials'); ?></h6>
                            <div class="row g-2 mb-3">
                                <div class="col-md-6">
                                    <label class="form-label"><?php echo xlt('Instance ID'); ?></label>
                                    <input type="text" name="ultramsg_instance" id="cfgUltraInstance" class="form-control form-control-sm" autocomplete="off" placeholder="e.g., instance41076">
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label"><?php echo xlt('API Token'); ?></label>
                                    <div class="input-group input-group-sm">
                                        <input type="password" name="ultramsg_api_key" id="cfgUltraApiKey" class="form-control" autocomplete="off" placeholder="Leave blank to keep existing">
                                        <button type="button" class="btn btn-outline-secondary" onclick="toggleUltraApiKey()" title="Show/Hide API Key">
                                            <i class="fas fa-eye" id="ultraApiKeyIcon"></i>
                                        </button>
                                    </div>
                                    <small class="text-muted" id="ultraApiKeyHint" style="display:none;">Current API key is set</small>
                                </div>
                            </div>
                        </div>

                        <hr>

                        <!-- WaSenderAPI Configuration -->
                        <div id="wasenderConfig">
                            <h6 class="text-info"><?php echo xlt('WaSenderAPI Credentials'); ?></h6>
                            <div class="row g-2 mb-3">
                                <div class="col-md-6">
                                    <label class="form-label"><?php echo xlt('API Key / Token'); ?></label>
                                    <div class="input-group input-group-sm">
                                        <input type="password" name="wasenderapi_api_key" id="cfgWaApiKey" class="form-control" autocomplete="off" placeholder="Leave blank to keep existing">
                                        <button type="button" class="btn btn-outline-secondary" onclick="toggleWaApiKey()" title="Show/Hide API Key">
                                            <i class="fas fa-eye" id="waApiKeyIcon"></i>
                                        </button>
                                    </div>
                                    <small class="text-muted" id="waApiKeyHint" style="display:none;">Current API key is set</small>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label"><?php echo xlt('Webhook Secret'); ?></label>
                                    <div class="input-group input-group-sm">
                                        <input type="password" name="wasenderapi_webhook_secret" id="cfgWaWebhook" class="form-control" autocomplete="off" placeholder="Leave blank to keep existing">
                                        <button type="button" class="btn btn-outline-secondary" onclick="toggleWaWebhook()" title="Show/Hide Webhook Secret">
                                            <i class="fas fa-eye" id="waWebhookIcon"></i>
                                        </button>
                                    </div>
                                    <small class="text-muted" id="waWebhookHint" style="display:none;">Current webhook secret is set</small>
                                </div>
                            </div>
                        </div>

                        <hr>

                        <!-- OpenWA Configuration -->
                        <div id="openwaConfig" style="display:none;">
                            <h6 class="text-warning"><?php echo xlt('OpenWA Credentials'); ?></h6>
                            <div class="row g-2 mb-3">
                                <div class="col-md-4">
                                    <label class="form-label"><?php echo xlt('Session ID (Instance)'); ?></label>
                                    <input type="text" name="openwa_instance" id="cfgOwaInstance" class="form-control form-control-sm" autocomplete="off" placeholder="e.g., session1">
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label"><?php echo xlt('API Key'); ?></label>
                                    <div class="input-group input-group-sm">
                                        <input type="password" name="openwa_api_key" id="cfgOwaApiKey" class="form-control" autocomplete="off" placeholder="Leave blank to keep existing">
                                        <button type="button" class="btn btn-outline-secondary" onclick="toggleOwaApiKey()" title="Show/Hide API Key">
                                            <i class="fas fa-eye" id="owaApiKeyIcon"></i>
                                        </button>
                                    </div>
                                    <small class="text-muted" id="owaApiKeyHint" style="display:none;">Current API key is set</small>
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label"><?php echo xlt('Webhook Secret'); ?></label>
                                    <div class="input-group input-group-sm">
                                        <input type="password" name="openwa_webhook_secret" id="cfgOwaWebhook" class="form-control" autocomplete="off" placeholder="Leave blank to keep existing">
                                        <button type="button" class="btn btn-outline-secondary" onclick="toggleOwaWebhook()" title="Show/Hide Webhook Secret">
                                            <i class="fas fa-eye" id="owaWebhookIcon"></i>
                                        </button>
                                    </div>
                                    <small class="text-muted" id="owaWebhookHint" style="display:none;">Current webhook secret is set</small>
                                </div>
                            </div>
                        </div>

                        <hr>

                        <!-- Evolution-Go Configuration -->
                        <div id="evolutionGoConfig" style="display:none;">
                            <h6 class="text-secondary"><?php echo xlt('Evolution-Go Credentials'); ?></h6>
                            <div class="row g-2 mb-3">
                                <div class="col-md-6">
                                    <label class="form-label"><?php echo xlt('Base URL'); ?></label>
                                    <input type="text" name="evolution_go_base_url" id="cfgEvoBaseUrl" class="form-control form-control-sm" autocomplete="off" placeholder="e.g., https://api.evolution-go.com">
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label"><?php echo xlt('Instance Name'); ?></label>
                                    <input type="text" name="evolution_go_instance_name" id="cfgEvoInstanceName" class="form-control form-control-sm" autocomplete="off" placeholder="e.g., my-instance">
                                </div>
                            </div>
                            <div class="row g-2 mb-3">
                                <div class="col-md-6">
                                    <label class="form-label"><?php echo xlt('API Key'); ?></label>
                                    <div class="input-group input-group-sm">
                                        <input type="password" name="evolution_go_api_key" id="cfgEvoApiKey" class="form-control" autocomplete="off" placeholder="Leave blank to keep existing">
                                        <button type="button" class="btn btn-outline-secondary" onclick="toggleEvoApiKey()" title="Show/Hide API Key">
                                            <i class="fas fa-eye" id="evoApiKeyIcon"></i>
                                        </button>
                                    </div>
                                    <small class="text-muted" id="evoApiKeyHint" style="display:none;">Current API key is set</small>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label"><?php echo xlt('Webhook Secret'); ?></label>
                                    <div class="input-group input-group-sm">
                                        <input type="password" name="evolution_go_webhook_secret" id="cfgEvoWebhook" class="form-control" autocomplete="off" placeholder="Leave blank to keep existing">
                                        <button type="button" class="btn btn-outline-secondary" onclick="toggleEvoWebhook()" title="Show/Hide Webhook Secret">
                                            <i class="fas fa-eye" id="evoWebhookIcon"></i>
                                        </button>
                                    </div>
                                    <small class="text-muted" id="evoWebhookHint" style="display:none;">Current webhook secret is set</small>
                                </div>
                            </div>
                            <div class="row g-2 mb-3">
                                <div class="col-md-12">
                                    <label class="form-label"><?php echo xlt('Webhook URL (configure in Evolution-Go dashboard)'); ?></label>
                                    <input type="text" class="form-control form-control-sm bg-light" readonly
                                           value="<?php echo attr($GLOBALS['webroot']); ?>/webhook/evolution-go/webhook.php"
                                           onclick="this.select()">
                                    <small class="text-muted"><?php echo xlt('Copy this URL to your Evolution-Go instance webhook settings.'); ?></small>
                                </div>
                            </div>
                        </div>

                        <hr>

                        <!-- HttpSMS Configuration -->
                        <div id="httpsmsConfig" style="display:none;">
                            <h6 class="text-dark"><i class="fas fa-sms me-1"></i><?php echo xlt('HttpSMS Credentials (SMS Gateway)'); ?></h6>
                            <div class="alert alert-info py-2 mb-3" style="font-size:.85em;">
                                <i class="fas fa-info-circle me-1"></i>
                                <?php echo xlt('HttpSMS converts your Android phone into an SMS gateway. Configure the webhook URL in your HttpSMS dashboard.'); ?>
                                <br><strong><?php echo xlt('Note:'); ?></strong> <?php echo xlt('SMS sends text only (no images, no calendar files).'); ?>
                            </div>
                            <div class="row g-2 mb-3">
                                <div class="col-md-6">
                                    <label class="form-label"><?php echo xlt('Server URL'); ?></label>
                                    <input type="text" name="httpsms_base_url" id="cfgHttpsmsBaseUrl" class="form-control form-control-sm" autocomplete="off"
                                           placeholder="e.g., https://sms.origen.ar" value="https://sms.origen.ar">
                                    <small class="text-muted"><?php echo xlt('URL of your HttpSMS server instance'); ?></small>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label"><?php echo xlt('From Number (Android phone)'); ?></label>
                                    <input type="text" name="httpsms_from_number" id="cfgHttpsmsFromNumber" class="form-control form-control-sm" autocomplete="off"
                                           placeholder="e.g., +5491155667788">
                                    <small class="text-muted"><?php echo xlt('International format with + prefix'); ?></small>
                                </div>
                            </div>
                            <div class="row g-2 mb-3">
                                <div class="col-md-6">
                                    <label class="form-label"><?php echo xlt('API Key'); ?></label>
                                    <div class="input-group input-group-sm">
                                        <input type="password" name="httpsms_api_key" id="cfgHttpsmsApiKey" class="form-control" autocomplete="off" placeholder="Leave blank to keep existing">
                                        <button type="button" class="btn btn-outline-secondary" onclick="toggleHttpsmsApiKey()" title="Show/Hide API Key">
                                            <i class="fas fa-eye" id="httpsmsApiKeyIcon"></i>
                                        </button>
                                    </div>
                                    <small class="text-muted" id="httpsmsApiKeyHint" style="display:none;">Current API key is set</small>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label"><?php echo xlt('Webhook Key'); ?> <small class="text-muted">(<?php echo xlt('optional'); ?>)</small></label>
                                    <div class="input-group input-group-sm">
                                        <input type="password" name="httpsms_signing_key" id="cfgHttpsmsSigningKey" class="form-control" autocomplete="off" placeholder="Leave blank to keep existing">
                                        <button type="button" class="btn btn-outline-secondary" onclick="toggleHttpsmsSigningKey()" title="Show/Hide Webhook Key">
                                            <i class="fas fa-eye" id="httpsmsSigningKeyIcon"></i>
                                        </button>
                                    </div>
                                    <small class="text-muted" id="httpsmsSigningKeyHint" style="display:none;">Current webhook key is set</small>
                                </div>
                            </div>
                            <div class="row g-2 mb-3">
                                <div class="col-md-12">
                                    <label class="form-label"><?php echo xlt('Webhook URL (configure in HttpSMS dashboard)'); ?></label>
                                    <input type="text" class="form-control form-control-sm bg-light" readonly
                                           value="<?php echo attr($GLOBALS['webroot']); ?>/webhook/httpsms/webhook.php"
                                           onclick="this.select()">
                                    <small class="text-muted"><?php echo xlt('Copy this URL to your HttpSMS dashboard → Settings → Webhook URL.'); ?></small>
                                </div>
                            </div>
                        </div>

                        <hr>

                        <!-- Logos Section -->
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

                        <!-- WSP Logo (separate row) -->
                        <div class="row g-2 mb-3">
                            <div class="col-md-6" id="logoWspContainer">
                                <label class="form-label"><?php echo xlt('WSP Logo'); ?> <small class="text-muted">(Logo Actual: <span id="currentLogoWspName">None</span>)</small></label>
                                <input type="file" name="logo_wsp" id="cfgLogoWsp" class="form-control form-control-sm" accept="image/*">
                                <div id="previewWsp" class="mt-2" style="max-height: 100px; display: none;"></div>
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
                            <div class="col-md-4">
                                <div class="d-flex align-items-center gap-2">
                                    <label class="custom-checkbox">
                                        <input type="checkbox" name="notify_cancelled" id="cfgNotifyCancelled" value="1">
                                        <span class="slider"></span>
                                    </label>
                                    <label class="form-label mb-0" for="cfgNotifyCancelled"><?php echo xlt('Notify on cancellation'); ?></label>
                                </div>
                            </div>
                        </div>

                         <hr>
                         <h6><?php echo xlt('Notification Sending Window'); ?></h6>
                         <div class="row g-2 mb-3 align-items-center">
                             <!-- Weekdays -->
                             <div class="col-md-4">
                                 <span class="fw-bold"><?php echo xlt('Monday – Friday'); ?></span>
                             </div>
                             <div class="col-md-4">
                                 <div class="d-flex align-items-center gap-2">
                                     <label class="form-label mb-0"><?php echo xlt('Start'); ?></label>
                                     <select name="send_weekday_start" id="cfgSendWeekdayStart" class="form-select form-select-sm">
                                         <?php for ($h = 0; $h < 24; $h++):
                                             $formatted = sprintf('%02d:00', $h);
                                             $ampm = ($h === 0) ? '12:00 AM' : (($h === 12) ? '12:00 PM' : (($h > 12) ? ($h - 12) . ':00 PM' : $h . ':00 AM'));
                                         ?>
                                             <option value="<?php echo $h; ?>"><?php echo "$formatted ($ampm)"; ?></option>
                                         <?php endfor; ?>
                                     </select>
                                 </div>
                             </div>
                             <div class="col-md-4">
                                 <div class="d-flex align-items-center gap-2">
                                     <label class="form-label mb-0"><?php echo xlt('End'); ?></label>
                                     <select name="send_weekday_end" id="cfgSendWeekdayEnd" class="form-select form-select-sm">
                                         <?php for ($h = 0; $h < 24; $h++):
                                             $formatted = sprintf('%02d:00', $h);
                                             $ampm = ($h === 0) ? '12:00 AM' : (($h === 12) ? '12:00 PM' : (($h > 12) ? ($h - 12) . ':00 PM' : $h . ':00 AM'));
                                         ?>
                                             <option value="<?php echo $h; ?>"><?php echo "$formatted ($ampm)"; ?></option>
                                         <?php endfor; ?>
                                     </select>
                                 </div>
                             </div>
                         </div>

                         <div class="row g-2 mb-3 align-items-center">
                             <!-- Saturday -->
                             <div class="col-md-4 d-flex align-items-center gap-2">
                                 <label class="custom-checkbox">
                                     <input type="checkbox" name="send_saturday_enabled" id="cfgSendSaturdayEnabled" value="1">
                                     <span class="slider"></span>
                                 </label>
                                 <span class="fw-bold"><?php echo xlt('Saturday'); ?></span>
                             </div>
                             <div class="col-md-4">
                                 <div class="d-flex align-items-center gap-2">
                                     <label class="form-label mb-0"><?php echo xlt('Start'); ?></label>
                                     <select name="send_saturday_start" id="cfgSendSaturdayStart" class="form-select form-select-sm">
                                         <?php for ($h = 0; $h < 24; $h++):
                                             $formatted = sprintf('%02d:00', $h);
                                             $ampm = ($h === 0) ? '12:00 AM' : (($h === 12) ? '12:00 PM' : (($h > 12) ? ($h - 12) . ':00 PM' : $h . ':00 AM'));
                                         ?>
                                             <option value="<?php echo $h; ?>"><?php echo "$formatted ($ampm)"; ?></option>
                                         <?php endfor; ?>
                                     </select>
                                 </div>
                             </div>
                             <div class="col-md-4">
                                 <div class="d-flex align-items-center gap-2">
                                     <label class="form-label mb-0"><?php echo xlt('End'); ?></label>
                                     <select name="send_saturday_end" id="cfgSendSaturdayEnd" class="form-select form-select-sm">
                                         <?php for ($h = 0; $h < 24; $h++):
                                             $formatted = sprintf('%02d:00', $h);
                                             $ampm = ($h === 0) ? '12:00 AM' : (($h === 12) ? '12:00 PM' : (($h > 12) ? ($h - 12) . ':00 PM' : $h . ':00 AM'));
                                         ?>
                                             <option value="<?php echo $h; ?>"><?php echo "$formatted ($ampm)"; ?></option>
                                         <?php endfor; ?>
                                     </select>
                                 </div>
                             </div>
                         </div>

                         <div class="row g-2 mb-3 align-items-center">
                             <!-- Sunday -->
                             <div class="col-md-4 d-flex align-items-center gap-2">
                                 <label class="custom-checkbox">
                                     <input type="checkbox" name="send_sunday_enabled" id="cfgSendSundayEnabled" value="1">
                                     <span class="slider"></span>
                                 </label>
                                 <span class="fw-bold"><?php echo xlt('Sunday'); ?></span>
                             </div>
                             <div class="col-md-4">
                                 <div class="d-flex align-items-center gap-2">
                                     <label class="form-label mb-0"><?php echo xlt('Start'); ?></label>
                                     <select name="send_sunday_start" id="cfgSendSundayStart" class="form-select form-select-sm">
                                         <?php for ($h = 0; $h < 24; $h++):
                                             $formatted = sprintf('%02d:00', $h);
                                             $ampm = ($h === 0) ? '12:00 AM' : (($h === 12) ? '12:00 PM' : (($h > 12) ? ($h - 12) . ':00 PM' : $h . ':00 AM'));
                                         ?>
                                             <option value="<?php echo $h; ?>"><?php echo "$formatted ($ampm)"; ?></option>
                                         <?php endfor; ?>
                                     </select>
                                 </div>
                             </div>
                             <div class="col-md-4">
                                 <div class="d-flex align-items-center gap-2">
                                     <label class="form-label mb-0"><?php echo xlt('End'); ?></label>
                                     <select name="send_sunday_end" id="cfgSendSundayEnd" class="form-select form-select-sm">
                                         <?php for ($h = 0; $h < 24; $h++):
                                             $formatted = sprintf('%02d:00', $h);
                                             $ampm = ($h === 0) ? '12:00 AM' : (($h === 12) ? '12:00 PM' : (($h > 12) ? ($h - 12) . ':00 PM' : $h . ':00 AM'));
                                         ?>
                                             <option value="<?php echo $h; ?>"><?php echo "$formatted ($ampm)"; ?></option>
                                         <?php endfor; ?>
                                     </select>
                                 </div>
                             </div>
                         </div>
                         <small class="text-muted d-block mb-3">
                             <i class="fas fa-info-circle me-1"></i><?php echo xlt('(When disabled, no messages are sent on that day)'); ?>
                         </small>
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
                        <div class="d-flex gap-2 mt-3">
                            <button type="submit" class="btn btn-success btn-save">
                                <?php echo xlt('Save Configuration'); ?>
                            </button>
                            <button type="button" id="btnCancelConfig" class="btn btn-outline-secondary btn-cancel">
                                <?php echo xlt('Cancel'); ?>
                            </button>
                            <button type="button" class="btn btn-outline-info" onclick="openTemplateManager()">
                                <i class="fas fa-edit me-1"></i><?php echo xlt('Templates'); ?>
                            </button>
                            <span id="cfgSaveMsg" class="align-self-center text-success" style="display:none;">
                                <i class="fas fa-check-circle"></i> <?php echo xlt('Saved!'); ?>
                            </span>
                        </div>
                    </div><!-- /facility-subtab-config -->

                    <!-- ================================================
                         FACILITY SUB-TAB: RECALLS
                    ================================================= -->
                    <div id="facility-subtab-recalls" class="d-none">
                        <div class="d-flex align-items-center justify-content-between mb-3">
                            <h6 class="text-warning mb-0">
                                <i class="fas fa-redo-alt me-2"></i><?php echo xlt('Recall Notification Schedule'); ?>
                            </h6>
                            <button type="button" class="btn btn-sm btn-outline-warning" onclick="addRecallScheduleRow()">
                                <i class="fas fa-plus me-1"></i><?php echo xlt('Add sequence'); ?>
                            </button>
                        </div>
                        <p class="text-muted small mb-2">
                            <?php echo xlt('Define how many recall notifications are sent per recall event and how many days before the recall date.'); ?>
                        </p>
                        <div class="table-responsive mb-2">
                            <table class="table table-sm table-bordered align-middle" id="recallScheduleTable">
                                <thead class="table-light">
                                    <tr>
                                        <th style="width:40px">#</th>
                                        <th><?php echo xlt('Days before recall date'); ?></th>
                                        <th style="width:80px"><?php echo xlt('Via WSP'); ?></th>
                                        <th style="width:80px"><?php echo xlt('Via Email'); ?></th>
                                        <th style="width:80px"><?php echo xlt('Active'); ?></th>
                                        <th style="width:40px"></th>
                                    </tr>
                                </thead>
                                <tbody id="recallScheduleBody">
                                    <!-- Injected by loadRecallConfig() -->
                                </tbody>
                            </table>
                        </div>

                        <hr>
                        <h6 class="text-warning"><?php echo xlt('Recall Message Template'); ?></h6>
                        <p class="text-muted small mb-2">
                            <?php echo xlt('Available tokens:'); ?>
                            <code>***PATIENT_NAME***</code>,
                            <code>***PATIENT_FIRSTNAME***</code>,
                            <code>***RECALL_DATE***</code>,
                            <code>***RECALL_REASON***</code>,
                            <code>***PROVIDER_NAME***</code>,
                            <code>***FACILITY_NAME***</code>,
                            <code>***FACILITY_PHONE***</code>,
                            <code>***FACILITY_ADDRESS***</code>
                        </p>

                        <div class="mb-3">
                            <label class="form-label"><?php echo xlt('WhatsApp Message'); ?></label>
                            <textarea id="recallWspMessage" class="form-control form-control-sm" rows="5"
                                      placeholder="<?php echo attr(xlt('WhatsApp recall message...')); ?>"></textarea>
                        </div>

                        <div class="mb-3">
                            <label class="form-label"><?php echo xlt('Email Subject'); ?></label>
                            <input type="text" id="recallEmailSubject" class="form-control form-control-sm"
                                   placeholder="<?php echo attr(xlt('Recall reminder subject...')); ?>">
                        </div>

                        <div class="mb-3">
                            <label class="form-label"><?php echo xlt('Email Body (HTML)'); ?></label>
                            <textarea id="recallEmailMessage" class="form-control form-control-sm" rows="8"
                                      placeholder="<?php echo attr(xlt('HTML email body for recall reminder...')); ?>"></textarea>
                        </div>

                        <div class="mb-3 d-flex align-items-center gap-2">
                            <label class="custom-checkbox">
                                <input type="checkbox" id="recallTemplateEnabled" value="1" checked>
                                <span class="slider"></span>
                            </label>
                            <label class="form-label mb-0" for="recallTemplateEnabled">
                                <?php echo xlt('Template enabled'); ?>
                            </label>
                        </div>

                        <div class="d-flex gap-2 mt-3">
                            <button type="button" class="btn btn-warning" onclick="saveRecallConfig()">
                                <i class="fas fa-save me-1"></i><?php echo xlt('Save Recall Config'); ?>
                            </button>
                            <span id="recallSaveMsg" class="align-self-center text-success" style="display:none;">
                                <i class="fas fa-check-circle"></i> <?php echo xlt('Saved!'); ?>
                            </span>
                        </div>
                    </div><!-- /facility-subtab-recalls -->

                    </form>
                </div>
            </div>
        </div>


        <?php else: ?>
        <div class="alert alert-warning"><?php echo xlt('Access to Facility Configuration requires administrator permissions.'); ?></div>
        <?php endif; ?>

    </div><!-- /tab-facility -->

    <!-- ===================================================================
         TAB: RECALLS
    ==================================================================== -->
    <div id="tab-recalls" class="<?php echo $activeTab === 'recalls' ? '' : 'd-none'; ?>">

        <!--  PANEL: Active Recalls  -->
        <div class="chart-card mb-3">
            <div class="d-flex align-items-center justify-content-between mb-3">
                <h5 class="mb-0">
                    <i class="fas fa-bell me-2 text-warning"></i><?php echo xlt('Active Recalls - Pending Notifications'); ?>
                </h5>
                <div class="d-flex align-items-center gap-2">
                    <label class="form-label small mb-0 me-1"><?php echo xlt('Horizon (days):'); ?></label>
                    <select id="pendingRecallHorizon" class="form-select form-select-sm" style="width:90px">
                        <option value="7">7</option>
                        <option value="15">15</option>
                        <option value="30" selected>30</option>
                        <option value="60">60</option>
                        <option value="90">90</option>
                    </select>
                    <select id="pendingRecallFacility" class="form-select form-select-sm" style="width:160px">
                        <option value="0"><?php echo xlt('All Facilities'); ?></option>
                        <?php foreach ($facilities as $sf): ?>
                        <option value="<?php echo attr((string)$sf['facility_id']); ?>"><?php echo text($sf['facility_name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <button id="btnRefreshPendingRecalls" class="btn btn-sm btn-outline-warning">
                        <i class="fas fa-sync-alt me-1"></i><?php echo xlt('Refresh'); ?>
                    </button>
                    <button id="btnRunRecallsNow" class="btn btn-sm btn-warning">
                        <i class="fas fa-paper-plane me-1"></i><?php echo xlt('Send Recalls Now'); ?>
                    </button>
                </div>
            </div>

            <div id="pendingRecallsWrap">
                <div class="text-center py-3 text-muted">
                    <i class="fas fa-spinner fa-spin fa-lg"></i>
                </div>
            </div>
        </div>

        <!--  PANEL: My Recalls (entries)  -->
        <div class="chart-card mb-3">
            <div class="d-flex align-items-center justify-content-between mb-3">
                <h5 class="mb-0">
                    <i class="fas fa-plus-circle me-2 text-success"></i><?php echo xlt('My Recalls'); ?>
                </h5>
                <button class="btn btn-sm btn-success" onclick="openRecallEntryModal(0)">
                    <i class="fas fa-plus me-1"></i><?php echo xlt('New Recall'); ?>
                </button>
            </div>
            <div id="myRecallEntriesWrap">
                <div class="text-center py-3 text-muted">
                    <i class="fas fa-spinner fa-spin fa-lg"></i>
                </div>
            </div>
        </div>

        <!--  PANEL: Search All Recalls  -->
        <div class="chart-card mb-3">
            <div class="d-flex align-items-center justify-content-between mb-3">
                <h5 class="mb-0">
                    <i class="fas fa-search me-2 text-info"></i><?php echo xlt('Search All Recalls'); ?>
                </h5>
            </div>

            <!-- Filters -->
            <div class="row g-2 mb-3">
                <div class="col-md-2">
                    <label class="form-label small mb-1"><?php echo xlt('Facility'); ?></label>
                    <select id="recallFilterFacility" class="form-select form-select-sm">
                        <option value=""><?php echo xlt('All Facilities'); ?></option>
                        <?php foreach ($facilities as $sf): ?>
                        <option value="<?php echo attr((string)$sf['facility_id']); ?>"><?php echo text($sf['facility_name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label small mb-1"><?php echo xlt('From Date'); ?></label>
                    <input type="text" id="recallFilterFrom" class="form-control form-control-sm datepicker"
                           value="<?php echo attr(oeFormatShortDate(date('Y-m-d'))); ?>">
                </div>
                <div class="col-md-2">
                    <label class="form-label small mb-1"><?php echo xlt('To Date'); ?></label>
                    <input type="text" id="recallFilterTo" class="form-control form-control-sm datepicker"
                           value="<?php echo attr(oeFormatShortDate(date('Y-m-d', strtotime('+6 months')))); ?>">
                </div>
                <div class="col-md-2">
                    <label class="form-label small mb-1"><?php echo xlt('Patient'); ?></label>
                    <input type="text" id="recallFilterPatient" class="form-control form-control-sm"
                           placeholder="<?php echo attr(xlt('Name...')); ?>">
                </div>
                <div class="col-md-2">
                    <label class="form-label small mb-1"><?php echo xlt('Notif. Status'); ?></label>
                    <select id="recallFilterStatus" class="form-select form-select-sm">
                        <option value=""><?php echo xlt('All'); ?></option>
                        <option value="UNSENT"><?php echo xlt('Not sent yet'); ?></option>
                        <option value="PENDING"><?php echo xlt('Pending'); ?></option>
                        <option value="SENT"><?php echo xlt('Sent'); ?></option>
                        <option value="FAILED"><?php echo xlt('Failed'); ?></option>
                        <option value="SKIPPED"><?php echo xlt('Skipped'); ?></option>
                    </select>
                </div>
                <div class="col-md-2 d-flex align-items-end">
                    <button id="btnLoadRecalls" class="btn btn-sm btn-success w-100">
                        <i class="fas fa-search me-1"></i><?php echo xlt('Search'); ?>
                    </button>
                </div>
            </div>

            <!-- Recalls Table -->
            <div class="table-responsive">
                <table class="table table-sm table-hover mb-0" id="recallsTable">
                    <thead class="table-light">
                        <tr>
                            <th><?php echo xlt('Patient'); ?></th>
                            <th><?php echo xlt('Phone / Email'); ?></th>
                            <th><?php echo xlt('Recall Date'); ?></th>
                            <th><?php echo xlt('Reason'); ?></th>
                            <th><?php echo xlt('Facility'); ?></th>
                            <th><?php echo xlt('Provider'); ?></th>
                            <th class="text-center"><?php echo xlt('Sequences'); ?></th>
                            <th class="text-center"><?php echo xlt('Status'); ?></th>
                        </tr>
                    </thead>
                    <tbody id="recallsTableBody">
                        <tr><td colspan="8" class="text-center text-muted py-4"><?php echo xlt('Use filters above and click Search.'); ?></td></tr>
                    </tbody>
                </table>
            </div>

            <!-- Pagination -->
            <div id="recallsPagination" class="d-flex align-items-center gap-3 mt-2" style="display:none!important">
                <small id="recallsCountLabel" class="text-muted"></small>
                <div class="ms-auto d-flex gap-1">
                    <button id="recallsPrevBtn" class="btn btn-xs btn-outline-secondary" onclick="recallsChangePage(-1)" disabled>
                        <i class="fas fa-chevron-left"></i>
                    </button>
                    <span id="recallsPageLabel" class="align-self-center small"></span>
                    <button id="recallsNextBtn" class="btn btn-xs btn-outline-secondary" onclick="recallsChangePage(1)" disabled>
                        <i class="fas fa-chevron-right"></i>
                    </button>
                </div>
            </div>
        </div>

        <!-- Run Now Log -->
        <div id="recallRunLog" class="chart-card mt-3" style="display:none">
            <div class="d-flex align-items-center gap-2 mb-2">
                <strong class="small"><?php echo xlt('Recall Run Log'); ?></strong>
                <button type="button" class="btn btn-xs btn-outline-secondary" onclick="document.getElementById('recallRunLog').style.display='none'">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <pre class="bg-dark text-light p-3 rounded" style="max-height:350px;overflow:auto;font-size:11px;line-height:1.3" id="recallRunLogContent"></pre>
        </div>
    </div><!-- /tab-recalls -->

    <!-- ===================================================================
         TAB: BLACKLIST
    ==================================================================== -->
    <div id="tab-blacklist" class="<?php echo $activeTab === 'blacklist' ? '' : 'd-none'; ?>">


        <?php if (AclMain::aclCheckCore('admin', 'super')): ?>
        <div class="chart-card">
            <div class="d-flex align-items-center justify-content-between mb-3">
                <h5 class="mb-0"><i class="fas fa-ban me-2 text-danger"></i><?php echo xlt('Blacklisted Numbers'); ?></h5>
                <button class="btn btn-sm btn-outline-danger" onclick="showBlAddModal()">
                    <i class="fas fa-plus me-1"></i><?php echo xlt('Add Number'); ?>
                </button>
            </div>

            <!-- Filters -->
            <div class="row g-2 mb-3">
                <div class="col-md-2">
                    <label class="form-label small mb-1"><?php echo xlt('Facility'); ?></label>
                    <select id="blFilterFacility" class="form-select form-select-sm">
                        <option value=""><?php echo xlt('All Facilities'); ?></option>
                        <option value="0"><?php echo xlt('Global'); ?></option>
                        <?php foreach ($facilities as $sf): ?>
                        <option value="<?php echo attr((string)$sf['facility_id']); ?>"><?php echo text($sf['facility_name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label small mb-1"><?php echo xlt('Vendor'); ?></label>
                    <select id="blFilterVendor" class="form-select form-select-sm">
                        <option value=""><?php echo xlt('All Vendors'); ?></option>
                        <option value="ultramsg">UltraMsg</option>
                        <option value="wasenderapi">WaSenderAPI</option>
                        <option value="openwa">OpenWA</option>
                        <option value="evolution-go">Evolution-Go</option>
                        <option value="all"><?php echo xlt('All (global)'); ?></option>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label small mb-1"><?php echo xlt('Reason'); ?></label>
                    <select id="blFilterReason" class="form-select form-select-sm">
                        <option value=""><?php echo xlt('All Reasons'); ?></option>
                        <option value="MANUAL"><?php echo xlt('Manual'); ?></option>
                        <option value="INVALID"><?php echo xlt('Invalid Number'); ?></option>
                        <option value="FAILED_MAX"><?php echo xlt('Too Many Failures'); ?></option>
                        <option value="TRACKING"><?php echo xlt('Tracking (not blocked)'); ?></option>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label small mb-1"><?php echo xlt('Status'); ?></label>
                    <select id="blFilterActive" class="form-select form-select-sm">
                        <option value=""><?php echo xlt('All'); ?></option>
                        <option value="1"><?php echo xlt('Active (blocked)'); ?></option>
                        <option value="0"><?php echo xlt('Inactive (released)'); ?></option>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label small mb-1"><?php echo xlt('Search Phone/Notes'); ?></label>
                    <input type="text" id="blFilterSearch" class="form-control form-control-sm" placeholder="<?php echo attr(xlt('Search...')); ?>">
                </div>
                <div class="col-md-2 d-flex align-items-end">
                    <button class="btn btn-sm btn-success w-100" onclick="loadBlacklist()">
                        <i class="fas fa-search me-1"></i><?php echo xlt('Search'); ?>
                    </button>
                </div>
            </div>

            <!-- Table -->
            <div class="table-responsive">
                <table class="table table-sm table-hover mb-0" id="blTable">
                    <thead class="table-light">
                        <tr>
                            <th><?php echo xlt('Phone'); ?></th>
                            <th><?php echo xlt('Facility'); ?></th>
                            <th><?php echo xlt('Vendor'); ?></th>
                            <th><?php echo xlt('Reason'); ?></th>
                            <th class="text-center"><?php echo xlt('Failures'); ?></th>
                            <th><?php echo xlt('Notes'); ?></th>
                            <th><?php echo xlt('Last Updated'); ?></th>
                            <th><?php echo xlt('Created'); ?></th>
                            <th class="text-center"><?php echo xlt('Status'); ?></th>
                            <th class="text-center"><?php echo xlt('Actions'); ?></th>
                        </tr>
                    </thead>
                    <tbody id="blTableBody">
                        <tr><td colspan="10" class="text-center text-muted py-4"><?php echo xlt('Use filters above and click Search.'); ?></td></tr>
                    </tbody>
                </table>
            </div>
            <div id="blPagination" class="d-flex align-items-center gap-3 mt-2" style="display:none!important">
                <small id="blCountLabel" class="text-muted"></small>
                <div class="ms-auto d-flex gap-1">
                    <button id="blPrevBtn" class="btn btn-xs btn-outline-secondary" onclick="blChangePage(-1)" disabled>
                        <i class="fas fa-chevron-left"></i>
                    </button>
                    <span id="blPageLabel" class="align-self-center small"></span>
                    <button id="blNextBtn" class="btn btn-xs btn-outline-secondary" onclick="blChangePage(1)" disabled>
                        <i class="fas fa-chevron-right"></i>
                    </button>
                </div>
            </div>
        </div>
        <?php else: ?>
        <div class="alert alert-warning"><?php echo xlt('Access to Blacklist requires administrator permissions.'); ?></div>
        <?php endif; ?>

    </div><!-- /tab-blacklist -->

    <!-- ===================================================================
         TAB: CATALOGS (Editable list_options)
    ==================================================================== -->
    <div id="tab-catalogs" class="<?php echo $activeTab === 'catalogs' ? '' : 'd-none'; ?>">
        <?php if (AclMain::aclCheckCore('admin', 'super')): ?>
        <div class="chart-card">
            <div class="d-flex align-items-center justify-content-between mb-3">
                <h5 class="mb-0"><i class="fas fa-list me-2 text-primary"></i><?php echo xlt('Editable Catalogs'); ?></h5>
            </div>
            <div id="lom-container">
                <div class="text-center text-muted py-5">
                    <i class="fa fa-spinner fa-spin fa-2x mb-2"></i>
                    <p><?php echo xlt('Loading...'); ?></p>
                </div>
            </div>
        </div>
        <script>
        document.addEventListener('DOMContentLoaded', function () {
            var lomTab = document.getElementById('tab-catalogs');
            if (lomTab && !lomTab.classList.contains('d-none')) {
                ListOptionsManager.init(
                    'apptstat',
                    '#lom-container',
                    '<?php echo CsrfCompat::collectCsrfToken(); ?>',
                    '<?php echo $moduleRoot; ?>/list_options_manager.php',
                    null,
                    {
                        selectList: <?php echo js_escape(xlt('Select List to Manage')); ?>,
                        lists: <?php echo js_escape(xlt('Lists')); ?>,
                        add: <?php echo js_escape(xlt('Add')); ?>,
                        noOptions: <?php echo js_escape(xlt('No options found for')); ?>,
                        newOption: <?php echo js_escape(xlt('New Option')); ?>,
                        optionId: <?php echo js_escape(xlt('Option ID')); ?>,
                        title: <?php echo js_escape(xlt('Title')); ?>,
                        color: <?php echo js_escape(xlt('Color')); ?>,
                        alertTime: <?php echo js_escape(xlt('Alert Time')); ?>,
                        checkIn: <?php echo js_escape(xlt('Check In')); ?>,
                        checkOut: <?php echo js_escape(xlt('Check Out')); ?>,
                        codes: <?php echo js_escape(xlt('Code(s)')); ?>,
                        notes: <?php echo js_escape(xlt('Notes')); ?>,
                        seq: <?php echo js_escape(xlt('Seq')); ?>,
                        optionIdCol: <?php echo js_escape(xlt('Option ID')); ?>,
                        titleCol: <?php echo js_escape(xlt('Title')); ?>,
                        notesCol: <?php echo js_escape(xlt('Notes')); ?>,
                        codesCol: <?php echo js_escape(xlt('Codes')); ?>,
                        colorCol: <?php echo js_escape(xlt('Color')); ?>,
                        alertTimeCol: <?php echo js_escape(xlt('Alert Time')); ?>,
                        checkInCol: <?php echo js_escape(xlt('Check In')); ?>,
                        checkOutCol: <?php echo js_escape(xlt('Check Out')); ?>,
                        defaultCol: <?php echo js_escape(xlt('Default')); ?>,
                        active: <?php echo js_escape(xlt('Active')); ?>,
                        actions: <?php echo js_escape(xlt('Actions')); ?>,
                        save: <?php echo js_escape(xlt('Save')); ?>,
                        deactivate: <?php echo js_escape(xlt('Deactivate')); ?>,
                        cancel: <?php echo js_escape(xlt('Cancel')); ?>,
                        networkError: <?php echo js_escape(xlt('Network error')); ?> + ': ',
                        optionIdRequired: <?php echo js_escape(xlt('Option ID is required')); ?>,
                        deactivateConfirm: <?php echo js_escape(xlt('Deactivate')); ?>
                    }
                );
            }
        });
        </script>
        <?php else: ?>
        <div class="alert alert-warning"><?php echo xlt('Access denied'); ?></div>
        <?php endif; ?>
    </div><!-- /tab-catalogs -->

    <!-- Modal: Add Blacklist Entry -->
    <div class="modal fade" id="blAddModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-ban me-2 text-danger"></i><?php echo xlt('Add to Blacklist'); ?></h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" onclick="closeModalParent(this)"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label"><?php echo xlt('Facility'); ?></label>
                        <select id="blAddFacility" class="form-select form-select-sm">
                            <option value="0"><?php echo xlt('Global (all facilities)'); ?></option>
                            <?php foreach ($facilities as $sf): ?>
                            <option value="<?php echo attr((string)$sf['facility_id']); ?>"><?php echo text($sf['facility_name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label"><?php echo xlt('Vendor'); ?></label>
                        <select id="blAddVendor" class="form-select form-select-sm">
                            <option value="all"><?php echo xlt('All (global block)'); ?></option>
                            <option value="ultramsg">UltraMsg</option>
                            <option value="wasenderapi">WaSenderAPI</option>
                            <option value="openwa">OpenWA</option>
                            <option value="evolution-go">Evolution-Go</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label"><?php echo xlt('Phone Number'); ?> <span class="text-danger">*</span></label>
                        <input type="text" id="blAddPhone" class="form-control form-control-sm" placeholder="e.g. 5491134567890">
                        <div class="form-text"><?php echo xlt('International format without + (e.g. 5491134567890)'); ?></div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label"><?php echo xlt('Notes'); ?></label>
                        <textarea id="blAddNotes" class="form-control form-control-sm" rows="3" placeholder="<?php echo attr(xlt('Reason for manual blacklisting...')); ?>"></textarea>
                    </div>
                    <div id="blAddError" class="alert alert-danger py-2" style="display:none"></div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal" onclick="closeModalParent(this)"><?php echo xlt('Cancel'); ?></button>
                    <button type="button" class="btn btn-danger btn-sm" onclick="blDoAdd()">
                        <i class="fas fa-ban me-1"></i><?php echo xlt('Add to Blacklist'); ?>
                    </button>
                </div>
            </div>
        </div>
    </div>

</div><!-- /container-fluid -->

<script>
/* =========================================================================
   Dashboard — Chart.js
   ========================================================================= */
const moduleRoot = <?php echo js_escape($moduleRoot); ?>;
const baseUrl    = <?php echo js_escape(rtrim($GLOBALS['site_addr_oath'] ?? $GLOBALS['webroot'] ?? '', '/')); ?>;
let chart = null;

// Status colors for charts - WhatsApp (Green), Email (Light Blue)
// Keys are canonical statuses (uppercase)
const statusColors = {
    'QUEUED':    { wsp: '#FFC107CC', email: '#FFCA28CC' },   // Amber / Light Amber
    'SENT':      { wsp: '#25D366CC', email: '#4FC3F7CC' },   // WhatsApp Green / Email Light Blue
    'DELIVERED': { wsp: '#128C7ECC', email: '#039BE5CC' },   // Dark Green / Dark Blue
    'READ':      { wsp: '#9C27B0CC', email: '#5E35B1CC' },   // Purple / Deep Purple
    'FAILED':    { wsp: '#D32F2FCC', email: '#E57373CC' },   // Dark Red / Pale Red
    'INVALID':   { wsp: '#9E9E9ECC', email: '#BDBDBDCC' },   // Gray / Light Gray
    'ERROR':     { wsp: '#F44336CC', email: '#EF5350CC' },   // Red / Light Red
    'UNSENT':    { wsp: '#616161CC', email: '#9E9E9ECC' }    // Dark Gray / Gray
};

// Available status filters (canonical statuses)
const statusFilters = [
    { value: 'QUEUED', label: '<?php echo xlt('Queued'); ?>', checked: true },
    { value: 'SENT', label: '<?php echo xlt('Sent'); ?>', checked: true },
    { value: 'DELIVERED', label: '<?php echo xlt('Delivered'); ?>', checked: true },
    { value: 'READ', label: '<?php echo xlt('Read'); ?>', checked: true },
    { value: 'FAILED', label: '<?php echo xlt('Failed'); ?>', checked: true },
    { value: 'INVALID', label: '<?php echo xlt('Invalid'); ?>', checked: true },
    { value: 'ERROR', label: '<?php echo xlt('Error'); ?>', checked: true },
    { value: 'UNSENT', label: '<?php echo xlt('Unsent'); ?>', checked: true }
];

function buildChart(stats) {
    const ctx = document.getElementById('chartNotifications').getContext('2d');

    // Collect unique dates
    const dates = [...new Set(stats.map(r => r.send_date))].sort();

    // Get selected status filters
    const selectedStatuses = statusFilters.filter(s => s.checked).map(s => s.value);

    // Group stats by type and status (normalize to uppercase)
    const grouped = {};
    stats.forEach(s => {
        const statusUpper = (s.status || 'UNSENT').toUpperCase();
        const key = `${s.type}_${statusUpper}`;
        if (!grouped[key]) {
            grouped[key] = { type: s.type, status: statusUpper, data: {} };
        }
        grouped[key].data[s.send_date] = s.total;
    });

    // Build datasets for each status
    const datasets = [];

    selectedStatuses.forEach(status => {
        const wspKey = `WSP_${status}`;
        const emailKey = `Email_${status}`;

        if (grouped[wspKey] || grouped[emailKey]) {
            const colors = statusColors[status] || { wsp: '#9E9E9ECC', email: '#BDBDBDCC' };

            // WhatsApp dataset
            if (grouped[wspKey]) {
                datasets.push({
                    label: `WhatsApp - ${status.charAt(0).toUpperCase() + status.slice(1)}`,
                    data: dates.map(d => grouped[wspKey].data[d] || 0),
                    backgroundColor: colors.wsp,
                    borderColor: colors.wsp.replace('CC', 'FF'),
                    borderWidth: 1,
                    borderRadius: 4,
                    stack: 'WhatsApp',
                    order: selectedStatuses.indexOf(status)
                });
            }

            // Email dataset
            if (grouped[emailKey]) {
                datasets.push({
                    label: `Email - ${status.charAt(0).toUpperCase() + status.slice(1)}`,
                    data: dates.map(d => grouped[emailKey].data[d] || 0),
                    backgroundColor: colors.email,
                    borderColor: colors.email.replace('CC', 'FF'),
                    borderWidth: 1,
                    borderRadius: 4,
                    stack: 'Email',
                    order: selectedStatuses.indexOf(status)
                });
            }
        }
    });

    // Fallback: if no status data, show simple totals
    if (datasets.length === 0) {
        const wspData   = dates.map(d => {
            const r = stats.find(s => s.send_date === d && s.type === 'WSP');
            return r ? parseInt(r.total) : 0;
        });
        const emailData = dates.map(d => {
            const r = stats.find(s => s.send_date === d && s.type === 'Email');
            return r ? parseInt(r.total) : 0;
        });

        datasets.push(
            { label: 'WhatsApp', data: wspData, backgroundColor: '#25D366CC', borderColor: '#128C7E', borderWidth: 1.5, borderRadius: 5 },
            { label: 'Email', data: emailData, backgroundColor: '#4FC3F7CC', borderColor: '#039BE5', borderWidth: 1.5, borderRadius: 5 }
        );
    }

    if (chart) chart.destroy();
    chart = new Chart(ctx, {
        type: 'bar',
        data: {
            labels: dates,
            datasets: datasets
        },
        options: {
            responsive: true,
            plugins: {
                legend: {
                    position: 'top',
                    labels: {
                        boxWidth: 12,
                        font: { size: 10 },
                        filter: function(legendItem, chartData) {
                            // Show all legends
                            return true;
                        }
                    }
                },
                tooltip: {
                    mode: 'index',
                    intersect: false,
                    callbacks: {
                        label: function(context) {
                            return context.dataset.label + ': ' + context.parsed.y;
                        }
                    }
                }
            },
            scales: {
                x: {
                    stacked: true,
                    ticks: { maxRotation: 45, minRotation: 45 }
                },
                y: {
                    beginAtZero: true,
                    ticks: { precision: 0 },
                    stacked: true
                }
            }
        }
    });
}

function renderStatusFilters() {
    const container = document.getElementById('chartStatusFilters');
    if (!container) return;

    container.innerHTML = statusFilters.map(s => `
        <button type="button"
                class="btn btn-sm status-filter-btn ${s.checked ? 'active' : ''}"
                data-status="${s.value}"
                onclick="toggleStatusFilter('${s.value}')">
            <i class="fas ${getCheckIcon(s.checked)}"></i> ${s.label}
        </button>
    `).join('');
}

function getCheckIcon(checked) {
    return checked ? 'fa-check-circle' : 'fa-circle';
}

function toggleStatusFilter(status) {
    const filter = statusFilters.find(f => f.value === status);
    if (filter) {
        filter.checked = !filter.checked;
        renderStatusFilters(); // Re-render to update icons
        loadStats(); // Reload chart with new filters
    }
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
document.getElementById('btnSendNow')?.addEventListener('click', sendNow);
document.getElementById('btnPdfReport')?.addEventListener('click', function() {
    const from = document.getElementById('statsFrom').value;
    const to   = document.getElementById('statsTo').value;
    const facility = document.getElementById('statsFacility').value;
    const url = moduleRoot + '/pages/ajax/generate_report.php?from=' + encodeURIComponent(from) + '&to=' + encodeURIComponent(to) + '&facility_id=' + encodeURIComponent(facility);
    window.open(url, '_blank');
});

function sendNow() {
    const btn = document.getElementById('btnSendNow');
    const logDiv = document.getElementById('sendNowLog');
    const logContent = document.getElementById('sendNowLogContent');

    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i><?php echo xlt('Sending...'); ?>';
    logDiv.style.display = 'block';
    logContent.textContent = '<?php echo xlt('Running...'); ?>';

    fetch(moduleRoot + '/pages/ajax/run_now.php', { method: 'POST' })
        .then(r => r.json().then(data => ({ ok: r.ok, data })))
        .then(({ ok, data }) => {
            if (!ok) {
                logContent.textContent = '<?php echo xlt('Error'); ?>: ' + (data.error || data.log || JSON.stringify(data));
                return;
            }
            logContent.textContent = data.log || '<?php echo xlt('No output.'); ?>';
            logContent.scrollTop = logContent.scrollHeight;
        })
        .catch(err => {
            logContent.textContent = '<?php echo xlt('Error'); ?>: ' + err.message;
        })
        .finally(() => {
            btn.disabled = false;
            btn.innerHTML = '<i class="fas fa-paper-plane me-1"></i><?php echo xlt('Send Now'); ?>';
        });
}

function toggleSendNowLog() {
    const el = document.getElementById('sendNowLogContent');
    if (el.style.display === 'none') {
        el.style.display = 'block';
    } else {
        el.style.display = 'none';
    }
}

/* =========================================================================
   Patient Status Tab
   ========================================================================= */
function renderStatus(status, type) {
    // Normalize to uppercase for canonical status
    status = (status || 'UNSENT').toUpperCase().trim();
    type = (type || '').toUpperCase().trim();

    // Define status configuration with icon, label, and CSS class
    // Uses canonical statuses from StatusNormalizer
    const statusConfig = {
        'QUEUED':    { icon: 'fa-clock',         label: '<?php echo xlt('Queued'); ?>',    css: 'badge-queue' },
        'SENT':      { icon: 'fa-check',         label: '<?php echo xlt('Sent'); ?>',      css: 'badge-sent ' + (type === 'WSP' ? 'type-wsp' : type === 'EMAIL' ? 'type-email' : '') },
        'IN_PROGRESS': { icon: 'fa-spinner fa-spin', label: '<?php echo xlt('Sending...'); ?>', css: 'badge-queue' },
        'DELIVERED': { icon: 'fa-box',           label: '<?php echo xlt('Delivered'); ?>', css: 'badge-delivered' },
        'READ':      { icon: 'fa-eye',           label: '<?php echo xlt('Read'); ?>',      css: 'badge-read' },
        'FAILED':    { icon: 'fa-times-circle',  label: '<?php echo xlt('Failed'); ?>',    css: 'badge-error' },
        'INVALID':   { icon: 'fa-question-circle', label: '<?php echo xlt('Invalid'); ?>', css: 'badge-invalid' },
        'ERROR':     { icon: 'fa-exclamation-triangle', label: '<?php echo xlt('Error'); ?>', css: 'badge-error' },
        'UNSENT':    { icon: 'fa-envelope',      label: '<?php echo xlt('Unsent'); ?>',    css: 'badge-unsent' },
        'MANUAL_WSP':   { icon: 'fa-paper-plane',  label: '<?php echo xlt('Manual WSP'); ?>',  css: 'badge-sent type-wsp' },
        'MANUAL_EMAIL': { icon: 'fa-paper-plane',  label: '<?php echo xlt('Manual Email'); ?>', css: 'badge-sent type-email' },
        'MANUAL_SMS':   { icon: 'fa-sms',          label: '<?php echo xlt('Manual SMS'); ?>',  css: 'badge-sms' },
        'MANUAL_VOZ':   { icon: 'fa-phone-alt',    label: '<?php echo xlt('Manual Voz'); ?>',  css: 'badge-voz' }
    };

    const config = statusConfig[status] || { icon: 'fa-question', label: status, css: 'badge-secondary' };

    return `<span class="badge badge-status ${config.css}" title="${status}">
                <i class="fas ${config.icon}"></i>
                <span>${config.label}</span>
            </span>`;
}

function searchPatients() {
    const q     = document.getElementById('patientSearch').value.trim();
    const from  = document.getElementById('patientFrom').value;
    const to    = document.getElementById('patientTo').value;
    const wsp   = document.getElementById('filterWsp').checked;
    const email = document.getElementById('filterEmail').checked;
    const sms   = document.getElementById('filterSms').checked;
    const voz   = document.getElementById('filterVoz').checked;
    const status = document.getElementById('filterStatus').value;
    const tbody = document.getElementById('patientTableBody');

    // Build channel list
    const channels = [];
    if (wsp) channels.push('WSP');
    if (email) channels.push('Email');
    if (sms) channels.push('SMS');
    if (voz) channels.push('VOZ');

    if (channels.length === 0) {
        tbody.innerHTML = '<tr><td colspan="8" class="text-center text-muted py-4"><?php echo js_escape(xlt('Please select at least one channel.')); ?></td></tr>';
        return;
    }

    tbody.innerHTML = '<tr><td colspan="8" class="text-center py-4"><i class="fas fa-spinner fa-spin fa-2x text-success"></i></td></tr>';

    const params = new URLSearchParams({
        q: q,
        from: from,
        to: to,
        channel: channels.join(','),
        status: status
    });

    fetch(`${moduleRoot}/pages/ajax/get_patient_logs.php?${params.toString()}`)
        .then(r => r.json())
        .then(data => {
            const rows = data.rows || [];
            if (!rows.length) {
                tbody.innerHTML = '<tr><td colspan="8" class="text-center text-muted py-4"><?php echo js_escape(xlt('No records found for the selected criteria.')); ?></td></tr>';
                return;
            }
            tbody.innerHTML = rows.map(r => {
                const typeIcons = {
                    'WSP': '<i class="fab fa-whatsapp text-success fa-lg" title="WhatsApp"></i>',
                    'EMAIL': '<i class="fas fa-envelope text-primary fa-lg" title="Email"></i>',
                    'SMS': '<i class="fas fa-sms text-info fa-lg" title="SMS"></i>',
                    'VOZ': '<i class="fas fa-phone-alt text-warning fa-lg" title="Voz"></i>'
                };
                const typeKey = (r.type || '').toUpperCase();
                const typeIcon = typeIcons[typeKey] || '<i class="fas fa-question-circle text-secondary fa-lg" title="' + escHtml(r.type) + '"></i>';
                
                const isRecall = !r.pc_eid && !r.pc_title;
                const apptInfo = isRecall
                    ? `<strong><?php echo xlt('Recall'); ?></strong><br><small class="text-muted">${escHtml(r.pc_eventDate)}</small>`
                    : `<strong>${escHtml(r.pc_title || 'Appt')}</strong><br><small class="text-muted">${escHtml(r.pc_eventDate)} ${r.pc_startTime}</small>`;
                
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
                    <td>${renderStatus(r.status, r.type)}</td>
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
            tbody.innerHTML = `<tr><td colspan="8" class="text-center text-danger py-4">Error: ${err}</td></tr>`;
        });
}

function resend(logId) {
    if (!confirm(<?php echo js_escape(xlt('Resend this notification?')); ?>)) return;
    fetch(`${moduleRoot}/pages/ajax/resend_notification.php?log_id=${logId}`, { method: 'POST' })
        .then(r => r.json())
        .then(d => {
            alert(d.message || <?php echo js_escape(xlt('Done')); ?>);
            if (d.success) searchPatients();
        })
        .catch(err => {
            alert(<?php echo js_escape(xlt('Resend failed: network or server error.')); ?> + ' ' + err);
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
                        <div class="fw-bold text-uppercase small">${escHtml(h.status_label || h.status)}</div>
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

            // Add active vendor badge
            const activeVendor = c.current_vendor || 'wasenderapi';
            let vendorBadge = '';
            if (activeVendor === 'ultramsg') {
                vendorBadge = '<span class="badge bg-primary ms-2 small">UltraMsg Active</span>';
            } else if (activeVendor === 'wasenderapi') {
                vendorBadge = '<span class="badge bg-info ms-2 small">WaSenderAPI Active</span>';
            } else if (activeVendor === 'openwa') {
                vendorBadge = '<span class="badge bg-warning ms-2 small">OpenWA Active</span>';
            } else if (activeVendor === 'evolution-go') {
                vendorBadge = '<span class="badge bg-secondary ms-2 small">Evolution-Go Active</span>';
            } else if (activeVendor === 'httpsms') {
                vendorBadge = '<span class="badge bg-dark ms-2 small">HttpSMS Active</span>';
            }
            title.innerHTML += vendorBadge;

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
            document.getElementById('cfgCurrentVendor').value   = c.current_vendor        || 'wasenderapi';

            // UltraMsg credentials - load and show hint if exists
            document.getElementById('cfgUltraInstance').value   = c.ultramsg_instance     || '';
            const ultraApiKeyInput = document.getElementById('cfgUltraApiKey');
            const ultraApiKeyHint = document.getElementById('ultraApiKeyHint');
            if (c.ultramsg_api_key && c.ultramsg_api_key.length > 0) {
                // Store full key in data attribute, show masked in input
                ultraApiKeyInput.dataset.fullKey = c.ultramsg_api_key;
                ultraApiKeyInput.value = '••••••••' + c.ultramsg_api_key.slice(-8);
                ultraApiKeyHint.style.display = 'block';
                ultraApiKeyInput.required = false;
            } else {
                delete ultraApiKeyInput.dataset.fullKey;
                ultraApiKeyInput.value = '';
                ultraApiKeyHint.style.display = 'none';
                ultraApiKeyInput.required = false;
            }

            // WaSenderAPI credentials - load and show hint if exists
            const waApiKeyInput = document.getElementById('cfgWaApiKey');
            const waApiKeyHint = document.getElementById('waApiKeyHint');
            if (c.wasenderapi_api_key && c.wasenderapi_api_key.length > 0) {
                // Store full key in data attribute, show masked in input
                waApiKeyInput.dataset.fullKey = c.wasenderapi_api_key;
                waApiKeyInput.value = '••••••••' + c.wasenderapi_api_key.slice(-8);
                waApiKeyHint.style.display = 'block';
                waApiKeyInput.required = false;
            } else {
                delete waApiKeyInput.dataset.fullKey;
                waApiKeyInput.value = '';
                waApiKeyHint.style.display = 'none';
                waApiKeyInput.required = false;
            }

            const waWebhookInput = document.getElementById('cfgWaWebhook');
            const waWebhookHint = document.getElementById('waWebhookHint');
            if (c.wasenderapi_webhook_secret && c.wasenderapi_webhook_secret.length > 0) {
                // Store full secret in data attribute, show masked in input
                waWebhookInput.dataset.fullKey = c.wasenderapi_webhook_secret;
                waWebhookInput.value = '••••••••' + c.wasenderapi_webhook_secret.slice(-8);
                waWebhookHint.style.display = 'block';
                waWebhookInput.required = false;
            } else {
                delete waWebhookInput.dataset.fullKey;
                waWebhookInput.value = '';
                waWebhookHint.style.display = 'none';
                waWebhookInput.required = false;
            }

            // OpenWA credentials - load and show hint if exists
            document.getElementById('cfgOwaInstance').value = c.openwa_instance || '';

            const owaApiKeyInput = document.getElementById('cfgOwaApiKey');
            const owaApiKeyHint = document.getElementById('owaApiKeyHint');
            if (c.openwa_api_key && c.openwa_api_key.length > 0) {
                owaApiKeyInput.dataset.fullKey = c.openwa_api_key;
                owaApiKeyInput.value = '••••••••' + c.openwa_api_key.slice(-8);
                owaApiKeyHint.style.display = 'block';
                owaApiKeyInput.required = false;
            } else {
                delete owaApiKeyInput.dataset.fullKey;
                owaApiKeyInput.value = '';
                owaApiKeyHint.style.display = 'none';
                owaApiKeyInput.required = false;
            }

            const owaWebhookInput = document.getElementById('cfgOwaWebhook');
            const owaWebhookHint = document.getElementById('owaWebhookHint');
            if (c.openwa_webhook_secret && c.openwa_webhook_secret.length > 0) {
                owaWebhookInput.dataset.fullKey = c.openwa_webhook_secret;
                owaWebhookInput.value = '••••••••' + c.openwa_webhook_secret.slice(-8);
                owaWebhookHint.style.display = 'block';
                owaWebhookInput.required = false;
            } else {
                delete owaWebhookInput.dataset.fullKey;
                owaWebhookInput.value = '';
                owaWebhookHint.style.display = 'none';
                owaWebhookInput.required = false;
            }

            // Evolution-Go credentials
            document.getElementById('cfgEvoBaseUrl').value = c['evolution_go_base_url'] || '';
            document.getElementById('cfgEvoInstanceName').value = c['evolution_go_instance_name'] || '';

            const evoApiKeyInput = document.getElementById('cfgEvoApiKey');
            const evoApiKeyHint = document.getElementById('evoApiKeyHint');
            if (c['evolution_go_api_key'] && c['evolution_go_api_key'].length > 0) {
                evoApiKeyInput.dataset.fullKey = c['evolution_go_api_key'];
                evoApiKeyInput.value = '••••••••' + c['evolution_go_api_key'].slice(-8);
                evoApiKeyHint.style.display = 'block';
                evoApiKeyInput.required = false;
            } else {
                delete evoApiKeyInput.dataset.fullKey;
                evoApiKeyInput.value = '';
                evoApiKeyHint.style.display = 'none';
                evoApiKeyInput.required = false;
            }

            const evoWebhookInput = document.getElementById('cfgEvoWebhook');
            const evoWebhookHint = document.getElementById('evoWebhookHint');
            if (c['evolution_go_webhook_secret'] && c['evolution_go_webhook_secret'].length > 0) {
                evoWebhookInput.dataset.fullKey = c['evolution_go_webhook_secret'];
                evoWebhookInput.value = '••••••••' + c['evolution_go_webhook_secret'].slice(-8);
                evoWebhookHint.style.display = 'block';
                evoWebhookInput.required = false;
            } else {
                delete evoWebhookInput.dataset.fullKey;
                evoWebhookInput.value = '';
                evoWebhookHint.style.display = 'none';
                evoWebhookInput.required = false;
            }

            // HttpSMS credentials
            document.getElementById('cfgHttpsmsBaseUrl').value       = c.httpsms_base_url    || 'https://sms.origen.ar';
            document.getElementById('cfgHttpsmsFromNumber').value    = c.httpsms_from_number || '';

            const httpsmsApiKeyInput = document.getElementById('cfgHttpsmsApiKey');
            const httpsmsApiKeyHint  = document.getElementById('httpsmsApiKeyHint');
            if (c.httpsms_api_key && c.httpsms_api_key.length > 0) {
                httpsmsApiKeyInput.dataset.fullKey = c.httpsms_api_key;
                httpsmsApiKeyInput.value = '••••••••' + c.httpsms_api_key.slice(-8);
                httpsmsApiKeyHint.style.display = 'block';
                httpsmsApiKeyInput.required = false;
            } else {
                delete httpsmsApiKeyInput.dataset.fullKey;
                httpsmsApiKeyInput.value = '';
                httpsmsApiKeyHint.style.display = 'none';
                httpsmsApiKeyInput.required = false;
            }

            const httpsmsSigningKeyInput = document.getElementById('cfgHttpsmsSigningKey');
            const httpsmsSigningKeyHint  = document.getElementById('httpsmsSigningKeyHint');
            if (c.httpsms_signing_key && c.httpsms_signing_key.length > 0) {
                httpsmsSigningKeyInput.dataset.fullKey = c.httpsms_signing_key;
                httpsmsSigningKeyInput.value = '••••••••' + c.httpsms_signing_key.slice(-8);
                httpsmsSigningKeyHint.style.display = 'block';
                httpsmsSigningKeyInput.required = false;
            } else {
                delete httpsmsSigningKeyInput.dataset.fullKey;
                httpsmsSigningKeyInput.value = '';
                httpsmsSigningKeyHint.style.display = 'none';
                httpsmsSigningKeyInput.required = false;
            }

            // Show/hide sections based on active vendor
            handleVendorChange();
            
            // Logo previews
            const prevWsp = document.getElementById('previewWsp');
            const nameWsp = document.getElementById('currentLogoWspName');
            if (c.logo_wsp) {
                nameWsp.textContent = c.logo_wsp;
                prevWsp.innerHTML = `<img src="${baseUrl}/public/images/wsp_email/logo_wsp/${c.logo_wsp}?t=${Date.now()}" style="max-height:60px; border:1px solid #ddd; padding:2px;">`;
                prevWsp.style.display = 'block';
            } else {
                nameWsp.textContent = 'None';
                prevWsp.style.display = 'none';
            }

            const prevEmail = document.getElementById('previewEmail');
            const nameEmail = document.getElementById('currentLogoEmailName');
            if (c.logo_email) {
                nameEmail.textContent = c.logo_email;
                prevEmail.innerHTML = `<img src="${baseUrl}/public/images/wsp_email/logo_email/${c.logo_email}?t=${Date.now()}" style="max-height:60px; border:1px solid #ddd; padding:2px;">`;
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
            document.getElementById('cfgNotifyCancelled').checked = parseInt(c.notify_cancelled ?? 0) === 1;

            // Sending Window
            document.getElementById('cfgSendWeekdayStart').value = c.send_weekday_start ?? 7;
            document.getElementById('cfgSendWeekdayEnd').value   = c.send_weekday_end   ?? 21;
            document.getElementById('cfgSendSaturdayEnabled').checked = parseInt(c.send_saturday_enabled ?? 1) === 1;
            document.getElementById('cfgSendSaturdayStart').value = c.send_saturday_start ?? 8;
            document.getElementById('cfgSendSaturdayEnd').value   = c.send_saturday_end   ?? 13;
            document.getElementById('cfgSendSundayEnabled').checked = parseInt(c.send_sunday_enabled ?? 0) === 1;
            document.getElementById('cfgSendSundayStart').value  = c.send_sunday_start   ?? 9;
            document.getElementById('cfgSendSundayEnd').value    = c.send_sunday_end     ?? 12;
            // ... Templates are now managed in the separate Manager Modal ...
            document.getElementById('facilityConfigForm').style.display = 'block';

            // Handle vendor-specific field visibility
            handleVendorChange();


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

            // Sub-tabs: wire up Configuración / Recalls buttons
            wireFacilitySubTabs(facilityId, isInactive);

            // Default: show Config sub-tab
            switchFacilitySubTab('config');
        });
}

/**
 * Wires the sub-tab buttons inside the facility config card.
 * Called every time a facility is selected.
 */
function wireFacilitySubTabs(facilityId, isInactive) {
    // Remove existing sub-tab nav if present
    const existing = document.getElementById('facilitySubTabNav');
    if (existing) existing.remove();

    // Insert sub-tab nav before the form
    const form = document.getElementById('facilityConfigForm');
    const nav  = document.createElement('ul');
    nav.id = 'facilitySubTabNav';
    nav.className = 'nav nav-tabs mb-3';
    nav.innerHTML = `
        <li class="nav-item">
            <a href="#" id="subTabBtnConfig" class="nav-link active"
               onclick="switchFacilitySubTab('config'); return false;">
                <i class="fas fa-cog me-1"></i><?php echo js_escape(xlt('Configuration')); ?>
            </a>
        </li>
        <li class="nav-item">
            <a href="#" id="subTabBtnRecalls" class="nav-link"
               onclick="switchFacilitySubTab('recalls'); return false;">
                <i class="fas fa-redo-alt me-1"></i><?php echo js_escape(xlt('Recalls')); ?>
            </a>
        </li>`;
    form.parentNode.insertBefore(nav, form);

    // Store current facility id for recall operations
    document.getElementById('facilityConfigCard').dataset.facilityId = facilityId;
}

/**
 * Switches between the Config and Recalls sub-tabs inside the facility panel.
 */
function switchFacilitySubTab(tab) {
    const configContent  = document.getElementById('facility-subtab-config');
    const recallsContent = document.getElementById('facility-subtab-recalls');
    const btnConfig  = document.getElementById('subTabBtnConfig');
    const btnRecalls = document.getElementById('subTabBtnRecalls');

    if (!configContent || !recallsContent) return;

    if (tab === 'config') {
        configContent.classList.remove('d-none');
        recallsContent.classList.add('d-none');
        btnConfig?.classList.add('active');
        btnRecalls?.classList.remove('active');
    } else {
        configContent.classList.add('d-none');
        recallsContent.classList.remove('d-none');
        btnConfig?.classList.remove('active');
        btnRecalls?.classList.add('active');

        // Lazy-load recall config when tab is first opened
        const facilityId = document.getElementById('facilityConfigCard').dataset.facilityId;
        if (facilityId) loadRecallConfig(facilityId);
    }
}

/* =========================================================================
   Facility → Recalls sub-tab: schedule + template
   ========================================================================= */
let recallSchedSeq = 0;

/**
 * Loads the recall schedule and template for a facility into the sub-tab.
 */
function loadRecallConfig(facilityId) {
    // Load schedule
    fetch(`${moduleRoot}/pages/ajax/get_recall_schedule.php?facility_id=${encodeURIComponent(facilityId)}`)
        .then(r => r.json())
        .then(data => {
            const slots = data.data || [];
            const tbody = document.getElementById('recallScheduleBody');
            tbody.innerHTML = '';
            recallSchedSeq = 0;
            if (slots.length) {
                slots.forEach(s => appendRecallScheduleRow(s));
            } else {
                appendRecallScheduleRow({ seq: 1, days_before: 7, enabled_wsp: 1, enabled_email: 1, enabled: 1 });
            }
        })
        .catch(e => console.error('Recall schedule load error', e));

    // Load template
    fetch(`${moduleRoot}/pages/ajax/get_recall_template.php?facility_id=${encodeURIComponent(facilityId)}`)
        .then(r => r.json())
        .then(data => {
            const tpl = data.data || {};
            document.getElementById('recallWspMessage').value    = tpl.wsp_message    || '';
            document.getElementById('recallEmailSubject').value  = tpl.email_subject  || '';
            document.getElementById('recallEmailMessage').value  = tpl.email_message  || '';
            document.getElementById('recallTemplateEnabled').checked = parseInt(tpl.enabled ?? 1) === 1;
        })
        .catch(e => console.error('Recall template load error', e));
}

function appendRecallScheduleRow(slot) {
    recallSchedSeq++;
    const n   = recallSchedSeq;
    const d   = slot.days_before  ?? 7;
    const wsp = parseInt(slot.enabled_wsp   ?? 1) === 1;
    const em  = parseInt(slot.enabled_email ?? 1) === 1;
    const en  = parseInt(slot.enabled       ?? 1) === 1;

    const tr = document.createElement('tr');
    tr.dataset.seq = n;
    tr.innerHTML = `
        <td class="text-center fw-bold text-muted">${n}</td>
        <td>
            <input type="number" class="form-control form-control-sm" min="0" max="3650"
                   name="recall_sched[${n}][days_before]" value="${d}">
        </td>
        <td class="text-center">
            <label class="custom-checkbox">
                <input type="checkbox" name="recall_sched[${n}][enabled_wsp]" value="1" ${wsp ? 'checked' : ''}>
                <span class="slider"></span>
            </label>
        </td>
        <td class="text-center">
            <label class="custom-checkbox">
                <input type="checkbox" name="recall_sched[${n}][enabled_email]" value="1" ${em ? 'checked' : ''}>
                <span class="slider"></span>
            </label>
        </td>
        <td class="text-center">
            <label class="custom-checkbox">
                <input type="checkbox" name="recall_sched[${n}][enabled]" value="1" ${en ? 'checked' : ''}>
                <span class="slider"></span>
            </label>
        </td>
        <td class="text-center">
            <button type="button" class="btn btn-xs btn-outline-danger"
                    onclick="this.closest('tr').remove(); renumberRecallSchedule();">
                <i class="fas fa-times"></i>
            </button>
        </td>`;
    document.getElementById('recallScheduleBody').appendChild(tr);
}

function addRecallScheduleRow() {
    appendRecallScheduleRow({ seq: recallSchedSeq + 1, days_before: 7, enabled_wsp: 1, enabled_email: 1, enabled: 1 });
}

function renumberRecallSchedule() {
    document.querySelectorAll('#recallScheduleBody tr').forEach((tr, i) => {
        tr.querySelector('td:first-child').textContent = i + 1;
        tr.dataset.seq = i + 1;
    });
}

/**
 * Saves both the recall schedule and template for the current facility.
 */
function saveRecallConfig() {
    const facilityId = document.getElementById('facilityConfigCard').dataset.facilityId;
    if (!facilityId) { alert('<?php echo js_escape(xlt("No facility selected.")); ?>'); return; }

    // Collect schedule rows
    const slots = [];
    document.querySelectorAll('#recallScheduleBody tr').forEach((tr, i) => {
        const seq     = i + 1;
        const days    = parseInt(tr.querySelector(`input[name="recall_sched[${tr.dataset.seq}][days_before]"]`)?.value ?? 7);
        const wsp     = tr.querySelector(`input[name="recall_sched[${tr.dataset.seq}][enabled_wsp]"]`)?.checked ? 1 : 0;
        const email   = tr.querySelector(`input[name="recall_sched[${tr.dataset.seq}][enabled_email]"]`)?.checked ? 1 : 0;
        const enabled = tr.querySelector(`input[name="recall_sched[${tr.dataset.seq}][enabled]"]`)?.checked ? 1 : 0;
        slots.push({ seq, days_before: days, enabled_wsp: wsp, enabled_email: email, enabled });
    });

    const saveBtn = document.querySelector('button[onclick="saveRecallConfig()"]');
    const saveMsg = document.getElementById('recallSaveMsg');
    if (saveBtn) { saveBtn.disabled = true; saveBtn.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i><?php echo js_escape(xlt("Saving...")); ?>'; }

    // Save schedule
    const schedBody = new FormData();
    schedBody.append('facility_id', facilityId);
    schedBody.append('schedule_json', JSON.stringify(slots));

    const schedPromise = fetch(`${moduleRoot}/pages/ajax/save_recall_schedule.php`, { method: 'POST', body: schedBody })
        .then(r => r.json());

    // Save template
    const tplBody = new FormData();
    tplBody.append('facility_id',    facilityId);
    tplBody.append('wsp_message',    document.getElementById('recallWspMessage').value);
    tplBody.append('email_subject',  document.getElementById('recallEmailSubject').value);
    tplBody.append('email_message',  document.getElementById('recallEmailMessage').value);
    tplBody.append('enabled',        document.getElementById('recallTemplateEnabled').checked ? 1 : 0);

    const tplPromise = fetch(`${moduleRoot}/pages/ajax/save_recall_template.php`, { method: 'POST', body: tplBody })
        .then(r => r.json());

    Promise.all([schedPromise, tplPromise])
        .then(([sched, tpl]) => {
            if (sched.success && tpl.success) {
                if (saveMsg) { saveMsg.style.display = 'inline'; setTimeout(() => { saveMsg.style.display = 'none'; }, 3000); }
            } else {
                alert('<?php echo js_escape(xlt("Error saving recall configuration.")); ?> ' + (sched.error || tpl.error || ''));
            }
        })
        .catch(e => { alert('<?php echo js_escape(xlt("Network error.")); ?> ' + e.message); })
        .finally(() => {
            if (saveBtn) { saveBtn.disabled = false; saveBtn.innerHTML = '<i class="fas fa-save me-1"></i><?php echo js_escape(xlt("Save Recall Config")); ?>'; }
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

function toggleApiKeyVisibility() {
    const input = document.getElementById('cfgApiKey');
    const icon = document.getElementById('apiKeyToggleIcon');

    if (!input || !icon) {
        console.error('API Key input or icon not found');
        return;
    }

    if (input.type === 'password') {
        input.type = 'text';
        icon.className = 'fas fa-eye-slash';
    } else {
        input.type = 'password';
        icon.className = 'fas fa-eye';
    }
}

function toggleUltraApiKey() {
    const input = document.getElementById('cfgUltraApiKey');
    const icon = document.getElementById('ultraApiKeyIcon');
    if (input && icon) {
        if (input.type === 'password') {
            // Show full key
            input.type = 'text';
            input.value = input.dataset.fullKey || input.value;
            icon.className = 'fas fa-eye-slash';
        } else {
            // Hide key again
            input.type = 'password';
            if (input.dataset.fullKey) {
                input.value = '••••••••' + input.dataset.fullKey.slice(-8);
            }
            icon.className = 'fas fa-eye';
        }
    }
}

function toggleWaApiKey() {
    const input = document.getElementById('cfgWaApiKey');
    const icon = document.getElementById('waApiKeyIcon');
    if (input && icon) {
        if (input.type === 'password') {
            // Show full key
            input.type = 'text';
            input.value = input.dataset.fullKey || input.value;
            icon.className = 'fas fa-eye-slash';
        } else {
            // Hide key again
            input.type = 'password';
            if (input.dataset.fullKey) {
                input.value = '••••••••' + input.dataset.fullKey.slice(-8);
            }
            icon.className = 'fas fa-eye';
        }
    }
}

function toggleWaWebhook() {
    const input = document.getElementById('cfgWaWebhook');
    const icon = document.getElementById('waWebhookIcon');
    if (input && icon) {
        if (input.type === 'password') {
            // Show full secret
            input.type = 'text';
            input.value = input.dataset.fullKey || input.value;
            icon.className = 'fas fa-eye-slash';
        } else {
            // Hide secret again
            input.type = 'password';
            if (input.dataset.fullKey) {
                input.value = '••••••••' + input.dataset.fullKey.slice(-8);
            }
            icon.className = 'fas fa-eye';
        }
    }
}

function toggleOwaApiKey() {
    const input = document.getElementById('cfgOwaApiKey');
    const icon = document.getElementById('owaApiKeyIcon');
    if (input && icon) {
        if (input.type === 'password') {
            input.type = 'text';
            input.value = input.dataset.fullKey || input.value;
            icon.className = 'fas fa-eye-slash';
        } else {
            input.type = 'password';
            if (input.dataset.fullKey) {
                input.value = '••••••••' + input.dataset.fullKey.slice(-8);
            }
            icon.className = 'fas fa-eye';
        }
    }
}

function toggleOwaWebhook() {
    const input = document.getElementById('cfgOwaWebhook');
    const icon = document.getElementById('owaWebhookIcon');
    if (input && icon) {
        if (input.type === 'password') {
            input.type = 'text';
            input.value = input.dataset.fullKey || input.value;
            icon.className = 'fas fa-eye-slash';
        } else {
            input.type = 'password';
            if (input.dataset.fullKey) {
                input.value = '••••••••' + input.dataset.fullKey.slice(-8);
            }
            icon.className = 'fas fa-eye';
        }
    }
}

function toggleEvoApiKey() {
    const input = document.getElementById('cfgEvoApiKey');
    const icon = document.getElementById('evoApiKeyIcon');
    if (input && icon) {
        if (input.type === 'password') {
            input.type = 'text';
            input.value = input.dataset.fullKey || input.value;
            icon.className = 'fas fa-eye-slash';
        } else {
            input.type = 'password';
            if (input.dataset.fullKey) {
                input.value = '••••••••' + input.dataset.fullKey.slice(-8);
            }
            icon.className = 'fas fa-eye';
        }
    }
}

function toggleEvoWebhook() {
    const input = document.getElementById('cfgEvoWebhook');
    const icon = document.getElementById('evoWebhookIcon');
    if (input && icon) {
        if (input.type === 'password') {
            input.type = 'text';
            input.value = input.dataset.fullKey || input.value;
            icon.className = 'fas fa-eye-slash';
        } else {
            input.type = 'password';
            if (input.dataset.fullKey) {
                input.value = '••••••••' + input.dataset.fullKey.slice(-8);
            }
            icon.className = 'fas fa-eye';
        }
    }
}

function toggleHttpsmsApiKey() {
    const input = document.getElementById('cfgHttpsmsApiKey');
    const icon  = document.getElementById('httpsmsApiKeyIcon');
    if (input && icon) {
        if (input.type === 'password') {
            input.type = 'text';
            input.value = input.dataset.fullKey || input.value;
            icon.className = 'fas fa-eye-slash';
        } else {
            input.type = 'password';
            if (input.dataset.fullKey) {
                input.value = '••••••••' + input.dataset.fullKey.slice(-8);
            }
            icon.className = 'fas fa-eye';
        }
    }
}

function toggleHttpsmsSigningKey() {
    const input = document.getElementById('cfgHttpsmsSigningKey');
    const icon  = document.getElementById('httpsmsSigningKeyIcon');
    if (input && icon) {
        if (input.type === 'password') {
            input.type = 'text';
            input.value = input.dataset.fullKey || input.value;
            icon.className = 'fas fa-eye-slash';
        } else {
            input.type = 'password';
            if (input.dataset.fullKey) {
                input.value = '••••••••' + input.dataset.fullKey.slice(-8);
            }
            icon.className = 'fas fa-eye';
        }
    }
}

/**
 * Show/hide sections based on selected active vendor
 */
function handleVendorChange() {
    const vendor = document.getElementById('cfgCurrentVendor').value;
    const ultramsgConfig = document.getElementById('ultramsgConfig');
    const wasenderConfig = document.getElementById('wasenderConfig');
    const openwaConfig   = document.getElementById('openwaConfig');
    const evoConfig      = document.getElementById('evolutionGoConfig');
    const httpsmsConfig  = document.getElementById('httpsmsConfig');

    const sections = [ultramsgConfig, wasenderConfig, openwaConfig, evoConfig, httpsmsConfig];
    sections.forEach(s => { if (s) s.style.display = 'none'; });

    if (vendor === 'ultramsg') {
        if (ultramsgConfig) ultramsgConfig.style.display = 'block';
    } else if (vendor === 'wasenderapi') {
        if (wasenderConfig) wasenderConfig.style.display = 'block';
    } else if (vendor === 'openwa') {
        if (openwaConfig) openwaConfig.style.display = 'block';
    } else if (vendor === 'evolution-go') {
        if (evoConfig) evoConfig.style.display = 'block';
    } else if (vendor === 'httpsms') {
        if (httpsmsConfig) httpsmsConfig.style.display = 'block';
    }
}

// Add event listener for vendor change (only when user manually changes)
document.addEventListener('DOMContentLoaded', function() {
    const vendorSelect = document.getElementById('cfgCurrentVendor');
    if (vendorSelect) {
        vendorSelect.addEventListener('change', handleVendorChange);
        // Don't call handleVendorChange() here - it's called by loadFacilityConfig()
    }

    // Live preview when selecting a logo file (before saving)
    ['cfgLogoWsp', 'cfgLogoEmail'].forEach(function(id) {
        const input = document.getElementById(id);
        if (!input) return;
        input.addEventListener('change', function() {
            const nameId   = id === 'cfgLogoWsp' ? 'currentLogoWspName' : 'currentLogoEmailName';
            const prevId   = id === 'cfgLogoWsp' ? 'previewWsp'        : 'previewEmail';
            const nameSpan = document.getElementById(nameId);
            const prevDiv  = document.getElementById(prevId);
            if (this.files && this.files[0]) {
                nameSpan.textContent = this.files[0].name;
                prevDiv.innerHTML = `<img src="${URL.createObjectURL(this.files[0])}" style="max-height:60px; border:1px solid #ddd; padding:2px;">`;
                prevDiv.style.display = 'block';
            }
        });
    });
});

function renumberSchedule() {
    document.querySelectorAll('#scheduleBody tr').forEach((tr, i) => {
        tr.cells[0].textContent = i + 1;
    });
}

document.getElementById('facilityConfigForm')?.addEventListener('submit', function(e) {
    e.preventDefault();
    const btn = e.target.querySelector('button[type="submit"]');
    if (!btn) {
        alert('Error: Submit button not found');
        return;
    }
    const oldHtml = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i> <?php echo xlt('Saving...'); ?>';

    const fd = new FormData(this);
    fd.set('enabled_wsp',   document.getElementById('cfgEnabledWsp').checked   ? 1 : 0);
    fd.set('enabled_email', document.getElementById('cfgEnabledEmail').checked ? 1 : 0);
    fd.set('notify_cancelled', document.getElementById('cfgNotifyCancelled').checked ? 1 : 0);
    fd.set('send_saturday_enabled', document.getElementById('cfgSendSaturdayEnabled').checked ? 1 : 0);
    fd.set('send_sunday_enabled',   document.getElementById('cfgSendSundayEnabled').checked ? 1 : 0);

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

function escJs(s) {
    return String(s || '').replace(/\\/g,'\\\\').replace(/'/g,"\\'").replace(/"/g,'\\"').replace(/\n/g,'\\n');
}

// Auto-load stats on dashboard tab
<?php if ($activeTab === 'dashboard'): ?>
renderStatusFilters(); // Render status filter checkboxes
loadStats();
<?php endif; ?>

// Auto-load schedules if on schedules tab
<?php if ($activeTab === 'schedules'): ?>
loadApptStatuses();
loadSchedules();
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

// =========================================================================
// Manual Notification Functions
// =========================================================================

/**
 * Opens the manual notification modal
 */
function openManualNotify(eid, pid, type, recipient, phone, patientName, email) {
    document.getElementById('mnEid').value = eid;
    document.getElementById('mnPid').value = pid;
    document.getElementById('mnType').value = type;
    document.getElementById('mnRecipient').value = recipient;
    document.getElementById('mnContact').value = phone;
    document.getElementById('mnEmail').value = email || '';
    document.getElementById('mnPatientName').textContent = patientName || 'Unknown';
    
    // Set channel badge
    const badge = document.getElementById('mnChannelBadge');
    if (type === 'WSP') {
        badge.innerHTML = '<span class="badge bg-success"><i class="fab fa-whatsapp me-1"></i>WhatsApp</span>';
    } else {
        badge.innerHTML = '<span class="badge bg-primary"><i class="fas fa-envelope me-1"></i>Email</span>';
    }
    
    // Default message template
    let defaultMsg = '';
    if (type === 'WSP') {
        defaultMsg = `Hola ${patientName}, le escribimos desde la clínica para recordarle su próxima cita. Por favor contáctenos si tiene alguna pregunta.`;
    } else {
        defaultMsg = `<p>Estimado/a ${patientName},</p><p>Le escribimos desde la clínica para informarle sobre su próxima cita.</p><p>Saludos cordiales.</p>`;
    }
    document.getElementById('mnMessage').value = defaultMsg;
    
    // Show modal
    try {
        const modalEl = document.getElementById('modalManualNotify');
        if (!modalEl) {
            console.error('Modal element not found!');
            alert('Error: Modal not found in page.');
            return;
        }
        const modal = new bootstrap.Modal(modalEl);
        modal.show();
    } catch (e) {
        console.error('Error showing modal:', e);
        alert('Error showing modal: ' + e.message);
    }
}

/**
 * Logs the manual notification and opens wa.me or mailto:
 */
function executeManualNotify() {
    const eid     = document.getElementById('mnEid').value;
    const pid     = document.getElementById('mnPid').value;
    const type    = document.getElementById('mnType').value;
    const recipient = document.getElementById('mnRecipient').value;
    const phone   = document.getElementById('mnContact').value;
    const email   = document.getElementById('mnEmail').value;
    const msg     = document.getElementById('mnMessage').value;

    // Determine contact based on type
    let contact = '';
    if (type === 'WSP') {
        contact = phone;
    } else {
        contact = email;
    }

    if (!contact) {
        const contactType = type === 'WSP' ? 'phone number' : 'email address';
        alert(`No ${contactType} available for this patient.`);
        return;
    }

    // 1. Log the notification attempt
    const formData = new URLSearchParams({
        pc_eid: eid,
        pid: pid,
        type: type,
        recipient: recipient,
        message: msg,
        phone: type === 'WSP' ? contact : '',
        email_addr: type === 'Email' ? contact : ''
    });

    fetch(`${moduleRoot}/pages/ajax/log_manual_notify.php`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: formData
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            // 2. Open the appropriate app
            if (type === 'WSP') {
                // Clean phone number for wa.me (remove +, spaces, dashes)
                const cleanPhone = contact.replace(/\D/g, '');
                const waUrl = `https://wa.me/${cleanPhone}?text=${encodeURIComponent(msg)}`;
                window.open(waUrl, '_blank');
            } else if (type === 'Email') {
                // Send email via PHPMailer on server
                const facilityId = document.getElementById('mnFacilityId').value || 3;
                const emailData = new URLSearchParams({
                    to: contact,
                    subject: 'Recordatorio de Cita',
                    message: msg,
                    facility_id: facilityId
                });

                fetch(`${moduleRoot}/pages/ajax/send_manual_email.php`, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: emailData
                })
                .then(r => r.json())
                .then(emailResult => {
                    if (emailResult.success) {
                        alert('✅ Email sent successfully to ' + contact);
                    } else {
                        alert('❌ Email send failed: ' + (emailResult.message || 'Unknown error'));
                    }
                })
                .catch(err => {
                    alert('❌ Email send error: ' + err);
                });
            }
            
            // Close modal
            try {
                bootstrap.Modal.getInstance(document.getElementById('modalManualNotify')).hide();
            } catch (e) {
                // Modal might already be hidden
            }
            
            // Refresh patient list
            searchPatients();
        } else {
            alert('Error logging notification: ' + (data.message || 'Unknown error'));
        }
    })
    .catch(err => {
        alert('Error: ' + err);
    });
}

// =========================================================================
// Schedules Tab Functions
// =========================================================================

function loadApptStatuses() {
    fetch(`${moduleRoot}/pages/ajax/get_appt_statuses.php`)
        .then(r => r.json())
        .then(data => {
            const select = document.getElementById('schedStatus');
            select.innerHTML = '<option value="">' + '<?php echo js_escape(xlt('All Statuses')); ?>' + '</option>';
            if (data.statuses) {
                data.statuses.forEach(s => {
                    const opt = document.createElement('option');
                    opt.value = s.id;
                    opt.textContent = s.title;
                    select.appendChild(opt);
                });
            }
        })
        .catch(err => console.error('Error loading statuses:', err));
}

function loadSchedules() {
    const fromDate   = document.getElementById('schedFromDate').value;
    const toDate     = document.getElementById('schedToDate').value;
    const patient    = document.getElementById('schedPatient').value;
    const apptStatus = document.getElementById('schedStatus').value;
    const facilityId = document.getElementById('schedFacility').value;
    const tbody      = document.getElementById('schedulesTableBody');

    tbody.innerHTML = '<tr><td colspan="8" class="text-center py-4"><i class="fas fa-spinner fa-spin fa-2x text-success"></i></td></tr>';

    const params = new URLSearchParams({
        from_date: fromDate,
        to_date: toDate,
        patient: patient,
        appt_status: apptStatus,
        facility_id: facilityId
    });

    fetch(`${moduleRoot}/pages/ajax/get_schedules.php?${params.toString()}`)
        .then(r => r.json())
        .then(data => {
            const rows = data.rows || [];
            if (!rows.length) {
                tbody.innerHTML = '<tr><td colspan="7" class="text-center text-muted py-4"><?php echo js_escape(xlt('No appointments found.')); ?></td></tr>';
                return;
            }
            tbody.innerHTML = rows.map(r => {
                const rawStatus = r.pc_apptstatus || '';
                const isPending = rawStatus === '^' || rawStatus === '-pending';
                const statusBadge = r.status_title ?
                    (isPending
                        ? `<span class="badge" style="background:#FFF3E0;color:#E65100">${escHtml(r.status_title)}</span>`
                        : `<span class="badge" style="background:#E3F2FD;color:#1565C0">${escHtml(r.status_title)}</span>`) :
                    `<span class="badge bg-light text-dark">${escHtml(rawStatus || '-')}</span>`;

                const typeLabel = getApptTypeLabel(r.pc_catid, r.pc_title);

                // Check if appointment is in the past
                const apptDateTime = r.pc_eventDateRaw ? `${r.pc_eventDateRaw} ${r.pc_startTime}` : '';
                const isPast = apptDateTime && new Date(apptDateTime.replace(' ', 'T')) < new Date();

                // Patient action buttons - skip if cancelled, blocked, or past
                const isBlocked = !r.template_status;  // empty template_status = blocked
                const isCancelled = (r.template_status === '-cancelled' || r.template_status === '-error');
                const canPtWsp = !isBlocked && !isCancelled && !isPast && r.hipaa_allowsms === 'YES' && r.phone_cell;
                const canPtEmail = !isBlocked && !isCancelled && !isPast && r.hipaa_allowemail === 'YES' && r.email;

                // Provider action buttons (only for Telehealth catid=80 and HBC catid=70/71)
                const isTelehealthOrHBC = (r.pc_catid === 80 || r.pc_catid === 70 || r.pc_catid === 71);
                const canProvWsp = !isBlocked && !isCancelled && !isPast && isTelehealthOrHBC && r.provider_phone;
                const canProvEmail = !isBlocked && !isCancelled && !isPast && isTelehealthOrHBC && r.provider_email;

                // Pt. Actions column
                let ptActions = '<div class="btn-group btn-group-sm">';
                if (canPtWsp) {
                    ptActions += `<button class="btn btn-outline-success" onclick="openScheduleNotify(${r.pc_eid}, ${r.pc_pid}, ${r.pc_catid}, '${escHtml(r.pc_apptstatus)}', 'WSP', 'patient', '${escJs(r.patient_name)}', '${escJs(r.phone_cell)}', '${escJs(r.email)}', '${escJs(r.pc_eventDate)}', '${escJs(r.pc_startTime)}', '${escJs(r.provider_name)}', '${escJs(r.facility_name)}', '${escJs(r.street || '')}', '${escJs(r.city || '')}', ${r.pc_facility}, '${escJs(r.template_status || '-scheduled')}')" title="<?php echo attr(xlt('Send WhatsApp to Patient')); ?>">
                        <i class="fab fa-whatsapp"></i>
                    </button>`;
                }
                if (canPtEmail) {
                    ptActions += `<button class="btn btn-outline-primary" onclick="openScheduleNotify(${r.pc_eid}, ${r.pc_pid}, ${r.pc_catid}, '${escHtml(r.pc_apptstatus)}', 'Email', 'patient', '${escJs(r.patient_name)}', '${escJs(r.phone_cell)}', '${escJs(r.email)}', '${escJs(r.pc_eventDate)}', '${escJs(r.pc_startTime)}', '${escJs(r.provider_name)}', '${escJs(r.facility_name)}', '${escJs(r.street || '')}', '${escJs(r.city || '')}', ${r.pc_facility}, '${escJs(r.template_status || '-scheduled')}')" title="<?php echo attr(xlt('Send Email to Patient')); ?>">
                        <i class="fas fa-envelope"></i>
                    </button>`;
                }
                if (isCancelled) {
                    ptActions += '<span class="text-muted small" title="<?php echo attr(xlt('Cancelled - no notifications sent')); ?>">🚫</span>';
                } else if (isBlocked) {
                    ptActions += '<span class="text-muted small" title="<?php echo attr(xlt('Already notified/confirmed')); ?>">✅</span>';
                } else if (isPast) {
                    ptActions += '<span class="text-muted small" title="<?php echo attr(xlt('Appointment already passed')); ?>">⏰</span>';
                } else if (!canPtWsp && !canPtEmail) {
                    ptActions += '<span class="text-muted small">—</span>';
                }
                ptActions += '</div>';

                // Prov. Actions column
                let provActions = '<div class="btn-group btn-group-sm">';
                if (canProvWsp) {
                    provActions += `<button class="btn btn-outline-success" onclick="openScheduleNotify(${r.pc_eid}, ${r.pc_pid}, ${r.pc_catid}, '${escHtml(r.pc_apptstatus)}', 'WSP', 'provider', '${escJs(r.patient_name)}', '${escJs(r.provider_phone)}', '${escJs(r.provider_email)}', '${escJs(r.pc_eventDate)}', '${escJs(r.pc_startTime)}', '${escJs(r.provider_name)}', '${escJs(r.facility_name)}', '${escJs(r.street || '')}', '${escJs(r.city || '')}', ${r.pc_facility}, '${escJs(r.template_status || '-scheduled')}')" title="<?php echo attr(xlt('Send WhatsApp to Provider')); ?>">
                        <i class="fab fa-whatsapp"></i>
                    </button>`;
                }
                if (canProvEmail) {
                    provActions += `<button class="btn btn-outline-primary" onclick="openScheduleNotify(${r.pc_eid}, ${r.pc_pid}, ${r.pc_catid}, '${escHtml(r.pc_apptstatus)}', 'Email', 'provider', '${escJs(r.patient_name)}', '${escJs(r.provider_phone)}', '${escJs(r.provider_email)}', '${escJs(r.pc_eventDate)}', '${escJs(r.pc_startTime)}', '${escJs(r.provider_name)}', '${escJs(r.facility_name)}', '${escJs(r.street || '')}', '${escJs(r.city || '')}', ${r.pc_facility}, '${escJs(r.template_status || '-scheduled')}')" title="<?php echo attr(xlt('Send Email to Provider')); ?>">
                        <i class="fas fa-envelope"></i>
                    </button>`;
                }
                if (isCancelled) {
                    provActions += '<span class="text-muted small" title="<?php echo attr(xlt('Cancelled - no notifications sent')); ?>">🚫</span>';
                } else if (isPast) {
                    provActions += '<span class="text-muted small" title="<?php echo attr(xlt('Appointment already passed')); ?>">⏰</span>';
                } else if (isBlocked) {
                    provActions += '<span class="text-muted small" title="<?php echo attr(xlt('Already notified/confirmed')); ?>">✅</span>';
                } else if (!isTelehealthOrHBC) {
                    provActions += '<span class="text-muted small" title="<?php echo attr(xlt('Only for Telehealth and HBC')); ?>">—</span>';
                } else if (!canProvWsp && !canProvEmail) {
                    provActions += '<span class="text-muted small" title="<?php echo attr(xlt('Provider has no phone/email configured')); ?>">⚠️</span>';
                }
                provActions += '</div>';

                return `
                <tr>
                    <td><strong>${escHtml(r.pc_eventDate)}</strong><br><small class="text-muted">${escHtml(r.pc_startTime)} - ${escHtml(r.pc_endTime)}</small></td>
                    <td><strong>${escHtml(r.patient_name)}</strong><br><small class="text-muted">PID: ${r.pc_pid}</small></td>
                    <td>${escHtml(r.provider_name || '-')}<br><small class="text-muted">${escHtml(r.provider_phone || '—')}</small></td>
                    <td>${typeLabel}</td>
                    <td>${statusBadge}</td>
                    <td class="text-center">${ptActions}</td>
                    <td class="text-center">${provActions}</td>
                </tr>`;
            }).join('');
        })
        .catch(err => {
            tbody.innerHTML = `<tr><td colspan="7" class="text-center text-danger py-4">Error: ${err}</td></tr>`;
        });
}

/**
 * Get appointment type label based on pc_catid
 */
function getApptTypeLabel(catid, title) {
    const catId = parseInt(catid) || 0;
    if (catId === 70 || catId === 71) return '<span class="badge bg-info">HBC</span>';
    if (catId === 80) return '<span class="badge bg-warning text-dark">Telehealth</span>';
    return '<span class="badge" style="background:#E8F5E9;color:#2E7D32">Ambulatorio</span>';
}

/**
 * Opens manual notification modal for a schedule row
 * @param {string} recipient - 'patient' or 'provider'
 * @param {string} templateStatus - Normalized status for template lookup
 */
function openScheduleNotify(eid, pid, catid, apptStatus, type, recipient, patientName, contact, email, apptDate, apptTime, provider, facility, street, city, facilityId, templateStatus) {
    const patientAddress = [street, city].filter(Boolean).join(', ') || 'N/A';
    const tplStatus = templateStatus || '-scheduled';

    // Fetch the appropriate template from the server
    fetch(`${moduleRoot}/pages/ajax/get_notification_template.php?pc_catid=${catid}&pc_apptstatus=${encodeURIComponent(tplStatus)}&type=${type}&recipient=${recipient}&facility_id=${facilityId || 3}`)
        .then(r => r.json())
        .then(tpl => {
            // Build message with template
            let message = '';
            let recipientDisplayName = '';
            
            if (tpl.success && (tpl.wsp_message || tpl.email_message)) {
                // Replace tokens - provider templates use ***PATIENT_NAME*** etc
                const rawTemplate = type === 'WSP' ? (tpl.wsp_message || '') : (tpl.email_message || '');
                
                if (recipient === 'provider') {
                    // Provider notification: provider is the recipient
                    recipientDisplayName = provider; // Show provider name
                    message = rawTemplate
                        .replace(/\*\*\*PROVIDER\*\*\*/g, provider)
                        .replace(/\*\*\*PATIENT_NAME\*\*\*/g, patientName)
                        .replace(/\*\*\*PATIENT_ADDRESS\*\*\*/g, patientAddress)
                        .replace(/\*\*\*PATIENT_PHONE\*\*\*/g, contact || 'N/A')
                        .replace(/\*\*\*DATE\*\*\*/g, apptDate)
                        .replace(/\*\*\*STARTTIME\*\*\*/g, apptTime)
                        .replace(/\*\*\*FACILITY_NAME\*\*\*/g, facility)
                        .replace(/\*\*\*VISIT_ADDRESS\*\*\*/g, patientAddress)
                        .replace(/\*\*\*VISIT_INSTRUCTIONS\*\*\*/g, 'N/A')
                        .replace(/\*\*\*VIDEO_LINK\*\*\*/g, 'Por confirmar')
                        .replace(/\*\*\*VIDEO_ROOM\*\*\*/g, 'N/A')
                        .replace(/\*\*\*VIDEO_PASSWORD\*\*\*/g, 'N/A')
                        .replace(/\*\*\*NAME\*\*\*/g, provider)  // For provider, NAME = provider
                        .replace(/\*\*\*USER_PREFFIX\*\*\*/g, 'Dr.')
                        .replace(/\*\*\*ENDTIME\*\*\*/g, 'N/A')
                        .replace(/\*\*\*FACILITY_ADDRESS\*\*\*/g, '')
                        .replace(/\*\*\*FACILITY_PHONE\*\*\*/g, '')
                        .replace(/\*\*\*FACILITY_EMAIL\*\*\*/g, '')
                        .replace(/\*\*\*FACILITY_MAP_LINK\*\*\*/g, '')
                        .replace(/\*\*\*FACILITY_WEBSITE\*\*\*/g, '')
                        .replace(/\*\*\*PID\*\*\*/g, pid)
                        .replace(/\*\*\*REASON\*\*\*/g, '')
                        .replace(/\*\*\*TITLE\*\*\*/g, '');
                } else {
                    // Patient notification: patient is the recipient
                    recipientDisplayName = patientName;
                    message = rawTemplate
                        .replace(/\*\*\*NAME\*\*\*/g, patientName)
                        .replace(/\*\*\*DATE\*\*\*/g, apptDate)
                        .replace(/\*\*\*STARTTIME\*\*\*/g, apptTime)
                        .replace(/\*\*\*PROVIDER\*\*\*/g, provider)
                        .replace(/\*\*\*FACILITY_NAME\*\*\*/g, facility)
                        .replace(/\*\*\*PATIENT_ADDRESS\*\*\*/g, patientAddress)
                        .replace(/\*\*\*VISIT_ADDRESS\*\*\*/g, patientAddress)
                        .replace(/\*\*\*VISIT_INSTRUCTIONS\*\*\*/g, 'N/A')
                        .replace(/\*\*\*VIDEO_LINK\*\*\*/g, 'Por confirmar')
                        .replace(/\*\*\*VIDEO_ROOM\*\*\*/g, 'N/A')
                        .replace(/\*\*\*VIDEO_PASSWORD\*\*\*/g, 'N/A')
                        .replace(/\*\*\*USER_PREFFIX\*\*\*/g, '')
                        .replace(/\*\*\*ENDTIME\*\*\*/g, 'N/A')
                        .replace(/\*\*\*FACILITY_ADDRESS\*\*\*/g, '')
                        .replace(/\*\*\*FACILITY_PHONE\*\*\*/g, '')
                        .replace(/\*\*\*FACILITY_EMAIL\*\*\*/g, '')
                        .replace(/\*\*\*FACILITY_MAP_LINK\*\*\*/g, '')
                        .replace(/\*\*\*FACILITY_WEBSITE\*\*\*/g, '')
                        .replace(/\*\*\*PID\*\*\*/g, pid)
                        .replace(/\*\*\*REASON\*\*\*/g, '')
                        .replace(/\*\*\*TITLE\*\*\*/g, '')
                        .replace(/\*\*\*PATIENT_NAME\*\*\*/g, patientName)
                        .replace(/\*\*\*PATIENT_ADDRESS\*\*\*/g, patientAddress)
                        .replace(/\*\*\*PATIENT_PHONE\*\*\*/g, contact || 'N/A');
                }
            } else {
                // Fallback messages
                if (recipient === 'provider') {
                    recipientDisplayName = provider;
                    const apptType = catid === 80 ? 'Telehealth' : (catid === 70 || catid === 71 ? 'HBC' : 'Cita');
                    message = type === 'WSP' ?
                        `Dr. ${provider}, tiene ${apptType} programada para ${apptDate} a las ${apptTime} Hs.\nPaciente: ${patientName}\nTel: ${contact || 'N/A'}\nDir: ${patientAddress}` :
                        `<p>Dr. ${provider},</p><p>Tiene ${apptType} programada para ${apptDate} a las ${apptTime} Hs.</p><p><strong>Paciente:</strong> ${patientName}</p><p><strong>Teléfono:</strong> ${contact || 'N/A'}</p><p><strong>Dirección:</strong> ${patientAddress}</p>`;
                } else {
                    recipientDisplayName = patientName;
                    message = type === 'WSP' ?
                        `Hola ${patientName}, le recordamos su cita para ${apptDate} a las ${apptTime} Hs. con ${provider} en ${facility}.` :
                        `<p>Estimado/a ${patientName},</p><p>Le recordamos su cita para ${apptDate} a las ${apptTime} Hs.</p>`;
                }
            }

            // Store data in hidden fields
            document.getElementById('mnEid').value = eid;
            document.getElementById('mnPid').value = pid;
            document.getElementById('mnType').value = type;
            document.getElementById('mnRecipient').value = recipient;
            document.getElementById('mnContact').value = type === 'WSP' ? contact : email;
            document.getElementById('mnEmail').value = email;
            document.getElementById('mnFacilityId').value = facilityId || 3;
            document.getElementById('mnPatientName').textContent = recipientDisplayName;
            document.getElementById('mnMessage').value = message;

            // Update recipient badge and contact info
            const recipientBadge = document.getElementById('mnRecipientBadge');
            const contactInfo = document.getElementById('mnContactInfo');
            if (recipient === 'provider') {
                recipientBadge.innerHTML = '<span class="badge bg-warning text-dark"><i class="fas fa-user-md me-1"></i>Provider</span>';
                contactInfo.textContent = type === 'WSP' ? (contact || 'No phone') : (email || 'No email');
            } else {
                recipientBadge.innerHTML = '<span class="badge bg-info"><i class="fas fa-user me-1"></i>Patient</span>';
                contactInfo.textContent = type === 'WSP' ? (contact || 'No phone') : (email || 'No email');
            }

            // Set badge with recipient indicator
            const badge = document.getElementById('mnChannelBadge');
            if (type === 'WSP') {
                badge.innerHTML = '<span class="badge bg-success"><i class="fab fa-whatsapp me-1"></i>WhatsApp</span>';
            } else {
                badge.innerHTML = '<span class="badge bg-primary"><i class="fas fa-envelope me-1"></i>Email</span>';
            }

            // Show modal
            try {
                const modalEl = document.getElementById('modalManualNotify');
                if (!modalEl) {
                    alert('Error: Modal not found.');
                    return;
                }
                const modal = new bootstrap.Modal(modalEl);
                modal.show();
            } catch (e) {
                alert('Error: ' + e.message);
            }
        })
        .catch(err => {
            console.error('Template fetch error:', err);
            // Fallback to direct notification
            const contact = type === 'WSP' ? phone : email;
            if (!contact) {
                alert(`No ${type === 'WSP' ? 'phone' : 'email'} available.`);
                return;
            }
            
            if (type === 'WSP') {
                const cleanPhone = phone.replace(/\D/g, '');
                const msg = `Hola ${patientName}, su cita es ${apptDate} ${apptTime} Hs.`;
                window.open(`https://wa.me/${cleanPhone}?text=${encodeURIComponent(msg)}`, '_blank');
            } else {
                // Send email via PHPMailer
                const emailData = new URLSearchParams({
                    to: email,
                    subject: 'Recordatorio de Cita',
                    message: `<p>Estimado/a ${patientName},</p><p>Le recordamos su cita para ${apptDate} a las ${apptTime} Hs.</p>`,
                    facility_id: facilityId || 3
                });

                fetch(`${moduleRoot}/pages/ajax/send_manual_email.php`, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: emailData
                })
                .then(r => r.json())
                .then(emailResult => {
                    if (emailResult.success) {
                        alert('✅ Email sent successfully to ' + email);
                    } else {
                        alert('❌ Email send failed: ' + (emailResult.message || 'Unknown error'));
                    }
                })
                .catch(err => {
                    alert('❌ Email send error: ' + err);
                });
            }
        });
}

// Button handlers
document.getElementById('btnLoadSchedules')?.addEventListener('click', loadSchedules);

// =========================================================================
// Template Manager Functions
// =========================================================================
let allTemplatesData = [];

function openTemplateManager() {
    const facId = document.getElementById('cfgFacilityId').value;
    if (!facId) { alert('<?php echo js_escape(xlt('Please select a facility first.')); ?>'); return; }
    
    document.getElementById('tmFacilityId').value = facId;
    const tbody = document.getElementById('templateTableBody');
    tbody.innerHTML = '<tr><td colspan="7" class="text-center py-4"><i class="fas fa-spinner fa-spin"></i> <?php echo js_escape(xlt('Loading...')); ?></td></tr>';
    
    const modal = new bootstrap.Modal(document.getElementById('modalTemplateManager'));
    modal.show();

    // Load categories
    fetch(`${moduleRoot}/pages/ajax/get_categories.php`)
        .then(r => r.json())
        .then(cats => {
            const sel = document.getElementById('tmNewCat');
            sel.innerHTML = '<option value=""><?php echo js_escape(xlt('-- Select --')); ?></option>';
            (cats.rows || []).forEach(c => {
                sel.innerHTML += `<option value="${c.pc_catid}">${escHtml(c.category)}</option>`;
            });
        })
        .catch(() => {});

    fetch(`${moduleRoot}/pages/ajax/get_templates.php?facility_id=${facId}`)
        .then(r => r.json())
        .then(data => {
            allTemplatesData = data.rows || [];
            renderTemplateTable();
        })
        .catch(err => {
            tbody.innerHTML = `<tr><td colspan="7" class="text-danger">Error loading templates: ${err}</td></tr>`;
        });
}

function toggleAddForm() {
    const f = document.getElementById('addTemplateForm');
    f.style.display = f.style.display === 'none' ? 'block' : 'none';
}

function addTemplateRow() {
    const catId  = document.getElementById('tmNewCat').value;
    const status = document.getElementById('tmNewStatus').value;
    const recip  = document.getElementById('tmNewRecipient').value;
    if (!catId) { alert('<?php echo js_escape(xlt('Select a category')); ?>'); return; }
    const catName = document.querySelector('#tmNewCat option:checked').text;

    allTemplatesData.push({
        id: 0,
        facility_id: parseInt(document.getElementById('tmFacilityId').value),
        pc_catid: parseInt(catId),
        category_name: catName,
        pc_apptstatus: status,
        recipient_type: recip,
        wsp_message: '',
        email_subject: '',
        email_message: '',
        enabled: 1
    });
    renderTemplateTable();
    toggleAddForm();
}

function removeTemplateRow(index) {
    if (!confirm('<?php echo js_escape(xlt('Remove this template?')); ?>')) return;
    allTemplatesData.splice(index, 1);
    renderTemplateTable();
}

function renderTemplateTable() {
    const tbody = document.getElementById('templateTableBody');
    tbody.innerHTML = '';

    allTemplatesData.forEach((tpl, index) => {
        const tr = document.createElement('tr');
        tr.innerHTML = `
            <td><strong>${escHtml(tpl.category_name || tpl.pc_catid)}</strong></td>
            <td>
                <select class="form-select form-select-sm status-select" data-idx="${index}"
                    onchange="updateTpl(${index}, 'pc_apptstatus', this.value); renderTemplateTable();">
                    <option value="-scheduled" ${tpl.pc_apptstatus === '-scheduled' ? 'selected' : ''}>${'<?php echo js_escape(xlt('Scheduled')); ?>'} (-scheduled)</option>
                    <option value="-cancelled" ${tpl.pc_apptstatus === '-cancelled' ? 'selected' : ''}>${'<?php echo js_escape(xlt('Cancelled')); ?>'} (-cancelled)</option>
                    <option value="-noshow" ${tpl.pc_apptstatus === '-noshow' ? 'selected' : ''}>${'<?php echo js_escape(xlt('No Show')); ?>'} (-noshow)</option>
                    <option value="-pending" ${tpl.pc_apptstatus === '-pending' ? 'selected' : ''}>${'<?php echo js_escape(xlt('Pending')); ?>'} (-pending)</option>
                    <option value="-" ${tpl.pc_apptstatus === '-' || (tpl.pc_apptstatus !== '-scheduled' && tpl.pc_apptstatus !== '-cancelled' && tpl.pc_apptstatus !== '-noshow' && tpl.pc_apptstatus !== '-pending') ? 'selected' : ''}>${'<?php echo js_escape(xlt('All')); ?>'} (-)</option>
                </select>
            </td>
            <td>
                <select class="form-select form-select-sm" onchange="updateTpl(${index}, 'recipient_type', this.value)">
                    <option value="patient" ${tpl.recipient_type === 'patient' ? 'selected' : ''}>${'<?php echo js_escape(xlt('Patient')); ?>'}</option>
                    <option value="provider" ${tpl.recipient_type === 'provider' ? 'selected' : ''}>${'<?php echo js_escape(xlt('Provider')); ?>'}</option>
                </select>
            </td>
            <td><textarea class="form-control form-control-sm mono" rows="3" onchange="updateTpl(${index}, 'wsp_message', this.value)">${escHtml(tpl.wsp_message)}</textarea></td>
            <td><input type="text" class="form-control form-control-sm" value="${escHtml(tpl.email_subject)}" onchange="updateTpl(${index}, 'email_subject', this.value)"></td>
            <td><textarea class="form-control form-control-sm mono" rows="3" onchange="updateTpl(${index}, 'email_message', this.value)">${escHtml(tpl.email_message)}</textarea></td>
            <td class="text-center">
                <button class="btn btn-sm btn-outline-danger" onclick="removeTemplateRow(${index})" title="Remove"><i class="fas fa-trash"></i></button>
            </td>
        `;
        tbody.appendChild(tr);
    });
}

function updateTpl(index, field, value) {
    allTemplatesData[index][field] = value;
}

function saveTemplates() {
    const facId = document.getElementById('tmFacilityId').value;
    const btn = document.querySelector('#modalTemplateManager .btn-primary');
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> <?php echo js_escape(xlt('Saving...')); ?>';

    fetch(`${moduleRoot}/pages/ajax/save_templates.php`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ facility_id: facId, templates: allTemplatesData })
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            alert('<?php echo js_escape(xlt('Templates saved successfully.')); ?>');
            // Cierre compatible con Bootstrap 4 y 5
            try {
                const modalEl = document.getElementById('modalTemplateManager');
                if (window.bootstrap) {
                    // Bootstrap 5
                    const modalInstance = bootstrap.Modal.getInstance(modalEl);
                    if (modalInstance) modalInstance.hide();
                } else {
                    // Bootstrap 4 (jQuery)
                    $(modalEl).modal('hide');
                }
            } catch (e) {
                console.error('Modal close error:', e);
            }
        } else {
            alert('Error: ' + data.message);
        }
    })
    .catch(err => alert('Error: ' + err))
    .finally(() => {
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-save me-1"></i> <?php echo js_escape(xlt('Save Changes')); ?>';
    });
}
</script>

<!-- Modal: Notification Details / Timeline -->
<div class="modal fade" id="modalLogDetail" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header border-0 pb-0">
                <h5 class="modal-title fw-bold"><?php echo xlt('Notification Lifecycle'); ?></h5>
                <button type="button" class="btn btn-sm btn-link text-secondary" data-dismiss="modal" data-bs-dismiss="modal" aria-label="Close" onclick="closeModalParent(this)"><i class="fas fa-times"></i></button>
            </div>
            <div class="modal-body py-4">
                <div id="logHistoryTimeline">
                    <!-- Loaded via AJAX -->
                </div>
            </div>
            <div class="modal-footer border-0 pt-0">
                <button type="button" class="btn btn-sm btn-secondary" data-dismiss="modal" data-bs-dismiss="modal" onclick="closeModalParent(this)"><?php echo xlt('Close'); ?></button>
            </div>
        </div>
    </div>
</div>

<!-- Modal: Manual Notification -->
<div class="modal fade" id="modalManualNotify" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header bg-success text-white">
                <h5 class="modal-title"><i class="fas fa-paper-plane me-2"></i><?php echo xlt('Manual Notification'); ?></h5>
                <button type="button" class="btn btn-sm btn-link text-white" data-dismiss="modal" data-bs-dismiss="modal" aria-label="Close" onclick="closeModalParent(this)"><i class="fas fa-times"></i></button>
            </div>
            <div class="modal-body">
                <div class="mb-2">
                    <small class="text-muted"><?php echo xlt('To:'); ?> <strong id="mnPatientName"></strong></small>
                    <div id="mnRecipientBadge" class="mt-1"></div>
                </div>
                <div class="mb-3">
                    <small class="text-muted"><?php echo xlt('Contact:'); ?> <strong id="mnContactInfo"></strong></small>
                </div>
                <div class="mb-3">
                    <small class="text-muted"><?php echo xlt('Channel:'); ?> <strong id="mnChannelBadge"></strong></small>
                </div>

                <div class="mb-3">
                    <label class="form-label fw-bold"><?php echo xlt('Message Content:'); ?></label>
                    <textarea id="mnMessage" class="form-control mono" rows="6"></textarea>
                    <div class="form-text"><?php echo xlt('Edit the message if needed.'); ?></div>
                </div>
                <input type="hidden" id="mnEid">
                <input type="hidden" id="mnPid">
                <input type="hidden" id="mnType">
                <input type="hidden" id="mnRecipient">
                <input type="hidden" id="mnContact">
                <input type="hidden" id="mnEmail">
                <input type="hidden" id="mnFacilityId">
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-dismiss="modal" data-bs-dismiss="modal" onclick="closeModalParent(this)"><?php echo xlt('Cancel'); ?></button>
                <button type="button" class="btn btn-success" onclick="executeManualNotify()">
                    <i class="fas fa-external-link-alt me-1"></i><?php echo xlt('Open & Log'); ?>
                </button>
            </div>
        </div>
    </div>
</div>

<style>
.extra-small { font-size: 0.75rem; }
.timeline-v2 { position: relative; padding-left: 5px; }

/* Status badge channel colors */
.badge-status .fa-check { margin-right: 3px; }
.badge-sent.type-wsp { background-color: #C8E6C9 !important; color: #2E7D32 !important; }
.badge-sent.type-email { background-color: #BBDEFB !important; color: #1565C0 !important; }
.badge-sms { background-color: #FFF3CD !important; color: #856404 !important; }
.badge-voz { background-color: #F8D7DA !important; color: #721C24 !important; }

/* Status dropdown colors */
.status-select option[value="-scheduled"] { color: #155724; background: #d4edda; }
.status-select option[value="-cancelled"] { color: #721c24; background: #f8d7da; }
</style>

<!-- Modal: Template Manager -->
<div class="modal fade" id="modalTemplateManager" tabindex="-1">
    <div class="modal-dialog modal-xl">
        <div class="modal-content">
            <div class="modal-header bg-info text-white">
                <h5 class="modal-title"><i class="fas fa-file-alt me-2"></i><?php echo xlt('Manage Notification Templates'); ?></h5>
                <button type="button" class="btn btn-sm btn-link text-white" data-dismiss="modal" data-bs-dismiss="modal" onclick="closeModalParent(this)"><i class="fas fa-times"></i></button>
            </div>
            <div class="modal-body">
                <p class="text-muted small"><?php echo xlt('Edit messages for different scenarios. Tokens like ***NAME***, ***DATE*** will be replaced automatically.'); ?></p>
                <div class="table-responsive">
                    <table class="table table-bordered table-sm align-middle" id="templateManagerTable">
                        <thead class="table-light">
                            <tr>
                                <th width="14%"><?php echo xlt('Scenario'); ?></th>
                                <th width="10%"><?php echo xlt('Status'); ?></th>
                                <th width="10%"><?php echo xlt('Recipient'); ?></th>
                                <th class="col-wsp"><?php echo xlt('WhatsApp Message'); ?></th>
                                <th class="col-subj"><?php echo xlt('Email Subject'); ?></th>
                                <th class="col-body"><?php echo xlt('HTML Email Body'); ?></th>
                                <th width="5%"></th>
                            </tr>
                        </thead>
                        <tbody id="templateTableBody">
                            <!-- Rows injected via JS -->
                        </tbody>
                    </table>
                </div>
                <input type="hidden" id="tmFacilityId">
                <div class="mt-3 p-3 bg-light rounded" id="addTemplateForm" style="display:none">
                    <div class="row g-2 align-items-end">
                        <div class="col-md-3">
                            <label class="form-label small mb-1"><?php echo xlt('Category'); ?></label>
                            <select class="form-select form-select-sm" id="tmNewCat"></select>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label small mb-1"><?php echo xlt('Status'); ?></label>
                            <select class="form-select form-select-sm" id="tmNewStatus">
                                <option value="-scheduled"><?php echo xlt('Scheduled'); ?> (-scheduled)</option>
                                <option value="-cancelled"><?php echo xlt('Cancelled'); ?> (-cancelled)</option>
                                <option value="-noshow"><?php echo xlt('No Show'); ?> (-noshow)</option>
                                <option value="-pending"><?php echo xlt('Pending'); ?> (-pending)</option>
                                <option value="-"><?php echo xlt('All'); ?> (-)</option>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label small mb-1"><?php echo xlt('Recipient'); ?></label>
                            <select class="form-select form-select-sm" id="tmNewRecipient">
                                <option value="patient"><?php echo xlt('Patient'); ?></option>
                                <option value="provider"><?php echo xlt('Provider'); ?></option>
                            </select>
                        </div>
                        <div class="col-md-3 d-flex gap-1">
                            <button type="button" class="btn btn-sm btn-success" onclick="addTemplateRow()"><i class="fas fa-plus"></i> <?php echo xlt('Add'); ?></button>
                            <button type="button" class="btn btn-sm btn-secondary" onclick="toggleAddForm()"><?php echo xlt('Cancel'); ?></button>
                        </div>
                    </div>
                </div>
            </div>
            <div class="modal-footer d-flex justify-content-between">
                <div>
                    <button type="button" class="btn btn-outline-success btn-sm" onclick="toggleAddForm()"><i class="fas fa-plus me-1"></i> <?php echo xlt('Add Template'); ?></button>
                </div>
                <div>
                    <button type="button" class="btn btn-secondary" data-dismiss="modal" data-bs-dismiss="modal" onclick="closeModalParent(this)"><?php echo xlt('Close'); ?></button>
                    <button type="button" class="btn btn-primary" onclick="saveTemplates()"><i class="fas fa-save me-1"></i> <?php echo xlt('Save Changes'); ?></button>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
function closeModalParent(el) {
    var modal = el.closest('.modal');
    if (modal) {
        try {
            var inst = bootstrap.Modal.getInstance(modal);
            if (inst) { inst.hide(); return; }
        } catch(e) {}
        modal.classList.remove('show');
        modal.style.display = 'none';
        document.querySelectorAll('.modal-backdrop').forEach(function(b) { b.remove(); });
        document.body.classList.remove('modal-open');
    }
}

/* =========================================================================
   BLACKLIST TAB
   ========================================================================= */
let blCurrentPage  = 0;
const blPageSize   = 50;
let blTotalRows    = 0;

const blReasonLabels = {
    MANUAL:     '<?php echo js_escape(xlt("Manual")); ?>',
    INVALID:    '<?php echo js_escape(xlt("Invalid Number")); ?>',
    FAILED_MAX: '<?php echo js_escape(xlt("Max Failures")); ?>',
    TRACKING:   '<?php echo js_escape(xlt("Tracking")); ?>'
};

const blReasonColors = {
    MANUAL:     'bg-warning text-dark',
    INVALID:    'bg-secondary',
    FAILED_MAX: 'bg-danger',
    TRACKING:   'bg-light text-dark border'
};

function loadBlacklist(resetPage) {
    if (resetPage) blCurrentPage = 0;
    const facility = document.getElementById('blFilterFacility').value;
    const vendor   = document.getElementById('blFilterVendor').value;
    const reason   = document.getElementById('blFilterReason').value;
    const active   = document.getElementById('blFilterActive').value;
    const search   = document.getElementById('blFilterSearch').value.trim();

    const params = new URLSearchParams({
        limit:  blPageSize,
        offset: blCurrentPage * blPageSize
    });
    if (facility !== '') params.set('facility_id', facility);
    if (vendor   !== '') params.set('vendor',      vendor);
    if (reason   !== '') params.set('reason',      reason);
    if (active   !== '') params.set('active',      active);
    if (search   !== '') params.set('search',      search);

    const tbody = document.getElementById('blTableBody');
    tbody.innerHTML = '<tr><td colspan="10" class="text-center text-muted py-4"><i class="fas fa-spinner fa-spin me-2"></i><?php echo js_escape(xlt("Loading...")); ?></td></tr>';

    fetch(`${moduleRoot}/pages/ajax/get_blacklist.php?${params.toString()}`)
        .then(r => r.json())
        .then(data => {
            blTotalRows = data.total || 0;
            const rows  = data.rows  || [];

            // Update pagination display
            const pag = document.getElementById('blPagination');
            pag.style.display = '';
            document.getElementById('blCountLabel').textContent =
                `${blTotalRows} <?php echo js_escape(xlt('records')); ?>`;
            const totalPages = Math.max(1, Math.ceil(blTotalRows / blPageSize));
            document.getElementById('blPageLabel').textContent =
                `<?php echo js_escape(xlt('Page')); ?> ${blCurrentPage + 1} / ${totalPages}`;
            document.getElementById('blPrevBtn').disabled = blCurrentPage <= 0;
            document.getElementById('blNextBtn').disabled = (blCurrentPage + 1) >= totalPages;

            if (!rows.length) {
                tbody.innerHTML = '<tr><td colspan="10" class="text-center text-muted py-4"><?php echo js_escape(xlt("No records found.")); ?></td></tr>';
                return;
            }

            tbody.innerHTML = rows.map(r => {
                const reasonBadge = `<span class="badge ${blReasonColors[r.reason] || 'bg-secondary'}">${escHtml(blReasonLabels[r.reason] || r.reason)}</span>`;

                const activeBadge = r.is_active
                    ? '<span class="badge bg-danger"><i class="fas fa-ban me-1"></i><?php echo js_escape(xlt("Blocked")); ?></span>'
                    : '<span class="badge bg-success"><i class="fas fa-check me-1"></i><?php echo js_escape(xlt("Released")); ?></span>';

                const toggleTitle = r.is_active ? '<?php echo js_escape(xlt("Release — unblock this number"));?>' : '<?php echo js_escape(xlt("Block — re-blacklist this number")); ?>';
                const toggleClass = r.is_active ? 'btn-outline-success' : 'btn-outline-danger';
                const toggleIcon  = r.is_active ? 'fa-unlock' : 'fa-lock';

                const vendorBadge = `<span class="badge bg-light text-dark border">${escHtml(r.vendor)}</span>`;

                const failCell = r.reason === 'TRACKING'
                    ? `<span class="text-warning fw-bold">${r.fail_count}</span>`
                    : (r.fail_count > 0 ? `<span class="text-danger fw-bold">${r.fail_count}</span>` : '—');

                return `<tr class="${r.is_active ? '' : 'table-secondary opacity-75'}">
                    <td><code>${escHtml(r.phone)}</code></td>
                    <td><small>${escHtml(r.facility_name)}</small></td>
                    <td>${vendorBadge}</td>
                    <td>${reasonBadge}</td>
                    <td class="text-center">${failCell}</td>
                    <td><small class="text-muted" title="${escHtml(r.notes || '')}">${escHtml((r.notes || '').substring(0, 50))}${(r.notes || '').length > 50 ? '…' : ''}</small></td>
                    <td><small class="text-muted">${escHtml(r.updated_at || '')}</small></td>
                    <td><small class="text-muted">${escHtml(r.created_at || '')}</small></td>
                    <td class="text-center">${activeBadge}</td>
                    <td class="text-center">
                        <div class="btn-group btn-group-sm">
                            <button class="btn ${toggleClass}" onclick="blToggle(${r.id})" title="${toggleTitle}" data-bs-toggle="tooltip" data-toggle="tooltip">
                                <i class="fas ${toggleIcon}"></i>
                            </button>
                            <button class="btn btn-outline-secondary" onclick="blRemove(${r.id}, '${escHtml(r.phone)}')" title="<?php echo js_escape(xlt('Delete permanently')); ?>" data-bs-toggle="tooltip" data-toggle="tooltip">
                                <i class="fas fa-trash"></i>
                            </button>
                        </div>
                    </td>
                </tr>`;
            }).join('');

            // Initialise Bootstrap tooltips on newly added buttons
            if (typeof $ !== 'undefined' && $.fn.tooltip) {
                setTimeout(() => $('[data-bs-toggle="tooltip"]').tooltip('dispose').tooltip(), 0);
            }
        })
        .catch(err => {
            tbody.innerHTML = `<tr><td colspan="10" class="text-center text-danger py-4"><?php echo js_escape(xlt('Error loading data')); ?>: ${err}</td></tr>`;
        });
}

function blChangePage(delta) {
    const totalPages = Math.max(1, Math.ceil(blTotalRows / blPageSize));
    blCurrentPage = Math.min(totalPages - 1, Math.max(0, blCurrentPage + delta));
    loadBlacklist();
}

function blToggle(id) {
    fetch(`${moduleRoot}/pages/ajax/manage_blacklist.php`, {
        method:  'POST',
        headers: { 'Content-Type': 'application/json' },
        body:    JSON.stringify({ action: 'toggle', id: id })
    })
    .then(r => r.json())
    .then(data => {
        if (data.error) { alert(data.error); return; }
        loadBlacklist();
    })
    .catch(err => alert('Error: ' + err));
}

function blRemove(id, phone) {
    if (!confirm(`<?php echo js_escape(xlt('Permanently delete this record for')); ?> ${phone}?`)) return;
    fetch(`${moduleRoot}/pages/ajax/manage_blacklist.php`, {
        method:  'POST',
        headers: { 'Content-Type': 'application/json' },
        body:    JSON.stringify({ action: 'remove', id: id })
    })
    .then(r => r.json())
    .then(data => {
        if (data.error) { alert(data.error); return; }
        loadBlacklist();
    })
    .catch(err => alert('Error: ' + err));
}

function showBlAddModal() {
    document.getElementById('blAddPhone').value  = '';
    document.getElementById('blAddNotes').value  = '';
    document.getElementById('blAddError').style.display = 'none';
    let modal;
    try { modal = new bootstrap.Modal(document.getElementById('blAddModal')); }
    catch(e) { modal = null; }
    if (modal) { modal.show(); }
    else {
        document.getElementById('blAddModal').classList.add('show');
        document.getElementById('blAddModal').style.display = 'block';
    }
}

function blDoAdd() {
    const phone  = document.getElementById('blAddPhone').value.trim();
    const vendor = document.getElementById('blAddVendor').value;
    const fac    = document.getElementById('blAddFacility').value;
    const notes  = document.getElementById('blAddNotes').value.trim();
    const errEl  = document.getElementById('blAddError');

    if (!phone) {
        errEl.textContent = '<?php echo js_escape(xlt("Phone number is required.")); ?>';
        errEl.style.display = '';
        return;
    }
    errEl.style.display = 'none';

    fetch(`${moduleRoot}/pages/ajax/manage_blacklist.php`, {
        method:  'POST',
        headers: { 'Content-Type': 'application/json' },
        body:    JSON.stringify({ action: 'add', facility_id: parseInt(fac), vendor, phone, notes })
    })
    .then(r => r.json())
    .then(data => {
        if (data.error) {
            errEl.textContent = data.error;
            errEl.style.display = '';
            return;
        }
        closeModalParent(document.getElementById('blAddModal').querySelector('button'));
        loadBlacklist(true);
    })
    .catch(err => {
        errEl.textContent = 'Error: ' + err;
        errEl.style.display = '';
    });
}

// Auto-load blacklist when that tab is active on page load
document.addEventListener('DOMContentLoaded', function() {
    if (document.getElementById('tab-blacklist') &&
        !document.getElementById('tab-blacklist').classList.contains('d-none')) {
        loadBlacklist(true);
    }
    // Auto-load recalls when that tab is active on page load
    if (document.getElementById('tab-recalls') &&
        !document.getElementById('tab-recalls').classList.contains('d-none')) {
        loadRecalls();
        loadPendingRecalls();
        loadMyRecalls();
    }
});

/* =========================================================================
   Recalls Tab
   ========================================================================= */
let recallsPage   = 1;
const recallsLimit = 50;
let recallsTotal  = 0;

function loadRecalls(resetPage = true) {
    if (resetPage) recallsPage = 1;

    const facility = document.getElementById('recallFilterFacility')?.value  || '';
    const from     = document.getElementById('recallFilterFrom')?.value       || '';
    const to       = document.getElementById('recallFilterTo')?.value         || '';
    const patient  = document.getElementById('recallFilterPatient')?.value    || '';
    const status   = document.getElementById('recallFilterStatus')?.value     || '';
    const tbody    = document.getElementById('recallsTableBody');

    tbody.innerHTML = '<tr><td colspan="8" class="text-center py-4"><i class="fas fa-spinner fa-spin fa-2x text-warning"></i></td></tr>';

    const params = new URLSearchParams({
        facility_id: facility,
        date_from:   from,
        date_to:     to,
        patient:     patient,
        status:      status,
        limit:       recallsLimit,
        offset:      (recallsPage - 1) * recallsLimit
    });

    fetch(`${moduleRoot}/pages/ajax/get_recalls.php?${params.toString()}`)
        .then(r => r.json())
        .then(data => {
            const rows = data.data  || [];
            recallsTotal = parseInt(data.total ?? 0);

            if (!rows.length) {
                tbody.innerHTML = '<tr><td colspan="8" class="text-center text-muted py-4"><?php echo js_escape(xlt("No recalls found for the selected criteria.")); ?></td></tr>';
                updateRecallsPagination();
                return;
            }

            tbody.innerHTML = rows.map(r => {
                const patientName = escHtml((r.fname || '') + ' ' + (r.lname || '')).trim();
                const contact = [r.phone_cell ? escHtml(r.phone_cell) : '', r.email ? escHtml(r.email) : '']
                    .filter(Boolean).join('<br>') || '-';

                const recallDate = r.r_eventDate
                    ? new Date(r.r_eventDate + 'T00:00:00').toLocaleDateString()
                    : '-';

                const seqDetail = r.seq_detail
                    ? r.seq_detail.split(' | ').map(part => {
                        const parts = part.split(':');
                        const st = parts.length > 1 ? parts[1] : parts[0];
                        const label = parts.length > 1 ? parts[0] : st;
                        const colorMap = { 'SENT':'success', 'FAILED':'danger', 'PENDING':'warning text-dark', 'SKIPPED':'secondary' };
                        const cls = colorMap[st] || 'secondary';
                        return `<span class="badge bg-${cls} me-1" style="font-size:85%">${escHtml(label)}</span>`;
                      }).join('')
                    : `<span class="badge bg-secondary" style="font-size:85%"><?php echo js_escape(xlt("Not sent")); ?></span>`;

                return `<tr>
                    <td>
                        <div class="fw-bold">${patientName}</div>
                        <div class="small text-muted">PID: ${escHtml(String(r.pid || ''))}</div>
                    </td>
                    <td><small>${contact}</small></td>
                    <td><strong>${recallDate}</strong></td>
                    <td>${escHtml(r.r_reason || '-')}</td>
                    <td><small>${escHtml(r.facility_name || '-')}</small></td>
                    <td><small>${escHtml(r.provider_name || '-')}</small></td>
                    <td class="text-center">${seqDetail}</td>
                    <td class="text-center">${renderRecallStatus(r.notif_status)}</td>
                </tr>`;
            }).join('');

            updateRecallsPagination();
        })
        .catch(err => {
            tbody.innerHTML = `<tr><td colspan="8" class="text-center text-danger py-4">Error: ${err}</td></tr>`;
        });
}

/**
 * Renders a recall notification status badge.
 */
function renderRecallStatus(status) {
    if (!status) {
        return `<span class="badge bg-secondary"><i class="fas fa-envelope me-1"></i><?php echo js_escape(xlt("Not sent")); ?></span>`;
    }
    const cfgMap = {
        'SENT':    { css: 'bg-success',   icon: 'fa-check' },
        'PENDING': { css: 'bg-warning text-dark', icon: 'fa-clock' },
        'FAILED':  { css: 'bg-danger',    icon: 'fa-times-circle' },
        'SKIPPED': { css: 'bg-secondary', icon: 'fa-forward' },
    };
    const c = cfgMap[status] || { css: 'bg-secondary', icon: 'fa-question' };
    return `<span class="badge ${c.css}"><i class="fas ${c.icon} me-1"></i>${escHtml(status)}</span>`;
}

function updateRecallsPagination() {
    const pg       = document.getElementById('recallsPagination');
    const label    = document.getElementById('recallsCountLabel');
    const pageLabel = document.getElementById('recallsPageLabel');
    const prevBtn  = document.getElementById('recallsPrevBtn');
    const nextBtn  = document.getElementById('recallsNextBtn');

    if (!pg) return;

    const totalPages = Math.max(1, Math.ceil(recallsTotal / recallsLimit));
    pg.style.removeProperty('display');

    if (label) label.textContent = `${recallsTotal} <?php echo js_escape(xlt('records')); ?>`;
    if (pageLabel) pageLabel.textContent = `${recallsPage} / ${totalPages}`;
    if (prevBtn) prevBtn.disabled = recallsPage <= 1;
    if (nextBtn) nextBtn.disabled = recallsPage >= totalPages;
}

function recallsChangePage(delta) {
    const totalPages = Math.ceil(recallsTotal / recallsLimit);
    recallsPage = Math.max(1, Math.min(totalPages, recallsPage + delta));
    loadRecalls(false);
}

// Recall tab event listeners
document.getElementById('btnLoadRecalls')?.addEventListener('click', () => loadRecalls(true));
document.getElementById('recallFilterPatient')?.addEventListener('keydown', e => { if (e.key === 'Enter') loadRecalls(true); });
document.getElementById('btnRefreshPendingRecalls')?.addEventListener('click', () => loadPendingRecalls());
document.getElementById('pendingRecallHorizon')?.addEventListener('change', () => loadPendingRecalls());
document.getElementById('pendingRecallFacility')?.addEventListener('change', () => loadPendingRecalls());

/* =========================================================================
   Active Recalls -- Pending Notifications panel
   ========================================================================= */
function loadPendingRecalls() {
    const wrap      = document.getElementById('pendingRecallsWrap');
    const horizon   = document.getElementById('pendingRecallHorizon')?.value  || 30;
    const facility  = document.getElementById('pendingRecallFacility')?.value || 0;

    if (!wrap) return;
    wrap.innerHTML = '<div class="text-center py-3 text-muted"><i class="fas fa-spinner fa-spin fa-lg"></i></div>';

    const params = new URLSearchParams({ facility_id: facility, horizon });

    fetch(`${moduleRoot}/pages/ajax/get_pending_recall_notifications.php?${params.toString()}`)
        .then(r => r.json())
        .then(data => {
            const rows = data.rows || [];

            if (!rows.length) {
                wrap.innerHTML = `<div class="alert alert-success py-2 mb-0">
                    <i class="fas fa-check-circle me-1"></i>
                    <?php echo js_escape(xlt('No pending recall notifications in the next')); ?> ${horizon} <?php echo js_escape(xlt('days.')); ?>
                </div>`;
                return;
            }

            // Group by scheduled_for date
            const byDate = {};
            rows.forEach(r => {
                const d = r.scheduled_for;
                if (!byDate[d]) byDate[d] = [];
                byDate[d].push(r);
            });

            // Build a table or cards for grouped notifications
            let html = '<div class="table-responsive"><table class="table table-sm table-hover mb-0 align-middle">';
            html += `<thead class="table-light">
                <tr>
                    <th style="width:40px"><input type="checkbox" id="selectAllPending" checked></th>
                    <th><?php echo js_escape(xlt('Scheduled For')); ?></th>
                    <th><?php echo js_escape(xlt('Patient')); ?></th>
                    <th><?php echo js_escape(xlt('Contact')); ?></th>
                    <th><?php echo js_escape(xlt('Recall Reason')); ?></th>
                    <th><?php echo js_escape(xlt('Facility')); ?></th>
                    <th class="text-center"><?php echo js_escape(xlt('Seq')); ?></th>
                    <th class="text-center"><?php echo js_escape(xlt('Urgency')); ?></th>
                </tr>
            </thead><tbody>`;

            // Sort dates ascending
            const sortedDates = Object.keys(byDate).sort();
            sortedDates.forEach(dateStr => {
                const group = byDate[dateStr];
                group.forEach(r => {
                    const patientName = escHtml((r.fname || '') + ' ' + (r.lname || '')).trim();
                    const contact = [r.phone_cell ? escHtml(r.phone_cell) : '', r.email ? escHtml(r.email) : '']
                        .filter(Boolean).join(' / ') || '-';
                    const daysUntil = parseInt(r.days_until_send);
                    const rowId = `recall_${r.recall_id}_${r.seq}`;
                    
                    let urgencyClass = 'bg-info text-white';
                    let urgencyText = '<?php echo js_escape(xlt("Upcoming")); ?>';
                    
                    if (daysUntil <= 0) {
                        urgencyClass = 'bg-danger text-white';
                        urgencyText = '<?php echo js_escape(xlt("Today/Overdue")); ?>';
                    } else if (daysUntil === 1) {
                        urgencyClass = 'bg-warning text-dark';
                        urgencyText = '<?php echo js_escape(xlt("Tomorrow")); ?>';
                    } else if (daysUntil <= 7) {
                        urgencyClass = 'bg-warning text-dark';
                        urgencyText = `${daysUntil} <?php echo js_escape(xlt("days")); ?>`;
                    } else {
                        urgencyText = `${daysUntil} <?php echo js_escape(xlt("days")); ?>`;
                    }

                    const formattedDate = r.scheduled_for
                        ? new Date(r.scheduled_for + 'T00:00:00').toLocaleDateString()
                        : '-';

                    html += `<tr>
                        <td><input type="checkbox" class="pending-recall-cb" data-recall-id="${escHtml(r.recall_id)}" data-pid="${escHtml(r.pid)}" data-seq="${escHtml(r.seq)}" data-facility-id="${escHtml(r.r_facility)}" data-days-before="${escHtml(r.days_before)}" checked></td>
                        <td><strong>${formattedDate}</strong></td>
                        <td>
                            <div class="fw-bold">${patientName}</div>
                            <div class="small text-muted">PID: ${escHtml(String(r.pid || ''))}</div>
                        </td>
                        <td><small>${contact}</small></td>
                        <td>${escHtml(r.r_reason || '-')}</td>
                        <td><small>${escHtml(r.facility_name || '-')}</small></td>
                        <td class="text-center"><span class="badge bg-secondary">Seq ${escHtml(r.seq)}</span></td>
                        <td class="text-center"><span class="badge ${urgencyClass}">${urgencyText}</span></td>
                    </tr>`;
                });
            });

            html += '</tbody></table></div>';
            wrap.innerHTML = html;

            // Wire Select All
            const selAll = document.getElementById('selectAllPending');
            if (selAll) {
                selAll.addEventListener('change', function() {
                    document.querySelectorAll('.pending-recall-cb').forEach(cb => cb.checked = this.checked);
                });
            }
        })
        .catch(err => {
            wrap.innerHTML = `<div class="alert alert-danger py-2 mb-0">Error: ${err.message}</div>`;
        });
}

// Run Recalls Now
document.getElementById('btnRunRecallsNow')?.addEventListener('click', function() {
    const btn     = this;
    const logDiv  = document.getElementById('recallRunLog');
    const logPre  = document.getElementById('recallRunLogContent');

    const checked = document.querySelectorAll('.pending-recall-cb:checked');
    if (!checked.length) {
        alert('<?php echo js_escape(xlt("No recalls selected. Check at least one row.")); ?>');
        return;
    }

    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i><?php echo js_escape(xlt("Running...")); ?>';
    logDiv.style.display = 'block';
    logPre.textContent   = '<?php echo js_escape(xlt("Running...")); ?>';

    const selected = Array.from(checked).map(cb => ({
        recall_id:    parseInt(cb.dataset.recallId),
        pid:          parseInt(cb.dataset.pid),
        seq:          parseInt(cb.dataset.seq),
        facility_id:  parseInt(cb.dataset.facilityId),
        days_before:  parseInt(cb.dataset.daysBefore),
    }));

    const body = new URLSearchParams();
    body.append('channel', 'all');
    body.append('dry_run', '0');
    body.append('selected', JSON.stringify(selected));

    fetch(`${moduleRoot}/pages/ajax/run_recalls_now.php`, { method: 'POST', body })
        .then(r => r.json())
        .then(data => {
            logPre.textContent = data.output || '<?php echo js_escape(xlt("No output.")); ?>';
            logPre.scrollTop   = logPre.scrollHeight;
            if (data.success) {
                loadPendingRecalls();
                loadRecalls(true);
            }
        })
        .catch(err => {
            logPre.textContent = '<?php echo js_escape(xlt("Error")); ?>: ' + err.message;
        })
        .finally(() => {
            btn.disabled  = false;
            btn.innerHTML = '<i class="fas fa-paper-plane me-1"></i><?php echo js_escape(xlt("Send Recalls Now")); ?>';
        });
});
/* =========================================================================
   My Recalls panel
   ========================================================================= */
function loadMyRecalls() {
    const wrap = document.getElementById('myRecallEntriesWrap');
    if (!wrap) return;
    wrap.innerHTML = '<div class="text-center py-3 text-muted"><i class="fas fa-spinner fa-spin fa-lg"></i></div>';

    fetch(`${moduleRoot}/pages/ajax/get_recall_entries.php`)
        .then(r => r.json())
        .then(data => {
            const rows = data.data || [];
            if (!rows.length) {
                wrap.innerHTML = '<div class="text-muted py-2"><?php echo xlt("No custom recall entries yet. Click New Recall to create one."); ?></div>';
                return;
            }
            let html = '<div class="table-responsive"><table class="table table-sm table-hover mb-0"><thead class="table-light"><tr>' +
                '<th><?php echo xlt("Patient"); ?></th>' +
                '<th><?php echo xlt("Event Date"); ?></th>' +
                '<th><?php echo xlt("Reason"); ?></th>' +
                '<th><?php echo xlt("Facility"); ?></th>' +
                '<th><?php echo xlt("Provider"); ?></th>' +
                '<th class="text-center"><?php echo xlt("Actions"); ?></th></tr></thead><tbody>';
            rows.forEach(r => {
                const name = escHtml((r.fname || '') + ' ' + (r.lname || '')).trim();
                html += `<tr>
                    <td><div class="fw-bold">${name}</div><div class="small text-muted">PID: ${escHtml(r.pid)}</div></td>
                    <td>${escHtml(r.event_date)}</td>
                    <td>${escHtml(r.reason || '-')}</td>
                    <td>${escHtml(r.facility_name || '-')}</td>
                    <td>${escHtml(r.provider_name || '-')}</td>
                    <td class="text-center">
                        <button class="btn btn-xs btn-outline-primary me-1" onclick="openRecallEntryModal(${r.id})" title="<?php echo attr(xlt("Edit")); ?>"><i class="fas fa-edit"></i></button>
                        <button class="btn btn-xs btn-outline-danger" onclick="deleteRecallEntry(${r.id})" title="<?php echo attr(xlt("Delete")); ?>"><i class="fas fa-trash"></i></button>
                    </td>
                </tr>`;
            });
            html += '</tbody></table></div>';
            wrap.innerHTML = html;
        })
        .catch(err => {
            wrap.innerHTML = `<div class="alert alert-danger py-2 mb-0">Error: ${err.message}</div>`;
        });
}

// Callback for find_patient_popup.php
function setpatient(pid, lname, fname, dob) {
    document.getElementById('recallEntryPid').value = pid;
    document.getElementById('recallEntryPatientName').value = lname + ', ' + fname;
}

function sel_recall_patient() {
    let title = <?php echo json_encode(xlt('Patient Search')); ?>;
    dlgopen(
        <?php echo json_encode($GLOBALS['webroot'] . '/interface/main/calendar/find_patient_popup.php'); ?>,
        'findPatient', 650, 300, '', title
    );
}

let recallEntryModalInstance = null;

function openRecallEntryModal(id) {
    const modal = document.getElementById('recallEntryModal');
    const form  = document.getElementById('recallEntryForm');
    form.reset();
    document.getElementById('recallEntryId').value = id || 0;
    document.getElementById('recallEntryModalTitle').textContent = id
        ? '<?php echo xlt('Edit Recall'); ?>'
        : '<?php echo xlt('New Recall'); ?>';

    if (id) {
        // Load existing data
        fetch(`${moduleRoot}/pages/ajax/get_recall_entries.php?id=${id}`)
            .then(r => r.json())
            .then(data => {
                if (data.data && data.data.length) {
                    const r = data.data[0];
                    document.getElementById('recallEntryPid').value = r.pid;
                    document.getElementById('recallEntryPatientName').value = escHtml((r.fname || '') + ' ' + (r.lname || ''));
                    document.getElementById('recallEntryDate').value = r.event_date;
                    document.getElementById('recallEntryFacility').value = r.facility_id;
                    document.getElementById('recallEntryProvider').value = r.provider_id || '';
                    document.getElementById('recallEntryReason').value = r.reason || '';
                }
            });
    } else {
        // Default to first facility for new recalls
        const facSelect = document.getElementById('recallEntryFacility');
        if (facSelect && facSelect.options.length > 1) {
            facSelect.selectedIndex = 1;
        }
    }

    if (modal) {
        recallEntryModalInstance = new bootstrap.Modal(modal);
        recallEntryModalInstance.show();
    }
}

function saveRecallEntry() {
    const pid = document.getElementById('recallEntryPid').value;
    const dateVal = document.getElementById('recallEntryDate').value;
    const facVal = document.getElementById('recallEntryFacility').value;
    if (!pid) {
        alert(<?php echo json_encode(xlt('Please select a patient first')); ?>);
        return;
    }
    if (!dateVal) {
        alert(<?php echo json_encode(xlt('Please select an event date')); ?>);
        return;
    }
    if (!facVal) {
        alert(<?php echo json_encode(xlt('Please select a facility')); ?>);
        return;
    }
    const form = document.getElementById('recallEntryForm');
    const data = new FormData(form);

    console.log('saveRecallEntry data:', Object.fromEntries(data.entries()));

    fetch(`${moduleRoot}/pages/ajax/save_recall_entry.php`, { method: 'POST', body: data })
        .then(r => r.json())
        .then(res => {
            if (res.success) {
                if (recallEntryModalInstance) {
                    recallEntryModalInstance.hide();
                }
                loadMyRecalls();
                loadRecalls(true);
            } else {
                alert('Error: ' + (res.error || 'Unknown'));
            }
        })
        .catch(err => alert('Error: ' + err.message));
}

function deleteRecallEntry(id) {
    if (!confirm('<?php echo js_escape(xlt("Delete this recall entry?")); ?>')) return;
    const body = new URLSearchParams();
    body.append('id', id);

    fetch(`${moduleRoot}/pages/ajax/delete_recall_entry.php`, { method: 'POST', body })
        .then(r => r.json())
        .then(res => {
            if (res.success) {
                loadMyRecalls();
                loadRecalls(true);
            } else {
                alert('Error: ' + (res.error || 'Unknown'));
            }
        })
        .catch(err => alert('Error: ' + err.message));
}

// Load My Recalls on tab show + wire modal form
function setRecallDateOffset(days) {
    const d = new Date();
    d.setDate(d.getDate() + days);
    const yyyy = d.getFullYear();
    const mm = String(d.getMonth() + 1).padStart(2, '0');
    const dd = String(d.getDate()).padStart(2, '0');
    document.getElementById('recallEntryDate').value = yyyy + '-' + mm + '-' + dd;
}

document.addEventListener('DOMContentLoaded', function() {
    const recallsTab = document.querySelector('[data-bs-target="#tab-recalls"]');
    if (recallsTab) {
        recallsTab.addEventListener('shown.bs.tab', function() {
            loadMyRecalls();
        });
    }
    document.getElementById('btnSaveRecallEntry')?.addEventListener('click', saveRecallEntry);
});
</script>

<!-- Modal: Recall Entry Form -->
<div class="modal fade" id="recallEntryModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="recallEntryModalTitle"><?php echo xlt('New Recall'); ?></h5>
                <button type="button" class="close" data-dismiss="modal" data-bs-dismiss="modal" aria-label="<?php echo xla('Close'); ?>">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
            <form id="recallEntryForm">
                <div class="modal-body">
                    <input type="hidden" name="id" id="recallEntryId" value="0">
                    <input type="hidden" name="pid" id="recallEntryPid" value="">
                    <div class="mb-3">
                        <label class="form-label small"><?php echo xlt('Patient'); ?></label>
                        <div class="input-group input-group-sm">
                            <input type="text" id="recallEntryPatientName" class="form-control" readonly placeholder="<?php echo xla('Click to select a patient'); ?>" onclick="sel_recall_patient()">
                            <button class="btn btn-outline-primary" type="button" onclick="sel_recall_patient()" title="<?php echo xla('Search Patient'); ?>">
                                <i class="fas fa-search"></i>
                            </button>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label small"><?php echo xlt('Event Date'); ?></label>
                        <input type="date" name="event_date" id="recallEntryDate" class="form-control form-control-sm" required>
                        <div class="mt-1 d-flex gap-1 flex-wrap">
                            <button type="button" class="btn btn-sm btn-outline-secondary" onclick="setRecallDateOffset(7)">7d</button>
                            <button type="button" class="btn btn-sm btn-outline-secondary" onclick="setRecallDateOffset(15)">15d</button>
                            <button type="button" class="btn btn-sm btn-outline-secondary" onclick="setRecallDateOffset(30)">30d</button>
                            <button type="button" class="btn btn-sm btn-outline-secondary" onclick="setRecallDateOffset(90)">90d</button>
                            <button type="button" class="btn btn-sm btn-outline-secondary" onclick="setRecallDateOffset(180)">6m</button>
                            <button type="button" class="btn btn-sm btn-outline-secondary" onclick="setRecallDateOffset(365)">1y</button>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label small"><?php echo xlt('Facility'); ?></label>
                        <select name="facility_id" id="recallEntryFacility" class="form-select form-select-sm" required>
                            <option value=""><?php echo xlt('Select'); ?></option>
                            <?php foreach ($facilities as $sf): ?>
                            <option value="<?php echo attr((string)$sf['facility_id']); ?>"><?php echo text($sf['facility_name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label small"><?php echo xlt('Provider'); ?></label>
                        <select name="provider_id" id="recallEntryProvider" class="form-select form-select-sm">
                            <option value=""><?php echo xlt('None'); ?></option>
                            <?php foreach ($providers as $prov): ?>
                            <?php $selected = ((int)($prov['id']) === (int)($_SESSION['authUserID'] ?? 0)) ? 'selected' : ''; ?>
                            <option value="<?php echo attr($prov['id']); ?>" <?php echo $selected; ?>><?php echo text($prov['lname'] . ', ' . $prov['fname'] . ($prov['suffix'] ? ' ' . $prov['suffix'] : '')); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label small"><?php echo xlt('Reason'); ?></label>
                        <textarea name="reason" id="recallEntryReason" class="form-control form-control-sm" rows="2"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-sm btn-secondary" data-dismiss="modal" data-bs-dismiss="modal"><?php echo xlt('Cancel'); ?></button>
                    <button type="button" class="btn btn-sm btn-success" id="btnSaveRecallEntry"><?php echo xlt('Save'); ?></button>
                </div>
            </form>
        </div>
    </div>
</div>

</body>
</html>
