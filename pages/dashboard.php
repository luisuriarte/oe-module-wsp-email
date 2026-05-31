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
            <a class="nav-link <?php echo $activeTab === 'schedules' ? 'active' : ''; ?>" href="?tab=schedules">
                <i class="fas fa-calendar-alt me-1"></i><?php echo xlt('Schedules'); ?>
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

                        <!-- Active Vendor Selector -->
                        <div class="mb-3">
                            <label class="form-label"><?php echo xlt('Select Vendor'); ?></label>
                            <select name="current_vendor" id="cfgCurrentVendor" class="form-select form-select-sm" onchange="handleVendorChange()">
                                <option value="ultramsg">UltraMsg</option>
                                <option value="wasenderapi">WaSenderAPI</option>
                                <option value="openwa">OpenWA</option>
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
                        <div class="d-flex gap-2 mt-3">
                            <button type="submit" class="btn btn-success btn-save">
                                <i class="fas fa-save me-1"></i><?php echo xlt('Save Configuration'); ?>
                            </button>
                            <button type="button" id="btnCancelConfig" class="btn btn-outline-secondary btn-cancel">
                                <i class="fas fa-times me-1"></i><?php echo xlt('Cancel'); ?>
                            </button>
                            <button type="button" class="btn btn-outline-info" onclick="openTemplateManager()">
                                <i class="fas fa-edit me-1"></i><?php echo xlt('Templates'); ?>
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
    { value: 'QUEUED', label: 'Queued', checked: true },
    { value: 'SENT', label: 'Sent', checked: true },
    { value: 'DELIVERED', label: 'Delivered', checked: true },
    { value: 'READ', label: 'Read', checked: true },
    { value: 'FAILED', label: 'Failed', checked: true },
    { value: 'INVALID', label: 'Invalid', checked: true },
    { value: 'ERROR', label: 'Error', checked: true },
    { value: 'UNSENT', label: 'Unsent', checked: true }
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
        'QUEUED':    { icon: 'fa-clock',         label: 'Queued',    css: 'badge-queue' },
        'SENT':      { icon: 'fa-check',         label: 'Sent',      css: 'badge-sent ' + (type === 'WSP' ? 'type-wsp' : type === 'EMAIL' ? 'type-email' : '') },
        'DELIVERED': { icon: 'fa-box',           label: 'Delivered', css: 'badge-delivered' },
        'READ':      { icon: 'fa-eye',           label: 'Read',      css: 'badge-read' },
        'FAILED':    { icon: 'fa-times-circle',  label: 'Failed',    css: 'badge-error' },
        'INVALID':   { icon: 'fa-question-circle', label: 'Invalid', css: 'badge-invalid' },
        'ERROR':     { icon: 'fa-exclamation-triangle', label: 'Error', css: 'badge-error' },
        'UNSENT':    { icon: 'fa-envelope',      label: 'Unsent',    css: 'badge-unsent' },
        'MANUAL_WSP':   { icon: 'fa-paper-plane',  label: 'Manual WSP',  css: 'badge-sent type-wsp' },
        'MANUAL_EMAIL': { icon: 'fa-paper-plane',  label: 'Manual Email', css: 'badge-sent type-email' },
        'MANUAL_SMS':   { icon: 'fa-sms',          label: 'Manual SMS',  css: 'badge-sms' },
        'MANUAL_VOZ':   { icon: 'fa-phone-alt',    label: 'Manual Voz',  css: 'badge-voz' }
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

            // Add active vendor badge
            const activeVendor = c.current_vendor || 'wasenderapi';
            let vendorBadge = '';
            if (activeVendor === 'ultramsg') {
                vendorBadge = '<span class="badge bg-primary ms-2 small">UltraMsg Active</span>';
            } else if (activeVendor === 'wasenderapi') {
                vendorBadge = '<span class="badge bg-info ms-2 small">WaSenderAPI Active</span>';
            } else if (activeVendor === 'openwa') {
                vendorBadge = '<span class="badge bg-warning ms-2 small">OpenWA Active</span>';
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

            // Show/hide sections based on active vendor
            handleVendorChange();
            
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

/**
 * Show/hide sections based on selected active vendor
 * - UltraMsg: shows UltraMsg credentials section
 * - WaSenderAPI: shows WaSenderAPI credentials section
 * - OpenWA: shows OpenWA credentials section
 */
function handleVendorChange() {
    const vendor = document.getElementById('cfgCurrentVendor').value;
    const ultramsgConfig = document.getElementById('ultramsgConfig');
    const wasenderConfig = document.getElementById('wasenderConfig');
    const openwaConfig = document.getElementById('openwaConfig');

    if (vendor === 'ultramsg') {
        if (ultramsgConfig) ultramsgConfig.style.display = 'block';
        if (wasenderConfig) wasenderConfig.style.display = 'none';
        if (openwaConfig) openwaConfig.style.display = 'none';
    } else if (vendor === 'wasenderapi') {
        if (ultramsgConfig) ultramsgConfig.style.display = 'none';
        if (wasenderConfig) wasenderConfig.style.display = 'block';
        if (openwaConfig) openwaConfig.style.display = 'none';
    } else if (vendor === 'openwa') {
        if (ultramsgConfig) ultramsgConfig.style.display = 'none';
        if (wasenderConfig) wasenderConfig.style.display = 'none';
        if (openwaConfig) openwaConfig.style.display = 'block';
    }
}

// Add event listener for vendor change (only when user manually changes)
document.addEventListener('DOMContentLoaded', function() {
    const vendorSelect = document.getElementById('cfgCurrentVendor');
    if (vendorSelect) {
        vendorSelect.addEventListener('change', handleVendorChange);
        // Don't call handleVendorChange() here - it's called by loadFacilityConfig()
    }
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
    if (!facId) { alert('Please select a facility first.'); return; }
    
    document.getElementById('tmFacilityId').value = facId;
    const tbody = document.getElementById('templateTableBody');
    tbody.innerHTML = '<tr><td colspan="6" class="text-center py-4"><i class="fas fa-spinner fa-spin"></i> Loading...</td></tr>';
    
    const modal = new bootstrap.Modal(document.getElementById('modalTemplateManager'));
    modal.show();

    fetch(`${moduleRoot}/pages/ajax/get_templates.php?facility_id=${facId}`)
        .then(r => r.json())
        .then(data => {
            allTemplatesData = data.rows || [];
            renderTemplateTable();
        })
        .catch(err => {
            tbody.innerHTML = `<tr><td colspan="6" class="text-danger">Error loading templates: ${err}</td></tr>`;
        });
}

function renderTemplateTable() {
    const tbody = document.getElementById('templateTableBody');
    tbody.innerHTML = '';

    allTemplatesData.forEach((tpl, index) => {
        const tr = document.createElement('tr');
        const chanBadge = tpl.channel === 'wsp' ? 'bg-success' : 'bg-primary';
        const recvBadge = tpl.recipient_type === 'patient' ? 'bg-info' : 'bg-warning text-dark';
        
        tr.innerHTML = `
            <td>
                <strong>${escHtml(tpl.category_name || tpl.pc_catid)}</strong><br>
                <span class="badge bg-secondary">${escHtml(tpl.pc_apptstatus)}</span>
            </td>
            <td><span class="badge ${chanBadge}">${tpl.channel}</span></td>
            <td><span class="badge ${recvBadge}">${tpl.recipient_type}</span></td>
            <td><textarea class="form-control form-control-sm mono" rows="3" onchange="updateTpl(${index}, 'wsp_message', this.value)">${escHtml(tpl.wsp_message)}</textarea></td>
            <td><input type="text" class="form-control form-control-sm" value="${escHtml(tpl.email_subject)}" onchange="updateTpl(${index}, 'email_subject', this.value)"></td>
            <td><textarea class="form-control form-control-sm mono" rows="3" onchange="updateTpl(${index}, 'email_message', this.value)">${escHtml(tpl.email_message)}</textarea></td>
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
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving...';

    fetch(`${moduleRoot}/pages/ajax/save_templates.php`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ facility_id: facId, templates: allTemplatesData })
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            alert('Templates saved successfully.');
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
        btn.innerHTML = '<i class="fas fa-save me-1"></i> Save Changes';
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
</style>

<!-- Modal: Template Manager -->
<div class="modal fade" id="modalTemplateManager" tabindex="-1">
    <div class="modal-dialog modal-xl">
        <div class="modal-content">
            <div class="modal-header bg-info text-white">
                <h5 class="modal-title"><i class="fas fa-file-alt me-2"></i>Manage Notification Templates</h5>
                <button type="button" class="btn btn-sm btn-link text-white" data-dismiss="modal" data-bs-dismiss="modal" onclick="closeModalParent(this)"><i class="fas fa-times"></i></button>
            </div>
            <div class="modal-body">
                <p class="text-muted small">Edit messages for different scenarios. Tokens like <code>***NAME***</code>, <code>***DATE***</code> will be replaced automatically.</p>
                <div class="table-responsive">
                    <table class="table table-bordered table-sm align-middle">
                        <thead class="table-light">
                            <tr>
                                <th width="20%">Scenario</th>
                                <th width="10%">Channel</th>
                                <th width="15%">Recipient</th>
                                <th>WhatsApp Message</th>
                                <th>Email Subject</th>
                                <th>HTML Email Body</th>
                            </tr>
                        </thead>
                        <tbody id="templateTableBody">
                            <!-- Rows injected via JS -->
                        </tbody>
                    </table>
                </div>
                <input type="hidden" id="tmFacilityId">
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-dismiss="modal" data-bs-dismiss="modal" onclick="closeModalParent(this)">Close</button>
                <button type="button" class="btn btn-primary" onclick="saveTemplates()"><i class="fas fa-save me-1"></i> Save Changes</button>
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
</script>

</body>
</html>
