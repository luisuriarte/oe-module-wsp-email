<?php
/**
 * on_cancel_hook.php — Hook para notificaciones de cancelación de citas.
 * 
 * Se llama desde el calendario de OpenEMR cuando una cita se cancela
 * (pc_apptstatus = 'x'). Envía notificaciones WSP y/o Email usando
 * la plantilla con status -cancelled.
 *
 * Integración sugerida en library/calendar_functions.php o
 * donde se procesa el guardado de citas:
 *
 *   if ($pc_apptstatus === 'x') {
 *       require_once __DIR__ . '/interface/modules/custom_modules/oe-module-wsp-email/hooks/on_cancel_hook.php';
 *       \OpenEMR\Modules\WspEmail\OnCancelHook::onAppointmentCancel($pcEid, $facility);
 *   }
 *
 * @package   OpenEMR\Modules\WspEmail
 * @author    Luis A. Uriarte <luis.uriarte@gmail.com>
 * @copyright Copyright (c) 2024-2025 Luis A. Uriarte
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

namespace OpenEMR\Modules\WspEmail;

if (!defined('OPENEMR')) {
    die('Direct access not permitted');
}

class OnCancelHook
{
    /**
     * Se llama cuando una cita es cancelada (pc_apptstatus = 'x').
     *
     * @param int  $pcEid      ID del evento
     * @param int  $facilityId ID de la facility
     */
    public static function onAppointmentCancel(int $pcEid, int $facilityId): void
    {
        if ($pcEid <= 0 || $facilityId <= 0) {
            error_log("WspEmail CancelHook: Invalid parameters eid={$pcEid}, facility={$facilityId}");
            return;
        }

        try {
            $notificationService = new NotificationService();
            $notificationService->sendCancellation($pcEid, $facilityId);

            error_log("WspEmail CancelHook: Cancellation notification sent for eid={$pcEid}");
        } catch (\Exception $e) {
            error_log("WspEmail CancelHook Error: " . $e->getMessage());
            error_log("Stack trace: " . $e->getTraceAsString());
        }
    }
}
