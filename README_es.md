# Recordatorios por WhatsApp y Email para OpenEMR

**Notificaciones automáticas de turnos por WhatsApp y Email para OpenEMR.**
Múltiples proveedores, configuración por centro, panel interactivo, seguimiento de estado vía webhook.

---

## Funcionalidades

- **WhatsApp** — Abstracción multi-proveedor: UltraMsg, WaSenderAPI, OpenWA.
- **Email** — HTML enriquecido con logo incrustado, archivo .ics adjunto, enlaces a Google Calendar y Google Maps.
- **Panel** — Gráficos Chart.js, búsqueda de pacientes, línea de tiempo de estados, reenvío y sincronización manual.
- **Planificación** — Al agendar (inmediato), por cron (N horas antes), manual (desde el panel).
- **Por centro** — Credenciales, logotipos, coordenadas, canales habilitados y plantillas independientes.
- **Plantillas** — Resolución de tres niveles (exacto → categoría → comodín), reemplazo de tokens.
- **Seguimiento** — Normalización canónica de estados para todos los proveedores, actualizaciones vía webhook.
- **Seguridad** — Validación HMAC SHA256 (OpenWA) y secreto compartido (WaSenderAPI) en webhooks.

---

## Proveedores de WhatsApp Soportados

| Proveedor | Credenciales | SDK | Sincronización |
| :--- | :--- | :--- | :--- |
| **UltraMsg** | `instance_id` + `api_key` | SDK oficial PHP | API REST (polling) |
| **WaSenderAPI** | `api_key` (Bearer) | Guzzle REST | Placeholder |
| **OpenWA** | `session_id` + `api_key` (X-API-Key) | Guzzle REST | Webhook (HMAC) |

---

## Instalación

1. Clonar el repositorio en `interface/modules/custom_modules/oe-module-wsp-email`.
2. OpenEMR Admin → Modules → Manage Custom Modules → Activar `oe-module-wsp-email`.
3. El instalador crea las tablas: `wsp_email_facility_config`, `wsp_email_notification_schedule`, `wsp_email_notification_templates`, `wsp_email_status_history`.

## Configuración de Cron

```cron
# WhatsApp — cada hora
0 * * * * php /ruta/a/openemr/interface/modules/custom_modules/oe-module-wsp-email/cron/cron_wsp.php site=default >> /var/log/wsp_notify.log 2>&1

# Email — cada hora
0 * * * * php /ruta/a/openemr/interface/modules/custom_modules/oe-module-wsp-email/cron/cron_email.php site=default >> /var/log/email_notify.log 2>&1
```

Modo de prueba (solo registra lo que se enviaría, sin enviar realmente):
```bash
php cron_wsp.php site=default dryrun=1
php cron_email.php site=default dryrun=1
```

---

## Plantillas de Mensajes y Tokens

Las plantillas se almacenan en `wsp_email_notification_templates` por centro, categoría, estado, tipo de destinatario y canal. El módulo las resuelve con tres niveles de fallback:

1. Coincidencia exacta: `facility_id + pc_catid + pc_apptstatus + recipient_type + channel`
2. Coincidencia por categoría: `facility_id + pc_catid + recipient_type + channel` (cualquier estado, mayor prioridad)
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
| `***PATIENT_NAME***` | Nombre del paciente (plantillas para profesional) |
| `***PATIENT_ADDRESS***` | Dirección del paciente (plantillas para profesional) |
| `***PATIENT_PHONE***` | Teléfono del paciente (plantillas para profesional) |

---

## Configuración de Webhook

Para el seguimiento de estado de entrega, configure la URL de webhook de su proveedor apuntando al módulo.

### Webhook genérico (UltraMsg, WaSenderAPI)

```
https://su-dominio.com/interface/modules/custom_modules/oe-module-wsp-email/webhook/webhook.php
```

- Valida el encabezado `X-Webhook-Signature` contra el `webhook_secret` en la configuración del centro.

### Webhook OpenWA (HMAC SHA256)

```
https://su-dominio.com/interface/modules/custom_modules/oe-module-wsp-email/webhook/openwa/webhook.php
```

- Valida el encabezado `X-OpenWA-Signature` (HMAC SHA256) contra `openwa_webhook_secret`.
- Soporta eventos: `message.sent`, `message.ack`.

---

## Normalización de Estados

Todos los estados de los proveedores se normalizan a 8 estados canónicos:

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

Los mapeos específicos por proveedor están definidos en `config/config_status.messages.php`.

---

## Tablas de Base de Datos

| Tabla | Propósito |
| :--- | :--- |
| `wsp_email_facility_config` | Credenciales, logotipos, coordenadas y canales por centro |
| `wsp_email_notification_schedule` | Ventanas de envío (horas antes, al agendar, canales) |
| `wsp_email_notification_templates` | Plantillas de mensajes por centro/categoría/estado/destinatario/canal |
| `wsp_email_status_history` | Registro histórico de todos los cambios de estado |

Además de columnas extendidas en `notification_log` (estado canónico, prioridad, payload del proveedor) y `openemr_postcalendar_events` (indicador de alerta WSP).

---

## Arquitectura General

```
┌──────────────┐     ┌───────────────────────────┐     ┌──────────────┐
│    Cron      │────▶│  NotificationService      │────▶│  WspSender   │───▶ UltraMsg
│  cron_wsp/   │     │  - runWsp/runEmail         │     │  - send()    │───▶ WaSenderAPI
│  cron_email  │     │  - runOnBooking            │     │              │───▶ OpenWA
└──────────────┘     │  - deliverWsp/deliverEmail │     └──────────────┘
                     │  - syncLogStatus           │     ┌──────────────┐
┌──────────────┐     │  - getPatientsForSlot      │────▶│  EmailSender │───▶ PHPMailer
│ On-Booking   │────▶│  - insertLog               │     │  - send()    │     SMTP
│ Hook         │     │  - markEventSent           │     └──────────────┘
└──────────────┘     └───────────────────────────┘
                              │
                     ┌───────▼────────┐
                     │ NotificationLog │──▶ tabla notification_log
                     │ - updateStatus │──▶ wsp_email_status_history
                     └────────────────┘
                              ▲
                     ┌────────┴────────┐
                     │  StatusNormalizer│
                     │  - normalize    │
                     │  - processWebhook│
                     └─────────────────┘
                              ▲
                     ┌────────┴────────┐
                     │   Webhooks     │
                     │  webhook.php    │
                     │  openwa/        │
                     │  webhook.php    │
                     └─────────────────┘
```

---

## Estructura de Directorios

```
src/              Clases principales (WspSender, EmailSender, NotificationService, etc.)
config/           Configuración de mapeo de estados
cron/             Puntos de entrada para cron (CLI)
webhook/          Receptores de webhook (genérico + OpenWA)
pages/            Panel de administración y endpoints AJAX
hooks/            Hooks de integración con OpenEMR (al agendar)
sql/              Esquema de base de datos y migraciones
public/ics/       Archivos .ics alojados temporalmente para proveedores WhatsApp
public/images/    Logotipos por centro
docs/             Documentación extendida
logs/             Registros de ejecución (webhook, email)
```

---

## Licencia

GNU General Public License 3. Ver [OpenEMR License](https://github.com/openemr/openemr/blob/master/LICENSE).
