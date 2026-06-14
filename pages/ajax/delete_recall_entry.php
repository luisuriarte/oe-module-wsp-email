<?php
/**
 * delete_recall_entry.php — Deletes a module recall entry.
 *
 * POST body: id (entry id from wsp_email_recall_entries)
 * Returns: JSON { success: bool, error?: string }
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

$id = (int)($_POST['id'] ?? 0);
if (!$id) {
    echo json_encode(['success' => false, 'error' => 'id required']);
    exit;
}

// Also clean up notification tracking
sqlStatement("DELETE FROM wsp_email_recall WHERE recall_id = ?", [(-$id)]);
sqlStatement("DELETE FROM wsp_email_recall_entries WHERE id = ?", [$id]);

echo json_encode(['success' => true]);
