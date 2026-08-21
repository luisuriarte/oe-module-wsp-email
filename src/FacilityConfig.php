<?php
/**
 * FacilityConfig — CRUD for wsp_email_facility_config and wsp_email_notification_schedule.
 *
 * @package   OpenEMR\Modules\WspEmail
 * @author    Luis A. Uriarte <luis.uriarte@gmail.com>
 * @copyright Copyright (c) 2024-2025 Luis A. Uriarte
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

namespace OpenEMR\Modules\WspEmail;

class FacilityConfig
{
    /**
     * Returns the extended configuration for a single facility.
     */
    public function getByFacilityId(int $facilityId): array
    {
        $this->ensureSmsColumns();
        $sql = "SELECT wfc.*, f.name AS facility_name, f.street, f.city, f.state,
                       f.phone AS facility_phone, f.email AS facility_email,
                       f.website AS website_url, f.inactive,
                       CONCAT(f.street, ', ', f.city, ', ', f.state) AS facility_address
                FROM facility f
                LEFT JOIN wsp_email_facility_config wfc ON wfc.facility_id = f.id
                WHERE f.id = ?";
        $result = sqlQuery($sql, [$facilityId]);
        if (empty($result)) {
            return [];
        }
        return $this->mergeGatewayCredentials($result);
    }

    /**
     * Returns all OpenEMR facilities joined with their extended config (LEFT JOIN).
     * Gateway credentials are merged from wsp_email_gateways_config.
     */
    public function getAllFacilitiesWithConfig(): array
    {
        $sql = "SELECT f.id AS facility_id, f.name AS facility_name,
                       f.street, f.city, f.state, f.inactive,
                       f.phone AS facility_phone, f.email AS facility_email,
                       CONCAT(f.street, ', ', f.city, ', ', f.state) AS facility_address,
                       wfc.*, f.website AS website_url
                FROM facility f
                LEFT JOIN wsp_email_facility_config wfc ON wfc.facility_id = f.id
                ORDER BY f.name";
        $res  = sqlStatement($sql);
        $rows = [];
        while ($row = sqlFetchArray($res)) {
            $rows[] = $this->mergeGatewayCredentials($row);
        }
        return $rows;
    }

    /**
     * Saves facility configuration (non-credential fields only).
     * Gateway credentials are saved separately via saveGatewayConfig().
     */
    public function save(array $data): bool
    {
        $facilityId = (int)($data['facility_id'] ?? 0);
        if ($facilityId === 0) {
            return false;
        }

        $exists = sqlQuery(
            "SELECT id FROM wsp_email_facility_config WHERE facility_id = ?",
            [$facilityId]
        );

        $fields = [
            'facility_id'              => $facilityId,
            'current_vendor'           => $data['current_vendor']           ?? 'wasenderapi',
            'vendor'                   => $data['current_vendor']           ?? 'wasenderapi',
            // Sending Window Configuration
            'send_weekday_start'       => isset($data['send_weekday_start'])    ? (int)$data['send_weekday_start']    : 7,
            'send_weekday_end'         => isset($data['send_weekday_end'])      ? (int)$data['send_weekday_end']      : 21,
            'send_saturday_enabled'    => isset($data['send_saturday_enabled']) ? (int)$data['send_saturday_enabled'] : 1,
            'send_saturday_start'      => isset($data['send_saturday_start'])   ? (int)$data['send_saturday_start']   : 8,
            'send_saturday_end'        => isset($data['send_saturday_end'])     ? (int)$data['send_saturday_end']     : 13,
            'send_sunday_enabled'      => isset($data['send_sunday_enabled'])   ? (int)$data['send_sunday_enabled']   : 0,
            'send_sunday_start'        => isset($data['send_sunday_start'])     ? (int)$data['send_sunday_start']     : 9,
            'send_sunday_end'          => isset($data['send_sunday_end'])       ? (int)$data['send_sunday_end']       : 12,
            // Common configuration
            'logo_wsp'                 => $data['logo_wsp']                 ?? null,
            'logo_email'               => $data['logo_email']               ?? null,
            'latitude'                 => $data['latitude']                 ?? null,
            'longitude'                => $data['longitude']                ?? null,
            'wsp_message'              => $data['wsp_message']              ?? null,
            'email_message'            => $data['email_message']            ?? null,
            'email_subject'            => $data['email_subject']            ?? null,
            'enabled_wsp'              => isset($data['enabled_wsp'])       ? (int)$data['enabled_wsp']   : 1,
            'enabled_email'            => isset($data['enabled_email'])     ? (int)$data['enabled_email'] : 1,
            'enabled_sms'              => isset($data['enabled_sms'])       ? (int)$data['enabled_sms']   : 0,
            'notify_cancelled'         => isset($data['notify_cancelled'])  ? (int)$data['notify_cancelled'] : 0,
        ];

        $this->ensureSmsColumns();

        if ($exists) {
            $setClauses = implode(', ', array_map(fn($k) => "`$k` = ?", array_keys($fields)));
            sqlStatement(
                "UPDATE wsp_email_facility_config SET $setClauses WHERE facility_id = ?",
                array_merge(array_values($fields), [$facilityId])
            );
        } else {
            $columns      = implode(', ', array_map(fn($k) => "`$k`", array_keys($fields)));
            $placeholders = implode(', ', array_fill(0, count($fields), '?'));
            sqlStatement(
                "INSERT INTO wsp_email_facility_config ($columns) VALUES ($placeholders)",
                array_values($fields)
            );
        }

        // Save gateway credentials if present
        $knownGateways = ['ultramsg', 'wasenderapi', 'openwa', 'evolution-go', 'httpsms', 'waha'];
        foreach ($knownGateways as $gw) {
            $gwKey = "gateway_config_{$gw}";
            if (isset($data[$gwKey]) && is_array($data[$gwKey])) {
                $this->saveGatewayConfig($facilityId, $gw, $data[$gwKey]);
            }
        }

        return true;
    }

    // =========================================================================
    // Gateway Credentials (wsp_email_gateways_config)
    // =========================================================================

    /**
     * Returns credentials for a single gateway as a flat array.
     */
    public function getGatewayConfig(int $facilityId, string $gatewayName): ?array
    {
        $row = sqlQuery(
            "SELECT * FROM wsp_email_gateways_config
             WHERE facility_id = ? AND gateway_name = ?",
            [$facilityId, $gatewayName]
        );
        if (empty($row)) {
            return null;
        }
        $decoded = json_decode($row['config_json'] ?? '{}', true);
        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Returns all gateway config rows for a facility.
     */
    public function getAllGatewayConfigs(int $facilityId): array
    {
        $res = sqlStatement(
            "SELECT * FROM wsp_email_gateways_config
             WHERE facility_id = ?
             ORDER BY gateway_name",
            [$facilityId]
        );
        $rows = [];
        while ($row = sqlFetchArray($res)) {
            $rows[] = $row;
        }
        return $rows;
    }

    /**
     * Saves (inserts or updates) credentials for a gateway.
     *
     * @param int    $facilityId
     * @param string $gatewayName  e.g. 'ultramsg', 'wasenderapi', 'openwa', 'evolution-go'
     * @param array  $data         Flat key-value pairs (e.g. ['api_key' => '...', 'instance' => '...'])
     */
    public function saveGatewayConfig(int $facilityId, string $gatewayName, array $data): bool
    {
        $existing = sqlQuery(
            "SELECT id FROM wsp_email_gateways_config
             WHERE facility_id = ? AND gateway_name = ?",
            [$facilityId, $gatewayName]
        );

        $configJson = json_encode($data, JSON_UNESCAPED_SLASHES);

        if ($existing) {
            sqlStatement(
                "UPDATE wsp_email_gateways_config
                 SET config_json = ?
                 WHERE facility_id = ? AND gateway_name = ?",
                [$configJson, $facilityId, $gatewayName]
            );
        } else {
            sqlStatement(
                "INSERT INTO wsp_email_gateways_config
                     (facility_id, gateway_name, config_json)
                 VALUES (?, ?, ?)",
                [$facilityId, $gatewayName, $configJson]
            );
        }

        return true;
    }

    /**
     * Deletes a gateway config row.
     */
    public function deleteGatewayConfig(int $facilityId, string $gatewayName): bool
    {
        sqlStatement(
            "DELETE FROM wsp_email_gateways_config
             WHERE facility_id = ? AND gateway_name = ?",
            [$facilityId, $gatewayName]
        );
        return true;
    }

    /**
     * Merges gateway credentials from wsp_email_gateways_config into the
     * facility config array using prefixed keys for backward compatibility.
     *
     * e.g. ultramsg: {instance, api_key} → $config['ultramsg_instance'], $config['ultramsg_api_key']
     */
    private function mergeGatewayCredentials(array $config): array
    {
        $facilityId = (int)($config['facility_id'] ?? $config['id'] ?? 0);
        if ($facilityId === 0) {
            return $config;
        }

        // Apply sensible defaults if facility config row hasn't been saved yet
        $config['facility_id']    = $facilityId;
        $config['current_vendor'] = !empty($config['current_vendor']) ? $config['current_vendor'] : (!empty($config['vendor']) ? $config['vendor'] : 'wasenderapi');
        $config['vendor']         = $config['current_vendor'];
        $config['enabled_wsp']    = isset($config['enabled_wsp']) && $config['enabled_wsp'] !== null ? (int)$config['enabled_wsp'] : 1;
        $config['enabled_email']  = isset($config['enabled_email']) && $config['enabled_email'] !== null ? (int)$config['enabled_email'] : 1;

        $gateways = $this->getAllGatewayConfigs($facilityId);
        foreach ($gateways as $gw) {
            $gwName = $gw['gateway_name'];
            // Normalize to underscores for safe PHP key access (e.g. evolution-go -> evolution_go)
            $prefix  = str_replace('-', '_', $gwName) . '_';
            $gwData = json_decode($gw['config_json'] ?? '{}', true);
            if (!is_array($gwData)) {
                continue;
            }
            foreach ($gwData as $key => $value) {
                $prefixedKey = $prefix . $key;
                // Precedence: credentials from wsp_email_gateways_config take precedence if non-empty
                if (($value !== null && $value !== '') || !isset($config[$prefixedKey])) {
                    $config[$prefixedKey] = $value;
                }
            }
        }

        return $config;
    }

    // =========================================================================
    // Notification Schedule
    // =========================================================================

    /**
     * Returns all notification schedule rows for a facility, ordered by seq.
     *
     * Each row represents one send event:
     *   - send_on_booking=1 → send immediately when appointment is created
     *   - hours_before=N    → send N hours before the appointment
     */
    public function getSchedule(int $facilityId): array
    {
        $sql = "SELECT * FROM wsp_email_notification_schedule
                WHERE facility_id = ?
                ORDER BY seq ASC";
        $res  = sqlStatement($sql, [$facilityId]);
        $rows = [];
        while ($row = sqlFetchArray($res)) {
            $rows[] = $row;
        }
        return $rows;
    }

    /**
     * Replaces the full schedule for a facility.
     *
     * @param int   $facilityId
     * @param array $slots  Array of rows, each: ['seq', 'hours_before', 'send_on_booking', 'enabled_wsp', 'enabled_email']
     */
    public function saveSchedule(int $facilityId, array $slots): bool
    {
        if ($facilityId === 0) {
            return false;
        }

        $this->ensureSmsColumns();

        // Delete existing schedule for this facility
        sqlStatement(
            "DELETE FROM wsp_email_notification_schedule WHERE facility_id = ?",
            [$facilityId]
        );

        foreach ($slots as $i => $slot) {
            $seq           = (int)($slot['seq']             ?? ($i + 1));
            $hoursBefore   = (int)($slot['hours_before']   ?? 48);
            $sendOnBooking = (int)($slot['send_on_booking'] ?? 0);
            $enabledWsp    = (int)($slot['enabled_wsp']    ?? 1);
            $enabledEmail  = (int)($slot['enabled_email']  ?? 1);
            $enabledSms    = (int)($slot['enabled_sms']    ?? 0);

            sqlStatement(
                "INSERT INTO wsp_email_notification_schedule
                     (facility_id, seq, hours_before, send_on_booking, enabled_wsp, enabled_email, enabled_sms)
                 VALUES (?, ?, ?, ?, ?, ?, ?)",
                [$facilityId, $seq, $hoursBefore, $sendOnBooking, $enabledWsp, $enabledEmail, $enabledSms]
            );
        }

        return true;
    }

    /**
     * Returns schedule slots where send_on_booking = 1 for a given facility.
     * Used by the booking hook to fire immediate notifications.
     */
    public function getOnBookingSlots(int $facilityId): array
    {
        $res  = sqlStatement(
            "SELECT * FROM wsp_email_notification_schedule
             WHERE facility_id = ? AND send_on_booking = 1
             ORDER BY seq ASC",
            [$facilityId]
        );
        $rows = [];
        while ($row = sqlFetchArray($res)) {
            $rows[] = $row;
        }
        return $rows;
    }

    /**
     * Returns schedule slots where send_on_booking = 0,
     * filtered to those whose hours_before window includes the current time.
     *
     * Used by the cron job to decide which patients to notify now.
     */
    public function getScheduledSlots(int $facilityId): array
    {
        $res  = sqlStatement(
            "SELECT * FROM wsp_email_notification_schedule
             WHERE facility_id = ? AND send_on_booking = 0
             ORDER BY seq ASC",
            [$facilityId]
        );
        $rows = [];
        while ($row = sqlFetchArray($res)) {
            $rows[] = $row;
        }
        return $rows;
    }

    // =========================================================================
    // Auto-Migrations: Ensure SMS Columns
    // =========================================================================

    private static bool $smsColumnsEnsured = false;

    private function ensureSmsColumns(): void
    {
        if (self::$smsColumnsEnsured) {
            return;
        }
        self::$smsColumnsEnsured = true;

        // Check enabled_sms in wsp_email_facility_config
        $col1 = sqlQuery(
            "SELECT COLUMN_NAME
             FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME   = 'wsp_email_facility_config'
               AND COLUMN_NAME  = 'enabled_sms'"
        );
        if (empty($col1)) {
            try {
                sqlStatement(
                    "ALTER TABLE `wsp_email_facility_config`
                     ADD COLUMN `enabled_sms` tinyint(1) NOT NULL DEFAULT 0
                     COMMENT 'SMS Enable Flag'
                     AFTER `enabled_email`"
                );
            } catch (\Throwable $e) {
                // Ignore if already exists
            }
        }

        // Check enabled_sms in wsp_email_notification_schedule
        $col2 = sqlQuery(
            "SELECT COLUMN_NAME
             FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME   = 'wsp_email_notification_schedule'
               AND COLUMN_NAME  = 'enabled_sms'"
        );
        if (empty($col2)) {
            try {
                sqlStatement(
                    "ALTER TABLE `wsp_email_notification_schedule`
                     ADD COLUMN `enabled_sms` tinyint(1) NOT NULL DEFAULT 0
                     COMMENT 'SMS Enable Flag'
                     AFTER `enabled_email`"
                );
            } catch (\Throwable $e) {
                // Ignore if already exists
            }
        }
    }

    // =========================================================================
    // Recall Schedule
    // =========================================================================

    /**
     * Creates wsp_email_recall_schedule if it doesn't exist yet (auto-migration).
     * Safe to call multiple times — uses a static flag to run only once per request.
     */
    private static bool $recallScheduleTableEnsured = false;

    private function ensureRecallScheduleTable(): void
    {
        if (self::$recallScheduleTableEnsured) {
            return;
        }
        self::$recallScheduleTableEnsured = true;

        sqlStatement(
            "CREATE TABLE IF NOT EXISTS `wsp_email_recall_schedule` (
                `id`            int(11)      NOT NULL AUTO_INCREMENT,
                `facility_id`   int(11)      NOT NULL                   COMMENT 'FK -> facility.id',
                `seq`           tinyint(3)   NOT NULL                   COMMENT 'Orden de envio (1, 2, 3...)',
                `days_before`   int(5)       NOT NULL DEFAULT 7         COMMENT 'Dias antes de r_eventDate',
                `enabled_wsp`   tinyint(1)   NOT NULL DEFAULT 1         COMMENT 'WhatsApp habilitado',
                `enabled_email` tinyint(1)   NOT NULL DEFAULT 1         COMMENT 'Email habilitado',
                `enabled_sms`   tinyint(1)   NOT NULL DEFAULT 1         COMMENT 'SMS habilitado',
                `enabled`       tinyint(1)   NOT NULL DEFAULT 1         COMMENT 'Secuencia activa',
                `created_at`    datetime     DEFAULT CURRENT_TIMESTAMP,
                `updated_at`    datetime     DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uq_facility_seq` (`facility_id`, `seq`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
        );

        // Auto-migrate: check if enabled_sms column exists on wsp_email_recall_schedule
        $smsCol = sqlQuery(
            "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME   = 'wsp_email_recall_schedule'
               AND COLUMN_NAME  = 'enabled_sms'"
        );
        if (empty($smsCol)) {
            try {
                sqlStatement(
                    "ALTER TABLE `wsp_email_recall_schedule`
                     ADD COLUMN `enabled_sms` tinyint(1) NOT NULL DEFAULT 1 COMMENT 'SMS habilitado'
                     AFTER `enabled_email`"
                );
            } catch (\Throwable $e) {
                // Ignore if already added
            }
        }
    }

    /**
     * Ensures notification_type column exists in wsp_email_notification_templates.
     * Adds it and fixes the unique index if missing (auto-migration for existing installs).
     */
    private static bool $notifTypeColumnEnsured = false;

    private function ensureNotificationTypeColumn(): void
    {
        if (self::$notifTypeColumnEnsured) {
            return;
        }
        self::$notifTypeColumnEnsured = true;

        $col = sqlQuery(
            "SELECT COLUMN_NAME
             FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME   = 'wsp_email_notification_templates'
               AND COLUMN_NAME  = 'notification_type'"
        );

        if (!empty($col)) {
            return; // column already exists
        }

        // Add the column
        sqlStatement(
            "ALTER TABLE `wsp_email_notification_templates`
             ADD COLUMN `notification_type` varchar(20) NOT NULL DEFAULT 'appointment'
             COMMENT 'Tipo: appointment | recall'
             AFTER `facility_id`"
        );

        // Update existing rows to appointment
        sqlStatement(
            "UPDATE `wsp_email_notification_templates`
             SET `notification_type` = 'appointment'
             WHERE `notification_type` IS NULL OR `notification_type` = ''"
        );

        // Drop old unique key (may fail if name differs — ignore)
        try {
            sqlStatement(
                "ALTER TABLE `wsp_email_notification_templates` DROP INDEX `uq_template`"
            );
        } catch (\Throwable $e) {
            // Ignore — index might not exist or already have the right definition
        }

        // Add updated unique key including notification_type
        sqlStatement(
            "ALTER TABLE `wsp_email_notification_templates`
             ADD UNIQUE KEY `uq_template`
             (`facility_id`, `notification_type`, `pc_catid`, `pc_apptstatus`, `recipient_type`)"
        );
    }

    /**
     * Returns all recall notification schedule rows for a facility, ordered by seq.
     */
    public function getRecallSchedule(int $facilityId): array
    {
        $this->ensureRecallScheduleTable();

        $res  = sqlStatement(
            "SELECT * FROM wsp_email_recall_schedule
             WHERE facility_id = ?
             ORDER BY seq ASC",
            [$facilityId]
        );
        $rows = [];
        while ($row = sqlFetchArray($res)) {
            $rows[] = $row;
        }
        return $rows;
    }

    /**
     * Replaces the full recall schedule for a facility.
     *
     * @param int   $facilityId
     * @param array $slots  Each slot: ['seq', 'days_before', 'enabled_wsp', 'enabled_email', 'enabled']
     */
    public function saveRecallSchedule(int $facilityId, array $slots): bool
    {
        if ($facilityId === 0) {
            return false;
        }

        $this->ensureRecallScheduleTable();

        sqlStatement(
            "DELETE FROM wsp_email_recall_schedule WHERE facility_id = ?",
            [$facilityId]
        );

        foreach ($slots as $i => $slot) {
            $seq          = (int)($slot['seq']          ?? ($i + 1));
            $daysBefore   = (int)($slot['days_before']  ?? 7);
            $enabledWsp   = (int)($slot['enabled_wsp']  ?? 1);
            $enabledEmail = (int)($slot['enabled_email'] ?? 1);
            $enabledSms   = (int)($slot['enabled_sms']   ?? 1);
            $enabled      = (int)($slot['enabled']       ?? 1);

            sqlStatement(
                "INSERT INTO wsp_email_recall_schedule
                     (facility_id, seq, days_before, enabled_wsp, enabled_email, enabled_sms, enabled)
                 VALUES (?, ?, ?, ?, ?, ?, ?)",
                [$facilityId, $seq, $daysBefore, $enabledWsp, $enabledEmail, $enabledSms, $enabled]
            );
        }

        return true;
    }

    // =========================================================================
    // Recall Templates
    // =========================================================================

    /**
     * Returns the recall notification template for a facility.
     * Template signature: notification_type='recall', pc_catid=0, pc_apptstatus='recall', recipient_type='patient'
     */
    public function getRecallTemplate(int $facilityId): array
    {
        $this->ensureNotificationTypeColumn();

        $row = sqlQuery(
            "SELECT * FROM wsp_email_notification_templates
             WHERE facility_id = ?
               AND notification_type = 'recall'
               AND pc_catid = 0
               AND pc_apptstatus = 'recall'
               AND recipient_type = 'patient'
             LIMIT 1",
            [$facilityId]
        );
        return $row ?: [];
    }

    /**
     * Inserts or updates the recall template for a facility.
     *
     * @param int   $facilityId
     * @param array $data  Keys: wsp_message, email_subject, email_message, enabled
     */
    public function saveRecallTemplate(int $facilityId, array $data): bool
    {
        if ($facilityId === 0) {
            return false;
        }

        $this->ensureNotificationTypeColumn();

        $existing = $this->getRecallTemplate($facilityId);

        $wspMessage   = $data['wsp_message']   ?? '';
        $emailSubject = $data['email_subject'] ?? '';
        $emailMessage = $data['email_message'] ?? '';
        $enabled      = (int)($data['enabled'] ?? 1);

        if ($existing) {
            sqlStatement(
                "UPDATE wsp_email_notification_templates
                 SET wsp_message = ?, email_subject = ?, email_message = ?, enabled = ?,
                     updated_at = NOW()
                 WHERE facility_id = ?
                   AND notification_type = 'recall'
                   AND pc_catid = 0
                   AND pc_apptstatus = 'recall'
                   AND recipient_type = 'patient'",
                [$wspMessage, $emailSubject, $emailMessage, $enabled, $facilityId]
            );
        } else {
            sqlStatement(
                "INSERT INTO wsp_email_notification_templates
                     (facility_id, notification_type, pc_catid, category_name,
                      pc_apptstatus, recipient_type, wsp_message, email_subject, email_message, enabled)
                 VALUES (?, 'recall', 0, 'Recall', 'recall', 'patient', ?, ?, ?, ?)",
                [$facilityId, $wspMessage, $emailSubject, $emailMessage, $enabled]
            );
        }

        return true;
    }
}
