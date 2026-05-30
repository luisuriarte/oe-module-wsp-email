-- ===========================================================================
-- install.sql  -  oe-module-wsp-email
-- Description: Database initialization for WhatsApp/Email Notification Module
-- Target: OpenEMR 7.0+
-- ===========================================================================

-- ---------------------------------------------------------------------------
-- 1. Facility Configuration Table
-- Supports multi-vendor credentials (UltraMsg, WaSenderAPI, OpenWA) and Telehealth
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `wsp_email_facility_config` (
  `id`                  int(11)       NOT NULL AUTO_INCREMENT,
  `facility_id`         int(11)       NOT NULL                   COMMENT 'FK -> facility.id (0 = global settings)',
  
  -- Active Vendor Selection
  `current_vendor`      varchar(50)   NOT NULL DEFAULT 'wasenderapi' COMMENT 'Active vendor identifier',
  
  -- Legacy Fields (Backwards Compatibility)
  `vendor`              varchar(50)   NOT NULL DEFAULT 'wasenderapi' COMMENT 'Deprecated: use current_vendor',
  `vendor_instance`     varchar(100)  DEFAULT NULL               COMMENT 'Deprecated: vendor instance ID',
  `vendor_api_key`      varchar(255)  DEFAULT NULL               COMMENT 'Deprecated: vendor API key',
  `webhook_secret`      varchar(255)  DEFAULT NULL               COMMENT 'Deprecated: webhook validation secret',
  
  -- UltraMsg Credentials
  `ultramsg_instance`   varchar(100)  DEFAULT NULL               COMMENT 'UltraMsg Instance ID',
  `ultramsg_api_key`    varchar(255)  DEFAULT NULL               COMMENT 'UltraMsg API Token',
  
  -- WaSenderAPI Credentials
  `wasenderapi_api_key`      varchar(255)  DEFAULT NULL         COMMENT 'WaSenderAPI Bearer Token',
  `wasenderapi_webhook_secret` varchar(255) DEFAULT NULL        COMMENT 'WaSenderAPI Webhook Secret',

  -- OpenWA Credentials (https://wa.origen.ar)
  `openwa_instance`          varchar(100)  DEFAULT NULL         COMMENT 'OpenWA Session ID',
  `openwa_api_key`           varchar(255)  DEFAULT NULL         COMMENT 'OpenWA API Key (owa_xxx...)',
  `openwa_webhook_secret`    varchar(255)  DEFAULT NULL         COMMENT 'OpenWA Webhook HMAC Secret',
  
  -- General Configuration
  `logo_wsp`            varchar(255)  DEFAULT NULL               COMMENT 'Logo URL for WhatsApp',
  `logo_email`          varchar(255)  DEFAULT NULL               COMMENT 'Logo path for Email templates',
  `latitude`            decimal(10,6) DEFAULT NULL               COMMENT 'Facility Latitude',
  `longitude`           decimal(10,6) DEFAULT NULL               COMMENT 'Facility Longitude',
  `geoapify_key`        varchar(255)  DEFAULT NULL               COMMENT 'Geoapify API Key',
  
  -- Legacy Templates (Deprecated: see wsp_email_notification_templates)
  `wsp_message`         text          DEFAULT NULL               COMMENT 'Deprecated: Global WhatsApp template',
  `email_message`       text          DEFAULT NULL               COMMENT 'Deprecated: Global Email template',
  `email_subject`       varchar(255)  DEFAULT NULL               COMMENT 'Deprecated: Global Email subject',
  `notify_hours_before` int(5)        NOT NULL DEFAULT 48        COMMENT 'Deprecated: Hours before appointment',
  `enabled_wsp`         tinyint(1)    NOT NULL DEFAULT 1         COMMENT 'WhatsApp Enable Flag',
  `enabled_email`       tinyint(1)    NOT NULL DEFAULT 1         COMMENT 'Email Enable Flag',

  -- Telehealth Settings (Jitsi/Meet)
  `th_jitsi_domain`             varchar(255)  DEFAULT NULL           COMMENT 'Jitsi Server Domain',
  `th_jitsi_base_url`           varchar(500)  DEFAULT NULL           COMMENT 'Jitsi Base URL',
  `th_room_prefix`              varchar(50)   DEFAULT 'th-'          COMMENT 'Room Name Prefix',
  `th_jwt_enabled`              tinyint(1)    NOT NULL DEFAULT 0     COMMENT 'JWT Auth Enable Flag',
  `th_jwt_app_id`               varchar(255)  DEFAULT NULL           COMMENT 'JWT App ID',
  `th_jwt_app_secret`           varchar(255)  DEFAULT NULL           COMMENT 'JWT App Secret',
  `th_default_duration`         int(11)       NOT NULL DEFAULT 30    COMMENT 'Default Duration (minutes)',
  `th_geolocation_enabled`      tinyint(1)    NOT NULL DEFAULT 1     COMMENT 'Geolocation Capture Flag',
  `th_recording_enabled`        tinyint(1)    NOT NULL DEFAULT 0     COMMENT 'Recording Enable Flag',
  `th_fallback_minutes`         int(11)       NOT NULL DEFAULT 5     COMMENT 'Fallback Link Delay',
  `th_default_patient_channel`  varchar(20)   DEFAULT 'whatsapp'     COMMENT 'Default Patient Channel',
  `th_default_provider_channel` varchar(20)   DEFAULT 'internal'     COMMENT 'Default Provider Channel',
  `th_webhook_token`            varchar(255)  DEFAULT NULL           COMMENT 'Webhook Auth Token',
  `th_enabled`                  tinyint(1)    NOT NULL DEFAULT 1     COMMENT 'Telehealth Notification Flag',
  
  -- Telehealth Templates
  `th_patient_template`         text          DEFAULT NULL           COMMENT 'Telehealth Patient Template',
  `th_provider_template`        text          DEFAULT NULL           COMMENT 'Telehealth Provider Template',
  
  -- Audit
  `created_at`          datetime      DEFAULT CURRENT_TIMESTAMP,
  `updated_at`          datetime      DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_facility_id` (`facility_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
  COMMENT='Facility-specific WhatsApp, Email, and Telehealth configuration';

-- ---------------------------------------------------------------------------
-- 2. OpenEMR Core Table Modifications
-- ---------------------------------------------------------------------------

-- Add WhatsApp alert flag to appointments
ALTER TABLE `openemr_postcalendar_events`
  ADD COLUMN IF NOT EXISTS `pc_sendalertwsp` varchar(3) NOT NULL DEFAULT 'NO'
  COMMENT 'Flag: YES if WhatsApp alert sent';

-- Ensure WSP is available in notification types
ALTER TABLE `notification_log`
  MODIFY COLUMN `type` enum('SMS','Email','WSP') NOT NULL DEFAULT 'SMS';

-- Add core tracking columns to notification log
ALTER TABLE `notification_log`
  ADD COLUMN IF NOT EXISTS `msg_id`           varchar(100) DEFAULT NULL COMMENT 'Vendor Message ID',
  ADD COLUMN IF NOT EXISTS `status`           varchar(50)  DEFAULT NULL COMMENT 'Current Delivery Status',
  ADD COLUMN IF NOT EXISTS `notification_seq` tinyint(3)   NOT NULL DEFAULT 1 COMMENT 'Sequence Number';

-- Add Status Normalization Columns (Canonical Statuses)
ALTER TABLE `notification_log`
  ADD COLUMN IF NOT EXISTS `status_current` varchar(50) DEFAULT NULL
  COMMENT 'Canonical Status (QUEUED, SENT, DELIVERED, READ, FAILED)',
  ADD COLUMN IF NOT EXISTS `provider_raw_status` varchar(100) DEFAULT NULL
  COMMENT 'Raw Vendor Status',
  ADD COLUMN IF NOT EXISTS `status_priority` tinyint(3) DEFAULT 0
  COMMENT 'Status Priority Level',
  ADD COLUMN IF NOT EXISTS `provider_payload` text DEFAULT NULL
  COMMENT 'Full Webhook JSON Payload';

-- Indexes for Performance
ALTER TABLE `notification_log`
  ADD INDEX IF NOT EXISTS `idx_status_current` (`status_current`),
  ADD INDEX IF NOT EXISTS `idx_type_status` (`type`, `status_current`);

-- ---------------------------------------------------------------------------
-- 3. Notification Schedule Table
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `wsp_email_notification_schedule` (
  `id`              int(11)    NOT NULL AUTO_INCREMENT,
  `facility_id`     int(11)    NOT NULL                 COMMENT 'FK -> facility.id',
  `seq`             tinyint(3) NOT NULL DEFAULT 1       COMMENT 'Execution Sequence',
  `hours_before`    int(5)     NOT NULL DEFAULT 48      COMMENT 'Hours Before Appointment',
  `send_on_booking` tinyint(1) NOT NULL DEFAULT 0       COMMENT 'Send Immediately on Booking',
  `enabled_wsp`     tinyint(1) NOT NULL DEFAULT 1       COMMENT 'WhatsApp Enable Flag',
  `enabled_email`   tinyint(1) NOT NULL DEFAULT 1       COMMENT 'Email Enable Flag',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_facility_seq` (`facility_id`, `seq`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
  COMMENT='Automated notification schedule triggers per facility';

-- ---------------------------------------------------------------------------
-- 4. Centralized Notification Templates
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `wsp_email_notification_templates` (
  `id`              int(11)       NOT NULL AUTO_INCREMENT,
  `facility_id`     int(11)       NOT NULL                 COMMENT 'FK -> facility.id',
  `pc_catid`        int(11)       NOT NULL                 COMMENT 'Appt Category ID (5=Amb, 70=HBC, 80=Tele)',
  `category_name`   varchar(100)  DEFAULT NULL             COMMENT 'Display Label',
  `pc_apptstatus`   varchar(50)   NOT NULL DEFAULT '-scheduled' COMMENT 'Appt Status (-scheduled, -cancelled)',
  `recipient_type`  varchar(20)   NOT NULL DEFAULT 'patient' COMMENT 'Target Audience (patient | provider)',
  `channel`         varchar(20)   NOT NULL DEFAULT 'wsp'   COMMENT 'Channel (wsp | email)',
  `wsp_message`     text          DEFAULT NULL             COMMENT 'WhatsApp Template (Plain Text)',
  `email_subject`   varchar(255)  DEFAULT NULL             COMMENT 'Email Subject',
  `email_message`   text          DEFAULT NULL             COMMENT 'Email Template (HTML)',
  `enabled`         tinyint(1)    NOT NULL DEFAULT 1       COMMENT 'Template Enable Flag',
  `created_at`      datetime      DEFAULT CURRENT_TIMESTAMP,
  `updated_at`      datetime      DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_template` (`facility_id`, `pc_catid`, `pc_apptstatus`, `recipient_type`, `channel`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
  COMMENT='Multi-channel notification templates by appt type and recipient';

-- ---------------------------------------------------------------------------
-- 5. Status History Table
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `wsp_email_status_history` (
  `id`                  int(11)     NOT NULL AUTO_INCREMENT,
  `log_id`              int(11)     NOT NULL COMMENT 'FK -> notification_log.iLogId',
  `status`              varchar(50) NOT NULL COMMENT 'Canonical Status Change',
  `provider_raw_status` varchar(100) DEFAULT NULL COMMENT 'Raw Vendor Status',
  `provider_name`       varchar(50)  DEFAULT NULL COMMENT 'Vendor Identifier',
  `provider_payload`    text         DEFAULT NULL COMMENT 'Full Webhook Payload',
  `created_at`          datetime     DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_log_id` (`log_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
  COMMENT='Audit trail for notification status transitions';

-- ---------------------------------------------------------------------------
-- 6. OpenEMR Tracker Status Options (Patient Tracker Integration)
-- ---------------------------------------------------------------------------
-- Adds WhatsApp status options to the appointment status list
INSERT IGNORE INTO `list_options` (`list_id`, `option_id`, `title`, `seq`, `is_default`, `option_value`, `mapping`, `notes`) VALUES
('apptstat', 'wsp-sent',   'WSP: Sent',       110, 0, 0, '', 'Message sent to WhatsApp Gateway'),
('apptstat', 'wsp-deliv',  'WSP: Delivered',  120, 0, 0, '', 'Message delivered to patient device'),
('apptstat', 'wsp-read',   'WSP: Read',       130, 0, 0, '', 'Message read by patient'),
('apptstat', 'wsp-err',    'WSP: Error',      140, 0, 0, '', 'Failed to send message');
