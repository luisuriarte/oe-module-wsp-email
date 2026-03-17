<?php
/**
 * Module Configuration Entry Point.
 * Shown inside the OpenEMR Module Manager config panel.
 *
 * @package   OpenEMR Module
 * @link      http://www.open-emr.org
 * @author    Luis A. Uriarte <luis.uriarte@gmail.com>
 * @copyright Copyright (c) 2024-2025 Luis A. Uriarte
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

$sessionAllowWrite = true;
require_once __DIR__ . '/../../../globals.php';

$module_config = 1;
?>

<div id="wsp-email-module-config">
    <iframe
        src="<?php echo $web_root; ?>/interface/modules/custom_modules/oe-module-wsp-email/pages/dashboard.php?tab=config"
        style="border:none; height:100vh; width:100%;">
    </iframe>
</div>
