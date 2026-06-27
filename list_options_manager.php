<?php
/**
 * list_options_manager.php — Generic CRUD endpoint for OpenEMR list_options.
 *
 * Single endpoint; action parameter drives behavior.
 * Security: CSRF (on writes) + ACL + list_id whitelist via `lists` list.
 * Only list_ids registered in `list_options WHERE list_id = 'lists'` are
 * eligible for editing. Register yours via install.sql:
 *
 *   INSERT IGNORE INTO `list_options` (`list_id`, `option_id`, `title`, ...)
 *   VALUES ('lists', 'my_list', 'My List', ...);
 *
 * Actions:
 *   get_lists              GET   Lists all whitelisted list_ids (from `lists`)
 *   get_options &list_id=x GET   Fetch rows for one list
 *   save_option            POST  Upsert one option
 *   delete_option          POST  Soft-delete (activity=0)
 *   reorder                POST  Bulk-update seq values
 *
 * Usage from JS:
 *   ListOptionsManager.init(listId, '#container', csrfToken, endpointUrl)
 *   ListOptionsManager.initPicker('#container', csrfToken, endpointUrl)
 *
 * @package   OpenEMR\Modules\WspEmail
 */

$sessionAllowWrite = false;
require_once __DIR__ . '/../../../globals.php';

header('Content-Type: application/json');

use OpenEMR\Common\Csrf\CsrfUtils;
use OpenEMR\Common\Acl\AclMain;

// ---------------------------------------------------------------------------
// ACL
// ---------------------------------------------------------------------------
if (!AclMain::aclCheckCore('admin', 'super')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => xlt('Access denied')]);
    exit;
}

// ---------------------------------------------------------------------------
// CSRF validation on write actions
// ---------------------------------------------------------------------------
$writeActions = ['save_option', 'delete_option', 'reorder'];

if (in_array($_REQUEST['action'] ?? '', $writeActions, true)) {
    if (!CsrfUtils::verifyCsrfToken($_POST['csrf_token_form'] ?? '')) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => xlt('Invalid CSRF token')]);
        exit;
    }
}

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

/**
 * Check if a list_id is registered in the master `lists` list.
 */
function isManagedList(string $listId): bool
{
    $row = sqlQuery(
        "SELECT option_id FROM list_options WHERE list_id = 'lists' AND option_id = ?",
        [$listId]
    );
    return !empty($row);
}

/**
 * Return all list_ids registered in the master `lists` list.
 */
function getManagedLists(): array
{
    $rows = sqlStatement(
        "SELECT option_id AS list_id, title AS display_name, notes AS description,
                codes AS extra_columns
         FROM list_options
         WHERE list_id = 'lists'
         ORDER BY seq ASC, option_id ASC"
    );
    $out = [];
    while ($row = sqlFetchArray($rows)) {
        $out[] = $row;
    }
    return $out;
}

/**
 * Return all options for a given list_id.
 */
function getOptions(string $listId): array
{
    $rows = sqlStatement(
        "SELECT option_id, title, seq, is_default, activity, notes, codes, mapping, option_value
         FROM list_options
         WHERE list_id = ?
         ORDER BY seq ASC, option_id ASC",
        [$listId]
    );
    $out = [];
    while ($row = sqlFetchArray($rows)) {
        $out[] = $row;
    }
    return $out;
}

// ---------------------------------------------------------------------------
// Router
// ---------------------------------------------------------------------------

$action = $_REQUEST['action'] ?? '';

switch ($action) {

    // ---- GET: all whitelisted lists (from `lists` list) -------------------
    case 'get_lists':
        echo json_encode(['success' => true, 'data' => getManagedLists()]);
        break;

    // ---- GET: options for one list ----------------------------------------
    case 'get_options':
        $listId = $_GET['list_id'] ?? '';
        if (!isManagedList($listId)) {
            echo json_encode(['success' => false, 'message' => xlt('List not allowed')]);
            exit;
        }
        echo json_encode(['success' => true, 'data' => getOptions($listId)]);
        break;

    // ---- POST: upsert one option ------------------------------------------
    case 'save_option':
        $listId   = $_POST['list_id'] ?? '';
        $optionId = $_POST['option_id'] ?? '';

        if (!isManagedList($listId)) {
            echo json_encode(['success' => false, 'message' => xlt('List not allowed')]);
            exit;
        }

        $title      = $_POST['title'] ?? '';
        $seq        = (int) ($_POST['seq'] ?? 0);
        $isDefault  = !empty($_POST['is_default']) ? 1 : 0;
        $activity   = !empty($_POST['activity']) ? 1 : 0;
        $notes      = $_POST['notes'] ?? '';
        $codes      = $_POST['codes'] ?? '';
        $mapping    = $_POST['mapping'] ?? '';
        $optionValue = (int) ($_POST['option_value'] ?? 0);

        $existing = sqlQuery(
            "SELECT option_id FROM list_options WHERE list_id = ? AND option_id = ?",
            [$listId, $optionId]
        );

        if (!empty($existing)) {
            sqlStatement(
                "UPDATE list_options
                 SET title = ?, seq = ?, is_default = ?, activity = ?,
                     notes = ?, codes = ?, mapping = ?, option_value = ?
                 WHERE list_id = ? AND option_id = ?",
                [$title, $seq, $isDefault, $activity,
                 $notes, $codes, $mapping, $optionValue,
                 $listId, $optionId]
            );
        } else {
            sqlStatement(
                "INSERT INTO list_options
                    (list_id, option_id, title, seq, is_default, activity,
                     notes, codes, mapping, option_value)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
                [$listId, $optionId, $title, $seq, $isDefault, $activity,
                 $notes, $codes, $mapping, $optionValue]
            );
        }

        echo json_encode(['success' => true, 'message' => xlt('Saved')]);
        break;

    // ---- POST: soft-delete (activity=0) -----------------------------------
    case 'delete_option':
        $listId   = $_POST['list_id'] ?? '';
        $optionId = $_POST['option_id'] ?? '';

        if (!isManagedList($listId)) {
            echo json_encode(['success' => false, 'message' => xlt('List not allowed')]);
            exit;
        }

        sqlStatement(
            "UPDATE list_options SET activity = 0 WHERE list_id = ? AND option_id = ?",
            [$listId, $optionId]
        );

        echo json_encode(['success' => true, 'message' => xlt('Deactivated')]);
        break;

    // ---- POST: bulk reorder -------------------------------------------------
    case 'reorder':
        $listId = $_POST['list_id'] ?? '';

        if (!isManagedList($listId)) {
            echo json_encode(['success' => false, 'message' => xlt('List not allowed')]);
            exit;
        }

        $order = json_decode($_POST['order'] ?? '[]', true);
        if (!is_array($order)) {
            echo json_encode(['success' => false, 'message' => xlt('Invalid order data')]);
            exit;
        }

        foreach ($order as $i => $optionId) {
            $seq = $i + 1;
            sqlStatement(
                "UPDATE list_options SET seq = ? WHERE list_id = ? AND option_id = ?",
                [$seq, $listId, $optionId]
            );
        }

        echo json_encode(['success' => true, 'message' => xlt('Reordered')]);
        break;

    default:
        echo json_encode(['success' => false, 'message' => xlt('Unknown action')]);
}
