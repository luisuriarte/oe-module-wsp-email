-- ===========================================================================
-- add_provider_templates.sql
-- Adds templates for Providers (HBC/Telehealth) and Cancellations.
-- ===========================================================================

-- Templates para PROVEEDORES (Scheduled)
INSERT IGNORE INTO `wsp_email_notification_templates` 
(`facility_id`, `pc_catid`, `category_name`, `pc_apptstatus`, `recipient_type`, `channel`, `wsp_message`, `email_subject`, `email_message`) VALUES
-- HBC Proveedor WhatsApp
(0, 70, 'HBC Visita Domicilio', '-scheduled', 'provider', 'wsp', 
 'Dr. ***PROVIDER***, mañana tiene visita domiciliaria.\nPaciente: ***PATIENT_NAME***\nDir: ***PATIENT_ADDRESS***\nTel: ***PATIENT_PHONE***', 'HBC Programada', NULL),
-- HBC Proveedor Email
(0, 70, 'HBC Visita Domicilio', '-scheduled', 'provider', 'email', 
 NULL, 'Visita Domiciliaria: ***PATIENT_NAME***', 
 '<h3>Visita Domiciliaria</h3><p>Dr. ***PROVIDER***, tiene visita programada para mañana.</p><p><strong>Paciente:</strong> ***PATIENT_NAME***<br><strong>Dirección:</strong> ***PATIENT_ADDRESS***</p>'),

-- Telehealth Proveedor WhatsApp
(0, 80, 'Telehealth Consulta Virtual', '-scheduled', 'provider', 'wsp', 
 'Dr. ***PROVIDER***, teleconsulta mañana.\nPaciente: ***PATIENT_NAME***\nHora: ***STARTTIME***\nSala: ***VIDEO_ROOM***', 'Telehealth Programada', NULL),
-- Telehealth Proveedor Email
(0, 80, 'Telehealth Consulta Virtual', '-scheduled', 'provider', 'email', 
 NULL, 'Teleconsulta: ***PATIENT_NAME***',
 '<h3>Teleconsulta</h3><p>Dr. ***PROVIDER***, su sesión virtual es mañana.</p><p><strong>Paciente:</strong> ***PATIENT_NAME***<br><strong>Sala:</strong> ***VIDEO_ROOM***</p>');

-- Templates para CANCELACIONES
INSERT IGNORE INTO `wsp_email_notification_templates` 
(`facility_id`, `pc_catid`, `category_name`, `pc_apptstatus`, `recipient_type`, `channel`, `wsp_message`, `email_subject`, `email_message`) VALUES
-- Cancelación Paciente WhatsApp
(0, 5, 'Cancelación', '-cancelled', 'patient', 'wsp', 
 'Su cita del ***DATE*** fue CANCELADA. Llame a ***FACILITY_PHONE*** para reprogramar.', 'Cita Cancelada', NULL),
-- Cancelación Paciente Email
(0, 5, 'Cancelación', '-cancelled', 'patient', 'email', 
 NULL, 'Aviso de Cancelación', 
 '<p>Estimado/a ***NAME***, su cita del ***DATE*** ha sido cancelada. Por favor contáctenos para reprogramar.</p>'),
-- Cancelación Proveedor WhatsApp
(0, 5, 'Cancelación', '-cancelled', 'provider', 'wsp', 
 'Dr. ***PROVIDER***, la cita de ***PATIENT_NAME*** para el ***DATE*** fue CANCELADA.', 'Cita Cancelada', NULL);

SELECT 'Provider and cancellation templates added successfully!' AS status;
