<?php
/**
 * RecallService — Orchestrator for recall-based WhatsApp and Email notifications.
 *
 * Processes medex_recalls against wsp_email_recall_schedule to determine which
 * patients need to be notified today (based on days_before and r_eventDate).
 *
 * For each recall + sequence:
 *   - scheduled_for = r_eventDate - days_before
 *   - If scheduled_for == TODAY and no entry exists in wsp_email_recall → send
 *
 * @package   OpenEMR\Modules\WspEmail
 * @author    Luis A. Uriarte <luis.uriarte@gmail.com>
 * @copyright Copyright (c) 2024-2025 Luis A. Uriarte
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

namespace OpenEMR\Modules\WspEmail;

use OpenEMR\Modules\WspEmail\StatusNormalizer;

class RecallService
{
    private WspSender       $wspSender;
    private EmailSender     $emailSender;
    private NotificationLog $log;
    private FacilityConfig  $facilityConfig;
    private RateLimiter     $rateLimiter;
    private Blacklist       $blacklist;

    public function __construct()
    {
        $this->wspSender      = new WspSender();
        $this->emailSender    = new EmailSender();
        $this->log            = new NotificationLog();
        $this->facilityConfig = new FacilityConfig();
        $this->rateLimiter    = new RateLimiter();
        $this->blacklist      = new Blacklist();
    }

    // =========================================================================
    // Public entry points
    // =========================================================================

    /**
     * Process all pending recall WhatsApp notifications (called by cron).
     */
    public function runWsp(bool $dryRun = false): void
    {
        $this->runByType('WSP', $dryRun);
    }

    /**
     * Process all pending recall Email notifications (called by cron).
     */
    public function runEmail(bool $dryRun = false): void
    {
        $this->runByType('EMAIL', $dryRun);
    }

    /**
     * Process both WSP and Email recall notifications.
     */
    public function runAll(bool $dryRun = false): void
    {
        $this->runByType('WSP',   $dryRun);
        $this->runByType('EMAIL', $dryRun);
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

            // Check allowed sending window (same logic as NotificationService)
            $currentHour = (int)date('G');
            $currentDow  = (int)date('w');

            if ($currentDow === 6) {
                $allowed = !empty($config['send_saturday_enabled']);
                $start   = (int)($config['send_saturday_start'] ?? 8);
                $end     = (int)($config['send_saturday_end']   ?? 13);
                $dayName = 'Saturday';
            } elseif ($currentDow === 0) {
                $allowed = !empty($config['send_sunday_enabled']);
                $start   = (int)($config['send_sunday_start'] ?? 9);
                $end     = (int)($config['send_sunday_end']   ?? 12);
                $dayName = 'Sunday';
            } else {
                $allowed = true;
                $start   = (int)($config['send_weekday_start'] ?? 7);
                $end     = (int)($config['send_weekday_end']   ?? 21);
                $dayName = 'Weekday';
            }

            if (!$allowed) {
                echo "  Recalls Facility #{$facilityId} | {$dayName} sending disabled. Skipping.\n";
                continue;
            }

            if ($currentHour < $start || $currentHour >= $end) {
                echo "  Recalls Facility #{$facilityId} | Outside allowed hours "
                   . "({$currentHour}:00, allowed {$start}:00-{$end}:00 on {$dayName}). Skipping.\n";
                continue;
            }

            // Get recall schedule sequences for this facility
            $sequences = $this->facilityConfig->getRecallSchedule($facilityId);

            if (empty($sequences)) {
                echo "  Recalls Facility #{$facilityId} | No recall schedule configured. Skipping.\n";
                continue;
            }

            foreach ($sequences as $seq) {
                $seqNum    = (int)$seq['seq'];
                $daysBefore = (int)($seq['days_before'] ?? 7);

                if (empty($seq['enabled'])) {
                    continue;
                }
                if ($type === 'WSP'   && empty($seq['enabled_wsp']))   continue;
                if ($type === 'EMAIL' && empty($seq['enabled_email'])) continue;

                $recalls = $this->getPendingRecalls($facilityId, $type, $seqNum, $daysBefore);
                echo "  Recalls Facility #{$facilityId} | seq={$seqNum} ({$daysBefore} days before) "
                   . "| {$type} | " . count($recalls) . " recall(s)\n";

                foreach ($recalls as $recall) {
                    $this->mergeConfig($recall, $config);

                    echo "    pid={$recall['pid']} | "
                       . trim(($recall['fname'] ?? '') . ' ' . ($recall['lname'] ?? ''))
                       . " | eventDate={$recall['r_eventDate']}\n";

                    if ($dryRun) {
                        echo "    [DRY-RUN] Skipping actual send.\n";
                        continue;
                    }

                    if ($type === 'WSP') {
                        $phone  = $recall['phone_cell'] ?? '';
                        $vendor = strtolower($config['current_vendor'] ?? $config['vendor'] ?? 'wasenderapi');

                        if ($this->blacklist->isBlacklisted($phone, $facilityId, $vendor)) {
                            echo "    [BLACKLIST] Skipping phone={$phone}\n";
                            $this->insertRecallLog($recall, $config, $seqNum, 'WSP', 'SKIPPED', null, null, 'SKIPPED', 0, 'blacklist');
                            continue;
                        }

                        $this->deliverRecallWsp($recall, $config, $seqNum);
                    } else {
                        $this->deliverRecallEmail($recall, $config, $seqNum);
                    }
                }
            }
        }
    }

    // =========================================================================
    // Delivery methods
    // =========================================================================

    private function deliverRecallWsp(array &$recall, array $config, int $seq): void
    {
        $facilityId = (int)($config['facility_id'] ?? $recall['r_facility'] ?? 0);
        $template   = $this->resolveRecallTemplate($facilityId);
        $recall['_message'] = $this->buildRecallMessage($template, $recall, $config);

        $phone  = $recall['phone_cell'] ?? '';
        $vendor = strtolower($config['current_vendor'] ?? $config['vendor'] ?? 'wasenderapi');

        try {
            $this->rateLimiter->throttle($facilityId, $vendor, $phone);

            // Build a pseudo-patient array compatible with WspSender::send()
            $pseudoPatient = $this->buildPseudoPatient($recall, $config);

            $result    = $this->wspSender->send($config, $pseudoPatient);
            $msgId     = $result['msgId'] ?? null;
            $rawStatus = $result['status'] ?? 'error';

            $this->blacklist->processResult($phone, $facilityId, $vendor, $result);

            if ($rawStatus === 'UNAUTHORIZED') {
                $err = "CRITICAL: API key unauthorized (401) for facility #{$facilityId} vendor={$vendor}. Halting.";
                echo "    [CRITICAL] {$err}\n";
                error_log($err);
                throw new \Exception($err);
            }
            if ($rawStatus === 'NOT_FOUND') {
                $err = "CRITICAL: Session ID not found (404) for facility #{$facilityId} vendor={$vendor}. Halting.";
                echo "    [CRITICAL] {$err}\n";
                error_log($err);
                throw new \Exception($err);
            }

            $canonicalStatus = StatusNormalizer::normalize($vendor, $rawStatus);
            $statusPriority  = StatusNormalizer::getPriority($canonicalStatus);

            $this->insertRecallLog(
                $recall, $config, $seq, 'WSP', 'SENT',
                $msgId, $rawStatus, $canonicalStatus, $statusPriority
            );

            echo "    WSP recall sent to {$phone}: {$rawStatus}\n";
        } catch (\Throwable $e) {
            $errorMsg = $e->getMessage();
            $this->insertRecallLog(
                $recall, $config, $seq, 'WSP', 'FAILED',
                null, 'error', 'ERROR', 0, null, $errorMsg
            );
            echo "    ERROR WSP recall: {$errorMsg}\n";
            if (strpos($errorMsg, 'CRITICAL:') === 0) {
                throw $e;
            }
        }
    }

    private function deliverRecallEmail(array &$recall, array $config, int $seq): void
    {
        $facilityId = (int)($config['facility_id'] ?? $recall['r_facility'] ?? 0);
        $template   = $this->resolveRecallTemplate($facilityId);
        $recall['_message'] = $this->buildRecallMessage($template, $recall, $config);

        // Resolve email subject from the same template record
        $tplRow = $this->getRecallTemplateRow($facilityId);
        $config['email_subject'] = $tplRow['email_subject'] ?? '';

        $pseudoPatient = $this->buildPseudoPatient($recall, $config);

        try {
            $ok        = $this->emailSender->send($config, $pseudoPatient);
            $rawStatus = $ok ? 'sent' : 'error';

            $logLine = date('Y-m-d H:i:s') . " — Recall Email to={$recall['email']} pid={$recall['pid']} status=$rawStatus\n";
            $logFile = __DIR__ . '/../logs/recall_notify.log';
            if (!is_dir(dirname($logFile))) {
                @mkdir(dirname($logFile), 0755, true);
            }
            @file_put_contents($logFile, $logLine, FILE_APPEND | LOCK_EX);

            $canonicalStatus = StatusNormalizer::normalize('default', $rawStatus);
            $statusPriority  = StatusNormalizer::getPriority($canonicalStatus);

            $this->insertRecallLog(
                $recall, $config, $seq, 'Email', 'SENT',
                null, $rawStatus, $canonicalStatus, $statusPriority
            );

            echo "    Email recall sent to {$recall['email']}: {$rawStatus}\n";
        } catch (\Throwable $e) {
            $this->insertRecallLog(
                $recall, $config, $seq, 'Email', 'FAILED',
                null, 'error', 'ERROR', 0, null, $e->getMessage()
            );
            echo "    ERROR Email recall: " . $e->getMessage() . "\n";
        }
    }

    // =========================================================================
    // Database helpers
    // =========================================================================

    /**
     * Returns recalls pending notification for a given facility, type, sequence, and days_before.
     *
     * A recall is "pending" when:
     *   - DATE_SUB(r_eventDate, INTERVAL daysBefore DAY) = CURDATE()
     *   - No wsp_email_recall entry for this recall_id + seq with status SENT or SKIPPED
     *   - Patient has the appropriate HIPAA consent
     */
    public function getPendingRecalls(int $facilityId, string $type, int $seq, int $daysBefore): array
    {
        if ($type === 'WSP') {
            $hipaaFilter = "AND pd.hipaa_allowsms = 'YES' AND pd.phone_cell <> ''";
        } else {
            $hipaaFilter = "AND pd.hipaa_allowemail = 'YES' AND pd.email <> ''";
        }

        $sql = "SELECT mr.r_ID AS recall_id, mr.r_pid AS pid,
                       mr.r_eventDate, mr.r_facility, mr.r_provider,
                       mr.r_reason,
                       pd.fname, pd.lname, pd.mname, pd.title,
                       pd.phone_cell, pd.email,
                       pd.hipaa_allowsms, pd.hipaa_allowemail,
                       CONCAT(u.fname,' ',IFNULL(u.mname,''),' ',u.lname) AS provider_name,
                       u.suffix AS provider_suffix
                FROM medex_recalls mr
                INNER JOIN patient_data pd ON pd.pid = mr.r_pid
                LEFT JOIN users u ON u.id = mr.r_provider
                WHERE mr.r_facility = ?
                  AND DATE_SUB(mr.r_eventDate, INTERVAL ? DAY) = CURDATE()
                  {$hipaaFilter}
                  AND NOT EXISTS (
                      SELECT 1 FROM wsp_email_recall wr
                      WHERE wr.recall_id = mr.r_ID
                        AND wr.seq = ?
                        AND wr.status IN ('SENT','SKIPPED')
                  )
                ORDER BY mr.r_eventDate ASC";

        $res     = sqlStatement($sql, [$facilityId, $daysBefore, $seq]);
        $recalls = [];
        while ($row = sqlFetchArray($res)) {
            $recalls[] = $row;
        }
        return $recalls;
    }

    /**
     * Resolves the WSP message template for recalls for a given facility.
     */
    private function resolveRecallTemplate(int $facilityId): string
    {
        $row = $this->getRecallTemplateRow($facilityId);
        return $row['wsp_message'] ?? '';
    }

    /**
     * Fetches the full recall template row for a facility.
     */
    private function getRecallTemplateRow(int $facilityId): array
    {
        $row = sqlQuery(
            "SELECT * FROM wsp_email_notification_templates
             WHERE facility_id = ?
               AND notification_type = 'recall'
               AND pc_catid = 0
               AND pc_apptstatus = 'recall'
               AND recipient_type = 'patient'
               AND enabled = 1
             LIMIT 1",
            [$facilityId]
        );
        return $row ?: [];
    }

    /**
     * Substitutes recall-specific tokens in a message template.
     *
     * Available tokens:
     *   ***PATIENT_NAME***      — Full patient name
     *   ***PATIENT_FIRSTNAME*** — First name
     *   ***PATIENT_LASTNAME***  — Last name
     *   ***RECALL_DATE***       — r_eventDate (formatted)
     *   ***RECALL_REASON***     — r_reason
     *   ***PROVIDER_NAME***     — Provider full name
     *   ***FACILITY_NAME***     — Facility name
     *   ***FACILITY_PHONE***    — Facility phone
     *   ***FACILITY_EMAIL***    — Facility email
     *   ***FACILITY_ADDRESS***  — Facility address
     */
    public function buildRecallMessage(string $template, array $recall, array $config = []): string
    {
        if (empty($template)) {
            return '';
        }

        $fullName  = trim(($recall['title'] ?? '') . ' ' . ($recall['fname'] ?? '') . ' ' . ($recall['lname'] ?? ''));
        $eventDate = !empty($recall['r_eventDate'])
            ? date('d/m/Y', strtotime($recall['r_eventDate']))
            : '';

        $replacements = [
            '***PATIENT_NAME***'      => $fullName,
            '***PATIENT_FIRSTNAME***' => $recall['fname']         ?? '',
            '***PATIENT_LASTNAME***'  => $recall['lname']         ?? '',
            '***RECALL_DATE***'       => $eventDate,
            '***RECALL_REASON***'     => $recall['r_reason']      ?? '',
            '***PROVIDER_NAME***'     => $recall['provider_name'] ?? '',
            '***FACILITY_NAME***'     => $recall['facility_name'] ?? $config['facility_name'] ?? '',
            '***FACILITY_PHONE***'    => $recall['facility_phone'] ?? $config['facility_phone'] ?? '',
            '***FACILITY_EMAIL***'    => $recall['facility_email'] ?? $config['facility_email'] ?? '',
            '***FACILITY_ADDRESS***'  => $recall['facility_address'] ?? $config['facility_address'] ?? '',
        ];

        return str_replace(array_keys($replacements), array_values($replacements), $template);
    }

    /**
     * Builds a pseudo-patient array compatible with WspSender::send() and EmailSender::send().
     * Maps recall fields to the expected appointment-centric field names.
     */
    private function buildPseudoPatient(array $recall, array $config): array
    {
        return [
            'pid'              => $recall['pid'],
            'title'            => $recall['title']          ?? '',
            'fname'            => $recall['fname']          ?? '',
            'lname'            => $recall['lname']          ?? '',
            'mname'            => $recall['mname']          ?? '',
            'phone_cell'       => $recall['phone_cell']     ?? '',
            'email'            => $recall['email']          ?? '',
            'hipaa_allowsms'   => $recall['hipaa_allowsms'] ?? 'NO',
            'hipaa_allowemail' => $recall['hipaa_allowemail'] ?? 'NO',
            // Map recall date to appointment-like fields expected by senders
            'pc_eid'           => 0,  // no appointment
            'pc_pid'           => $recall['pid'],
            'pc_eventDate'     => $recall['r_eventDate']    ?? '',
            'pc_endDate'       => $recall['r_eventDate']    ?? '',
            'pc_startTime'     => '00:00:00',
            'pc_endTime'       => '00:00:00',
            'pc_facility'      => $recall['r_facility']     ?? 0,
            'pc_catid'         => 0,
            'pc_apptstatus'    => 'recall',
            'pc_hometext'      => $recall['r_reason']       ?? '',
            'user_name'        => $recall['provider_name']  ?? '',
            'user_preffix'     => $recall['provider_suffix'] ?? '',
            // Facility info
            'facility_name'    => $config['facility_name']    ?? '',
            'facility_address' => $config['facility_address'] ?? '',
            'facility_phone'   => $config['facility_phone']   ?? '',
            'facility_email'   => $config['facility_email']   ?? '',
            'latitude'         => $config['latitude']         ?? '',
            'longitude'        => $config['longitude']        ?? '',
            'website_url'      => $config['website_url']      ?? '',
            // Pre-built message
            '_message'         => $recall['_message']        ?? '',
            // Recall-specific extras passed through
            'r_eventDate'      => $recall['r_eventDate']     ?? '',
            'r_reason'         => $recall['r_reason']        ?? '',
        ];
    }

    /**
     * Merges facility config fields into the recall array.
     */
    private function mergeConfig(array &$recall, array $config): void
    {
        $recall['facility_name']    = $config['facility_name']    ?? '';
        $recall['facility_address'] = $config['facility_address'] ?? '';
        $recall['facility_phone']   = $config['facility_phone']   ?? '';
        $recall['facility_email']   = $config['facility_email']   ?? '';
        $recall['latitude']         = $config['latitude']         ?? '';
        $recall['longitude']        = $config['longitude']        ?? '';
        $recall['website_url']      = $config['website_url']      ?? '';
    }

    /**
     * Inserts or updates the recall notification entry in wsp_email_recall
     * and optionally links it to a notification_log entry.
     */
    private function insertRecallLog(
        array  $recall,
        array  $config,
        int    $seq,
        string $channel,
        string $status,
        ?string $msgId,
        ?string $rawStatus,
        string $canonicalStatus,
        int    $statusPriority,
        ?string $skipReason = null,
        ?string $errorMsg   = null
    ): void {
        $facilityId   = (int)($config['facility_id'] ?? $recall['r_facility'] ?? 0);
        $recallId     = (int)($recall['recall_id']   ?? 0);
        $pid          = (int)($recall['pid']          ?? 0);
        $scheduledFor = $recall['r_eventDate']        ?? date('Y-m-d');
        $vendor       = strtolower($config['current_vendor'] ?? $config['vendor'] ?? '');

        // First: write to notification_log to get a log_id
        $patientInfo = trim(($recall['title'] ?? '') . ' ' . ($recall['fname'] ?? '') . ' ' . ($recall['lname'] ?? ''))
                     . '|||' . ($recall['phone_cell'] ?? '')
                     . '|||' . ($recall['email'] ?? '');
        $gatewayType = ($channel === 'WSP') ? $vendor : 'email';
        $message     = $recall['_message'] ?? '';

        $logSql = "INSERT INTO notification_log
                       (pid, pc_eid, sms_gateway_type, message, email_sender, email_subject,
                        type, patient_info, smsgateway_info, msg_id, status, status_current,
                        status_priority, provider_raw_status, provider_payload, notification_seq,
                        pc_eventDate, pc_endDate, pc_startTime, pc_endTime, dSentDateTime)
                   VALUES (?, 0, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, '00:00:00', '00:00:00', NOW())";

        sqlStatement($logSql, [
            $pid,
            $gatewayType,
            $message,
            $config['facility_email'] ?? '',
            $config['email_subject']  ?? '',
            strtoupper($channel === 'Email' ? 'EMAIL' : 'WSP'),
            $patientInfo,
            $gatewayType,
            $msgId,
            $rawStatus ?? $status,
            $canonicalStatus,
            $statusPriority,
            $rawStatus ?? $status,
            $errorMsg ? json_encode(['error' => $errorMsg]) : null,
            $seq,
            $scheduledFor,
            $scheduledFor,
        ]);

        $logId = (int)sqlGetLastInsertId();

        // Then: write / update wsp_email_recall
        $channelEnum = ($channel === 'WSP') ? 'WSP' : 'Email';
        $sentAt      = ($status === 'SENT') ? date('Y-m-d H:i:s') : null;

        $existing = sqlQuery(
            "SELECT id FROM wsp_email_recall WHERE recall_id = ? AND seq = ?",
            [$recallId, $seq]
        );

        if ($existing) {
            sqlStatement(
                "UPDATE wsp_email_recall SET
                    status = ?, skip_reason = ?, sent_at = ?, log_id = ?
                 WHERE recall_id = ? AND seq = ?",
                [$status, $skipReason, $sentAt, $logId > 0 ? $logId : null, $recallId, $seq]
            );
        } else {
            sqlStatement(
                "INSERT INTO wsp_email_recall
                     (recall_id, facility_id, pid, seq, channel, log_id,
                      status, skip_reason, scheduled_for, sent_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
                [
                    $recallId, $facilityId, $pid, $seq, $channelEnum,
                    $logId > 0 ? $logId : null,
                    $status, $skipReason, $scheduledFor, $sentAt
                ]
            );
        }

        // Add status history entry
        if ($logId > 0) {
            $this->log->addStatusHistory($logId, $canonicalStatus, $rawStatus ?? $status, $vendor, null);
        }
    }
}
