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
        $sql = "SELECT wfc.*, f.name AS facility_name, f.street, f.city, f.state,
                       f.phone AS facility_phone, f.email AS facility_email,
                       CONCAT(f.street, ', ', f.city, ', ', f.state) AS facility_address
                FROM wsp_email_facility_config wfc
                INNER JOIN facility f ON f.id = wfc.facility_id
                WHERE wfc.facility_id = ?";
        $result = sqlQuery($sql, [$facilityId]);
        return $result ?: [];
    }

    /**
     * Returns all OpenEMR facilities joined with their extended config (LEFT JOIN).
     */
    public function getAllFacilitiesWithConfig(): array
    {
        $sql = "SELECT f.id AS facility_id, f.name AS facility_name,
                       f.street, f.city, f.state,
                       f.phone AS facility_phone, f.email AS facility_email,
                       CONCAT(f.street, ', ', f.city, ', ', f.state) AS facility_address,
                       wfc.id, wfc.vendor, wfc.vendor_instance, wfc.vendor_api_key,
                       wfc.webhook_secret, wfc.logo_wsp, wfc.logo_email,
                       wfc.latitude, wfc.longitude, wfc.website_url,
                       wfc.wsp_message, wfc.email_message, wfc.email_subject,
                       wfc.enabled_wsp, wfc.enabled_email
                FROM facility f
                LEFT JOIN wsp_email_facility_config wfc ON wfc.facility_id = f.id
                ORDER BY f.name";
        $res  = sqlStatement($sql);
        $rows = [];
        while ($row = sqlFetchArray($res)) {
            $rows[] = $row;
        }
        return $rows;
    }

    /**
     * Inserts or updates the main configuration for a facility (upsert by facility_id).
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
            'facility_id'   => $facilityId,
            'vendor'        => $data['vendor']        ?? 'wasenderapi',
            'vendor_instance'  => $data['vendor_instance']  ?? null,
            'vendor_api_key'   => $data['vendor_api_key']   ?? null,
            'webhook_secret'   => $data['webhook_secret']   ?? null,
            'logo_wsp'         => $data['logo_wsp']         ?? null,
            'logo_email'       => $data['logo_email']       ?? null,
            'latitude'         => $data['latitude']         ?? null,
            'longitude'        => $data['longitude']        ?? null,
            'website_url'      => $data['website_url']      ?? null,
            'wsp_message'      => $data['wsp_message']      ?? null,
            'email_message'    => $data['email_message']    ?? null,
            'email_subject'    => $data['email_subject']    ?? null,
            'enabled_wsp'      => isset($data['enabled_wsp'])   ? (int)$data['enabled_wsp']   : 1,
            'enabled_email'    => isset($data['enabled_email']) ? (int)$data['enabled_email'] : 1,
        ];

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

        return true;
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

            sqlStatement(
                "INSERT INTO wsp_email_notification_schedule
                     (facility_id, seq, hours_before, send_on_booking, enabled_wsp, enabled_email)
                 VALUES (?, ?, ?, ?, ?, ?)",
                [$facilityId, $seq, $hoursBefore, $sendOnBooking, $enabledWsp, $enabledEmail]
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
}
