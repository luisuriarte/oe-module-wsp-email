<?php
/**
 * RateLimiter.php
 * Controls WhatsApp send rate per facility and vendor to reduce ban risk.
 */

namespace OpenEMR\Modules\WspEmail;

class RateLimiter
{
    /** Maximum messages per minute per facility+vendor */
    private const MAX_PER_MINUTE = 10;

    /** Minimum delay between sends in seconds */
    private const DELAY_MIN = 4;

    /** Maximum delay between sends in seconds */
    private const DELAY_MAX = 8;

    /** How many seconds to keep rate limit log records */
    private const LOG_RETENTION_SECONDS = 86400; // 24 hours

    public function __construct()
    {
        $this->purgeOldRecords();
    }

    /**
     * Wait if necessary to respect rate limit, then register the send attempt.
     * Blocks execution with sleep() until a slot is available.
     *
     * @param int    $facilityId
     * @param string $vendor
     * @param string $phone
     */
    public function throttle(int $facilityId, string $vendor, string $phone): void
    {
        // Wait until under the per-minute limit
        while ($this->countLastMinute($facilityId, $vendor) >= self::MAX_PER_MINUTE) {
            echo "    [RATE LIMIT] Waiting 5s — limit of " . self::MAX_PER_MINUTE . " msg/min reached for vendor={$vendor}\n";
            sleep(5);
        }

        // Random delay to simulate human behavior
        $delay = rand(self::DELAY_MIN, self::DELAY_MAX);
        sleep($delay);

        $this->registerSend($facilityId, $vendor, $phone);
    }

    /**
     * Count sends in the last 60 seconds for a given facility+vendor.
     */
    private function countLastMinute(int $facilityId, string $vendor): int
    {
        $row = sqlQuery(
            "SELECT COUNT(*) AS total FROM wsp_email_rate_limit_log
             WHERE facility_id = ?
               AND vendor      = ?
               AND sent_at     >= DATE_SUB(NOW(), INTERVAL 60 SECOND)",
            [$facilityId, $vendor]
        );

        return (int)($row['total'] ?? 0);
    }

    /**
     * Register a send attempt in the rate limit log.
     */
    private function registerSend(int $facilityId, string $vendor, string $phone): void
    {
        sqlStatement(
            "INSERT INTO wsp_email_rate_limit_log
                (facility_id, vendor, phone, sent_at)
             VALUES (?, ?, ?, NOW())",
            [$facilityId, $vendor, $phone]
        );
    }

    /**
     * Delete records older than LOG_RETENTION_SECONDS to keep the table small.
     */
    private function purgeOldRecords(): void
    {
        sqlStatement(
            "DELETE FROM wsp_email_rate_limit_log
             WHERE sent_at < DATE_SUB(NOW(), INTERVAL ? SECOND)",
            [self::LOG_RETENTION_SECONDS]
        );
    }
}