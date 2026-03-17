<?php
/**
 * Module entry point — redirects to dashboard.
 *
 * @package   OpenEMR Module
 * @link      http://www.open-emr.org
 * @author    Luis A. Uriarte <luis.uriarte@gmail.com>
 * @copyright Copyright (c) 2024-2025 Luis A. Uriarte
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

require_once __DIR__ . '/../../../globals.php';

header('Location: ' . $web_root . '/interface/modules/custom_modules/oe-module-wsp-email/pages/dashboard.php');
exit;
