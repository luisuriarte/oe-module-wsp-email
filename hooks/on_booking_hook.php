<?php
/**
 * on_booking_hook.php — Hook para notificaciones inmediatas al crear/editar citas.
 * 
 * Este archivo se llama desde el calendario de OpenEMR cuando se crea o edita una cita.
 * 
 * Uso desde OpenEMR:
 *   require_once __DIR__ . '/modules/custom_modules/oe-module-wsp-email/hooks/on_booking_hook.php';
 *   WspEmailHook::onAppointmentSave($appointment_data);
 * 
 * @package   OpenEMR\Modules\WspEmail
 * @author    Luis A. Uriarte <luis.uriarte@gmail.com>
 * @copyright Copyright (c) 2024-2025 Luis A. Uriarte
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

namespace OpenEMR\Modules\WspEmail;

// Prevenir acceso directo
if (!defined('OPENEMR')) {
    die('Direct access not permitted');
}

class OnBookingHook
{
    /**
     * Se llama cuando se guarda una cita (crear, editar o cancelar).
     * 
     * @param array $appointment Datos de la cita:
     *   - pc_eid: ID del evento
     *   - pc_pid: ID del paciente
     *   - pc_facility: ID de la facility
     *   - pc_eventDate: Fecha del evento (YYYY-MM-DD)
     *   - pc_startTime: Hora de inicio (HH:MM:SS)
     *   - pc_endTime: Hora de fin (HH:MM:SS)
     *   - pc_aid: ID del proveedor
     *   - pc_apptstatus: Estado de la cita (opcional)
     *   - editMode: true si es edición, false si es nueva
     */
    public static function onAppointmentSave(array $appointment): void
    {
        // Detectar cancelación (pc_apptstatus = 'x')
        $apptStatus = $appointment['pc_apptstatus'] ?? '';
        if (strtolower($apptStatus) === 'x') {
            $facilityId = (int)($appointment['pc_facility'] ?? 0);
            $pcEid      = (int)($appointment['pc_eid'] ?? 0);
            if ($pcEid > 0 && $facilityId > 0) {
                // Verificar flag notify_cancelled antes de enviar
                $facilityConfig = new FacilityConfig();
                $config = $facilityConfig->getByFacilityId($facilityId);
                if (!empty($config['notify_cancelled'])) {
                    try {
                        $notificationService = new NotificationService();
                        $notificationService->sendCancellation($pcEid, $facilityId);
                        error_log("WspEmail Hook: Cancellation notification sent for eid={$pcEid}");
                    } catch (\Exception $e) {
                        error_log("WspEmail Hook Cancel Error: " . $e->getMessage());
                    }
                } else {
                    error_log("WspEmail Hook: Cancellation skipped (notify_cancelled=0) for eid={$pcEid}");
                }
            }
            return;
        }

        // Notificación on-booking solo para citas nuevas
        if (!empty($appointment['editMode'])) {
            return;
        }

        $facilityId = (int)($appointment['pc_facility'] ?? 0);
        if ($facilityId === 0) {
            error_log("WspEmail Hook: Invalid facility_id for appointment");
            return;
        }

        // Verificar si hay slots de notificación inmediata configurados
        $facilityConfig = new FacilityConfig();
        $onBookingSlots = $facilityConfig->getOnBookingSlots($facilityId);

        if (empty($onBookingSlots)) {
            // No hay slots configurados para notificación inmediata
            return;
        }

        // Verificar configuración de la facility
        $config = $facilityConfig->getByFacilityId($facilityId);
        if (empty($config)) {
            error_log("WspEmail Hook: No configuration for facility_id=$facilityId");
            return;
        }

        // Solo continuar si al menos un canal está habilitado
        if (empty($config['enabled_wsp']) && empty($config['enabled_email'])) {
            return;
        }

        // Ejecutar notificaciones
        try {
            $notificationService = new NotificationService();
            $notificationService->runOnBooking($appointment);
            
            error_log("WspEmail Hook: On-booking notifications sent for appointment eid=" . ($appointment['pc_eid'] ?? 'N/A'));
        } catch (\Exception $e) {
            error_log("WspEmail Hook Error: " . $e->getMessage());
            error_log("Stack trace: " . $e->getTraceAsString());
        }
    }

    /**
     * Método alternativo que acepta solo pc_eid y obtiene los datos de la BD.
     * Útil si solo tienes el ID del evento.
     */
    public static function onAppointmentSaveById(int $pcEid): void
    {
        $sql = "SELECT pc_eid, pc_pid, pc_facility, pc_eventDate, pc_startTime, pc_endTime, pc_aid
                FROM openemr_postcalendar_events
                WHERE pc_eid = ?";
        
        $appointment = sqlQuery($sql, [$pcEid]);
        
        if ($appointment) {
            $appointment['editMode'] = false; // Asumimos que es nuevo
            self::onAppointmentSave($appointment);
        }
    }
}
