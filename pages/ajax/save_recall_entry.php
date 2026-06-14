<?php
/**
 * save_recall_entry.php — Creates or updates a module recall entry.
 *
 * POST body: id, pid, event_date, facility_id, provider_id, reason
 *   id=0 → create new; id>0 → update existing
 * Returns: JSON { success: bool, error?: string, id?: int }
 *
 * @package   OpenEMR\Modules\WspEmail
 */

$sessionAllowWrite = true;
require_once __DIR__ . '/../../../../../globals.php';

use OpenEMR\Common\Acl\AclMain;

header('Content-Type: application/json');

if (!AclMain::aclCheckCore('admin', 'super')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Access denied']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'error' => 'POST required']);
    exit;
}

$id         = (int)($_POST['id'] ?? 0);
$pid        = (int)($_POST['pid'] ?? 0);
$eventDate  = trim($_POST['event_date'] ?? '');
$facilityId = (int)($_POST['facility_id'] ?? 0);
$providerId = (int)($_POST['provider_id'] ?? 0);
$reason     = trim($_POST['reason'] ?? '');

if (!$pid || !$eventDate || !$facilityId) {
    echo json_encode(['success' => false, 'error' => 'pid, event_date and facility_id are required']);
    exit;
}

// Auto-create table
sqlStatement("CREATE TABLE IF NOT EXISTS `wsp_email_recall_entries` (
  `id`          int(11)      NOT NULL AUTO_INCREMENT,
  `pid`         int(11)      NOT NULL,
  `event_date`  date         NOT NULL,
  `facility_id` int(11)      NOT NULL,
  `provider_id` int(11)      DEFAULT NULL,
  `reason`      varchar(255) DEFAULT NULL,
  `created_at`  datetime     DEFAULT CURRENT_TIMESTAMP,
  `updated_at`  datetime     DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_pid` (`pid`),
  KEY `idx_facility` (`facility_id`),
  KEY `idx_event_date` (`event_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

if ($id > 0) {
    sqlStatement(
        "UPDATE wsp_email_recall_entries SET
            pid = ?, event_date = ?, facility_id = ?, provider_id = ?, reason = ?
         WHERE id = ?",
        [$pid, $eventDate, $facilityId, $providerId ?: null, $reason ?: null, $id]
    );
    echo json_encode(['success' => true, 'id' => $id]);
} else {
    sqlStatement(
        "INSERT INTO wsp_email_recall_entries
            (pid, event_date, facility_id, provider_id, reason)
         VALUES (?, ?, ?, ?, ?)",
        [$pid, $eventDate, $facilityId, $providerId ?: null, $reason ?: null]
    );
    echo json_encode(['success' => true, 'id' => (int)sqlGetLastInsertId()]);
}
