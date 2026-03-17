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

class NotificationService
{
    private WspSender       $wspSender;
    private EmailSender     $emailSender;
    private NotificationLog $log;
    private FacilityConfig  $facilityConfig;

    /** Base public URL for temporary .ics files served to WhatsApp vendors. */
    private string $moduleBaseUrl;

    public function __construct()
    {
        $this->wspSender      = new WspSender();
        $this->emailSender    = new EmailSender();
        $this->log            = new NotificationLog();
        $this->facilityConfig = new FacilityConfig();
        $this->moduleBaseUrl  = rtrim($GLOBALS['site_addr_oath'] ?? '', '/') .
                                '/interface/modules/custom_modules/oe-module-wsp-email/public/ics/';
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
        $this->runByType('Email', $dryRun);
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
                if (!$this->alreadySent('Email', (int)$patient['pid'], (int)$patient['pc_eid'], $seq)) {
                    $this->deliverEmail($patient, $config, $seq, false);
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
            if ($type === 'Email' && empty($config['enabled_email'])) continue;

            // Fetch the cron-triggered schedule slots for this facility
            $slots = $this->facilityConfig->getScheduledSlots($facilityId);

            if (empty($slots)) {
                // Fallback: if no schedule configured, use a single default 48-hour slot
                $slots = [['seq' => 1, 'hours_before' => 48, 'send_on_booking' => 0, 'enabled_wsp' => 1, 'enabled_email' => 1]];
            }

            foreach ($slots as $slot) {
                $seq         = (int)$slot['seq'];
                $hoursBefore = (int)($slot['hours_before'] ?? 48);

                // Only process slots whose channel matches the current run type
                if ($type === 'WSP'   && empty($slot['enabled_wsp']))   continue;
                if ($type === 'Email' && empty($slot['enabled_email'])) continue;

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
                        $this->deliverWsp($patient, $config, $seq, true);
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
        $template        = $config['wsp_message'] ?? '';
        $patient['_message'] = WspSender::buildMessage($template, $patient);

        // Build and publish the temporary .ics file
        $icsPath            = WspSender::buildIcsFile($patient, $config);
        $icsBase            = basename($icsPath);
        $patient['_ics_url'] = $this->moduleBaseUrl . $icsBase;

        $publicIcsDir = __DIR__ . '/../public/ics/';
        if (!is_dir($publicIcsDir)) {
            mkdir($publicIcsDir, 0755, true);
        }
        copy($icsPath, $publicIcsDir . $icsBase);
        @unlink($icsPath);

        $result = $this->wspSender->send($config, $patient);
        $msgId  = $result['msgId'] ?? null;
        $status = $result['status'] === 'success' ? 'in_progress' : 'error';

        $this->insertLog('WSP', $patient, $config, $msgId, $status, $seq);

        if ($updateCalFlag) {
            $this->markEventSent('WSP', (int)$patient['pid'], (int)$patient['pc_eid']);
        }
        $this->updateTracker($patient, 'WSP');

        // Allow time for vendor to download the .ics before deleting it
        sleep(5);
        @unlink($publicIcsDir . $icsBase);
    }

    private function deliverEmail(array &$patient, array $config, int $seq, bool $updateCalFlag): void
    {
        $template        = $config['email_message'] ?? '';
        $patient['_message'] = WspSender::buildMessage($template, $patient);

        $ok     = $this->emailSender->send($config, $patient);
        $status = $ok ? 'sent' : 'error';

        $this->insertLog('Email', $patient, $config, null, $status, $seq);

        if ($updateCalFlag) {
            $this->markEventSent('Email', (int)$patient['pid'], (int)$patient['pc_eid']);
        }
        $this->updateTracker($patient, 'Email');
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
        if ($type === 'WSP') {
            $hipaaFilter = "AND pd.hipaa_allowsms = 'YES' AND pd.phone_cell <> ''";
        } else {
            $hipaaFilter = "AND pd.hipaa_allowemail = 'YES' AND pd.email <> ''";
        }

        // Time window: appointment is within [now, now + hoursBefore]
        $windowStart = date('Y-m-d H:i:s');
        $windowEnd   = date('Y-m-d H:i:s', strtotime("+{$hoursBefore} hours"));

        $sql = "SELECT pd.pid, pd.title, pd.fname, pd.lname, pd.mname, pd.phone_cell,
                       pd.email, pd.hipaa_allowsms, pd.hipaa_allowemail,
                       ope.pc_eid, ope.pc_pid, ope.pc_title, ope.pc_hometext,
                       ope.pc_eventDate, ope.pc_endDate, ope.pc_duration,
                       ope.pc_startTime, ope.pc_endTime, ope.pc_facility,
                       CONCAT(u.fname,' ',IFNULL(u.mname,''),' ',u.lname) AS user_name,
                       u.suffix AS user_preffix
                FROM openemr_postcalendar_events ope
                INNER JOIN patient_data pd ON pd.pid = ope.pc_pid
                LEFT  JOIN users        u  ON u.id   = ope.pc_aid
                WHERE ope.pc_facility = ?
                  AND CONCAT(ope.pc_eventDate, ' ', ope.pc_startTime) > ?
                  AND CONCAT(ope.pc_eventDate, ' ', ope.pc_startTime) <= ?
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
    }

    /** Records the notification attempt in notification_log including the seq number. */
    private function insertLog(string $type, array $patient, array $config, ?string $msgId, string $status, int $seq): void
    {
        $patientInfo = trim(($patient['title'] ?? '') . ' ' . $patient['fname'] . ' ' . $patient['lname'])
                     . '|||' . $patient['phone_cell']
                     . '|||' . $patient['email'];
        $gatewayType  = $config['vendor'] ?? '';
        $gatewayInfo  = ($type === 'WSP') ? $gatewayType : (($config['facility_email'] ?? '') . '|||' . ($config['email_subject'] ?? ''));

        // Use extended insert that includes notification_seq
        $sql = "INSERT INTO notification_log
                    (pid, pc_eid, sms_gateway_type, message, email_sender, email_subject,
                     type, patient_info, smsgateway_info, msg_id, status, notification_seq,
                     pc_eventDate, pc_endDate, pc_startTime, pc_endTime, dSentDateTime)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())";

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
            $status,
            $seq,
            $patient['pc_eventDate'],
            $patient['pc_endDate'] ?? $patient['pc_eventDate'],
            $patient['pc_startTime'],
            $patient['pc_endTime'],
        ]);
    }

    /** Updates the calendar event flag so legacy compatibility is maintained. */
    private function markEventSent(string $type, int $pid, int $pcEid): void
    {
        if ($type === 'WSP') {
            sqlStatement(
                "UPDATE openemr_postcalendar_events SET pc_sendalertwsp = 'YES' WHERE pc_pid = ? AND pc_eid = ?",
                [$pid, $pcEid]
            );
        } else {
            sqlStatement(
                "UPDATE openemr_postcalendar_events SET pc_sendalertemail = 'YES' WHERE pc_pid = ? AND pc_eid = ?",
                [$pid, $pcEid]
            );
        }
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
                $type,
                '',
                ''
            );
        }
    }
}
