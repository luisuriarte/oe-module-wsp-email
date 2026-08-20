<?php
/**
 * NotificationService — Central orchestrator for WhatsApp and Email notifications.
 *
 * Supports multiple notification slots per facility (defined in wsp_email_notification_schedule):
 *   - Slot with send_on_booking=1: fired immediately when the appointment is created
 *   - Slots with hours_before=N:   fired N hours before the appointment (by cron)
 *
 * For each slot, the service checks whether that specific sequence number has already been
 * sent for this appointment, preventing duplicate notifications.
 *
 * @package   OpenEMR\Modules\WspEmail
 * @author    Luis A. Uriarte <luis.uriarte@gmail.com>
 * @copyright Copyright (c) 2024-2025 Luis A. Uriarte
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

namespace OpenEMR\Modules\WspEmail;

use OpenEMR\Common\Crypto\CryptoGen;
use OpenEMR\Modules\WspEmail\StatusNormalizer;

class NotificationService
{
    private WspSender       $wspSender;
    private EmailSender     $emailSender;
    private NotificationLog $log;
    private FacilityConfig  $facilityConfig;
    private RateLimiter     $rateLimiter;
    private Blacklist       $blacklist;

    /** Base public URL for temporary .ics files served to WhatsApp vendors. */
    private string $moduleBaseUrl;

    public function __construct()
    {
        $this->wspSender      = new WspSender();
        $this->emailSender    = new EmailSender();
        $this->log            = new NotificationLog();
        $this->facilityConfig = new FacilityConfig();
        $this->moduleBaseUrl  = rtrim($GLOBALS['site_addr_oath'] ?? '', '/') .
                                '/public/ics/';
        $this->rateLimiter = new RateLimiter();
        $this->blacklist   = new Blacklist();
    }

    // =========================================================================
    // Public entry points for cron scripts
    // =========================================================================

    /**
     * Process all pending WhatsApp notifications (called by cron).
     * Iterates over every facility → every schedule slot → every eligible patient.
     *
     * @param bool $dryRun  If true, log but do NOT actually send.
     */
    public function runWsp(bool $dryRun = false): void
    {
        $this->runByType('WSP', $dryRun);
    }

    /**
     * Process all pending Email notifications (called by cron).
     *
     * @param bool $dryRun  If true, log but do NOT actually send.
     */
    public function runEmail(bool $dryRun = false): void
    {
        $this->runByType('EMAIL', $dryRun);
    }

    /**
     * Process all pending SMS notifications (called by cron).
     *
     * @param bool $dryRun  If true, log but do NOT actually send.
     */
    public function runSms(bool $dryRun = false): void
    {
        $this->runByType('SMS', $dryRun);
    }

    /**
     * Fires the on-booking notification for a newly created/edited appointment.
     * Call this from the calendar save hook (OpenEMR event) or from a post-save trigger.
     *
     * @param array $appointment  Must contain at minimum: pc_eid, pc_pid, pc_facility,
     *                            pc_eventDate, pc_startTime, pc_endTime
     */
    public function runOnBooking(array $appointment): void
    {
        $facilityId    = (int)($appointment['pc_facility'] ?? 0);
        $onBookingSlots = $this->facilityConfig->getOnBookingSlots($facilityId);

        if (empty($onBookingSlots)) {
            return; // No on-booking slots configured for this facility
        }

        // Enrich the appointment row with patient + provider data
        $patient = $this->enrichAppointment($appointment);
        if (empty($patient)) {
            return;
        }

        $config = $this->facilityConfig->getByFacilityId($facilityId);
        if (empty($config)) {
            return;
        }
        $this->mergeConfig($patient, $config);

        foreach ($onBookingSlots as $slot) {
            $seq = (int)$slot['seq'];

            if (!empty($slot['enabled_wsp']) && !empty($config['enabled_wsp'])) {
                if (!$this->alreadySent('WSP', (int)$patient['pid'], (int)$patient['pc_eid'], $seq)) {
                    $this->deliverWsp($patient, $config, $seq, false);
                }
            }
            if (!empty($slot['enabled_email']) && !empty($config['enabled_email'])) {
                if (!$this->alreadySent('EMAIL', (int)$patient['pid'], (int)$patient['pc_eid'], $seq)) {
                    $this->deliverEmail($patient, $config, $seq, false);
                }
            }
            if (!empty($slot['enabled_sms']) && !empty($config['enabled_sms'])) {
                if (!$this->alreadySent('SMS', (int)$patient['pid'], (int)$patient['pc_eid'], $seq)) {
                    $this->deliverSms($patient, $config, $seq, false);
                }
            }
        }
    }

    // =========================================================================
    // Internal run engine
    // =========================================================================

    private function runByType(string $type, bool $dryRun): void
    {
        $facilities = $this->facilityConfig->getAllFacilitiesWithConfig();

        foreach ($facilities as $facilityRow) {
            $facilityId = (int)$facilityRow['facility_id'];
            $config     = $this->facilityConfig->getByFacilityId($facilityId);

            if (empty($config)) {
                continue;
            }
            if ($type === 'WSP'   && empty($config['enabled_wsp']))   continue;
            if ($type === 'EMAIL' && empty($config['enabled_email'])) continue;
            if ($type === 'SMS'   && empty($config['enabled_sms']))   continue;

            // Fetch the cron-triggered schedule slots for this facility
            $slots = $this->facilityConfig->getScheduledSlots($facilityId);

            if (empty($slots)) {
                // Fallback: if no schedule configured, use a single default 48-hour slot
                $slots = [['seq' => 1, 'hours_before' => 48, 'send_on_booking' => 0, 'enabled_wsp' => 1, 'enabled_email' => 1, 'enabled_sms' => 1]];
            }

            // Check allowed sending window for this facility (day-aware)
            $currentHour    = (int)date('G');
            $currentDow     = (int)date('w'); // 0=Sunday, 1=Monday ... 6=Saturday

            if ($currentDow === 6) {
                // Saturday
                $allowed = !empty($config['send_saturday_enabled']);
                $start   = (int)($config['send_saturday_start'] ?? 8);
                $end     = (int)($config['send_saturday_end']   ?? 13);
                $dayName = 'Saturday';
            } elseif ($currentDow === 0) {
                // Sunday
                $allowed = !empty($config['send_sunday_enabled']);
                $start   = (int)($config['send_sunday_start'] ?? 9);
                $end     = (int)($config['send_sunday_end']   ?? 12);
                $dayName = 'Sunday';
            } else {
                // Monday to Friday
                $allowed = true;
                $start   = (int)($config['send_weekday_start'] ?? 7);
                $end     = (int)($config['send_weekday_end']   ?? 21);
                $dayName = 'Weekday';
            }

            if (!$allowed) {
                echo "  Facility #{$facilityId} | {$dayName} sending disabled. Skipping.\n";
                continue;
            }

            if ($currentHour < $start || $currentHour >= $end) {
                echo "  Facility #{$facilityId} | Outside allowed hours "
                . "({$currentHour}:00, allowed {$start}:00-{$end}:00 on {$dayName}). Skipping.\n";
                continue;
            }

            foreach ($slots as $slot) {
                $seq         = (int)$slot['seq'];
                $hoursBefore = (int)($slot['hours_before'] ?? 48);

                // Only process slots whose channel matches the current run type
                if ($type === 'WSP'   && empty($slot['enabled_wsp']))   continue;
                if ($type === 'EMAIL' && empty($slot['enabled_email'])) continue;
                if ($type === 'SMS'   && empty($slot['enabled_sms']))   continue;

                $patients = $this->getPatientsForSlot($facilityId, $type, $seq, $hoursBefore);
                echo "  Facility #{$facilityId} | slot seq={$seq} ({$hoursBefore}h before) | {$type} | "
                   . count($patients) . " patient(s)\n";

                foreach ($patients as $patient) {
                    $this->mergeConfig($patient, $config);

                    echo "    pid={$patient['pid']} | "
                    . trim($patient['fname'] . ' ' . $patient['lname']) . "\n";

                    if ($dryRun) {
                        echo "    [DRY-RUN] Skipping actual send.\n";
                        continue;
                    }

                    if ($type === 'WSP') {
                        $phone  = $patient['phone_cell'] ?? '';
                        $vendor = strtolower($config['current_vendor'] ?? $config['vendor'] ?? 'wasenderapi');

                        if ($this->blacklist->isBlacklisted($phone, $facilityId, $vendor)) {
                            echo "    [BLACKLIST] Skipping phone={$phone} — blacklisted for vendor={$vendor}\n";
                            continue;
                        }

                        $this->deliverWsp($patient, $config, $seq, true);
                    } elseif ($type === 'SMS') {
                        $this->deliverSms($patient, $config, $seq, true);
                    } else {
                        $this->deliverEmail($patient, $config, $seq, true);
                    }
                }
            }
        }
    }

    // =========================================================================
    // Delivery methods
    // =========================================================================

    private function deliverWsp(array &$patient, array $config, int $seq, bool $updateCalFlag): void
    {
        // Resolve message template (same logic as WspSender::send())
        $facilityId = (int)($config['facility_id'] ?? $patient['pc_facility'] ?? 0);
        $pcCatid    = (int)($patient['pc_catid'] ?? 0);
        $pcStatus   = WspSender::normalizeApptStatusForTemplate(
            (string)($patient['tracker_status'] ?? ''),
            (string)($patient['pc_apptstatus'] ?? '')
        );
        $template = '';
        if (!empty($pcCatid)) {
            $template = WspSender::resolveTemplate($facilityId, $pcCatid, $pcStatus, 'patient');
        }
        $patient['_message'] = WspSender::buildMessage($template, $patient);

        // Build and publish the temporary .ics file
        $pcEid              = (int)($patient['pc_eid'] ?? 0);
        $icsPath            = WspSender::buildIcsFile($patient, $config);
        $icsPublicName      = "appointment_{$pcEid}_{$seq}.ics";

        // Build public URL for the .ics file (must be reachable by the WhatsApp vendor)
        $baseUrl = rtrim($config['website_url'] ?? '', '/');
        if (empty($baseUrl)) {
            $baseUrl = rtrim($GLOBALS['site_addr_oath'] ?? '', '/');
        }
        $patient['_ics_url'] = "{$baseUrl}/public/ics/{$icsPublicName}";
        error_log("WspEmail deliverWsp: _ics_url={$patient['_ics_url']}, website_url={$config['website_url']}, site_addr_oath=" . ($GLOBALS['site_addr_oath'] ?? '(not set)'));

        $publicIcsDir = $GLOBALS['fileroot'] . '/public/ics/';
        if (!is_dir($publicIcsDir)) {
            mkdir($publicIcsDir, 0755, true);
        }
        copy($icsPath, $publicIcsDir . $icsPublicName);
        @unlink($icsPath);

        try {
            $phone  = $patient['phone_cell'] ?? '';
            $vendor = strtolower($config['current_vendor'] ?? $config['vendor'] ?? 'wasenderapi');
            $facilityId = (int)($config['facility_id'] ?? $patient['pc_facility'] ?? 0);

            // -----------------------------------------------------------------
            // OpenWA pre-send contact validation (initial notification only)
            // Only runs for seq <= 1 (seq=1 = first scheduled slot, seq=0 = on-booking).
            // Recall / escalation sequences (seq > 1) skip this check.
            // -----------------------------------------------------------------
            if ($vendor === 'openwa' && $seq <= 1) {
                $owInstance = $config['openwa_instance'] ?? '';
                $owApiKey   = $config['openwa_api_key']  ?? '';

                $contactStatus = $this->wspSender->checkOpenWaContact($owInstance, $owApiKey, $phone);

                if ($contactStatus === 'not_found') {
                    // Number is NOT on WhatsApp → log and deliver via email fallback
                    $msg = "OpenWA contact check: phone={$phone} NOT on WhatsApp — triggering email fallback (seq={$seq}).";
                    echo "    [CONTACT-CHECK] {$msg}\n";
                    error_log($msg);

                    $this->insertLog(
                        'WSP', $patient, $config, null, 'WSP_NOT_ON_WA',
                        $seq, 'WSP_NOT_ON_WA', 0, $vendor,
                        ['contact_check' => 'not_found', 'phone' => $phone]
                    );

                    // Auto-blacklist: prevent future WSP attempts to this number
                    $this->blacklist->addNotOnWhatsApp($phone, $facilityId, $vendor);
                    echo "    [CONTACT-CHECK] Phone={$phone} added to blacklist (NOT_ON_WA).\n";

                    // Trigger email fallback if email channel is enabled for this facility
                    if (!empty($config['enabled_email']) && !empty($patient['email'])
                        && ($patient['hipaa_allowemail'] ?? '') === 'YES'
                        && !$this->alreadySent('EMAIL', (int)$patient['pid'], (int)$patient['pc_eid'], $seq)
                    ) {
                        echo "    [CONTACT-CHECK] Delivering via email fallback to {$patient['email']}\n";
                        $this->deliverEmail($patient, $config, $seq, $updateCalFlag);
                    } else {
                        echo "    [CONTACT-CHECK] No email fallback available (disabled, no email, or HIPAA restriction).\n";
                    }

                    // Clean up .ics file before returning
                    sleep(2);
                    @unlink($publicIcsDir . $icsPublicName);
                    return;

                } elseif ($contactStatus === 'service_unavailable') {
                    // OpenWA did not confirm the session — fail-closed: no WSP, no email fallback
                    $msg = "OpenWA contact check: phone={$phone} returned service_unavailable (503) — will retry on next cron run (seq={$seq}).";
                    echo "    [CONTACT-CHECK] {$msg}\n";
                    error_log($msg);

                    // NOTE: Intentionally NOT inserting into notification_log here.
                    // The NOT EXISTS filter in getPatientsForSlot() will include this
                    // patient again on the next cron run, giving automatic retry
                    // once the OpenWA session recovers. Only write to the module log
                    // for traceability without blocking the retry cycle.
                    $logLine = date('Y-m-d H:i:s') . " [CHECK_UNAVAILABLE] pid={$patient['pid']} pc_eid={$patient['pc_eid']} phone={$phone} seq={$seq} — will retry automatically\n";
                    $logFile = __DIR__ . '/../logs/wsp_notify.log';
                    if (!is_dir(dirname($logFile))) {
                        @mkdir(dirname($logFile), 0755, true);
                    }
                    @file_put_contents($logFile, $logLine, FILE_APPEND | LOCK_EX);

                    // Do NOT fall back to email; retry via cron is the safe path
                    sleep(2);
                    @unlink($publicIcsDir . $icsPublicName);
                    return;
                }

                // $contactStatus === 'exists' → continue with normal send flow below
                error_log("WspSender::checkOpenWaContact — phone={$phone} exists on WhatsApp, proceeding with send.");
            }

            // Rate limiting + delay aleatorio antes de enviar
            $this->rateLimiter->throttle($facilityId, $vendor, $phone);

            $result = $this->wspSender->send($config, $patient);
            $msgId  = $result['msgId'] ?? null;
            $rawStatus = $result['status'] ?? 'error';
            $vendor = strtolower($config['current_vendor'] ?? $config['vendor'] ?? 'wasenderapi');

            // Evaluate results for blacklisting
            $this->blacklist->processResult($phone, $facilityId, $vendor, $result);

            // Halt cron on critical errors (401 Unauthorized / 404 Not Found)
            if ($rawStatus === 'UNAUTHORIZED') {
                $err = "CRITICAL: API key is unauthorized (401) for facility #{$facilityId} on vendor={$vendor}. Halting cron execution.";
                echo "    [CRITICAL] {$err}\n";
                error_log($err);
                throw new \Exception($err);
            }
            if ($rawStatus === 'NOT_FOUND') {
                $err = "CRITICAL: Session ID not found (404) for facility #{$facilityId} on vendor={$vendor}. Halting cron execution.";
                echo "    [CRITICAL] {$err}\n";
                error_log($err);
                throw new \Exception($err);
            }

            // Normalize status using StatusNormalizer
            $canonicalStatus = StatusNormalizer::normalize($vendor, $rawStatus);
            $statusPriority = StatusNormalizer::getPriority($canonicalStatus);

            $this->insertLog('WSP', $patient, $config, $msgId, $rawStatus, $seq, $canonicalStatus, $statusPriority, $vendor, $result);

            if ($updateCalFlag) {
                $this->markEventSent('WSP', (int)$patient['pid'], (int)$patient['pc_eid']);
            }
            $this->updateTracker($patient, 'WSP');
        } catch (\Throwable $e) {
            // Log the error even if send fails catastrophically
            $errorMsg = $e->getMessage();
            $errorLog = 'EXCEPTION in deliverWsp: ' . $errorMsg . "\nTrace: " . $e->getTraceAsString();
            
            $this->insertLog('WSP', $patient, $config, null, 'error', $seq, 'ERROR', 0, strtolower($config['current_vendor'] ?? $config['vendor'] ?? 'wasenderapi'), ['error' => $errorMsg, 'log' => $errorLog]);
            
            echo "    ERROR: " . $errorMsg . "\n";

            // If it's a critical halt exception, propagate it to stop the cron
            if (strpos($errorMsg, 'CRITICAL:') === 0) {
                throw $e;
            }
        }

        // Allow time for vendor to download the .ics before deleting it
        sleep(5);
        @unlink($publicIcsDir . $icsPublicName);
    }

    private function deliverEmail(array &$patient, array $config, int $seq, bool $updateCalFlag): void
    {
        // Resolve message template (same logic as WspSender::send())
        $facilityId = (int)($config['facility_id'] ?? $patient['pc_facility'] ?? 0);
        $pcCatid    = (int)($patient['pc_catid'] ?? 0);
        $pcStatus   = WspSender::normalizeApptStatusForTemplate(
            (string)($patient['tracker_status'] ?? ''),
            (string)($patient['pc_apptstatus'] ?? '')
        );
        $template = '';
        if (!empty($pcCatid)) {
            $template = WspSender::resolveTemplate($facilityId, $pcCatid, $pcStatus, 'patient');
        }
        $patient['_message'] = WspSender::buildMessage($template, $patient);
        // Also resolve email subject from templates
        $config['email_subject'] = WspSender::resolveTemplate($facilityId, $pcCatid, $pcStatus, 'patient', 'email_subject');

        $ok     = $this->emailSender->send($config, $patient);
        $rawStatus = $ok ? 'sent' : 'error';
        echo "    Email result: $rawStatus\n";

        // Log to module file
        $logLine = date('Y-m-d H:i:s') . " — Email to={$patient['email']} pid={$patient['pid']} status=$rawStatus\n";
        $logFile = __DIR__ . '/../logs/email_notify.log';
        $logDir  = dirname($logFile);
        if (!is_dir($logDir)) {
            @mkdir($logDir, 0755, true);
        }
        @file_put_contents($logFile, $logLine, FILE_APPEND | LOCK_EX);

        // Normalize email status
        $canonicalStatus = StatusNormalizer::normalize('default', $rawStatus);
        $statusPriority = StatusNormalizer::getPriority($canonicalStatus);

        $this->insertLog('EMAIL', $patient, $config, null, $rawStatus, $seq, $canonicalStatus, $statusPriority, 'email', ['success' => $ok]);

        if ($updateCalFlag) {
            $this->markEventSent('EMAIL', (int)$patient['pid'], (int)$patient['pc_eid']);
        }
        $this->updateTracker($patient, 'EMAIL');
    }

    private function deliverSms(array &$patient, array $config, int $seq, bool $updateCalFlag): void
    {
        // Resolve message template
        $facilityId = (int)($config['facility_id'] ?? $patient['pc_facility'] ?? 0);
        $pcCatid    = (int)($patient['pc_catid'] ?? 0);
        $pcStatus   = WspSender::normalizeApptStatusForTemplate(
            (string)($patient['tracker_status'] ?? ''),
            (string)($patient['pc_apptstatus'] ?? '')
        );
        $template = '';
        if (!empty($pcCatid)) {
            $template = WspSender::resolveTemplate($facilityId, $pcCatid, $pcStatus, 'patient');
        }
        $patient['_message'] = WspSender::buildMessage($template, $patient);

        try {
            $phone = $patient['phone_cell'] ?? '';

            // Rate limiting + delay
            $this->rateLimiter->throttle($facilityId, 'httpsms', $phone);

            $result    = $this->wspSender->sendSms($config, $patient);
            $msgId     = $result['msgId'] ?? null;
            $rawStatus = $result['status'] ?? 'error';

            // Normalize status
            $canonicalStatus = StatusNormalizer::normalize('httpsms', $rawStatus);
            $statusPriority  = StatusNormalizer::getPriority($canonicalStatus);

            $this->insertLog('SMS', $patient, $config, $msgId, $rawStatus, $seq, $canonicalStatus, $statusPriority, 'httpsms', $result);

            if ($updateCalFlag) {
                $this->markEventSent('SMS', (int)$patient['pid'], (int)$patient['pc_eid']);
            }
            $this->updateTracker($patient, 'SMS');
        } catch (\Throwable $e) {
            $errorMsg = $e->getMessage();
            $errorLog = 'EXCEPTION in deliverSms: ' . $errorMsg . "\nTrace: " . $e->getTraceAsString();
            $this->insertLog('SMS', $patient, $config, null, 'error', $seq, 'ERROR', 0, 'httpsms', ['error' => $errorMsg, 'log' => $errorLog]);
            echo "    SMS ERROR: " . $errorMsg . "\n";
        }
    }

    private function resolveNotificationTemplate(int $facilityId, int $pcCatid, string $pcApptstatus, string $recipientType = 'patient'): string
    {
        // Try exact match first: facility_id + pc_catid + pc_apptstatus + recipient_type
        $sql = "SELECT wsp_message FROM wsp_email_notification_templates
                WHERE facility_id = ?
                  AND pc_catid = ?
                  AND pc_apptstatus = ?
                  AND recipient_type = ?
                  AND enabled = 1
                LIMIT 1";
        $row = sqlQuery($sql, [$facilityId, $pcCatid, $pcApptstatus, $recipientType]);
        if (!empty($row['wsp_message'])) {
            return $row['wsp_message'];
        }
        // Fallback: match facility_id + pc_catid (any status)
        $sql = "SELECT wsp_message FROM wsp_email_notification_templates
                WHERE facility_id = ?
                  AND pc_catid = ?
                  AND pc_apptstatus = '-'
                  AND recipient_type = ?
                  AND enabled = 1
                LIMIT 1";
        $row = sqlQuery($sql, [$facilityId, $pcCatid, $recipientType]);
        if (!empty($row['wsp_message'])) {
            return $row['wsp_message'];
        }
        // Fallback: match facility_id only (wildcard category)
        $sql = "SELECT wsp_message FROM wsp_email_notification_templates
                WHERE facility_id = ?
                  AND pc_catid = 0
                  AND pc_apptstatus = '-'
                  AND recipient_type = ?
                  AND enabled = 1
                LIMIT 1";
        $row = sqlQuery($sql, [$facilityId, $recipientType]);
        return $row['wsp_message'] ?? '';
    }

    // =========================================================================
    // Database helpers
    // =========================================================================

    /**
     * Returns patients eligible for a specific schedule slot.
     *
     * Multiple conditions must be met:
     *   1. Patient has not received this specific seq notification yet
     *   2. The appointment is within the hours_before window
     *   3. The patient has allowed the notification channel (HIPAA flags)
     */
    private function getPatientsForSlot(int $facilityId, string $type, int $seq, int $hoursBefore): array
    {
        if ($type === 'WSP' || $type === 'SMS') {
            $hipaaFilter = "AND pd.hipaa_allowsms = 'YES' AND pd.phone_cell <> ''";
        } else {
            $hipaaFilter = "AND pd.hipaa_allowemail = 'YES' AND pd.email <> ''";
        }

        // Time window: appointment is within [now, now + hoursBefore]
        $windowStart = date('Y-m-d H:i:s');
        $windowEnd   = date('Y-m-d H:i:s', strtotime("+{$hoursBefore} hours"));

        $sql = "SELECT DISTINCT pd.pid, pd.title, pd.fname, pd.lname, pd.mname, pd.phone_cell,
                       pd.email, pd.hipaa_allowsms, pd.hipaa_allowemail,
                       ope.pc_eid, ope.pc_pid, ope.pc_title, ope.pc_hometext,
                       ope.pc_eventDate, ope.pc_endDate, ope.pc_duration,
                       ope.pc_startTime, ope.pc_endTime, ope.pc_facility, ope.pc_catid,
                       ope.pc_apptstatus,
                       COALESCE(pt_latest.status, ope.pc_apptstatus) AS tracker_status,
                       CONCAT(u.fname,' ',IFNULL(u.mname,''),' ',u.lname) AS user_name,
                       u.suffix AS user_preffix
                FROM openemr_postcalendar_events ope
                INNER JOIN patient_data pd ON pd.pid = ope.pc_pid
                LEFT  JOIN users        u  ON u.id   = ope.pc_aid
                LEFT JOIN patient_tracker pt ON pt.eid = ope.pc_eid
                LEFT JOIN patient_tracker_element pt_latest ON pt_latest.pt_tracker_id = pt.id
                    AND pt_latest.seq = (SELECT MAX(seq) FROM patient_tracker_element WHERE pt_tracker_id = pt.id)
                WHERE ope.pc_facility = ?
                  AND CONCAT(ope.pc_eventDate, ' ', COALESCE(ope.pc_startTime, '00:00:00')) > ?
                  AND CONCAT(ope.pc_eventDate, ' ', COALESCE(ope.pc_startTime, '00:00:00')) <= ?
                  AND ope.pc_apptstatus NOT IN ('X', '%', '^', '*', '-cancelled', '-noshow')
                  AND COALESCE(pt_latest.status, ope.pc_apptstatus) NOT IN ('x', '%', '?', '^', 'wsp-err')
                  $hipaaFilter
                  AND NOT EXISTS (
                      SELECT 1 FROM notification_log nl
                      WHERE nl.pid = ope.pc_pid
                        AND nl.pc_eid = ope.pc_eid
                        AND nl.type = ?
                        AND nl.notification_seq = ?
                  )
                ORDER BY ope.pc_eventDate, ope.pc_startTime";

        $res      = sqlStatement($sql, [$facilityId, $windowStart, $windowEnd, $type, $seq]);
        $patients = [];
        while ($row = sqlFetchArray($res)) {
            $patients[] = $row;
        }
        return $patients;
    }

    /**
     * Checks whether a specific notification sequence has already been sent for an appointment.
     */
    private function alreadySent(string $type, int $pid, int $pcEid, int $seq): bool
    {
        $row = sqlQuery(
            "SELECT iLogId FROM notification_log WHERE pid = ? AND pc_eid = ? AND type = ? AND notification_seq = ? LIMIT 1",
            [$pid, $pcEid, $type, $seq]
        );
        return !empty($row);
    }

    /** Enriches a bare appointment array with patient + provider data. */
    private function enrichAppointment(array $appointment): array
    {
        $sql = "SELECT pd.pid, pd.title, pd.fname, pd.lname, pd.mname, pd.phone_cell,
                       pd.email, pd.hipaa_allowsms, pd.hipaa_allowemail,
                       ope.pc_eid, ope.pc_pid, ope.pc_eventDate, ope.pc_endDate,
                       ope.pc_startTime, ope.pc_endTime, ope.pc_facility,
                       ope.pc_catid, ope.pc_apptstatus, ope.pc_hometext,
                       CONCAT(u.fname,' ',IFNULL(u.mname,''),' ',u.lname) AS user_name,
                       u.suffix AS user_preffix
                FROM openemr_postcalendar_events ope
                INNER JOIN patient_data pd ON pd.pid = ope.pc_pid
                LEFT  JOIN users        u  ON u.id   = ope.pc_aid
                WHERE ope.pc_eid = ?";
        return sqlQuery($sql, [(int)($appointment['pc_eid'] ?? 0)]) ?: [];
    }

    /**
     * Merges facility name/address/phone/email from config into the patient row
     * so message templates can use ***FACILITY_**** tokens.
     */
    private function mergeConfig(array &$patient, array $config): void
    {
        $patient['facility_name']    = $config['facility_name']    ?? '';
        $patient['facility_address'] = $config['facility_address'] ?? '';
        $patient['facility_phone']   = $config['facility_phone']   ?? '';
        $patient['facility_email']   = $config['facility_email']   ?? '';
        $patient['latitude']         = $config['latitude']         ?? '';
        $patient['longitude']        = $config['longitude']        ?? '';
        $patient['website_url']      = $config['website_url']      ?? '';
    }

    /** Records the notification attempt in notification_log including the seq number. */
    private function insertLog(
        string $type, array $patient, array $config,
        ?string $msgId, string $status, int $seq,
        ?string $canonicalStatus = null, ?int $statusPriority = null,
        ?string $provider = null, ?array $result = null
    ): void {
        $patientInfo = trim(($patient['title'] ?? '') . ' ' . $patient['fname'] . ' ' . $patient['lname'])
                     . '|||' . $patient['phone_cell']
                     . '|||' . $patient['email'];
        $gatewayType  = strtolower($config['current_vendor'] ?? $config['vendor'] ?? '');
        $gatewayInfo  = ($type === 'WSP') ? $gatewayType : (($config['facility_email'] ?? '') . '|||' . ($config['email_subject'] ?? ''));

        // Normalize canonical status if not provided
        if ($canonicalStatus === null) {
            $canonicalStatus = StatusNormalizer::normalize($provider ?? 'default', $status);
        }
        if ($statusPriority === null) {
            $statusPriority = StatusNormalizer::getPriority($canonicalStatus);
        }

        // Use extended insert that includes new status normalization columns
        $sql = "INSERT INTO notification_log
                    (pid, pc_eid, sms_gateway_type, message, email_sender, email_subject,
                     type, patient_info, smsgateway_info, msg_id, status, status_current,
                     status_priority, provider_raw_status, provider_payload, notification_seq,
                     pc_eventDate, pc_endDate, pc_startTime, pc_endTime, dSentDateTime)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())";

        sqlStatement($sql, [
            $patient['pid'],
            $patient['pc_eid'],
            $gatewayType,
            $patient['_message'] ?? '',
            $config['facility_email'] ?? '',
            $config['email_subject'] ?? '',
            $type,
            $patientInfo,
            $gatewayInfo,
            $msgId,
            $status,  // raw status
            $canonicalStatus,  // normalized status
            $statusPriority,  // priority
            $status,  // provider_raw_status (same as status initially)
            $result ? json_encode($result, JSON_UNESCAPED_UNICODE) : null,  // provider_payload
            $seq,
            $patient['pc_eventDate'],
            $patient['pc_endDate'] ?? $patient['pc_eventDate'],
            $patient['pc_startTime'],
            $patient['pc_endTime'],
        ]);

        // Get last inserted ID using OpenEMR's sqlGetLastInsertId() function
        $logId = (int)sqlGetLastInsertId();
        if ($logId > 0) {
            $this->log->addStatusHistory($logId, $canonicalStatus, $status, $provider, $result);
        }
    }

    /**
     * Updates the calendar event flag and tracker status after sending a notification.
     * Sets pc_apptstatus to 'wsp-sent' or 'EMAIL' to match list_options.apptstat.
     */
    private function markEventSent(string $type, int $pid, int $pcEid): void
    {
        $trackerStatus = ($type === 'WSP') ? 'wsp-sent' : 'EMAIL';

        // Legacy flags
        if ($type === 'WSP') {
            sqlStatement(
                "UPDATE openemr_postcalendar_events SET pc_sendalertwsp = 'YES', pc_apptstatus = ? WHERE pc_pid = ? AND pc_eid = ?",
                [$trackerStatus, $pid, $pcEid]
            );
        } else {
            sqlStatement(
                "UPDATE openemr_postcalendar_events SET pc_sendalertemail = 'YES', pc_apptstatus = ? WHERE pc_pid = ? AND pc_eid = ?",
                [$trackerStatus, $pid, $pcEid]
            );
        }

        // Update patient_tracker_element.status to match
        sqlStatement(
            "UPDATE patient_tracker_element pte
             INNER JOIN patient_tracker pt ON pt.id = pte.pt_tracker_id
             SET pte.status = ?
             WHERE pt.eid = ? AND pte.seq = (
                 SELECT MAX(seq) FROM patient_tracker_element
                 WHERE pt_tracker_id = pt.id
             )",
            [$trackerStatus, $pcEid]
        );
    }

    /** Updates the patient tracker with the notification event. */
    private function updateTracker(array $patient, string $type): void
    {
        if (function_exists('manage_tracker_status')) {
            manage_tracker_status(
                $patient['pc_eventDate'],
                $patient['pc_startTime'],
                (int)$patient['pc_eid'],
                (int)$patient['pid'],
                'Automatic',
                strtoupper($type),
                '',
                ''
            );
        }
    }

    /**
     * Send cancellation notification for an appointment that was cancelled (status 'x').
     * Resolves the -cancelled template and sends via WSP and/or Email.
     *
     * @param int $pcEid     Appointment event ID
     * @param int $facilityId Facility ID
     */
    public function sendCancellation(int $pcEid, int $facilityId): void
    {
        $config = $this->facilityConfig->getByFacilityId($facilityId);
        if (empty($config)) {
            echo "  Cancellation: No configuration for facility_id={$facilityId}\n";
            return;
        }

        if (empty($config['notify_cancelled'])) {
            echo "  Cancellation: Notifications disabled for facility_id={$facilityId} (notify_cancelled=0)\n";
            return;
        }

        if (empty($config['enabled_wsp']) && empty($config['enabled_email'])) {
            echo "  Cancellation: No channel enabled for facility_id={$facilityId}\n";
            return;
        }

        // Fetch appointment + patient data
        $sql = "SELECT pd.pid, pd.title, pd.fname, pd.lname, pd.mname, pd.phone_cell,
                       pd.email, pd.hipaa_allowsms, pd.hipaa_allowemail,
                       ope.pc_eid, ope.pc_pid, ope.pc_eventDate, ope.pc_endDate,
                       ope.pc_startTime, ope.pc_endTime, ope.pc_facility, ope.pc_catid,
                       ope.pc_apptstatus,
                       CONCAT(u.fname,' ',IFNULL(u.mname,''),' ',u.lname) AS user_name,
                       u.suffix AS user_preffix
                FROM openemr_postcalendar_events ope
                INNER JOIN patient_data pd ON pd.pid = ope.pc_pid
                LEFT  JOIN users        u  ON u.id   = ope.pc_aid
                WHERE ope.pc_eid = ?";
        $patient = sqlQuery($sql, [$pcEid]);
        if (empty($patient)) {
            echo "  Cancellation: Appointment {$pcEid} not found\n";
            return;
        }

        $this->mergeConfig($patient, $config);

        // Normalise status for template lookup
        $pcStatus = WspSender::normalizeApptStatusForTemplate(
            (string)($patient['tracker_status'] ?? ''),
            (string)($patient['pc_apptstatus'] ?? '')
        );
        $pcCatid = (int)($patient['pc_catid'] ?? 0);

        // Resolve cancellation template (single row per scenario)
        $template = '';
        if (!empty($pcCatid)) {
            $template = WspSender::resolveTemplate($facilityId, $pcCatid, $pcStatus, 'patient');
        }
        $patient['_message'] = WspSender::buildMessage($template, $patient);
        // Resolve email subject from the same template row
        $config['email_subject'] = WspSender::resolveTemplate($facilityId, $pcCatid, $pcStatus, 'patient', 'email_subject');

        $seq = 0; // cancellation uses seq=0 (not tied to schedule slots)

        // Send WhatsApp
        if (!empty($config['enabled_wsp'])) {
            $phone  = $patient['phone_cell'] ?? '';
            $vendor = strtolower($config['current_vendor'] ?? $config['vendor'] ?? 'wasenderapi');

            if (!empty($phone) && ($patient['hipaa_allowsms'] ?? '') === 'YES') {
                if (!$this->blacklist->isBlacklisted($phone, $facilityId, $vendor)) {
                    try {
                        $result = $this->wspSender->send($config, $patient);
                        $msgId  = $result['msgId'] ?? null;
                        $rawStatus = $result['status'] ?? 'error';
                        $this->blacklist->processResult($phone, $facilityId, $vendor, $result);
                        $canonicalStatus = StatusNormalizer::normalize($vendor, $rawStatus);
                        $statusPriority  = StatusNormalizer::getPriority($canonicalStatus);
                        $this->insertLog('WSP', $patient, $config, $msgId, $rawStatus, $seq, $canonicalStatus, $statusPriority, $vendor, $result);
                        echo "  Cancellation WSP sent to {$phone}: {$rawStatus}\n";
                    } catch (\Throwable $e) {
                        $this->insertLog('WSP', $patient, $config, null, 'error', $seq, 'ERROR', 0, $vendor, ['error' => $e->getMessage()]);
                        echo "  Cancellation WSP error: " . $e->getMessage() . "\n";
                    }
                } else {
                    echo "  Cancellation WSP skipped for {$phone}: blacklisted\n";
                }
            }
        }

        // Send Email
        if (!empty($config['enabled_email'])) {
            $email = $patient['email'] ?? '';
            if (!empty($email) && ($patient['hipaa_allowemail'] ?? '') === 'YES') {
                try {
                    $this->emailSender->send($config, $patient, false);
                    $this->insertLog('EMAIL', $patient, $config, null, 'sent', $seq, 'SENT', 10, null, null);
                    echo "  Cancellation Email sent to {$email}\n";
                } catch (\Throwable $e) {
                    $this->insertLog('EMAIL', $patient, $config, null, 'error', $seq, 'ERROR', 0, null, ['error' => $e->getMessage()]);
                    echo "  Cancellation Email error: " . $e->getMessage() . "\n";
                }
            }
        }
    }

    /**
     * Manually syncs the status of a specific log entry from the vendor API.
     */
    public function syncLogStatus(int $logId): array
    {
        $sql = "SELECT nl.*, pe.pc_facility
                FROM notification_log nl
                LEFT JOIN openemr_postcalendar_events pe ON pe.pc_eid = nl.pc_eid
                WHERE nl.iLogId = ?";
        $data = sqlQuery($sql, [$logId]);

        if (!$data || empty($data['msg_id'])) {
            return ['success' => false, 'message' => 'Notification not found or has no message ID.'];
        }

        // Merge gateway credentials via FacilityConfig
        $facilityId = (int)($data['pc_facility'] ?? 0);
        if ($facilityId === 0 && !empty($data['pc_eid'])) {
            $evt = sqlQuery("SELECT pc_facility FROM openemr_postcalendar_events WHERE pc_eid = ?", [(int)$data['pc_eid']]);
            $facilityId = (int)($evt['pc_facility'] ?? 0);
        }

        $fc = new FacilityConfig();
        if ($facilityId > 0) {
            $facilityConfig = $fc->getByFacilityId($facilityId);
        } else {
            $all = $fc->getAllFacilitiesWithConfig();
            $facilityConfig = $all[0] ?? [];
        }
        if (!empty($facilityConfig)) {
            $data = array_merge($facilityConfig, $data);
        }

        $sync = $this->wspSender->syncStatus($data, $data['msg_id']);
        if (!empty($sync['status']) && $sync['status'] !== 'error') {
            $vendorName = strtolower($data['sms_gateway_type'] ?? $data['current_vendor'] ?? 'openwa');
            $this->log->updateStatus($data['msg_id'], (string)$sync['status'], $vendorName, $sync['raw'] ?? null);
            $canonical = StatusNormalizer::normalize($vendorName, (string)$sync['status']);

            // Update calendar & tracker status if DELIVERED or READ
            if (!empty($data['pc_eid']) && !empty($data['pid'])) {
                $newApptStatus = null;
                if ($canonical === 'READ') {
                    $newApptStatus = 'wsp-read';
                } elseif ($canonical === 'DELIVERED') {
                    $newApptStatus = 'wsp-deliv';
                }

                if ($newApptStatus !== null) {
                    sqlStatement(
                        "UPDATE openemr_postcalendar_events SET pc_apptstatus = ? WHERE pc_eid = ?",
                        [$newApptStatus, (int)$data['pc_eid']]
                    );
                    sqlStatement(
                        "UPDATE patient_tracker_element pte
                         INNER JOIN patient_tracker pt ON pt.id = pte.pt_tracker_id
                         SET pte.status = ?
                         WHERE pt.eid = ?",
                        [$newApptStatus, (int)$data['pc_eid']]
                    );
                }
            }

            return ['success' => true, 'status' => $canonical, 'raw_status' => $sync['status']];
        }

        $err = $sync['error'] ?? 'Status not updated from vendor.';
        return ['success' => false, 'message' => $err];
    }
}
