# WSP/Email Notification Module - Próximos Pasos

Este documento describe las características implementadas y cómo utilizarlas.

## ✅ Características Implementadas

### 1. Hook para Notificación Inmediata (runOnBooking)

**Ubicación:** `hooks/on_booking_hook.php`

**Descripción:** 
Envía notificaciones inmediatamente cuando se crea una cita nueva (opcional para edición).

**Configuración:**
1. Asegúrate de tener al menos un slot con `send_on_booking=1`:
   ```sql
   SELECT * FROM wsp_email_notification_schedule WHERE send_on_booking = 1;
   ```

2. Integra el hook en tu código de guardado de citas:
   ```php
   require_once __DIR__ . '/modules/custom_modules/oe-module-wsp-email/hooks/on_booking_hook.php';
   
   $appointmentData = [
       'pc_eid'      => $newEid,
       'pc_pid'      => $pid,
       'pc_facility' => $facility,
       'pc_eventDate' => $eventDate,
       'pc_startTime' => $startTime,
       'pc_endTime'   => $endTime,
       'editMode'     => false, // true para edición
   ];
   
   \OpenEMR\Modules\WspEmail\OnBookingHook::onAppointmentSave($appointmentData);
   ```

**Ver ejemplos en:** `hooks/INTEGRATION_EXAMPLES.php`

---

### 2. Webhook para Actualizaciones de Estado

**Ubicación:** `webhook/webhook.php`

**Descripción:**
Recibe actualizaciones de estado de WaSenderAPI y actualiza `notification_log` y `wsp_email_status_history`.

**Eventos Soportados:**
- `messages.sent` → Estado: `sent`
- `messages.delivered` → Estado: `DELIVERED`
- `messages.read` → Estado: `READ`
- `messages.update` → Estado: variable
- `messages.upsert` → Estado: variable
- `messages.failed` → Estado: `error`

**Configuración en WaSenderAPI:**
1. URL del Webhook:
   ```
   https://tudominio.com/openemr/interface/modules/custom_modules/oe-module-wsp-email/webhook/webhook.php
   ```

2. Webhook Secret (X-Webhook-Signature):
   - Configúralo en `wsp_email_facility_config.webhook_secret`
   - Debe coincidir con el que configures en WaSenderAPI

   ```sql
   UPDATE wsp_email_facility_config 
   SET webhook_secret = 'tu_secreto_aqui' 
   WHERE facility_id = 3;
   ```

**Logs:**
- Los eventos se registran en `logs/webhook.log`
- Para ver logs en tiempo real:
  ```bash
  tail -f /var/www/html/origen.ar/hcd/interface/modules/custom_modules/oe-module-wsp-email/logs/webhook.log
  ```

**Ver estado de notificaciones:**
```sql
SELECT 
    nl.iLogId,
    nl.pid,
    CONCAT(pd.fname, ' ', pd.lname) AS paciente,
    nl.type,
    nl.status,
    nl.msg_id,
    nl.dSentDateTime,
    GROUP_CONCAT(
        CONCAT(sh.status, ' (', sh.created_at, ')')
        ORDER BY sh.created_at SEPARATOR ' -> '
    ) AS historial
FROM notification_log nl
LEFT JOIN patient_data pd ON pd.pid = nl.pid
LEFT JOIN wsp_email_status_history sh ON sh.log_id = nl.iLogId
WHERE nl.pc_eid = 139
GROUP BY nl.iLogId
ORDER BY nl.dSentDateTime DESC;
```

---

### 3. Múltiples Slots de Notificación

**Ubicación:** UI en Dashboard → Facilities → Configurar facility

**Descripción:**
Permite configurar múltiples recordatorios por cita.

**Ejemplos de Configuración:**

Ver `sql/notification_schedule_examples.sql` para ejemplos completos.

**Configuración Recomendada por Tipo de Clínica:**

| Tipo | Slots | Cuándo | Canales |
|------|-------|--------|---------|
| Consultorio pequeño | 1 | 48h antes | WhatsApp + Email |
| Clínica mediana | 2 | 72h + 24h antes | WhatsApp + Email |
| Clínica grande | 3 | On booking + 48h + 24h | WhatsApp + Email |
| Especialidades | 5+ | 7d + 3d + 2d + 1d + 2h | WhatsApp |

**Desde la UI:**
1. Ir a Dashboard → Facilities
2. Seleccionar una facility
3. En "Notification Schedule", hacer clic en "Add notification slot"
4. Configurar cada slot:
   - **Send on booking?**: Sí = enviar inmediatamente al crear
   - **Hours before appt.**: Horas antes de la cita (si no es on booking)
   - **Via WSP**: Enviar por WhatsApp
   - **Via Email**: Enviar por Email
5. Guardar configuración

---

## 📊 Verificación y Monitoreo

### Verificar Envíos

```sql
-- Últimos envíos
SELECT 
    nl.iLogId,
    nl.type,
    nl.status,
    nl.msg_id,
    CONCAT(pd.fname, ' ', pd.lname) AS paciente,
    nl.dSentDateTime
FROM notification_log nl
LEFT JOIN patient_data pd ON pd.pid = nl.pid
ORDER BY nl.iLogId DESC
LIMIT 20;

-- Estadísticas por día
SELECT 
    DATE(nl.dSentDateTime) AS fecha,
    nl.type,
    COUNT(*) AS total,
    SUM(CASE WHEN nl.status IN ('sent', 'DELIVERED', 'READ') THEN 1 ELSE 0 END) AS exitosos,
    SUM(CASE WHEN nl.status = 'error' THEN 1 ELSE 0 END) AS fallidos
FROM notification_log nl
GROUP BY DATE(nl.dSentDateTime), nl.type
ORDER BY fecha DESC, nl.type;
```

### Verificar Configuración

```sql
-- Configuración de facilities
SELECT 
    f.id,
    f.name,
    wfc.vendor,
    wfc.enabled_wsp,
    wfc.enabled_email,
    wfc.webhook_secret IS NOT NULL AS tiene_webhook_secret
FROM facility f
LEFT JOIN wsp_email_facility_config wfc ON wfc.facility_id = f.id
ORDER BY f.name;

-- Slots configurados
SELECT 
    f.name AS facility,
    wns.seq,
    CASE WHEN wns.send_on_booking = 1 THEN 'INMEDIATO'
         ELSE CONCAT(wns.hours_before, 'h antes')
    END AS cuando,
    CASE WHEN wns.enabled_wsp = 1 THEN '✓' ELSE '✗' END AS WSP,
    CASE WHEN wns.enabled_email = 1 THEN '✓' ELSE '✗' END AS Email
FROM wsp_email_notification_schedule wns
LEFT JOIN facility f ON f.id = wns.facility_id
ORDER BY f.name, wns.seq;
```

---

## 🔧 Comandos Útiles

### Ejecutar Cron Jobs Manualmente

```bash
cd /var/www/html/origen.ar/hcd/interface/modules/custom_modules/oe-module-wsp-email/cron

# WhatsApp (dry-run - no envía)
php cron_wsp.php site=default dryrun=1

# WhatsApp (envío real)
php cron_wsp.php site=default

# Email (dry-run - no envía)
php cron_email.php site=default dryrun=1

# Email (envío real)
php cron_email.php site=default
```

### Ver Logs

```bash
# Logs de WhatsApp
tail -f /var/www/html/origen.ar/hcd/interface/modules/custom_modules/oe-module-wsp-email/logs/wsp_notify.log

# Logs de Email
tail -f /var/www/html/origen.ar/hcd/interface/modules/custom_modules/oe-module-wsp-email/logs/email_notify.log

# Logs de Webhook
tail -f /var/www/html/origen.ar/hcd/interface/modules/custom_modules/oe-module-wsp-email/logs/webhook.log

# Todos los logs simultáneamente (en diferentes terminales)
tail -f logs/*.log
```

### Diagnóstico

```bash
cd /var/www/html/origen.ar/hcd/interface/modules/custom_modules/oe-module-wsp-email

# Ejecutar script de diagnóstico
php debug_notifications.php site=default
```

---

## 📝 Próximas Mejoras Sugeridas

1. **Panel de estadísticas en tiempo real** en el Dashboard
2. **Reenvío manual** de notificaciones desde la UI
3. **Plantillas de mensajes** por tipo de cita
4. **Blacklist** de pacientes que no desean recibir notificaciones
5. **Reportes PDF** de notificaciones enviadas
6. **Integración con más vendors** (Twilio, MessageBird, etc.)
7. **Notificaciones SMS** tradicionales
8. **Recordatorios por voz** (llamadas automáticas)

---

## 🆘 Soporte

Para problemas o preguntas:
1. Revisar los logs en `logs/`
2. Ejecutar `debug_notifications.php`
3. Verificar configuración en la BD
4. Consultar la documentación de WaSenderAPI: https://wasenderapi.com/docs
