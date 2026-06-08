-- ===========================================================================
-- restore_facility_3.sql
-- Restore templates for facility_id=3 from user backup, without channel column.
-- Merges duplicate rows (same scenario, different channel) into single rows.
-- ===========================================================================

-- First, remove existing rows for this facility
DELETE FROM wsp_email_notification_templates WHERE facility_id = 3;

-- -----------------------------------------------------------------------
-- Patient: Consulta Ambulatoria - Scheduled
-- (merged from former WSP row + Email row)
-- -----------------------------------------------------------------------
INSERT INTO `wsp_email_notification_templates` 
(`facility_id`, `pc_catid`, `category_name`, `pc_apptstatus`, `recipient_type`, `wsp_message`, `email_subject`, `email_message`) VALUES
(3, 5, 'Consulta Ambulatoria', '-scheduled', 'patient',
 '***NAME***, recuerde que tiene una cita con ***USER_PREFFIX*** ***PROVIDER***  en nuestra clínica el ***DATE***, a partir de las ***STARTTIME*** Hs. ubicada en ***FACILITY_ADDRESS***. Por favor, si no puede presentarse le solicitamos envíe un mensaje a nuestro número de teléfono ***FACILITY_PHONE***. Gracias. ***FACILITY_NAME***',
 'Confirmación de Cita',
 '<html><body style="font-family: Arial, sans-serif; color: #333; line-height: 1.6;">
        <div style="max-width: 600px; margin: 0 auto; border: 1px solid #e0e0e0; border-radius: 8px; overflow: hidden;">
            <div style="background-color: #1a73e8; color: #ffffff; padding: 20px; text-align: center;">
                <h2 style="margin: 0;">Recordatorio de Cita</h2>
            </div>
            <div style="padding: 20px;">
                <p>Hola <strong>***NAME***</strong>,</p>
                <p>Le recordamos que tiene una cita médica programada:</p>
                <div style="background-color: #f8f9fa; padding: 15px; border-radius: 5px; margin: 20px 0;">
                    <strong>Profesional:</strong> ***USER_PREFFIX*** ***PROVIDER***<br>
                    <strong>Fecha:</strong> ***DATE***<br>
                    <strong>Hora:</strong> ***STARTTIME*** hs.<br>
                    <strong>Ubicación:</strong> ***FACILITY_ADDRESS***
                </div>
                <p>Si no puede presentarse, le solicitamos que nos informe llamando o enviando un mensaje al <strong>***FACILITY_PHONE***</strong>.</p>
                <p>Gracias por confiar en nosotros,<br><strong>***FACILITY_NAME***</strong></p>
            </div>
        </div>
        </body></html>');

-- -----------------------------------------------------------------------
-- Patient: Consulta Ambulatoria - Cancelled
-- -----------------------------------------------------------------------
INSERT INTO `wsp_email_notification_templates` 
(`facility_id`, `pc_catid`, `category_name`, `pc_apptstatus`, `recipient_type`, `wsp_message`, `email_subject`, `email_message`) VALUES
(3, 5, 'Consulta Ambulatoria', '-cancelled', 'patient',
 '***NAME***, su cita del ***DATE*** fue CANCELADA. Llame a ***FACILITY_PHONE*** para reprogramar.',
 'Aviso de Cancelación',
 '<html><body style="font-family: Arial, sans-serif; color: #444;">
        <div style="max-width: 600px; margin: 0 auto; padding: 20px; border: 1px solid #f5c6cb; border-radius: 8px; background-color: #f8d7da;">
            <h2 style="color: #721c24;">Aviso de Cancelación</h2>
            <p>Estimado/a <strong>***NAME***</strong>,</p>
            <p>Le informamos que su cita programada para el día <strong>***DATE***</strong> ha sido <strong>CANCELADA</strong>.</p>
            <p>Para reprogramar su turno, por favor póngase en contacto con nosotros al <strong>***FACILITY_PHONE***</strong>.</p>
            <p>Atentamente,<br>El equipo de ***FACILITY_NAME***</p>
        </div>
        </body></html>');

-- -----------------------------------------------------------------------
-- Patient: Visita al Domicilio - Scheduled
-- -----------------------------------------------------------------------
INSERT INTO `wsp_email_notification_templates` 
(`facility_id`, `pc_catid`, `category_name`, `pc_apptstatus`, `recipient_type`, `wsp_message`, `email_subject`, `email_message`) VALUES
(3, 70, 'Visita al Domicilio', '-scheduled', 'patient',
 'Hola ***NAME***, en breve visitaremos su domicilio en ***PATIENT_ADDRESS*** a las ***STARTTIME*** Hs. Por favor confirme recepción.',
 'Visita Programada a su Domicilio',
 '<html><body style="font-family: Arial, sans-serif; color: #333;">
        <div style="max-width: 600px; margin: 0 auto; border: 1px solid #1a73e8; border-radius: 8px;">
            <div style="background-color: #1a73e8; color: white; padding: 15px; text-align: center;">
                <h3 style="margin: 0;">Visita Domiciliaria Programada</h3>
            </div>
            <div style="padding: 20px;">
                <p>Hola <strong>***NAME***</strong>,</p>
                <p>Le informamos que el día <strong>***DATE***</strong> realizaremos una visita a su domicilio:</p>
                <div style="border-left: 4px solid #1a73e8; padding-left: 15px; margin: 20px 0;">
                    <strong>Hora estimada:</strong> ***STARTTIME*** hs.<br>
                    <strong>Dirección:</strong> ***PATIENT_ADDRESS***
                </div>
                <p>Por favor, confirme la recepción de este mensaje y asegúrese de que haya alguien en el domicilio para recibir al profesional.</p>
                <p>Cualquier duda, contáctenos al ***FACILITY_PHONE***.</p>
            </div>
        </div>
        </body></html>');

-- -----------------------------------------------------------------------
-- Provider: Visita al Domicilio - Scheduled
-- -----------------------------------------------------------------------
INSERT INTO `wsp_email_notification_templates` 
(`facility_id`, `pc_catid`, `category_name`, `pc_apptstatus`, `recipient_type`, `wsp_message`, `email_subject`, `email_message`) VALUES
(3, 70, 'Visita al Domicilio', '-scheduled', 'provider',
 'Dr. ***PROVIDER***, en breve tiene visita domiciliaria.\n👤 Paciente: ***PATIENT_NAME***\n📍 Dirección: ***PATIENT_ADDRESS***\n📞 Tel: ***PATIENT_PHONE***\n📝 Notas: ***VISIT_INSTRUCTIONS***',
 'Hoja de Ruta: Visita Domiciliaria',
 '<html><body>
        <h3>Hoja de Ruta: Nueva Visita Domiciliaria</h3>
        <p>Estimado/a <strong>***PROVIDER***</strong>, tiene una visita asignada:</p>
        <ul>
            <li><strong>Paciente:</strong> ***PATIENT_NAME***</li>
            <li><strong>Fecha:</strong> ***DATE***</li>
            <li><strong>Hora:</strong> ***STARTTIME*** hs.</li>
            <li><strong>Dirección:</strong> ***PATIENT_ADDRESS***</li>
            <li><strong>Teléfono:</strong> ***PATIENT_PHONE***</li>
        </ul>
        <p><strong>Instrucciones:</strong> ***VISIT_INSTRUCTIONS***</p>
        </body></html>');

-- -----------------------------------------------------------------------
-- Provider: Visita al Domicilio - Cancelled
-- -----------------------------------------------------------------------
INSERT INTO `wsp_email_notification_templates` 
(`facility_id`, `pc_catid`, `category_name`, `pc_apptstatus`, `recipient_type`, `wsp_message`, `email_subject`, `email_message`) VALUES
(3, 70, 'Visita al Domicilio', '-cancelled', 'provider',
 'Dr. ***PROVIDER***, la visita a ***PATIENT_ADDRESS*** fue CANCELADA por el paciente. Se liberó el horario.',
 'Cancelación de Visita Domiciliaria',
 '<html><body>
        <h3 style="color: red;">URGENTE: Visita Cancelada</h3>
        <p>Dr/a. <strong>***PROVIDER***</strong>, le informamos que la visita programada para el paciente <strong>***PATIENT_NAME***</strong> en <strong>***PATIENT_ADDRESS***</strong> ha sido cancelada por el paciente.</p>
        <p>El horario ha sido liberado de su agenda.</p>
        </body></html>');

-- -----------------------------------------------------------------------
-- Patient: Consulta Virtual - Scheduled
-- -----------------------------------------------------------------------
INSERT INTO `wsp_email_notification_templates` 
(`facility_id`, `pc_catid`, `category_name`, `pc_apptstatus`, `recipient_type`, `wsp_message`, `email_subject`, `email_message`) VALUES
(3, 80, 'Consulta Virtual', '-scheduled', 'patient',
 'Estimado/a ***PATIENT_NAME***,

El día ***DATE*** a las ***TIME*** hs., el/la ***PROVIDER_NAME*** estará realizando una consulta virtual.

Para ingresar a la videoconsulta, presione en el siguiente enlace a la hora programada:
***JITSI_LINK***

Recomendaciones:
• Asegúrese de tener una conexión estable a internet
• Pruebe su cámara y micrófono antes de la consulta
• Busque un lugar tranquilo y privado
• Tenga a mano su documento y credencial de obra social

¡Gracias!',
 'Enlace de Teleconsulta - ***DATE***',
 '<html><body style="font-family: Arial, sans-serif; color: #333;">
        <div style="max-width: 600px; margin: 0 auto; border: 1px solid #28a745; border-radius: 8px;">
            <div style="background-color: #28a745; color: white; padding: 15px; text-align: center;">
                <h3 style="margin: 0;">Enlace de su Video-Consulta</h3>
            </div>
            <div style="padding: 20px;">
                <p>Estimado/a <strong>***PATIENT_NAME***</strong>,</p>
                <p>Su consulta virtual con <strong>***PROVIDER_NAME***</strong> es el día <strong>***DATE***</strong> a las <strong>***TIME***</strong> hs.</p>
                <p style="text-align: center; margin: 30px 0;">
                    <a href="***JITSI_LINK***" style="background-color: #28a745; color: white; padding: 12px 25px; text-decoration: none; border-radius: 5px; font-weight: bold;">INGRESAR A LA CONSULTA</a>
                </p>
                <div style="background-color: #f1f1f1; padding: 15px; font-size: 0.9em; border-radius: 5px;">
                    <strong>Recomendaciones:</strong>
                    <ul>
                        <li>Asegúrese de tener buena conexión a Internet.</li>
                        <li>Busque un lugar privado y con buena luz.</li>
                        <li>Tenga sus estudios o credenciales a mano.</li>
                    </ul>
                </div>
            </div>
        </div>
        </body></html>');

-- -----------------------------------------------------------------------
-- Provider: Consulta Virtual - Scheduled
-- -----------------------------------------------------------------------
INSERT INTO `wsp_email_notification_templates` 
(`facility_id`, `pc_catid`, `category_name`, `pc_apptstatus`, `recipient_type`, `wsp_message`, `email_subject`, `email_message`) VALUES
(3, 80, 'Consulta Virtual', '-scheduled', 'provider',
 'Recordatorio de Teleconsulta

Paciente: ***PATIENT_NAME***
📅 Fecha: ***DATE***
⏰ Hora: ***TIME*** hs.

Ingresar a la videoconsulta:
***JITSI_LINK***',
 'Agenda Virtual: ***PATIENT_NAME***',
 '<html><body>
        <h3>Recordatorio de Teleconsulta</h3>
        <p>Tiene una consulta virtual pendiente con el paciente <strong>***PATIENT_NAME***</strong>.</p>
        <p><strong>Fecha/Hora:</strong> ***DATE*** a las ***TIME*** hs.</p>
        <p><strong>Acceda mediante este link:</strong> <a href="***JITSI_LINK***">***JITSI_LINK***</a></p>
        </body></html>');

SELECT 'Templates for facility 3 restored successfully!' AS status;
