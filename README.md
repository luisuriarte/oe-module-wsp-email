# OpenEMR WhatsApp & Email Reminders

**Automated appointment notifications via WhatsApp and Email for OpenEMR.**
Multi-vendor, per-facility configuration, interactive dashboard, webhook status tracking.

---

## Features

- **WhatsApp** — Multi-vendor abstraction: UltraMsg, WaSenderAPI, OpenWA.
- **Email** — Rich HTML with embedded logo, .ics calendar attachment, Google Calendar & Maps links.
- **Dashboard** — Chart.js analytics, searchable patient log, status timeline, manual resend/sync.
- **Scheduling** — On-booking (immediate), cron-based (N hours before), manual (from dashboard).
- **Per-facility** — Independent credentials, logos, coordinates, channel toggles, and templates.
- **Templates** — Three-level fallback (exact → category → wildcard), token replacement.
- **Status tracking** — Canonical status normalization across all vendors, webhook updates.
- **Security** — HMAC SHA256 (OpenWA), shared secret (WaSenderAPI) webhook validation.

---

## Supported WhatsApp Vendors

| Vendor | Credentials | SDK | Status sync |
| :--- | :--- | :--- | :--- |
| **UltraMsg** | `instance_id` + `api_key` | Official PHP SDK | Polling REST API |
| **WaSenderAPI** | `api_key` (Bearer) | Guzzle REST | Placeholder |
| **OpenWA** | `session_id` + `api_key` (X-API-Key) | Guzzle REST | Webhook (HMAC) |

---

## Installation

1. Clone into `interface/modules/custom_modules/oe-module-wsp-email`.
2. OpenEMR Admin → Modules → Manage Custom Modules → Activate `oe-module-wsp-email`.
3. Installer creates tables: `wsp_email_facility_config`, `wsp_email_notification_schedule`, `wsp_email_notification_templates`, `wsp_email_status_history`.

## Cron Setup

```cron
# WhatsApp — every hour
0 * * * * php /path/to/openemr/interface/modules/custom_modules/oe-module-wsp-email/cron/cron_wsp.php site=default >> /var/log/wsp_notify.log 2>&1

# Email — every hour
0 * * * * php /path/to/openemr/interface/modules/custom_modules/oe-module-wsp-email/cron/cron_email.php site=default >> /var/log/email_notify.log 2>&1
```

Dry-run mode (logs what would be sent without actually sending):
```bash
php cron_wsp.php site=default dryrun=1
php cron_email.php site=default dryrun=1
```

---

## Message Templates & Tokens

Templates are stored in `wsp_email_notification_templates` per facility, category, status, recipient type, and channel. The module resolves them with a three-level fallback:

1. Exact match: `facility_id + pc_catid + pc_apptstatus + recipient_type + channel`
2. Category match: `facility_id + pc_catid + recipient_type + channel` (any status, highest priority)
3. Wildcard: `facility_id + pc_catid=0 + recipient_type + channel`

### Available tokens

| Token | Description |
| :--- | :--- |
| `***NAME***` | Patient full name |
| `***PID***` | Patient internal ID |
| `***PROVIDER***` | Assigned provider name |
| `***USER_PREFFIX***` | Provider suffix/title (MD, DDS) |
| `***DATE***` | Appointment date (localized) |
| `***STARTTIME***` | Appointment start time |
| `***ENDTIME***` | Appointment end time |
| `***TITLE***` | Appointment title |
| `***REASON***` | Appointment reason |
| `***FACILITY_NAME***` | Facility name |
| `***FACILITY_ADDRESS***` | Facility full address |
| `***FACILITY_PHONE***` | Facility phone |
| `***FACILITY_EMAIL***` | Facility email |
| `***FACILITY_WEBSITE***` | Facility website |
| `***FACILITY_MAP_LINK***` | Google Maps link |
| `***PATIENT_NAME***` | Patient name (provider templates) |
| `***PATIENT_ADDRESS***` | Patient address (provider templates) |
| `***PATIENT_PHONE***` | Patient phone (provider templates) |

---

## Webhook Setup

For delivery status tracking, point your vendor's webhook URL to the module.

### Generic webhook (UltraMsg, WaSenderAPI)

```
https://your-domain.com/interface/modules/custom_modules/oe-module-wsp-email/webhook/webhook.php
```

- Validates `X-Webhook-Signature` header against `webhook_secret` in facility config.

### OpenWA webhook (HMAC SHA256)

```
https://your-domain.com/interface/modules/custom_modules/oe-module-wsp-email/webhook/openwa/webhook.php
```

- Validates `X-OpenWA-Signature` header (HMAC SHA256) against `openwa_webhook_secret`.
- Supports events: `message.sent`, `message.ack`.

---

## Status Normalization

All vendor statuses are normalized to 8 canonical states:

| Canonical | Priority | Color | Icon |
| :--- | :--- | :--- | :--- |
| `QUEUED` | 1 | Amber | `fa-clock` |
| `SENT` | 2 | Green (WSP) / Blue (Email) | `fa-check` |
| `DELIVERED` | 3 | Blue | `fa-box` |
| `READ` | 4 | Purple | `fa-eye` |
| `FAILED` | 5 | Red | `fa-times-circle` |
| `INVALID` | 5 | Gray | `fa-question-circle` |
| `ERROR` | 5 | Deep Orange | `fa-exclamation-triangle` |
| `UNSENT` | 0 | Light Gray | `fa-envelope` |

Vendor-specific mappings are defined in `config/config_status.messages.php`.

---

## Database Tables

| Table | Purpose |
| :--- | :--- |
| `wsp_email_facility_config` | Per-facility vendor credentials, logos, coordinates, channel toggles |
| `wsp_email_notification_schedule` | Schedule slots (hours_before, send_on_booking, channel enablers) |
| `wsp_email_notification_templates` | Message templates per facility/category/status/recipient/channel |
| `wsp_email_status_history` | Audit trail of all status transitions |

Plus extended columns on `notification_log` (canonical status, priority, provider payload) and `openemr_postcalendar_events` (WSP alert flag).

---

## Architecture Overview

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
                     │ NotificationLog │──▶ notification_log table
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
```

---

## Directory Layout

```
src/              Core classes (WspSender, EmailSender, NotificationService, etc.)
config/           Status mapping configuration
cron/             CLI cron entry points
webhook/          Webhook receivers (generic + OpenWA)
pages/            Dashboard UI and AJAX endpoints
hooks/            OpenEMR integration hooks (on-booking)
sql/              Database schema and migrations
public/ics/       Temporarily hosted .ics files for WhatsApp vendors
public/images/    Per-facility logos
docs/             Extended documentation
logs/             Runtime logs (webhook, email)
```

---

## License

GNU General Public License 3. See [OpenEMR License](https://github.com/openemr/openemr/blob/master/LICENSE).
