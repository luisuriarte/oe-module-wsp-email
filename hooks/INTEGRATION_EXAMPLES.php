/**
 * Ejemplo de integración del hook de notificación en OpenEMR.
 * 
 * Para usar las notificaciones automáticas al crear citas,
 * agrega la siguiente llamada en el archivo donde se guardan las citas:
 * 
 * Archivo sugerido: /library/calendar_functions.php
 * o donde se procesa el guardado de citas en tu instalación.
 */

// =========================================================================
// OPCIÓN 1: Llamar directamente después de guardar la cita
// =========================================================================

// Después de guardar la cita exitosamente (después del INSERT/UPDATE):
require_once __DIR__ . '/modules/custom_modules/oe-module-wsp-email/hooks/on_booking_hook.php';

// Crear array con los datos de la cita
$appointmentData = [
    'pc_eid'      => $newEid,        // ID del evento (después del insert)
    'pc_pid'      => $pid,           // ID del paciente
    'pc_facility' => $facility,      // ID de la facility
    'pc_eventDate' => $eventDate,    // YYYY-MM-DD
    'pc_startTime' => $startTime,    // HH:MM:SS
    'pc_endTime'   => $endTime,      // HH:MM:SS
    'pc_aid'       => $providerId,   // ID del proveedor
    'editMode'     => false,         // false = nuevo, true = edición
];

// Llamar al hook
\OpenEMR\Modules\WspEmail\OnBookingHook::onAppointmentSave($appointmentData);


// =========================================================================
// OPCIÓN 2: Usar solo el ID del evento
// =========================================================================

// Si ya tienes el pc_eid después de guardar:
require_once __DIR__ . '/modules/custom_modules/oe-module-wsp-email/hooks/on_booking_hook.php';
\OpenEMR\Modules\WspEmail\OnBookingHook::onAppointmentSaveById($newEid);


// =========================================================================
// OPCIÓN 3: Integrar en post_calendar_events.php (si existe en tu versión)
// =========================================================================

// Buscar en el código de OpenEMR donde se guarda la cita y agregar:
/*
if ($mode == 'new' && !empty($newEid)) {
    // Notificación inmediata al crear cita
    require_once __DIR__ . '/modules/custom_modules/oe-module-wsp-email/hooks/on_booking_hook.php';
    \OpenEMR\Modules\WspEmail\OnBookingHook::onAppointmentSaveById($newEid);
}
*/


// =========================================================================
// CANCELACIÓN DE CITAS — Notificación al cancelar (AUTOMÁTICO)
// =========================================================================

// Si ya tenés integrado OnBookingHook::onAppointmentSave(), la cancelación
// se detecta automáticamente: cuando pc_apptstatus = 'x', el hook envía
// la notificación usando la plantilla con status -cancelled.
//
// No requiere cambios adicionales si ya llamás a onAppointmentSave().
//
// Si preferís un hook separado, usá on_cancel_hook.php:
//
//   if ($pc_apptstatus === 'x') {
//       require_once __DIR__ . '/interface/modules/custom_modules/oe-module-wsp-email/hooks/on_cancel_hook.php';
//       \OpenEMR\Modules\WspEmail\OnCancelHook::onAppointmentCancel($pcEid, $facility);
//   }
//
// El hook de cancelación:
// 1. Busca la plantilla con status -cancelled en wsp_email_notification_templates
// 2. Envía WSP y/o Email según la configuración de la facility
// 3. Registra el envío en notification_log con notification_seq = 0
// 4. Requiere que la plantilla -cancelled esté configurada en el Template Manager


// =========================================================================
// Notas importantes:
// =========================================================================
// 1. El hook solo envía notificaciones si hay slots configurados con 
//    send_on_booking=1 en wsp_email_notification_schedule
// 2. El hook verifica las banderas enabled_wsp y enabled_email
// 3. El hook verifica los permisos HIPAA del paciente
// 4. Para edición de citas, usa editMode=true para NO re-enviar notificaciones
// 5. Para cancelación, el template debe tener pc_apptstatus = -cancelled
