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
  `logo_wsp`            varchar(255)  DEFAULT NULL              COMMENT 'Logo URL for WhatsApp',
  `logo_email`          varchar(255)  DEFAULT NULL              COMMENT 'Logo path for Email templates',
  `latitude`            decimal(10,6) DEFAULT NULL              COMMENT 'Facility Latitude',
  `longitude`           decimal(10,6) DEFAULT NULL              COMMENT 'Facility Longitude',
  `geoapify_key`        varchar(255)  DEFAULT NULL              COMMENT 'Geoapify API Key',

  -- Weekday sending window (Monday to Friday)
  `send_weekday_start`    tinyint(2)  NOT NULL DEFAULT 7        COMMENT 'Weekday first allowed send hour (0-23, inclusive)',
  `send_weekday_end`      tinyint(2)  NOT NULL DEFAULT 21       COMMENT 'Weekday last allowed send hour (0-23, exclusive)',

  -- Saturday
  `send_saturday_enabled` tinyint(1)  NOT NULL DEFAULT 1        COMMENT 'Allow sending on Saturdays',
  `send_saturday_start`   tinyint(2)  NOT NULL DEFAULT 8        COMMENT 'Saturday first allowed send hour (0-23, inclusive)',
  `send_saturday_end`     tinyint(2)  NOT NULL DEFAULT 13       COMMENT 'Saturday last allowed send hour (0-23, exclusive)',

  -- Sunday
  `send_sunday_enabled`   tinyint(1)  NOT NULL DEFAULT 0        COMMENT 'Allow sending on Sundays',
  `send_sunday_start`     tinyint(2)  NOT NULL DEFAULT 9        COMMENT 'Sunday first allowed send hour (0-23, inclusive)',
  `send_sunday_end`       tinyint(2)  NOT NULL DEFAULT 12        COMMENT 'Sunday last allowed send hour (0-23, exclusive)',

  -- Legacy Templates (Deprecated: see wsp_email_notification_templates)
  `wsp_message`         text          DEFAULT NULL               COMMENT 'Deprecated: Global WhatsApp template',
  `email_message`       text          DEFAULT NULL               COMMENT 'Deprecated: Global Email template',
  `email_subject`       varchar(255)  DEFAULT NULL               COMMENT 'Deprecated: Global Email subject',
  `notify_hours_before` int(5)        NOT NULL DEFAULT 48        COMMENT 'Deprecated: Hours before appointment',
  `enabled_wsp`         tinyint(1)    NOT NULL DEFAULT 1         COMMENT 'WhatsApp Enable Flag',
  `enabled_email`       tinyint(1)    NOT NULL DEFAULT 1         COMMENT 'Email Enable Flag',
  `notify_cancelled`    tinyint(1)    NOT NULL DEFAULT 1         COMMENT 'Send cancellation notifications',

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
  ADD COLUMN `pc_sendalertwsp` varchar(3) NOT NULL DEFAULT 'NO'
  COMMENT 'Flag: YES if WhatsApp alert sent';

-- Ensure WSP is available in notification types
ALTER TABLE `notification_log`
  MODIFY COLUMN `type` enum('SMS','Email','WSP') NOT NULL DEFAULT 'SMS';

-- Add core tracking columns to notification log
ALTER TABLE `notification_log`
  ADD COLUMN `msg_id`           varchar(100) DEFAULT NULL COMMENT 'Vendor Message ID',
  ADD COLUMN `status`           varchar(50)  DEFAULT NULL COMMENT 'Current Delivery Status',
  ADD COLUMN `notification_seq` tinyint(3)   NOT NULL DEFAULT 1 COMMENT 'Sequence Number';

-- Add Status Normalization Columns (Canonical Statuses)
ALTER TABLE `notification_log`
  ADD COLUMN `status_current` varchar(50) DEFAULT NULL
  COMMENT 'Canonical Status (QUEUED, SENT, DELIVERED, READ, FAILED)',
  ADD COLUMN `provider_raw_status` varchar(100) DEFAULT NULL
  COMMENT 'Raw Vendor Status',
  ADD COLUMN `status_priority` tinyint(3) DEFAULT 0
  COMMENT 'Status Priority Level',
  ADD COLUMN `provider_payload` text DEFAULT NULL
  COMMENT 'Full Webhook JSON Payload';

-- Indexes for Performance
ALTER TABLE `notification_log`
  ADD INDEX `idx_status_current` (`status_current`),
  ADD INDEX `idx_type_status` (`type`, `status_current`);

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
  `notification_type` varchar(20) NOT NULL DEFAULT 'appointment'  COMMENT 'Tipo: appointment | recall',
  `pc_catid`        int(11)       NOT NULL                 COMMENT 'Appt Category ID (5=Amb, 70=HBC, 80=Tele)',
  `category_name`   varchar(100)  DEFAULT NULL             COMMENT 'Display Label',
  `pc_apptstatus`   varchar(50)   NOT NULL DEFAULT '-scheduled' COMMENT 'Appt Status (-scheduled, -cancelled)',
  `recipient_type`  varchar(20)   NOT NULL DEFAULT 'patient' COMMENT 'Target Audience (patient | provider)',
  `wsp_message`     text          DEFAULT NULL             COMMENT 'WhatsApp Template (Plain Text)',
  `email_subject`   varchar(255)  DEFAULT NULL             COMMENT 'Email Subject',
  `email_message`   text          DEFAULT NULL             COMMENT 'Email Template (HTML)',
  `enabled`         tinyint(1)    NOT NULL DEFAULT 1       COMMENT 'Template Enable Flag',
  `created_at`      datetime      DEFAULT CURRENT_TIMESTAMP,
  `updated_at`      datetime      DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_template` (`facility_id`, `notification_type`, `pc_catid`, `pc_apptstatus`, `recipient_type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
  COMMENT='Notification templates with both WSP and Email content';

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

-- ---------------------------------------------------------------------------
-- 7. Rate Limit Log (WhatsApp send rate control)
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `wsp_email_rate_limit_log` (
  `id`          int(11)      NOT NULL AUTO_INCREMENT,
  `facility_id` int(11)      NOT NULL                           COMMENT 'FK -> facility.id',
  `vendor`      varchar(50)  NOT NULL                           COMMENT 'Vendor identifier (openwa, ultramsg, wasenderapi)',
  `phone`       varchar(30)  NOT NULL                           COMMENT 'Destination phone number',
  `sent_at`     datetime     NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'Timestamp of send attempt',
  PRIMARY KEY (`id`),
  KEY `idx_rate_facility_vendor_sent` (`facility_id`, `vendor`, `sent_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
  COMMENT='WhatsApp send log for rate limiting control per time window';

-- ---------------------------------------------------------------------------
-- 8. Blacklist (numbers with permanent delivery failures)
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `wsp_email_blacklist` (
  `id`          int(11)      NOT NULL AUTO_INCREMENT,
  `facility_id` int(11)      NOT NULL DEFAULT 0                 COMMENT 'FK -> facility.id (0 = global)',
  `vendor`      varchar(50)  NOT NULL DEFAULT 'all'             COMMENT 'Affected vendor (all = applies to every vendor)',
  `phone`       varchar(30)  NOT NULL                           COMMENT 'Blacklisted phone number',
  `reason`      varchar(20)  NOT NULL DEFAULT 'FAILED_MAX'      COMMENT 'Reason: INVALID | FAILED_MAX | MANUAL',
  `fail_count`  tinyint(3)   NOT NULL DEFAULT 0                 COMMENT 'Consecutive failure count',
  `is_active`   tinyint(1)   NOT NULL DEFAULT 1                 COMMENT 'Active flag (0 = manually disabled)',
  `notes`       varchar(255) DEFAULT NULL                       COMMENT 'Additional notes (e.g. number changed)',
  `created_at`  datetime     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`  datetime     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_blacklist_facility_vendor_phone` (`facility_id`, `vendor`, `phone`),
  KEY `idx_blacklist_phone` (`phone`),
  KEY `idx_blacklist_active` (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
  COMMENT='Blacklist of WhatsApp numbers with permanent or manual delivery failures';

-- ---------------------------------------------------------------------------
-- Recall Schedule: secuencias escalonadas de notificación por facility
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `wsp_email_recall_schedule` (
  `id`            int(11)      NOT NULL AUTO_INCREMENT,
  `facility_id`   int(11)      NOT NULL                   COMMENT 'FK -> facility.id',
  `seq`           tinyint(3)   NOT NULL                   COMMENT 'Orden de envío (1, 2, 3...)',
  `days_before`   int(5)       NOT NULL DEFAULT 7         COMMENT 'Días antes de r_eventDate para enviar',
  `enabled_wsp`   tinyint(1)   NOT NULL DEFAULT 1         COMMENT 'WhatsApp habilitado',
  `enabled_email` tinyint(1)   NOT NULL DEFAULT 1         COMMENT 'Email habilitado',
  `enabled`       tinyint(1)   NOT NULL DEFAULT 1         COMMENT 'Secuencia activa',
  `created_at`    datetime     DEFAULT CURRENT_TIMESTAMP,
  `updated_at`    datetime     DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_facility_seq` (`facility_id`, `seq`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
  COMMENT='Secuencias escalonadas de notificación de recalls por facility';

-- ---------------------------------------------------------------------------
-- Recall Notifications: registro de envíos por recall + secuencia
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `wsp_email_recall` (
  `id`            int(11)      NOT NULL AUTO_INCREMENT,
  `recall_id`     int(11)      NOT NULL                   COMMENT 'FK -> medex_recalls.r_ID',
  `facility_id`   int(11)      NOT NULL                   COMMENT 'FK -> facility.id',
  `pid`           int(11)      NOT NULL                   COMMENT 'FK -> patient_data.pid',
  `seq`           tinyint(3)   NOT NULL                   COMMENT 'FK -> wsp_email_recall_schedule.seq',
  `channel`       enum('WSP','Email','Both') NOT NULL DEFAULT 'WSP' COMMENT 'Canal usado',
  `log_id`        int(11)      DEFAULT NULL               COMMENT 'FK -> notification_log.iLogId',
  `status`        enum('PENDING','SENT','FAILED','SKIPPED') NOT NULL DEFAULT 'PENDING' COMMENT 'Estado del intento',
  `skip_reason`   varchar(100) DEFAULT NULL               COMMENT 'Motivo si SKIPPED (blacklist, ventana, etc.)',
  `scheduled_for` date         NOT NULL                   COMMENT 'Fecha calculada del envío',
  `sent_at`       datetime     DEFAULT NULL               COMMENT 'Timestamp efectivo de envío',
  `created_at`    datetime     DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_recall_seq_channel` (`recall_id`, `seq`, `channel`),
  KEY `idx_facility_status_scheduled` (`facility_id`, `status`, `scheduled_for`),
  KEY `idx_pid` (`pid`),
  KEY `idx_log_id` (`log_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
  COMMENT='Registro de notificaciones de recalls por secuencia';

-- ---------------------------------------------------------------------------
-- Recall Entries: recalls creados desde el módulo (sin restricción por paciente)
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `wsp_email_recall_entries` (
  `id`          int(11)      NOT NULL AUTO_INCREMENT,
  `pid`         int(11)      NOT NULL                   COMMENT 'FK -> patient_data.pid',
  `event_date`  date         NOT NULL                   COMMENT 'Fecha del recall',
  `facility_id` int(11)      NOT NULL                   COMMENT 'FK -> facility.id',
  `provider_id` int(11)      DEFAULT NULL               COMMENT 'FK -> users.id',
  `reason`      varchar(255) DEFAULT NULL,
  `created_at`  datetime     DEFAULT CURRENT_TIMESTAMP,
  `updated_at`  datetime     DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_pid` (`pid`),
  KEY `idx_facility` (`facility_id`),
  KEY `idx_event_date` (`event_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
  COMMENT='Recalls creados desde el módulo (sin restricción único por paciente)';

-- Elimina la UNIQUE KEY (r_PRACTID, r_pid) que impide
-- tener múltiples recalls para un mismo paciente.
-- La PRIMARY KEY (r_ID) ya garantiza unicidad de cada fila.

ALTER TABLE `medex_recalls`
  DROP INDEX `r_PRACTID`;