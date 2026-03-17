# 🚀 OpenEMR WhatsApp & Email Reminders 📅

**Automated Appointment Notifications & Interactive Dashboard for OpenEMR**

This module empowers OpenEMR with proactive patient engagement by sending automated reminders through **WhatsApp** and **Email**. It features a modern, interactive dashboard for tracking delivery performance and a per-facility configuration system.

---

## ✨ Key Features

### 📡 Multi-Channel Notifications
- **WhatsApp Integration**: Support for multiple vendors (WaSenderAPI, UltraMsg, etc.).
- **Professional Email**: Beautiful HTML templates with:
    - 🗺️ **Dynamic Maps**: Automatic links to OpenStreetMap/Google Maps.
    - 🗓️ **Calendar Support**: iCal attachments (.ics) and direct Google Calendar links.
    - 📎 **Logo Branding**: Customizable per-facility logos for a professional look.

### 📊 Powerful Dashboard
- **Analytics at a Glance**: Real-time charts (Chart.js) showing Sent, Pending, and Failed statuses.
- **Patient History**: Searchable logs to verify when and how a patient was notified.
- **Resend Capability**: Easily trigger reminders manually if a delivery fails.

### 🏢 Intelligent Facility Management
- **Granular Control**: Different API keys, templates, and coordinates per facility.
- **Smart Safeguards**: 
    - 🚫 **Inactive Lockout**: Inactive facilities are automatically set to read-only mode to prevent configuration errors.
    - 🛡️ **Interactive Warnings**: Clear visual indicators and badges for inactive centers.
- **Webhook Updates**: Automatic status tracking (Delivered, Read, etc.) via incoming vendor webhooks.

---

## 🛠️ Installation & Setup

### 1. Module Deployment
1. Download or clone this repository into:
   `/path/to/openemr/interface/modules/custom_modules/oe-module-wsp-email`
2. Log in to OpenEMR as an Administrator.
3. Navigate to **Admin → Modules → Manage Custom Modules**.
4. Locate `oe-module-wsp-email` and click **Activate**.
5. The installer will automatically set up the required database tables.

### 2. Automation (Cron Job)
Add the following lines to your server's `crontab` to ensure notifications are sent hourly:

```cron
# WhatsApp Reminders
0 * * * * php /var/www/html/openemr/interface/modules/custom_modules/oe-module-wsp-email/cron/cron_wsp.php site=default >> /var/log/wsp_notify.log 2>&1

# Email Reminders
0 * * * * php /var/www/html/openemr/interface/modules/custom_modules/oe-module-wsp-email/cron/cron_email.php site=default >> /var/log/email_notify.log 2>&1
```

---

## 📝 Message Templates & Tokens

Customize your messages using these dynamic tokens:

| Token | Description | Example |
| :--- | :--- | :--- |
| `***NAME***` | Patient's Full Name | *John Doe* |
| `***PID***` | Patient internal ID | *12345* |
| `***PROVIDER***` | Assigned Provider | *Dr. Smith* |
| `***USER_PREFFIX***` | Provider Suffix/Title | *MD, DDS* |
| `***DATE***` | Appointment Date | *Monday, Oct 25* |
| `***STARTTIME***` | Appointment Start | *10:30 AM* |
| `***ENDTIME***` | Appointment End | *11:15 AM* |
| `***TITLE***` | Appointment Title | *Consultation* |
| `***REASON***` | Appointment Reason | *Routine Checkup* |
| `***FACILITY_NAME***` | Facility Name | *City Health Center* |
| `***FACILITY_ADDRESS***` | Full Address | *123 Main St, NY* |
| `***FACILITY_PHONE***` | Facility Phone | *+1-555-0199* |
| `***FACILITY_EMAIL***` | Facility Email | *clinic@example.com* |
| `***FACILITY_WEBSITE***` | Facility Website | *https://clinic.com* |
| `***FACILITY_MAP_LINK***` | Google Maps Link | *https://www.google.com/maps/* |

---

## 🔗 Hooking up Webhooks

To track delivery status (e.g., "Message Read"), point your vendor's webhook URL to:
`https://your-domain.com/interface/modules/custom_modules/oe-module-wsp-email/webhook/webhook.php`

> [!TIP]
> Ensure the **Webhook Secret** in the Facility Config matches the `X-Webhook-Signature` header from your vendor for secure updates.

---

## 📂 Project Structure

- `cron/`: CLI automation scripts.
- `pages/`: UI components and AJAX endpoints.
- `src/`: Core logic (Sender services, Log management).
- `sql/`: Database schema definitions.
- `webhook/`: Real-time status receiver.

---

## 📜 License
Released under the **GNU General Public License 3**.  
See the [OpenEMR License](https://github.com/openemr/openemr/blob/master/LICENSE) for full details.
