# OpenEMR Recordatorios WhatsApp y Email

**Notificaciones automáticas de turnos vía WhatsApp y Email para OpenEMR.**
Soporte multi-vendor, configuración por centro clínico, dashboard interactivo, tracking de estado vía webhook. Incluye soporte completo para **Recordatorios (Recalls)** con entrada personalizada, secuencias programadas y envío selectivo.

---

## Características

- **WhatsApp** — Abstracción multi-vendor: UltraMsg, WaSenderAPI, OpenWA.
- **Email** — HTML enriquecido con logo incrustado, adjunto .ics, enlaces a Google Calendar y Maps.
- **Dashboard** — Analíticas con Chart.js, buscador de pacientes, timeline de estados, reenvío manual.
- **Programación** — Al agendar (inmediato), por cron (N horas antes), manual (desde el dashboard).
- **Recalls (Recordatorios)** — Secuencias programadas por días de anticipación (`days_before`), doble origen (`medex_recalls` legacy + entradas personalizadas), checkboxes selectivos con "Enviar Recalls Ahora".
- **Por centro** — Credenciales, logos, coordenadas, habilitación de canales y plantillas independientes.
- **Plantillas** — Resolución por tres niveles (exacto → categoría → comodín), remplazo de tokens.
- **Tracking de estado** — Normalización canónica de estados entre todos los vendors, actualización vía webhook.
- **Seguridad** — HMAC SHA256 (OpenWA), secreto compartido (WaSenderAPI) para validación de webhook.

---

## Proveedores WhatsApp Soportados

| Vendor | Credenciales | SDK | Sincronización |
| :--- | :--- | :--- | :--- |
| **UltraMsg** | `instance_id` + `api_key` | SDK oficial PHP | Polling REST API |
| **WaSenderAPI** | `api_key` (Bearer) | Guzzle REST | Placeholder |
| **OpenWA** | `session_id` + `api_key` (X-API-Key) | Guzzle REST | Webhook (HMAC) |

---

## Instalación

1. Clonar en `interface/modules/custom_modules/oe-module-wsp-email`.
2. Admin de OpenEMR → Modules → Manage Custom Modules → Activar `oe-module-wsp-email`.
3. El instalador crea las tablas (ver [Tablas de Base de Datos](#tablas-de-base-de-datos)).

### Migraciones para instalaciones existentes

Si se actualiza desde una versión anterior, ejecutar los scripts en orden:

```sql
-- 1. Agregar columna channel y actualizar UNIQUE KEY en wsp_email_recall
source sql/update_wsp_email_recall_add_channel.sql;

-- 2. Crear tabla auxiliar de entradas de recall (si no existe)
source sql/create_recall_entries_table.sql;
```

---

## Configuración de Cron

```cron
# WhatsApp turnos — cada hora
0 * * * * php /ruta/.../cron/cron_wsp.php site=default >> /var/log/wsp_notify.log 2>&1

# Email turnos — cada hora
0 * * * * php /ruta/.../cron/cron_email.php site=default >> /var/log/email_notify.log 2>&1

# Recalls WhatsApp+Email — diario a las 8am
0 8 * * * php /ruta/.../cron/cron_recall.php site=default >> /var/log/recall_notify.log 2>&1
```

Modo simulación (solo registra sin enviar):
```bash
php cron_wsp.php site=default dryrun=1
php cron_email.php site=default dryrun=1
php cron_recall.php --dry-run
```

---

## Recalls (Recordatorios)

### Visión General

El sistema de recalls soporta dos orígenes de datos:

1. **`medex_recalls` (legacy)** — Tabla nativa de OpenEMR. Limitada a un recall por paciente (UNIQUE KEY en `r_PRACTID, r_pid`). Utilizada por el módulo MedEx.
2. **`wsp_email_recall_entries` (personalizada)** — Tabla propia del módulo sin restricción de unicidad por paciente. Permite múltiples recalls por paciente. Se gestiona desde el panel "My Recalls" del dashboard.

Ambos orígenes se unifican mediante consultas UNION ALL, usando **IDs negativos** (`-id`) para las entradas personalizadas y evitar colisiones con los IDs positivos de `medex_recalls`.

### Programación de Recalls

Cada centro clínico define secuencias de notificación con un valor `days_before`. Para cada recall se calcula:

```
fecha_programada = r_eventDate - days_before
```

En la fecha programada, el paciente recibe una notificación WSP y/o Email (según canales habilitados). Se pueden configurar múltiples secuencias por centro (ej: 7 días antes y 1 día antes).

Configurar las secuencias en Dashboard → Config → sección Recall Schedule.

### Plantillas de Recalls

Las plantillas para recalls usan `notification_type = 'recall'` en `wsp_email_notification_templates`. Se resuelven por centro con `pc_catid = 0` y `pc_apptstatus = 'recall'`.

Tokens disponibles (además de los estándar):

| Token | Descripción |
| :--- | :--- |
| `***RECALL_DATE***` | Fecha del recall (formateada) |
| `***RECALL_REASON***` | Motivo del recall |
| `***PATIENT_NAME***` | Nombre completo del paciente |
| `***PATIENT_FIRSTNAME***` | Nombre del paciente |
| `***PATIENT_LASTNAME***` | Apellido del paciente |
| `***PROVIDER_NAME***` | Nombre del profesional |
| `***FACILITY_NAME***` | Nombre del centro |
| `***FACILITY_PHONE***` | Teléfono del centro |
| `***FACILITY_EMAIL***` | Email del centro |
| `***FACILITY_ADDRESS***` | Dirección del centro |

### Paneles del Dashboard

La pestaña Recalls en el dashboard tiene tres paneles:

1. **Active Recalls — Pending Notifications** — Muestra las secuencias próximas con badges de urgencia, filtro de horizonte, y checkboxes por fila. Usar **"Send Recalls Now"** para enviar solo a los pacientes seleccionados.

2. **My Recalls** — Gestiona entradas de recall personalizadas (no vinculadas a `medex_recalls`). Crear, editar o eliminar entradas con búsqueda de paciente, selector de profesional, centro, fecha y motivo.

3. **Search Recalls** — Búsqueda de texto completo en ambos orígenes con filtros por estado y fecha.

### Lógica de Envío

- **Cron** (`cron_recall.php`): Procesa todos los recalls pendientes respetando ventanas horarias y `fecha_programada = CURDATE()`.
- **Manual** ("Send Recalls Now"): Omite restricciones de fecha y ventana horaria. Envía solo a las filas marcadas. Envía tanto WSP como Email.

### Tracking

Las notificaciones se registran en `wsp_email_recall` con filas por canal (`channel` = WSP o Email) vinculadas por `(recall_id, seq, channel)`. Estados: PENDING, SENT, FAILED, SKIPPED. Se crea una entrada en `notification_log` por cada intento.

---

## Plantillas y Tokens

Las plantillas se almacenan en `wsp_email_notification_templates` por centro, categoría, estado, tipo de destinatario y canal. El módulo las resuelve con tres niveles de fallback:

1. Coincidencia exacta: `facility_id + pc_catid + pc_apptstatus + recipient_type + channel`
2. Coincidencia por categoría: `facility_id + pc_catid + recipient_type + channel` (cualquier estado)
3. Comodín: `facility_id + pc_catid=0 + recipient_type + channel`

### Tokens disponibles

| Token | Descripción |
| :--- | :--- |
| `***NAME***` | Nombre completo del paciente |
| `***PID***` | ID interno del paciente |
| `***PROVIDER***` | Nombre del profesional asignado |
| `***USER_PREFFIX***` | Título del profesional (MD, DDS) |
| `***DATE***` | Fecha del turno (localizada) |
| `***STARTTIME***` | Hora de inicio del turno |
| `***ENDTIME***` | Hora de fin del turno |
| `***TITLE***` | Título del turno |
| `***REASON***` | Motivo del turno |
| `***FACILITY_NAME***` | Nombre del centro |
| `***FACILITY_ADDRESS***` | Dirección completa del centro |
| `***FACILITY_PHONE***` | Teléfono del centro |
| `***FACILITY_EMAIL***` | Email del centro |
| `***FACILITY_WEBSITE***` | Sitio web del centro |
| `***FACILITY_MAP_LINK***` | Enlace a Google Maps |
| `***PATIENT_NAME***` | Nombre del paciente (plantillas de profesional) |
| `***PATIENT_ADDRESS***` | Dirección del paciente (plantillas de profesional) |
| `***PATIENT_PHONE***` | Teléfono del paciente (plantillas de profesional) |
| `***RECALL_DATE***` | Fecha del recall |
| `***RECALL_REASON***` | Motivo del recall |
| `***PATIENT_FIRSTNAME***` | Nombre del paciente |
| `***PATIENT_LASTNAME***` | Apellido del paciente |

---

## Configuración de Webhook

Para tracking de estado de entrega, apuntar la URL de webhook del vendor al módulo.

### Webhook genérico (UltraMsg, WaSenderAPI)

```
https://su-dominio.com/interface/modules/custom_modules/oe-module-wsp-email/webhook/webhook.php
```

- Valida el header `X-Webhook-Signature` contra `webhook_secret` en la configuración del centro.

### Webhook OpenWA (HMAC SHA256)

```
https://su-dominio.com/interface/modules/custom_modules/oe-module-wsp-email/webhook/openwa/webhook.php
```

- Valida el header `X-OpenWA-Signature` (HMAC SHA256) contra `openwa_webhook_secret`.
- Soporta eventos: `message.sent`, `message.ack`.

---

## Normalización de Estados

Todos los estados de los vendors se normalizan a 8 estados canónicos:

| Canónico | Prioridad | Color | Icono |
| :--- | :--- | :--- | :--- |
| `QUEUED` | 1 | Ámbar | `fa-clock` |
| `SENT` | 2 | Verde (WSP) / Azul (Email) | `fa-check` |
| `DELIVERED` | 3 | Azul | `fa-box` |
| `READ` | 4 | Púrpura | `fa-eye` |
| `FAILED` | 5 | Rojo | `fa-times-circle` |
| `INVALID` | 5 | Gris | `fa-question-circle` |
| `ERROR` | 5 | Naranja intenso | `fa-exclamation-triangle` |
| `UNSENT` | 0 | Gris claro | `fa-envelope` |

Los mapeos específicos de cada vendor están definidos en `config/config_status.messages.php`.

---

## Tablas de Base de Datos

| Tabla | Propósito |
| :--- | :--- |
| `wsp_email_facility_config` | Credenciales de vendor, logos, coordenadas, habilitación de canales por centro |
| `wsp_email_notification_schedule` | Bloques de programación de turnos (hours_before, send_on_booking, canales) |
| `wsp_email_notification_templates` | Plantillas por centro/categoría/estado/destinatario/canal (soporta `notification_type = 'appointment'` o `'recall'`) |
| `wsp_email_status_history` | Auditoría de todas las transiciones de estado |
| `wsp_email_recall_schedule` | Secuencias de recall por centro (`seq`, `days_before`, `enabled_wsp`, `enabled_email`) |
| `wsp_email_recall` | Tracking de notificaciones de recall por `(recall_id, seq, channel)` con registro de estado |
| `wsp_email_recall_entries` | Entradas personalizadas de recall (propias del módulo, sin restricción de unicidad por paciente) |

Además, columnas extendidas en `notification_log` (estado canónico, prioridad, payload del provider) y `openemr_postcalendar_events` (flag de alerta WSP).

---

## Arquitectura

```
┌──────────────┐       ┌───────────────────────────┐      ┌──────────────┐
│    Cron      │────▶  │  NotificationService      │────▶│  WspSender   │───▶ UltraMsg
│  cron_wsp/   │       │  - runWsp/runEmail         │     │  - send()    │───▶ WaSenderAPI
│  cron_email  │       │  - runOnBooking            │     │              │───▶ OpenWA
└──────────────┘       │  - deliverWsp/deliverEmail │     └──────────────┘
                       │  - syncLogStatus           │      ┌──────────────┐
┌──────────────┐       │  - getPatientsForSlot      │────▶│  EmailSender │───▶ PHPMailer
│ On-Booking   │────▶  │  - insertLog               │     │  - send()    │     SMTP
│ Hook         │       │  - markEventSent           │      └──────────────┘
└──────────────┘       └────────────────────────────┘
                              │
                     ┌───────▼─────────┐
                     │ NotificationLog │──▶ notification_log
                     │ - updateStatus  │──▶ wsp_email_status_history
                     └─────────────────┘
                              ▲
                     ┌────────┴─────────┐
                     │  StatusNormalizer│
                     │  - normalize     │
                     │  - processWebhook│
                     └──────────────────┘
                              ▲
                     ┌────────┴────────┐
                     │   Webhooks      │
                     │  webhook.php    │
                     │  openwa/        │
                     │  webhook.php    │
                     └─────────────────┘

┌──────────────┐       ┌───────────────────────────┐
│  Cron        │────▶  │  RecallService            │
│  cron_recall │       │  - runWsp/runEmail         │
└──────────────┘       │  - runAll                  │
                       │  - sendSelected            │
┌──────────────┐       │  - getPendingRecalls       │
│ Dashboard    │────▶  │  - deliverRecallWsp/Email  │
│ Send Recalls │       │  - insertRecallLog         │
│ Now          │       └────────────────────────────┘
└──────────────┘          │          │
                   medex_recalls    wsp_email_recall_entries
                   (legacy)         (personalizadas)
```

---

## Estructura de Directorios

```
src/              Clases principales (WspSender, EmailSender, NotificationService, RecallService, etc.)
config/           Configuración de mapeo de estados
cron/             Puntos de entrada para cron (cron_wsp.php, cron_email.php, cron_recall.php)
webhook/          Recepción de webhooks (genérico + OpenWA)
pages/            Dashboard UI y endpoints AJAX (dashboard.php, run_recalls_now.php, etc.)
hooks/            Hooks de integración con OpenEMR (al agendar turno)
sql/              Esquema de base de datos y migraciones
public/ics/       Archivos .ics temporales para vendors de WhatsApp
public/images/    Logos por centro clínico
docs/             Documentación extendida
logs/             Logs de ejecución (webhook, email)
```

---

## Licencia

GNU General Public License 3. Ver [OpenEMR License](https://github.com/openemr/openemr/blob/master/LICENSE).
