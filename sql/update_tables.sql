-- ===========================================================================
-- update_tables.sql  -  oe-module-wsp-email
-- Description: Migration script to update existing tables to new design
-- Target: OpenEMR 7.0+
-- Instructions: Execute once on existing database
-- ===========================================================================

-- ---------------------------------------------------------------------------
-- 1. Update wsp_email_facility_config
-- ---------------------------------------------------------------------------

-- Multi-vendor support
ALTER TABLE `wsp_email_facility_config`
  ADD COLUMN IF NOT EXISTS `current_vendor` varchar(50) NOT NULL DEFAULT 'wasenderapi'
  COMMENT 'Active vendor identifier'
  AFTER `facility_id`;

-- UltraMsg specific credentials
ALTER TABLE `wsp_email_facility_config`
  ADD COLUMN IF NOT EXISTS `ultramsg_instance` varchar(100) DEFAULT NULL
  COMMENT 'UltraMsg Instance ID'
  AFTER `webhook_secret`;

ALTER TABLE `wsp_email_facility_config`
  ADD COLUMN IF NOT EXISTS `ultramsg_api_key` varchar(255) DEFAULT NULL
  COMMENT 'UltraMsg API Token'
  AFTER `ultramsg_instance`;

-- WaSenderAPI specific credentials
ALTER TABLE `wsp_email_facility_config`
  ADD COLUMN IF NOT EXISTS `wasenderapi_api_key` varchar(255) DEFAULT NULL
  COMMENT 'WaSenderAPI Bearer Token'
  AFTER `ultramsg_api_key`;

ALTER TABLE `wsp_email_facility_config`
  ADD COLUMN IF NOT EXISTS `wasenderapi_webhook_secret` varchar(255) DEFAULT NULL
  COMMENT 'WaSenderAPI Webhook Secret'
  AFTER `wasenderapi_api_key`;

-- Telehealth Settings
ALTER TABLE `wsp_email_facility_config`
  ADD COLUMN IF NOT EXISTS `th_jitsi_domain` varchar(255) DEFAULT NULL
  COMMENT 'Jitsi Server Domain'
  AFTER `th_provider_template`;

ALTER TABLE `wsp_email_facility_config`
  ADD COLUMN IF NOT EXISTS `th_jitsi_base_url` varchar(500) DEFAULT NULL
  COMMENT 'Jitsi Base URL'
  AFTER `th_jitsi_domain`;

ALTER TABLE `wsp_email_facility_config`
  ADD COLUMN IF NOT EXISTS `th_room_prefix` varchar(50) DEFAULT 'th-'
  COMMENT 'Room Name Prefix'
  AFTER `th_jitsi_base_url`;

ALTER TABLE `wsp_email_facility_config`
  ADD COLUMN IF NOT EXISTS `th_jwt_enabled` tinyint(1) NOT NULL DEFAULT 0
  COMMENT 'JWT Auth Enable Flag'
  AFTER `th_room_prefix`;

ALTER TABLE `wsp_email_facility_config`
  ADD COLUMN IF NOT EXISTS `th_jwt_app_id` varchar(255) DEFAULT NULL
  COMMENT 'JWT App ID'
  AFTER `th_jwt_enabled`;

ALTER TABLE `wsp_email_facility_config`
  ADD COLUMN IF NOT EXISTS `th_jwt_app_secret` varchar(255) DEFAULT NULL
  COMMENT 'JWT App Secret'
  AFTER `th_jwt_app_id`;

ALTER TABLE `wsp_email_facility_config`
  ADD COLUMN IF NOT EXISTS `th_default_duration` int(11) NOT NULL DEFAULT 30
  COMMENT 'Default Duration (minutes)'
  AFTER `th_jwt_app_secret`;

ALTER TABLE `wsp_email_facility_config`
  ADD COLUMN IF NOT EXISTS `th_geolocation_enabled` tinyint(1) NOT NULL DEFAULT 1
  COMMENT 'Geolocation Capture Flag'
  AFTER `th_default_duration`;

ALTER TABLE `wsp_email_facility_config`
  ADD COLUMN IF NOT EXISTS `th_recording_enabled` tinyint(1) NOT NULL DEFAULT 0
  COMMENT 'Recording Enable Flag'
  AFTER `th_geolocation_enabled`;

ALTER TABLE `wsp_email_facility_config`
  ADD COLUMN IF NOT EXISTS `th_fallback_minutes` int(11) NOT NULL DEFAULT 5
  COMMENT 'Fallback Link Delay'
  AFTER `th_recording_enabled`;

ALTER TABLE `wsp_email_facility_config`
  ADD COLUMN IF NOT EXISTS `th_default_patient_channel` varchar(20) DEFAULT 'whatsapp'
  COMMENT 'Default Patient Channel'
  AFTER `th_fallback_minutes`;

ALTER TABLE `wsp_email_facility_config`
  ADD COLUMN IF NOT EXISTS `th_default_provider_channel` varchar(20) DEFAULT 'internal'
  COMMENT 'Default Provider Channel'
  AFTER `th_default_patient_channel`;

ALTER TABLE `wsp_email_facility_config`
  ADD COLUMN IF NOT EXISTS `th_webhook_token` varchar(255) DEFAULT NULL
  COMMENT 'Webhook Auth Token'
  AFTER `th_default_provider_channel`;

-- ---------------------------------------------------------------------------
-- 2. Update wsp_email_status_history (if columns missing)
-- ---------------------------------------------------------------------------

ALTER TABLE `wsp_email_status_history`
  ADD COLUMN IF NOT EXISTS `provider_raw_status` varchar(100) DEFAULT NULL
  COMMENT 'Raw Vendor Status'
  AFTER `status`;

ALTER TABLE `wsp_email_status_history`
  ADD COLUMN IF NOT EXISTS `provider_name` varchar(50) DEFAULT NULL
  COMMENT 'Vendor Identifier'
  AFTER `provider_raw_status`;

ALTER TABLE `wsp_email_status_history`
  ADD COLUMN IF NOT EXISTS `provider_payload` text DEFAULT NULL
  COMMENT 'Full Webhook Payload'
  AFTER `provider_name`;

-- ---------------------------------------------------------------------------
-- 3. Update notification_log (Core Table Modifications)
-- ---------------------------------------------------------------------------

-- Status Normalization Columns
ALTER TABLE `notification_log`
  ADD COLUMN IF NOT EXISTS `status_current` varchar(50) DEFAULT NULL
  COMMENT 'Canonical Status (QUEUED, SENT, DELIVERED, READ, FAILED)'
  AFTER `status`;

ALTER TABLE `notification_log`
  ADD COLUMN IF NOT EXISTS `provider_raw_status` varchar(100) DEFAULT NULL
  COMMENT 'Raw Vendor Status'
  AFTER `status_current`;

ALTER TABLE `notification_log`
  ADD COLUMN IF NOT EXISTS `status_priority` tinyint(3) DEFAULT 0
  COMMENT 'Status Priority Level'
  AFTER `provider_raw_status`;

ALTER TABLE `notification_log`
  ADD COLUMN IF NOT EXISTS `provider_payload` text DEFAULT NULL
  COMMENT 'Full Webhook JSON Payload'
  AFTER `status_priority`;

-- Indexes for Performance
ALTER TABLE `notification_log`
  ADD INDEX IF NOT EXISTS `idx_status_current` (`status_current`);

ALTER TABLE `notification_log`
  ADD INDEX IF NOT EXISTS `idx_type_status` (`type`, `status_current`);

-- ---------------------------------------------------------------------------
-- 4. Create wsp_email_notification_templates (New Table)
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
-- 5. OpenEMR Tracker Status Options (Patient Tracker Integration)
-- ---------------------------------------------------------------------------
-- Adds WhatsApp status options to the appointment status list
INSERT IGNORE INTO `list_options` (`list_id`, `option_id`, `title`, `seq`, `is_default`, `option_value`, `mapping`, `notes`) VALUES
('apptstat', 'wsp-sent',   'WSP: Sent',       110, 0, 0, '', 'Message sent to WhatsApp Gateway'),
('apptstat', 'wsp-deliv',  'WSP: Delivered',  120, 0, 0, '', 'Message delivered to patient device'),
('apptstat', 'wsp-read',   'WSP: Read',       130, 0, 0, '', 'Message read by patient'),
('apptstat', 'wsp-err',    'WSP: Error',      140, 0, 0, '', 'Failed to send message');

-- ---------------------------------------------------------------------------
-- Migration Complete
-- ---------------------------------------------------------------------------
SELECT 'Migration completed successfully!' AS status;
