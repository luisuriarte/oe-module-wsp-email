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
     * Also records the event in the history table.
     */
    public function updateStatus(string $msgId, string $status): bool
    {
        if (empty($msgId)) {
            return false;
        }

        // 1. Update main record
        sqlStatement(
            "UPDATE notification_log SET status = ? WHERE msg_id = ?",
            [$status, $msgId]
        );

        // 2. Add to history
        $log = sqlQuery("SELECT iLogId FROM notification_log WHERE msg_id = ?", [$msgId]);
        if ($log) {
            $this->addStatusHistory((int)$log['iLogId'], $status);
        }

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
        $this->addStatusHistory($logId, $status);
    }

    /**
     * Records a specific status transition in the history table.
     */
    public function addStatusHistory(int $logId, string $status): void
    {
        sqlStatement(
            "INSERT INTO wsp_email_status_history (log_id, status, created_at) VALUES (?, ?, NOW())",
            [$logId, $status]
        );
    }

    /**
     * Returns the timeline of status changes for a specific notification.
     */
    public function getStatusHistory(int $logId): array
    {
        $res = sqlStatement(
            "SELECT status, created_at FROM wsp_email_status_history WHERE log_id = ? ORDER BY created_at ASC",
            [$logId]
        );
        $rows = [];
        while ($row = sqlFetchArray($res)) {
            $rows[] = $row;
        }
        return $rows;
    }

    /**
     * Searches notification records by patient name, PID, phone or patient_info field.
     */
    public function searchByPatient(string $search, int $limit = 100, int $offset = 0, ?string $dateFrom = null, ?string $dateTo = null, ?string $type = null): array
    {
        $like      = '%' . $search . '%';
        $pidSearch = is_numeric($search) ? (int)$search : 0;
        
        // Base conditions: Search in patient_info (log), then name fields, phones, email and pid
        $where = "(nl.patient_info LIKE ? 
                   OR pd.fname LIKE ? 
                   OR pd.lname LIKE ? 
                   OR pd.mname LIKE ?
                   OR CONCAT(pd.fname, ' ', pd.mname, ' ', pd.lname) LIKE ?
                   OR CONCAT(pd.lname, ' ', pd.fname, ' ', pd.mname) LIKE ?
                   OR pd.phone_cell LIKE ?
                   OR pd.phone_home LIKE ?
                   OR pd.phone_biz LIKE ?
                   OR pd.email LIKE ?
                   OR nl.pid = ?)";
        
        $params = [$like, $like, $like, $like, $like, $like, $like, $like, $like, $like, $pidSearch];

        if (!empty($dateFrom) && !empty($dateTo)) {
            $where .= " AND nl.dSentDateTime >= ? AND nl.dSentDateTime <= ?";
            $params[] = $dateFrom . ' 00:00:00';
            $params[] = $dateTo . ' 23:59:59';
        }

        if ($type && in_array($type, ['WSP', 'Email'])) {
            $where .= " AND nl.type = ?";
            $params[] = $type;
        }

        $sql = "SELECT nl.*, pd.fname, pd.lname, pd.phone_cell,
                       pe.pc_title, pe.pc_eventDate AS event_date_cal
                FROM notification_log nl
                LEFT JOIN patient_data pd ON pd.pid = nl.pid
                LEFT JOIN openemr_postcalendar_events pe ON pe.pc_eid = nl.pc_eid
                WHERE $where
                ORDER BY nl.dSentDateTime DESC
                LIMIT " . (int)$limit . " OFFSET " . (int)$offset;

        $res  = sqlStatement($sql, $params);
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
