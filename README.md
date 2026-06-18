# OpenEMR WhatsApp & Email Reminders

**Automated appointment notifications via WhatsApp and Email for OpenEMR.**
Multi-vendor, per-facility configuration, interactive dashboard, webhook status tracking. Includes **Recall notification** support with custom recall entries, schedule-based sequences, and selective sending.

---

## Features

- **WhatsApp** — Multi-vendor abstraction: UltraMsg, WaSenderAPI, OpenWA.
- **Email** — Rich HTML with embedded logo, .ics calendar attachment, Google Calendar & Maps links.
- **Dashboard** — Chart.js analytics, searchable patient log, status timeline, manual resend/sync.
- **Scheduling** — On-booking (immediate), cron-based (N hours before), manual (from dashboard).
- **Recalls** — Schedule-based recall sequences (`days_before`), dual source (`medex_recalls` legacy + custom entries), selective checkboxes with "Send Recalls Now".
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
3. Installer creates tables (see [Database Tables](#database-tables) below).

### Migrations for existing installations

If upgrading from a previous version, run the migration scripts in order:

```sql
-- 1. Add channel column and update unique key on wsp_email_recall
source sql/update_wsp_email_recall_add_channel.sql;

-- 2. Create the auxiliary recall entries table (if not present)
source sql/create_recall_entries_table.sql;
```

---

## Cron Setup

```cron
# Appointment WSP — every hour
0 * * * * php /path/to/.../cron/cron_wsp.php site=default >> /var/log/wsp_notify.log 2>&1

# Appointment Email — every hour
0 * * * * php /path/to/.../cron/cron_email.php site=default >> /var/log/email_notify.log 2>&1

# Recalls WSP+Email — daily at 8am
0 8 * * * php /path/to/.../cron/cron_recall.php site=default >> /var/log/recall_notify.log 2>&1
```

Dry-run mode (logs what would be sent without actually sending):
```bash
php cron_wsp.php site=default dryrun=1
php cron_email.php site=default dryrun=1
php cron_recall.php --dry-run
```

---

## Recalls

### Overview

The recall system supports two data sources:

1. **`medex_recalls` (legacy)** — OpenEMR's built-in recall table. Limited to one recall per patient (UNIQUE KEY on `r_PRACTID, r_pid`). Used by the MedEx module.
2. **`wsp_email_recall_entries` (custom)** — Module-owned table with no per-patient uniqueness constraint. Allows multiple recalls per patient. Managed via the "My Recalls" panel in the dashboard.

Both sources are unified via UNION ALL queries, using **negative IDs** (`-id`) for custom entries to avoid collisions with `medex_recalls` positive IDs.

### Recall Schedule

Per-facility schedules define notification sequences with a `days_before` value. For each recall, the module calculates:

```
scheduled_for = r_eventDate - days_before
```

On the scheduled date, the patient receives a WSP and/or Email notification (depending on channel configuration). Multiple sequences can be configured per facility (e.g., 7 days before and 1 day before).

Configure sequences in the Dashboard → Config tab → Recall Schedule section.

### Recall Templates

Templates for recalls use `notification_type = 'recall'` in `wsp_email_notification_templates`. They are resolved per facility with `pc_catid = 0` and `pc_apptstatus = 'recall'`.

Available tokens (in addition to the standard ones):

| Token | Description |
| :--- | :--- |
| `***RECALL_DATE***` | Recall event date (formatted) |
| `***RECALL_REASON***` | Recall reason text |
| `***PATIENT_NAME***` | Patient full name |
| `***PATIENT_FIRSTNAME***` | Patient first name |
| `***PATIENT_LASTNAME***` | Patient last name |
| `***PROVIDER_NAME***` | Provider full name |
| `***FACILITY_NAME***` | Facility name |
| `***FACILITY_PHONE***` | Facility phone |
| `***FACILITY_EMAIL***` | Facility email |
| `***FACILITY_ADDRESS***` | Facility address |

### Dashboard Panels

The Recalls tab in the dashboard provides three panels:

1. **Active Recalls — Pending Notifications** — Lists upcoming recall sequences with urgency badges, scheduling horizon filter, and per-row checkboxes. Use **"Send Recalls Now"** to send to selected patients only.

2. **My Recalls** — Manage custom recall entries (not tied to `medex_recalls`). Create, edit, or delete entries with patient search, provider dropdown, facility, date, and reason.

3. **Search Recalls** — Full-text search across both data sources with status and date filters.

### Sending Logic

- **Cron** (`cron_recall.php`): Processes all pending recalls respecting time/day windows and `scheduled_for = CURDATE()`.
- **Manual** ("Send Recalls Now"): Bypasses date and time-window restrictions. Sends only to checked rows. Sends both WSP and Email channels.

### Tracking

Notifications are tracked in `wsp_email_recall` with per-channel rows (`channel` = WSP or Email) linked by `(recall_id, seq, channel)`. Statuses: PENDING, SENT, FAILED, SKIPPED. A `notification_log` entry is created for each attempt.

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
| `***RECALL_DATE***` | Recall event date |
| `***RECALL_REASON***` | Recall reason |
| `***PATIENT_FIRSTNAME***` | Patient first name |
| `***PATIENT_LASTNAME***` | Patient last name |

---

## Webhook Setup

For delivery status tracking, point your vendor's webhook URL to the module.

### Generic webhook (UltraMsg, WaSenderAPI)

```
https://your-domain.com/webhook/ultramsg/webhook.php
```

- Validates `X-Webhook-Signature` header against `webhook_secret` in facility config.

### OpenWA webhook (HMAC SHA256)

```
https://your-domain.com/webhook/openwa/webhook.php
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
| `wsp_email_notification_schedule` | Appointment schedule slots (hours_before, send_on_booking, channel enablers) |
| `wsp_email_notification_templates` | Message templates per facility/category/status/recipient/channel (supports `notification_type = 'appointment'` or `'recall'`) |
| `wsp_email_status_history` | Audit trail of all status transitions |
| `wsp_email_recall_schedule` | Per-facility recall sequences (`seq`, `days_before`, `enabled_wsp`, `enabled_email`) |
| `wsp_email_recall` | Recall notification tracking per `(recall_id, seq, channel)` with status logging |
| `wsp_email_recall_entries` | Custom recall entries (module-owned, no per-patient uniqueness constraint) |

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
                   (legacy)         (custom entries)
```

---

## Directory Layout

```
src/              Core classes (WspSender, EmailSender, NotificationService, RecallService, etc.)
config/           Status mapping configuration
cron/             CLI cron entry points (cron_wsp.php, cron_email.php, cron_recall.php)
webhook/          Webhook receivers (generic + OpenWA)
pages/            Dashboard UI and AJAX endpoints (dashboard.php, run_recalls_now.php, etc.)
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
