# oe-module-wsp-email

**WhatsApp & Email Appointment Notification Module for OpenEMR**

Sends automatic appointment reminders to patients via WhatsApp and/or Email,
tracks delivery status via webhook, and provides a dashboard with charts.

---

## Features

- **Multi-vendor WhatsApp** support: WaSenderAPI, WaApi, UltraMsg
- **Email** with HTML template, static map (Geoapify), Google Calendar link, and iCal attachment
- **Dashboard** — Charts (Chart.js) + summary cards for sent/pending/failed messages
- **Patient Status** tab — Search notification history + webhook delivery status
- **Facility Configuration** — Per-facility API keys, logos, coordinates, message templates
- **Webhook receiver** — Automatically updates `notification_log.status` when the vendor reports delivery

---

## Installation

1. Copy (or symlink) this folder to:
   ```
   /openemr/interface/modules/custom_modules/oe-module-wsp-email
   ```
2. Go to **Admin → Modules → Manage Custom Modules** in OpenEMR.
3. Click **Activate** next to `oe-module-wsp-email`.
4. The installer will run `sql/install.sql` automatically.

---

## Cron Setup

Add to your `crontab`:

```cron
# WhatsApp notifications — runs every hour
0 * * * * php /path/to/openemr/interface/modules/custom_modules/oe-module-wsp-email/cron/cron_wsp.php site=default >> /var/log/wsp_notify.log 2>&1

# Email notifications — runs every hour
0 * * * * php /path/to/openemr/interface/modules/custom_modules/oe-module-wsp-email/cron/cron_email.php site=default >> /var/log/email_notify.log 2>&1
```

**Dry-run test** (no messages sent):
```bash
php cron/cron_wsp.php site=default dryrun=1
```

---

## Webhook Configuration

Point your vendor's webhook URL to:
```
https://your-site/openemr/interface/modules/custom_modules/oe-module-wsp-email/webhook/webhook.php
```

Set `X-Webhook-Signature` in the vendor panel to match the **Webhook Secret**
stored in the facility configuration form.

---

## Message Template Tokens

Use these tokens in WSP and Email templates — they are replaced at send time:

| Token | Replaced with |
|-------|---------------|
| `***NAME***` | Patient full name |
| `***PROVIDER***` | Provider name |
| `***USER_PREFFIX***` | Provider title/suffix |
| `***DATE***` | Appointment date (long format) |
| `***STARTTIME***` | Appointment start time |
| `***ENDTIME***` | Appointment end time |
| `***FACILITY_NAME***` | Facility name |
| `***FACILITY_ADDRESS***` | Facility address |
| `***FACILITY_PHONE***` | Facility phone |
| `***FACILITY_EMAIL***` | Facility email |

---

## Directory Structure

```
oe-module-wsp-email/
├── cron/
│   ├── cron_email.php        CLI cron for Email notifications
│   └── cron_wsp.php          CLI cron for WhatsApp notifications
├── logs/                     Webhook log files
├── pages/
│   ├── ajax/
│   │   ├── get_facility_config.php
│   │   ├── get_patient_logs.php
│   │   ├── get_stats.php
│   │   ├── resend_notification.php
│   │   └── save_facility_config.php
│   └── dashboard.php         Main UI (4 tabs)
├── public/
│   └── ics/                  Temporary .ics files for WSP delivery
├── sql/
│   └── install.sql           DB install script
├── src/
│   ├── EmailSender.php
│   ├── FacilityConfig.php
│   ├── NotificationLog.php
│   ├── NotificationService.php
│   └── WspSender.php
├── webhook/
│   └── webhook.php           Delivery status webhook receiver
├── composer.json
├── index.php
├── moduleConfig.php
├── openemr.bootstrap.php
├── version.php
└── README.md
```

---

## License

GNU General Public License 3 — https://github.com/openemr/openemr/blob/master/LICENSE
