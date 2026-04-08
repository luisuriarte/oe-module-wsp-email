# Provider Notifications - Telehealth & HBC

## Overview

The Schedule tab now supports sending WhatsApp and Email notifications **directly to providers** (doctors) for Telehealth and HBC appointments.

## UI Changes

### Schedule Table - Action Buttons

Each appointment row now shows **two rows of buttons**:

1. **Patient buttons** (always visible if HIPAA consent exists):
   - `📱 WhatsApp Pt` - Send WhatsApp to patient
   - `📧 Email Pt` - Send Email to patient

2. **Provider buttons** (only for Telehealth `catid=80` and HBC `catid=70/71`):
   - `📱 WhatsApp Dr` - Send WhatsApp to provider
   - `📧 Email Dr` - Send Email to provider

### Modal Changes

When opening a provider notification, the modal now shows:
- **Recipient badge**: `👨‍⚕️ Provider` (yellow) vs `👤 Patient` (blue)
- **Contact info**: Provider's phone or email from `users` table

## Database Changes

### `get_schedules.php`
Now returns additional fields:
- `provider_phone` - from `users.phone`
- `provider_email` - from `users.email`

## Template Tokens

### Provider Templates (`recipient_type='provider'`)

These tokens are replaced in provider notifications:

| Token | Description | Example |
|-------|-------------|---------|
| `***PROVIDER***` | Provider name | Dr. Juan Pérez |
| `***PATIENT_NAME***` | Patient full name | Ana Rodriguez |
| `***PATIENT_ADDRESS***` | Patient address | Calle 123, Ciudad |
| `***PATIENT_PHONE***` | Patient phone | +5493404540440 |
| `***DATE***` | Appointment date | 08/04/2026 |
| `***STARTTIME***` | Start time | 14:30 |
| `***FACILITY_NAME***` | Facility name | Centro de Salud |
| `***VIDEO_ROOM***` | Virtual room (Telehealth) | room-123 |
| `***VIDEO_LINK***` | Meeting link | https://jitsi.example.com/room-123 |
| `***VISIT_ADDRESS***` | Visit address (HBC) | Same as PATIENT_ADDRESS |
| `***VISIT_INSTRUCTIONS***` | Special instructions | N/A |

### Patient Tokens (backward compatible)

Patient templates use these tokens:
- `***NAME***` - Patient name
- `***PROVIDER***` - Provider name
- `***DATE***`, `***STARTTIME***`, `***ENDTIME***`
- `***FACILITY_NAME***`, `***FACILITY_ADDRESS***`, `***FACILITY_PHONE***`, `***FACILITY_EMAIL***`
- `***FACILITY_MAP_LINK***`, `***FACILITY_WEBSITE***`
- `***VIDEO_LINK***`, `***VIDEO_ROOM***`, `***VIDEO_PASSWORD***`
- `***PATIENT_NAME***`, `***PATIENT_ADDRESS***`, `***PATIENT_PHONE***` (new)

## Setup

### 1. Configure Provider Contact Info

Ensure providers have phone/email in OpenEMR `users` table:
- `users.phone` - For WhatsApp notifications
- `users.email` - For Email notifications

### 2. Customize Templates

1. Go to Dashboard → Facility Configuration
2. Click "Manage Templates" button
3. Filter by `recipient_type = provider`
4. Edit WhatsApp messages and Email subjects/bodies

## How It Works

1. User clicks `WhatsApp Dr` or `Email Dr` button on a Telehealth/HBC appointment
2. System fetches provider template from `wsp_email_notification_templates` where `recipient_type='provider'`
3. Tokens are replaced with patient and appointment data
4. Modal opens with pre-filled message
5. User can edit the message if needed
6. Click "Open & Log":
   - **WhatsApp**: Opens `wa.me/{provider_phone}?text={message}`
   - **Email**: Sends via PHPMailer and logs the result
7. Notification is recorded in `notification_log` with `recipient='provider'`

## Technical Details

### Files Modified

| File | Changes |
|------|---------|
| `pages/ajax/get_schedules.php` | Added `provider_phone`, `provider_email` to response; ORDER BY DESC |
| `pages/dashboard.php` | Added provider buttons, updated `openScheduleNotify()` to handle `recipient` param |
| `pages/ajax/get_notification_template.php` | Already supports `recipient` param (no changes needed) |
| `pages/ajax/log_manual_notify.php` | Already supports `recipient` param (no changes needed) |

### New Files

| File | Purpose |
|------|---------|
| `docs/PROVIDER_NOTIFICATIONS.md` | This documentation |

### Code Flow

```
User clicks "WhatsApp Dr"
  ↓
openScheduleNotify(... recipient='provider' ...)
  ↓
Fetch template WHERE recipient_type='provider'
  ↓
Replace tokens (***PATIENT_NAME***, ***PROVIDER***, etc.)
  ↓
Show modal with provider badge and contact info
  ↓
User clicks "Open & Log"
  ↓
executeManualNotify()
  ↓
Log to notification_log (recipient='provider')
  ↓
Open wa.me link or send email via PHPMailer
```

## Troubleshooting

### Provider buttons not showing
- Check appointment `pc_catid`: must be 80 (Telehealth) or 70/71 (HBC)
- Check provider has phone/email in `users` table

### Template not found
- Check `wsp_email_notification_templates` has rows with `recipient_type='provider'`
- Ensure `enabled=1`
- Run `sql/add_provider_templates.sql` if missing

### Phone format error (WhatsApp)
- Provider phone must be in international format (e.g., +5493404540440)
- wa.me will clean the number automatically
