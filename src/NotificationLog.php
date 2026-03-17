<?php
/**
 * NotificationLog — CRUD for notification_log table
 *
 * @package   OpenEMR\Modules\WspEmail
 * @author    Luis A. Uriarte <luis.uriarte@gmail.com>
 * @copyright Copyright (c) 2024-2025 Luis A. Uriarte
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

namespace OpenEMR\Modules\WspEmail;

class NotificationLog
{
    /**
     * Inserts a new notification record and returns the new row ID.
     */
    public function insert(
        string  $type,
        int     $pid,
        int     $pcEid,
        string  $gatewayType,
        string  $message,
        string  $emailSender,
        string  $emailSubject,
        string  $patientInfo,
        string  $gatewayInfo,
        string  $pcEventDate,
        string  $pcEndDate,
        string  $pcStartTime,
        string  $pcEndTime,
        ?string $msgId  = null,
        ?string $status = null
    ): int {
        $sql = "INSERT INTO notification_log
                    (pid, pc_eid, sms_gateway_type, message, email_sender, email_subject,
                     type, patient_info, smsgateway_info, msg_id, status,
                     pc_eventDate, pc_endDate, pc_startTime, pc_endTime, dSentDateTime)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())";

        sqlStatement($sql, [
            $pid, $pcEid, $gatewayType, $message, $emailSender, $emailSubject,
            $type, $patientInfo, $gatewayInfo, $msgId, $status,
            $pcEventDate, $pcEndDate, $pcStartTime, $pcEndTime,
        ]);

        return (int)sqlQuery("SELECT LAST_INSERT_ID() AS id")['id'];
    }

    /**
     * Updates the delivery status of a notification identified by its vendor message ID.
     */
    public function updateStatus(string $msgId, string $status): bool
    {
        if (empty($msgId)) {
            return false;
        }
        sqlStatement(
            "UPDATE notification_log SET status = ? WHERE msg_id = ?",
            [$status, $msgId]
        );
        return true;
    }

    /**
     * Updates msg_id and status on a specific log row (used right after sending).
     */
    public function updateMsgId(int $logId, string $msgId, string $status): void
    {
        sqlStatement(
            "UPDATE notification_log SET msg_id = ?, status = ? WHERE iLogId = ?",
            [$msgId, $status, $logId]
        );
    }

    /**
     * Searches notification records by patient name, PID, or patient_info field.
     */
    public function searchByPatient(string $search, int $limit = 100, int $offset = 0): array
    {
        $like      = '%' . $search . '%';
        $pidSearch = is_numeric($search) ? (int)$search : 0;

        $sql = "SELECT nl.*, pd.fname, pd.lname, pd.phone_cell,
                       pe.pc_title, pe.pc_eventDate AS event_date_cal
                FROM notification_log nl
                LEFT JOIN patient_data pd ON pd.pid = nl.pid
                LEFT JOIN openemr_postcalendar_events pe ON pe.pc_eid = nl.pc_eid
                WHERE nl.patient_info LIKE ?
                   OR pd.fname LIKE ?
                   OR pd.lname LIKE ?
                   OR nl.pid = ?
                ORDER BY nl.dSentDateTime DESC
                LIMIT ? OFFSET ?";

        $res  = sqlStatement($sql, [$like, $like, $like, $pidSearch, $limit, $offset]);
        $rows = [];
        while ($row = sqlFetchArray($res)) {
            $rows[] = $row;
        }
        return $rows;
    }

    /**
     * Returns daily send counts grouped by notification type (WSP / Email).
     * Output is suitable for direct consumption by Chart.js.
     */
    public function getStats(string $dateFrom, string $dateTo, ?int $facilityId = null): array
    {
        $params      = [$dateFrom, $dateTo];
        $facilityJoin = '';

        if ($facilityId) {
            // Filter by facility via the calendar event link
            $facilityJoin = "INNER JOIN openemr_postcalendar_events pe
                               ON pe.pc_eid = nl.pc_eid AND pe.pc_facility = ?";
            array_unshift($params, $facilityId);
        }

        $sql = "SELECT DATE(nl.dSentDateTime) AS send_date,
                       nl.type,
                       COUNT(*) AS total,
                       SUM(CASE WHEN nl.status IN ('READ','DELIVERED','sent') THEN 1 ELSE 0 END) AS delivered,
                       SUM(CASE WHEN nl.status IS NULL OR nl.status = 'in_progress' THEN 1 ELSE 0 END) AS pending,
                       SUM(CASE WHEN nl.status = 'error' THEN 1 ELSE 0 END) AS failed
                FROM notification_log nl
                $facilityJoin
                WHERE DATE(nl.dSentDateTime) BETWEEN ? AND ?
                GROUP BY DATE(nl.dSentDateTime), nl.type
                ORDER BY send_date ASC";

        $res  = sqlStatement($sql, $params);
        $rows = [];
        while ($row = sqlFetchArray($res)) {
            $rows[] = $row;
        }
        return $rows;
    }

    /**
     * Returns aggregated totals for the dashboard summary cards.
     */
    public function getSummaryTotals(string $dateFrom, string $dateTo): array
    {
        $sql = "SELECT
                  SUM(type = 'WSP')   AS total_wsp,
                  SUM(type = 'Email') AS total_email,
                  SUM(status IS NULL OR status = 'in_progress') AS pending,
                  SUM(status = 'error') AS failed,
                  COUNT(*) AS grand_total
                FROM notification_log
                WHERE DATE(dSentDateTime) BETWEEN ? AND ?";

        return sqlQuery($sql, [$dateFrom, $dateTo]) ?: [
            'total_wsp'   => 0,
            'total_email' => 0,
            'pending'     => 0,
            'failed'      => 0,
            'grand_total' => 0,
        ];
    }
}
