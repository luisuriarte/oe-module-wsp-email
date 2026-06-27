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
use OpenEMR\Services\ListService;

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
// Helpers (using OpenEMR\Services\ListService)
// ---------------------------------------------------------------------------

$listService = new ListService();

/**
 * Check if a list_id is registered in the master `lists` list.
 */
function isManagedList(string $listId): bool
{
    global $listService;
    return $listService->getListOption('lists', $listId) !== null;
}

/**
 * Return all list_ids registered in the master `lists` list.
 */
function getManagedLists(): array
{
    global $listService;
    $result = $listService->searchLists([]);
    $data = $result->getData();
    $out = [];
    foreach ($data as $row) {
        $out[] = [
            'list_id'       => $row['option_id'],
            'display_name'  => $row['title'],
            'description'   => $row['notes'] ?? '',
            'extra_columns' => $row['codes'] ?? '',
        ];
    }
    return $out;
}

/**
 * Return all options for a given list_id.
 */
function getOptions(string $listId): array
{
    global $listService;
    $rows = $listService->getOptionsByListName($listId);
    $out = [];
    foreach ($rows as $row) {
        $entry = [
            'option_id'       => $row['option_id'],
            'title'           => $row['title'],
            'seq'             => $row['seq'],
            'is_default'      => $row['is_default'],
            'activity'        => $row['activity'],
            'codes'           => $row['codes'] ?? '',
            'mapping'         => $row['mapping'] ?? '',
            'option_value'    => $row['option_value'] ?? 0,
            'toggle_setting_1'=> $row['toggle_setting_1'] ?? 0,
            'toggle_setting_2'=> $row['toggle_setting_2'] ?? 0,
        ];
        // apptstat stores color + alert_time in notes: "#hexcolor|minutes"
        if ($listId === 'apptstat') {
            $notes = $row['notes'] ?? '';
            if (preg_match('/^(#[0-9A-Fa-f]{6})\|(\d*)$/', $notes, $m)) {
                $entry['color']      = $m[1];
                $entry['alert_time'] = (int)$m[2];
            } else {
                $entry['color']      = $notes;
                $entry['alert_time'] = 0;
            }
        } else {
            $entry['notes'] = $row['notes'] ?? '';
        }
        $out[] = $entry;
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
        $codes      = $_POST['codes'] ?? '';
        $mapping    = $_POST['mapping'] ?? '';
        $optionValue = (int) ($_POST['option_value'] ?? 0);
        $toggle1    = !empty($_POST['toggle_setting_1']) ? 1 : 0;
        $toggle2    = !empty($_POST['toggle_setting_2']) ? 1 : 0;

        // apptstat stores color + alert_time in notes
        if ($listId === 'apptstat') {
            $color      = $_POST['color'] ?? '';
            $alertTime  = (int) ($_POST['alert_time'] ?? 0);
            $notes      = $color . '|' . $alertTime;
        } else {
            $notes = $_POST['notes'] ?? '';
        }

        $existing = sqlQuery(
            "SELECT option_id FROM list_options WHERE list_id = ? AND option_id = ?",
            [$listId, $optionId]
        );

        if (!empty($existing)) {
            sqlStatement(
                "UPDATE list_options
                 SET title = ?, seq = ?, is_default = ?, activity = ?,
                     notes = ?, codes = ?, mapping = ?, option_value = ?,
                     toggle_setting_1 = ?, toggle_setting_2 = ?
                 WHERE list_id = ? AND option_id = ?",
                [$title, $seq, $isDefault, $activity,
                 $notes, $codes, $mapping, $optionValue,
                 $toggle1, $toggle2,
                 $listId, $optionId]
            );
        } else {
            sqlStatement(
                "INSERT INTO list_options
                    (list_id, option_id, title, seq, is_default, activity,
                     notes, codes, mapping, option_value,
                     toggle_setting_1, toggle_setting_2)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
                [$listId, $optionId, $title, $seq, $isDefault, $activity,
                 $notes, $codes, $mapping, $optionValue,
                 $toggle1, $toggle2]
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
