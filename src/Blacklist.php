<?php
/**
 * Blacklist.php
 * Manages WhatsApp phone number blacklisting based on delivery failures.
 */

namespace OpenEMR\Modules\WspEmail;

class Blacklist
{
    /** Number of consecutive failures before auto-blacklisting */
    private const MAX_FAILURES = 3;

    /** Vendor statuses that trigger immediate blacklisting (no retry) */
    private const PERMANENT_STATUSES = ['INVALID'];

    /**
     * Days after which a NOT_ON_WA blacklist entry is considered expired.
     *
     * Phone numbers in Argentina (and most countries) are recycled by carriers
     * after a period of inactivity. After this TTL the next cron run will
     * re-check the number via checkOpenWaContact() instead of skipping it.
     *
     * Set to 0 to disable TTL (permanent blacklist for NOT_ON_WA).
     */
    private const NOT_ON_WA_TTL_DAYS = 180;

    /**
     * Add a number to the blacklist because it is not registered on WhatsApp.
     * Called after a positive 'not_found' result from checkOpenWaContact().
     * Scope: facility_id + vendor so it only blocks future WSP sends for this facility.
     *
     * @param string $phone      Raw phone from patient_data.phone_cell
     * @param int    $facilityId
     * @param string $vendor     Should be 'openwa'
     */
    public function addNotOnWhatsApp(string $phone, int $facilityId, string $vendor): void
    {
        $this->addToBlacklist(
            $phone, $facilityId, $vendor,
            'NOT_ON_WA', 1,
            'Auto-blacklisted: number not registered on WhatsApp (contact check)'
        );
        error_log("WspEmail [BLACKLIST] Added phone={$phone} — reason=NOT_ON_WA (not on WhatsApp)");
    }

    public function __construct()
    {
        // No initialization needed
    }

    /**
     * Check if a phone number is blacklisted for a given facility and vendor.
     *
     * @param string $phone
     * @param int    $facilityId
     * @param string $vendor
     * @return bool
     */
    public function isBlacklisted(string $phone, int $facilityId, string $vendor): bool
    {
        // Build the TTL exclusion clause for NOT_ON_WA entries.
        // If TTL is 0, the clause is omitted and NOT_ON_WA entries never expire.
        $ttlClause = '';
        $ttlParams  = [];
        if (self::NOT_ON_WA_TTL_DAYS > 0) {
            // Exclude NOT_ON_WA rows whose updated_at is older than the TTL.
            // This lets the cron re-check recycled numbers automatically.
            $ttlClause = 'AND NOT (reason = ? AND updated_at < DATE_SUB(NOW(), INTERVAL ? DAY))';
            $ttlParams  = ['NOT_ON_WA', self::NOT_ON_WA_TTL_DAYS];
        }

        $row = sqlQuery(
            "SELECT COUNT(*) AS total FROM wsp_email_blacklist
             WHERE phone     = ?
               AND is_active = 1
               AND (
                   (facility_id = ? AND vendor IN (?, 'all'))
                   OR
                   (facility_id = 0 AND vendor IN (?, 'all'))
               )
               {$ttlClause}",
            array_merge([$phone, $facilityId, $vendor, $vendor], $ttlParams)
        );

        return (int)($row['total'] ?? 0) > 0;
    }

    /**
     * Process the result of a send attempt and blacklist if warranted.
     *
     * @param string $phone
     * @param int    $facilityId
     * @param string $vendor
     * @param array  $result     Return value from WspSender::send()
     */
    public function processResult(
        string $phone,
        int $facilityId,
        string $vendor,
        array $result
    ): void {
        $status = strtoupper($result['status'] ?? '');

        // Immediate blacklist: invalid number format
        if (in_array($status, self::PERMANENT_STATUSES, true)) {
            $this->addToBlacklist(
                $phone, $facilityId, $vendor,
                'INVALID', 1,
                'Auto-blacklisted: invalid number format'
            );
            error_log("WspEmail [BLACKLIST] Added phone={$phone} — reason=INVALID");
            return;
        }

        // Consecutive failure tracking
        if ($status === 'ERROR') {
            $failCount = $this->incrementFailCount($phone, $facilityId, $vendor);
            error_log("WspEmail [BLACKLIST] Failure count for phone={$phone}: {$failCount}/" . self::MAX_FAILURES);

            if ($failCount >= self::MAX_FAILURES) {
                $this->addToBlacklist(
                    $phone, $facilityId, $vendor,
                    'FAILED_MAX', $failCount,
                    "Auto-blacklisted after {$failCount} consecutive failures"
                );
                error_log("WspEmail [BLACKLIST] Added phone={$phone} — reason=FAILED_MAX");
            }
            return;
        }

        // Successful send: reset failure count if exists
        if (in_array($status, ['SUCCESS', 'SENT'], true)) {
            $this->resetFailCount($phone, $facilityId, $vendor);
        }
    }

    /**
     * Manually add a number to the blacklist (e.g. from admin UI).
     */
    public function addManual(string $phone, int $facilityId, string $vendor, string $notes = ''): void
    {
        $this->addToBlacklist($phone, $facilityId, $vendor, 'MANUAL', 0, $notes);
    }

    /**
     * Deactivate a blacklisted number (e.g. patient updated their number).
     */
    public function remove(string $phone, int $facilityId, string $vendor): void
    {
        sqlStatement(
            "UPDATE wsp_email_blacklist
             SET is_active = 0, updated_at = NOW()
             WHERE phone       = ?
               AND facility_id = ?
               AND vendor      = ?",
            [$phone, $facilityId, $vendor]
        );
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    private function addToBlacklist(
        string $phone,
        int $facilityId,
        string $vendor,
        string $reason,
        int $failCount,
        string $notes
    ): void {
        sqlStatement(
            "INSERT INTO wsp_email_blacklist
                 (facility_id, vendor, phone, reason, fail_count, is_active, notes)
             VALUES (?, ?, ?, ?, ?, 1, ?)
             ON DUPLICATE KEY UPDATE
                 reason     = VALUES(reason),
                 fail_count = VALUES(fail_count),
                 is_active  = 1,
                 notes      = VALUES(notes),
                 updated_at = NOW()",
            [$facilityId, $vendor, $phone, $reason, $failCount, $notes]
        );
    }

    /**
     * Increment consecutive failure count for a number.
     * Uses a tracking row with reason=TRACKING and is_active=0
     * (not yet blacklisted, just counting).
     *
     * @return int Updated failure count
     */
    private function incrementFailCount(string $phone, int $facilityId, string $vendor): int
    {
        sqlStatement(
            "INSERT INTO wsp_email_blacklist
                 (facility_id, vendor, phone, reason, fail_count, is_active, notes)
             VALUES (?, ?, ?, 'TRACKING', 1, 0, 'Failure tracking')
             ON DUPLICATE KEY UPDATE
                 fail_count = fail_count + 1,
                 updated_at = NOW()",
            [$facilityId, $vendor, $phone]
        );

        $row = sqlQuery(
            "SELECT fail_count FROM wsp_email_blacklist
             WHERE phone       = ?
               AND facility_id = ?
               AND vendor      = ?",
            [$phone, $facilityId, $vendor]
        );

        return (int)($row['fail_count'] ?? 0);
    }

    private function resetFailCount(string $phone, int $facilityId, string $vendor): void
    {
        sqlStatement(
            "UPDATE wsp_email_blacklist
             SET fail_count = 0, updated_at = NOW()
             WHERE phone       = ?
               AND facility_id = ?
               AND vendor      = ?
               AND reason      = 'TRACKING'",
            [$phone, $facilityId, $vendor]
        );
    }
}