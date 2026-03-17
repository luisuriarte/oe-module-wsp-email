-- ===========================================================================
-- install.sql  -  oe-module-wsp-email
-- Executed automatically by the Module Manager when activating the module.
-- ===========================================================================

-- ---------------------------------------------------------------------------
-- Extended per-facility configuration table
-- Replaces the incorrect use of standard facility fields for API keys/vendors.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `wsp_email_facility_config` (
  `id`                  int(11)       NOT NULL AUTO_INCREMENT,
  `facility_id`         int(11)       NOT NULL                   COMMENT 'FK -> facility.id',
  `vendor`              varchar(50)   NOT NULL DEFAULT 'wasenderapi' COMMENT 'waapi | ultramsg | wasenderapi',
  `vendor_instance`     varchar(100)  DEFAULT NULL               COMMENT 'Vendor instance ID for WhatsApp gateway',
  `vendor_api_key`      varchar(255)  DEFAULT NULL               COMMENT 'API Key / Bearer Token for the vendor',
  `webhook_secret`      varchar(255)  DEFAULT NULL               COMMENT 'Secret used to validate incoming webhook requests',
  `logo_wsp`            varchar(255)  DEFAULT NULL               COMMENT 'Public URL of the logo sent via WhatsApp',
  `logo_email`          varchar(255)  DEFAULT NULL               COMMENT 'Absolute server path of the logo embedded in emails',
  `latitude`            decimal(10,6) DEFAULT NULL               COMMENT 'Facility geographic latitude',
  `longitude`           decimal(10,6) DEFAULT NULL               COMMENT 'Facility geographic longitude',
  `website_url`         varchar(255)  DEFAULT NULL               COMMENT 'Facility website URL',
  `geoapify_key`        varchar(255)  DEFAULT NULL               COMMENT 'Geoapify API key for static maps in email',
  `wsp_message`         text          DEFAULT NULL               COMMENT 'WhatsApp message template (supports token replacement)',
  `email_message`       text          DEFAULT NULL               COMMENT 'HTML body template for the notification email',
  `email_subject`       varchar(255)  DEFAULT NULL               COMMENT 'Email notification subject line',
  `notify_hours_before` int(5)        NOT NULL DEFAULT 48        COMMENT 'Hours before the appointment to send the notification',
  `enabled_wsp`         tinyint(1)    NOT NULL DEFAULT 1         COMMENT '1=enabled, 0=disabled',
  `enabled_email`       tinyint(1)    NOT NULL DEFAULT 1         COMMENT '1=enabled, 0=disabled',
  `created_at`          datetime      DEFAULT CURRENT_TIMESTAMP,
  `updated_at`          datetime      DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_facility_id` (`facility_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
  COMMENT='Extended WhatsApp/Email configuration per medical facility';

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
