-- ===========================================================================
-- install.sql  -  oe-module-wsp-email
-- Executed automatically by the Module Manager when activating the module.
--
-- This script creates:
-- - wsp_email_facility_config: Extended facility configuration for WhatsApp/Email
--   (supports multiple vendors: UltraMsg, WaSenderAPI, etc.)
-- - wsp_email_notification_schedule: When notifications are sent per facility
-- - wsp_email_status_history: Timeline of status changes for each notification
-- - Adds columns to openemr_postcalendar_events and notification_log
--
-- Note: Tables openemr_postcalendar_events and notification_log exist in
-- OpenEMR core. We only add columns via ALTER TABLE.
-- ===========================================================================

-- ---------------------------------------------------------------------------
-- Extended per-facility configuration table
-- Supports multiple WhatsApp vendors per facility with active vendor selection
-- AND telehealth module settings (Jitsi, notifications, templates).
-- Uses facility_id=0 for global/module-level telehealth defaults.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `wsp_email_facility_config` (
  `id`                  int(11)       NOT NULL AUTO_INCREMENT,
  `facility_id`         int(11)       NOT NULL                   COMMENT 'FK -> facility.id (0 = global telehealth settings)',

  -- Active vendor selection
  `current_vendor`      varchar(50)   NOT NULL DEFAULT 'wasenderapi' COMMENT 'Active vendor: ultramsg|wasenderapi',

  -- Common fields (for backward compatibility)
  `vendor`              varchar(50)   NOT NULL DEFAULT 'wasenderapi' COMMENT 'Default vendor (deprecated, use current_vendor)',
  `vendor_instance`     varchar(100)  DEFAULT NULL               COMMENT 'Vendor instance ID (deprecated)',
  `vendor_api_key`      varchar(255)  DEFAULT NULL               COMMENT 'API Key (deprecated)',
  `webhook_secret`      varchar(255)  DEFAULT NULL               COMMENT 'Webhook secret (deprecated)',

  -- UltraMsg specific credentials
  `ultramsg_instance`   varchar(100)  DEFAULT NULL               COMMENT 'UltraMsg instance ID (e.g., instance41076)',
  `ultramsg_api_key`    varchar(255)  DEFAULT NULL               COMMENT 'UltraMsg API token',

  -- WaSenderAPI specific credentials
  `wasenderapi_api_key`      varchar(255)  DEFAULT NULL         COMMENT 'WaSenderAPI Bearer token',
  `wasenderapi_webhook_secret` varchar(255) DEFAULT NULL        COMMENT 'WaSenderAPI webhook secret',

  -- Common configuration
  `logo_wsp`            varchar(255)  DEFAULT NULL               COMMENT 'Public URL of the logo sent via WhatsApp',
  `logo_email`          varchar(255)  DEFAULT NULL               COMMENT 'Absolute server path of the logo embedded in emails',
  `latitude`            decimal(10,6) DEFAULT NULL               COMMENT 'Facility geographic latitude',
  `longitude`           decimal(10,6) DEFAULT NULL               COMMENT 'Facility geographic longitude',
  `geoapify_key`        varchar(255)  DEFAULT NULL               COMMENT 'Geoapify API key for static maps in email',
  `wsp_message`         text          DEFAULT NULL               COMMENT 'WhatsApp message template (supports token replacement)',
  `email_message`       text          DEFAULT NULL               COMMENT 'HTML body template for the notification email',
  `email_subject`       varchar(255)  DEFAULT NULL               COMMENT 'Email notification subject line',
  `notify_hours_before` int(5)        NOT NULL DEFAULT 48        COMMENT 'Hours before the appointment to send the notification',
  `enabled_wsp`         tinyint(1)    NOT NULL DEFAULT 1         COMMENT '1=enabled, 0=disabled',
  `enabled_email`       tinyint(1)    NOT NULL DEFAULT 1         COMMENT '1=enabled, 0=disabled',

  -- ===================================================================
  -- TELEHEALTH SETTINGS (Jitsi + Notifications + Security)
  -- ===================================================================
  `th_jitsi_domain`             varchar(255)  DEFAULT NULL           COMMENT 'Jitsi Meet server domain (e.g. meet.yourdomain.com)',
  `th_jitsi_base_url`           varchar(500)  DEFAULT NULL           COMMENT 'Full Jitsi base URL (e.g. https://meet.yourdomain.com)',
  `th_room_prefix`              varchar(50)   DEFAULT 'th-'          COMMENT 'Prefix for Jitsi room names',
  `th_jwt_enabled`              tinyint(1)    NOT NULL DEFAULT 0     COMMENT '1=JWT authentication enabled for Jitsi',
  `th_jwt_app_id`               varchar(255)  DEFAULT NULL           COMMENT 'Jitsi JWT application ID',
  `th_jwt_app_secret`           varchar(255)  DEFAULT NULL           COMMENT 'Jitsi JWT signing secret',
  `th_default_duration`         int(11)       NOT NULL DEFAULT 30    COMMENT 'Default session duration in minutes',
  `th_geolocation_enabled`      tinyint(1)    NOT NULL DEFAULT 1     COMMENT '1=patient geolocation capture enabled',
  `th_recording_enabled`        tinyint(1)    NOT NULL DEFAULT 0     COMMENT '1=session recording enabled (requires Jibri)',
  `th_fallback_minutes`         int(11)       NOT NULL DEFAULT 5     COMMENT 'Minutes before sending fallback link to provider mobile',
  `th_default_patient_channel`  varchar(20)   DEFAULT 'whatsapp'     COMMENT 'Default patient notification channel',
  `th_default_provider_channel` varchar(20)   DEFAULT 'internal'     COMMENT 'Default provider notification channel',
  `th_webhook_token`            varchar(255)  DEFAULT NULL           COMMENT 'Bearer token for Jitsi webhook authentication',
  `th_enabled`                  tinyint(1)    NOT NULL DEFAULT 1     COMMENT '1=telehealth notifications enabled for this facility, 0=disabled',

  -- ===================================================================
  -- TELEHEALTH MESSAGE TEMPLATES (***TOKEN*** tag system)
  -- ===================================================================
  `th_patient_template`         text          DEFAULT NULL           COMMENT 'Telehealth WhatsApp message template for patients',
  `th_provider_template`        text          DEFAULT NULL           COMMENT 'Telehealth WhatsApp message template for providers',

  -- Audit
  `created_at`          datetime      DEFAULT CURRENT_TIMESTAMP,
  `updated_at`          datetime      DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_facility_id` (`facility_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
  COMMENT='Extended WhatsApp/Email + Telehealth configuration per facility';

-- ---------------------------------------------------------------------------
-- Add pc_sendalertwsp column to openemr_postcalendar_events (if not present)
-- Tracks whether a WhatsApp alert has already been sent for this appointment.
-- Kept separate from pc_sendalertsms (SMS) to allow independent tracking.
-- ---------------------------------------------------------------------------
ALTER TABLE `openemr_postcalendar_events`
  ADD COLUMN IF NOT EXISTS `pc_sendalertwsp` varchar(3) NOT NULL DEFAULT 'NO'
  COMMENT 'YES if a WhatsApp alert has been sent for this event';

-- ---------------------------------------------------------------------------
-- Ensure notification_log.type ENUM includes WSP
-- (legacy tables on some installations may be missing this value)
-- ---------------------------------------------------------------------------
ALTER TABLE `notification_log`
  MODIFY COLUMN `type` enum('SMS','Email','WSP') NOT NULL DEFAULT 'SMS';

-- ---------------------------------------------------------------------------
-- Ensure msg_id, status and notification_seq columns exist in notification_log
-- (some older installations may not have these columns)
-- notification_seq tracks which schedule slot was used (1st, 2nd, 3rd send, etc.)
-- ---------------------------------------------------------------------------
ALTER TABLE `notification_log`
  ADD COLUMN IF NOT EXISTS `msg_id`           varchar(100) DEFAULT NULL COMMENT 'Message ID returned by the gateway vendor',
  ADD COLUMN IF NOT EXISTS `status`           varchar(50)  DEFAULT NULL COMMENT 'Delivery status updated via webhook',
  ADD COLUMN IF NOT EXISTS `notification_seq` tinyint(3)   NOT NULL DEFAULT 1 COMMENT 'Sequence number of this notification (1=first, 2=second, etc.)';

-- ---------------------------------------------------------------------------
-- Add status normalization columns to notification_log
-- These columns support multi-provider WhatsApp status tracking
-- ---------------------------------------------------------------------------

-- status_current: Normalized canonical status (QUEUED, SENT, DELIVERED, READ, FAILED, etc.)
ALTER TABLE `notification_log`
  ADD COLUMN IF NOT EXISTS `status_current` varchar(50) DEFAULT NULL
  COMMENT 'Current canonical status (QUEUED, SENT, DELIVERED, READ, FAILED, etc.)'
  AFTER `status`;

-- provider_raw_status: Raw status received from provider (e.g., ack, sending, device_offline)
ALTER TABLE `notification_log`
  ADD COLUMN IF NOT EXISTS `provider_raw_status` varchar(100) DEFAULT NULL
  COMMENT 'Raw status received from provider (e.g., ack, sending, etc.)'
  AFTER `status_current`;

-- status_priority: Numeric priority for status ordering (higher = more advanced/final)
ALTER TABLE `notification_log`
  ADD COLUMN IF NOT EXISTS `status_priority` tinyint(3) DEFAULT 0
  COMMENT 'Status priority (higher = more advanced/final)'
  AFTER `provider_raw_status`;

-- provider_payload: Complete JSON payload from provider webhook for debugging
ALTER TABLE `notification_log`
  ADD COLUMN IF NOT EXISTS `provider_payload` text DEFAULT NULL
  COMMENT 'Complete JSON payload from provider webhook'
  AFTER `status_priority`;

-- Add indexes for efficient status queries
ALTER TABLE `notification_log`
  ADD INDEX IF NOT EXISTS `idx_status_current` (`status_current`);

ALTER TABLE `notification_log`
  ADD INDEX IF NOT EXISTS `idx_type_status` (`type`, `status_current`);

-- ---------------------------------------------------------------------------
-- Notification schedule table — defines WHEN each notification is sent per facility.
--
-- Each row = one send event in the lifecycle of a patient appointment.
-- Examples:
--   seq=1, send_on_booking=1  → send immediately when the appointment is created
--   seq=2, hours_before=72    → send 72 hours before the appointment
--   seq=3, hours_before=24    → send 24 hours before the appointment
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `wsp_email_notification_schedule` (
  `id`              int(11)    NOT NULL AUTO_INCREMENT,
  `facility_id`     int(11)    NOT NULL                 COMMENT 'FK -> facility.id',
  `seq`             tinyint(3) NOT NULL DEFAULT 1       COMMENT 'Send order (1=first, 2=second, …)',
  `hours_before`    int(5)     NOT NULL DEFAULT 48      COMMENT 'Hours before the appointment to send (ignored when send_on_booking=1)',
  `send_on_booking` tinyint(1) NOT NULL DEFAULT 0       COMMENT '1 = send immediately when the appointment is booked/created',
  `enabled_wsp`     tinyint(1) NOT NULL DEFAULT 1       COMMENT '1=send via WhatsApp for this slot',
  `enabled_email`   tinyint(1) NOT NULL DEFAULT 1       COMMENT '1=send via Email for this slot',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_facility_seq` (`facility_id`, `seq`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
  COMMENT='Per-facility notification schedule: when and how many times to notify per appointment';

-- ---------------------------------------------------------------------------
-- Status history table — tracks every event/transition for a notification.
-- Allows viewing a timeline: Sent -> Delivered -> Read.
-- Now includes provider-specific fields for multi-provider support.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `wsp_email_status_history` (
  `id`                  int(11)     NOT NULL AUTO_INCREMENT,
  `log_id`              int(11)     NOT NULL COMMENT 'FK -> notification_log.iLogId',
  `status`              varchar(50) NOT NULL COMMENT 'Canonical status string (QUEUED, SENT, DELIVERED, READ, FAILED, etc.)',
  `provider_raw_status` varchar(100) DEFAULT NULL COMMENT 'Raw status received from provider',
  `provider_name`       varchar(50)  DEFAULT NULL COMMENT 'Provider name (ultramsg, wasenderapi, meta, twilio, etc.)',
  `provider_payload`    text         DEFAULT NULL COMMENT 'Complete JSON payload from provider webhook',
  `created_at`          datetime     DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_log_id` (`log_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
  COMMENT='Timeline of status transitions for each notification with provider details';
