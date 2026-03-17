<?php
/**
 * Bootstrap for oe-module-wsp-email
 * Registers menu items and event listeners in OpenEMR.
 *
 * @package   OpenEMR Module
 * @link      http://www.open-emr.org
 * @author    Luis A. Uriarte <luis.uriarte@gmail.com>
 * @copyright Copyright (c) 2024-2025 Luis A. Uriarte
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

use OpenEMR\Menu\MenuEvent;
use OpenEMR\Modules\WspEmail\Bootstrap\BootstrapService;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * @global OpenEMR\Core\ModulesClassLoader $classLoader
 */
$classLoader->registerNamespaceIfNotExists(
    'OpenEMR\\Modules\\WspEmail\\',
    __DIR__ . DIRECTORY_SEPARATOR . 'src'
);

/**
 * @var EventDispatcherInterface $eventDispatcher
 * @var array $module
 */
$dispatcher = $GLOBALS['kernel']->getEventDispatcher();

// ---------------------------------------------------------------------------
// Menu Registration
// ---------------------------------------------------------------------------
function oe_module_wspemail_add_menu_item(MenuEvent $event): MenuEvent
{
    $menu = $event->getMenu();

    $moduleBase = '/interface/modules/custom_modules/oe-module-wsp-email/pages/dashboard.php';

    // Main module entry
    $menuDash = new stdClass();
    $menuDash->requirement = 0;
    $menuDash->target      = 'wsp';
    $menuDash->menu_id     = 'wsp_dashboard';
    $menuDash->label       = xlt('Notificaciones WSP/Email');
    $menuDash->url         = $moduleBase . '?tab=dashboard';
    $menuDash->children    = [];
    $menuDash->acl_req     = ['patients', 'demo'];

    // Patient status
    $menuPatients = new stdClass();
    $menuPatients->requirement = 0;
    $menuPatients->target      = 'wsp';
    $menuPatients->menu_id     = 'wsp_patients';
    $menuPatients->label       = xlt('Estado de Pacientes');
    $menuPatients->url         = $moduleBase . '?tab=patients';
    $menuPatients->children    = [];
    $menuPatients->acl_req     = ['patients', 'demo'];

    // Facility config
    $menuFacility = new stdClass();
    $menuFacility->requirement = 0;
    $menuFacility->target      = 'wsp';
    $menuFacility->menu_id     = 'wsp_facility';
    $menuFacility->label       = xlt('Config. Centros');
    $menuFacility->url         = $moduleBase . '?tab=facility';
    $menuFacility->children    = [];
    $menuFacility->acl_req     = ['admin', 'docs'];

    // Notification config
    $menuConfig = new stdClass();
    $menuConfig->requirement = 0;
    $menuConfig->target      = 'wsp';
    $menuConfig->menu_id     = 'wsp_config';
    $menuConfig->label       = xlt('Config. Notificaciones');
    $menuConfig->url         = $moduleBase . '?tab=config';
    $menuConfig->children    = [];
    $menuConfig->acl_req     = ['admin', 'docs'];

    // Parent sub-menu under "Servicios"
    $subMenu = new stdClass();
    $subMenu->requirement = 0;
    $subMenu->target      = 'wsp';
    $subMenu->menu_id     = 'wsp_submenu';
    $subMenu->label       = xlt('WSP/Email Notificaciones');
    $subMenu->children    = [$menuDash, $menuPatients, $menuFacility, $menuConfig];
    $subMenu->acl_req     = ['patients', 'demo'];

    // Inject into the "Servicios" top-level menu
    $i = 0;
    foreach ($menu as $item) {
        if ($item->menu_id === 'service') {
            $item->children[] = $subMenu;
        }
        $menu[$i] = $item;
        $i++;
    }

    $event->setMenu($menu);
    return $event;
}

$eventDispatcher->addListener(MenuEvent::MENU_UPDATE, 'oe_module_wspemail_add_menu_item');
