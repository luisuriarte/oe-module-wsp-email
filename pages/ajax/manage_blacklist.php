<?php
/**
 * manage_blacklist.php — Add / toggle / remove blacklist entries.
 *
 * POST body (JSON):
 *   action      'add' | 'toggle' | 'remove'
 *
 *   For 'add':
 *     facility_id  int
 *     vendor       string
 *     phone        string
 *     notes        string (optional)
 *
 *   For 'toggle' | 'remove':
 *     id           int   – primary key of wsp_email_blacklist
 *
 * Response JSON:
 *   { success: true } | { error: '...' }
 */

$sessionAllowWrite = true;
require_once __DIR__ . '/../../../../../globals.php';
require_once __DIR__ . '/../../src/Blacklist.php';

use OpenEMR\Common\Acl\AclMain;
use OpenEMR\Modules\WspEmail\Blacklist;

header('Content-Type: application/json');

if (!AclMain::aclCheckCore('admin', 'super')) {
    http_response_code(403);
    echo json_encode(['error' => 'Access denied']);
    exit;
}

$body = json_decode(file_get_contents('php://input'), true);
if (!$body || !isset($body['action'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing action']);
    exit;
}

$action = $body['action'];

try {
    if ($action === 'add') {
        $facilityId = (int)($body['facility_id'] ?? 0);
        $vendor     = trim($body['vendor']  ?? '');
        $phone      = trim($body['phone']   ?? '');
        $notes      = trim($body['notes']   ?? '');

        if ($phone === '') {
            echo json_encode(['error' => 'Phone number is required']);
            exit;
        }
        if ($vendor === '') {
            echo json_encode(['error' => 'Vendor is required']);
            exit;
        }

        $bl = new Blacklist();
        $bl->addManual($phone, $facilityId, $vendor, $notes);

        echo json_encode(['success' => true]);

    } elseif ($action === 'toggle') {
        $id = (int)($body['id'] ?? 0);
        if ($id <= 0) {
            echo json_encode(['error' => 'Invalid id']);
            exit;
        }

        // Flip is_active
        sqlStatement(
            "UPDATE wsp_email_blacklist
                SET is_active  = IF(is_active = 1, 0, 1),
                    updated_at = NOW()
              WHERE id = ?",
            [$id]
        );

        $row = sqlQuery("SELECT is_active FROM wsp_email_blacklist WHERE id = ?", [$id]);
        echo json_encode(['success' => true, 'is_active' => (int)($row['is_active'] ?? 0)]);

    } elseif ($action === 'remove') {
        $id = (int)($body['id'] ?? 0);
        if ($id <= 0) {
            echo json_encode(['error' => 'Invalid id']);
            exit;
        }

        sqlStatement("DELETE FROM wsp_email_blacklist WHERE id = ?", [$id]);
        echo json_encode(['success' => true]);

    } else {
        echo json_encode(['error' => 'Unknown action: ' . $action]);
    }
} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
