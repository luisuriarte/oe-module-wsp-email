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
     *
     * @param string $msgId Message ID del proveedor
     * @param string $rawStatus Estado raw del proveedor
     * @param string|null $provider Nombre del proveedor (opcional)
     * @param array|null $payload Payload completo del webhook (opcional)
     * @return bool true si se actualizó correctamente
     */
    public function updateStatus(string $msgId, string $rawStatus, ?string $provider = null, ?array $payload = null): bool
    {
        if (empty($msgId)) {
            return false;
        }

        // Normalizar estado si tenemos el proveedor
        $canonicalStatus = 'SENT'; // Default
        $statusPriority = 2;

        if (!empty($provider)) {
            $normalized = StatusNormalizer::processWebhook($provider, $payload ?? ['status' => $rawStatus]);
            $canonicalStatus = $normalized['canonical'];
            $statusPriority = $normalized['priority'];
        } else {
            // Fallback: intentar normalizar sin proveedor
            $canonicalStatus = StatusNormalizer::normalize('default', $rawStatus);
            $statusPriority = StatusNormalizer::getPriority($canonicalStatus);
        }

        // 1. Update main record with normalized status
        sqlStatement(
            "UPDATE notification_log
             SET status = ?,
                 status_current = ?,
                 provider_raw_status = ?,
                 status_priority = ?,
                 provider_payload = ?
             WHERE msg_id = ?",
            [$rawStatus, $canonicalStatus, $rawStatus, $statusPriority, $payload ? json_encode($payload, JSON_UNESCAPED_UNICODE) : null, $msgId]
        );

        // 2. Check if this is a higher priority status than existing
        $current = sqlQuery(
            "SELECT status_priority FROM notification_log WHERE msg_id = ?",
            [$msgId]
        );

        // Solo actualizar historial si es un estado de mayor prioridad
        // o si es un estado terminal diferente
        $shouldAddHistory = true;
        if ($current && isset($current['status_priority'])) {
            $existingPriority = (int)$current['status_priority'];
            // Evitar duplicados: no agregar si es la misma prioridad y mismo estado
            if ($existingPriority >= $statusPriority && $existingPriority > 0) {
                // Verificar si ya existe este estado en el historial
                $existingHistory = sqlQuery(
                    "SELECT COUNT(*) as count FROM wsp_email_status_history
                     WHERE log_id = (SELECT iLogId FROM notification_log WHERE msg_id = ?)
                     AND status = ?
                     LIMIT 1",
                    [$msgId, $canonicalStatus]
                );
                if ($existingHistory && $existingHistory['count'] > 0) {
                    $shouldAddHistory = false;
                }
            }
        }

        // 3. Add to history if should
        if ($shouldAddHistory) {
            $log = sqlQuery("SELECT iLogId FROM notification_log WHERE msg_id = ?", [$msgId]);
            if ($log) {
                $this->addStatusHistory((int)$log['iLogId'], $canonicalStatus, $rawStatus, $provider, $payload);
            }
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
     *
     * @param int $logId ID del log de notificación
     * @param string $status Estado canónico normalizado
     * @param string|null $rawStatus Estado raw del proveedor
     * @param string|null $provider Nombre del proveedor
     * @param array|null $payload Payload completo del webhook
     */
    public function addStatusHistory(int $logId, string $status, ?string $rawStatus = null, ?string $provider = null, ?array $payload = null): void
    {
        sqlStatement(
            "INSERT INTO wsp_email_status_history
                (log_id, status, provider_raw_status, provider_name, provider_payload, created_at)
             VALUES (?, ?, ?, ?, ?, NOW())",
            [
                $logId,
                $status,
                $rawStatus,
                $provider,
                $payload ? json_encode($payload, JSON_UNESCAPED_UNICODE) : null
            ]
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
    public function searchByPatient(string $search, int $limit = 100, int $offset = 0, ?string $dateFrom = null, ?string $dateTo = null, ?string $type = null, ?string $status = null): array
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

        // Filter by status if provided
        if ($status && !empty($status)) {
            // Use status_current (canonical status) for filtering
            $where .= " AND LOWER(nl.status_current) = ?";
            $params[] = strtolower($status);
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
     * Returns daily send counts grouped by notification type (WSP / Email) and status.
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
                       nl.status_current AS status,
                       COUNT(*) AS total
                FROM notification_log nl
                $facilityJoin
                WHERE DATE(nl.dSentDateTime) BETWEEN ? AND ?
                GROUP BY DATE(nl.dSentDateTime), nl.type, nl.status_current
                ORDER BY send_date ASC, nl.type ASC, nl.status_current ASC";

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
