-- ===========================================================================
-- Ejemplos de configuración de slots de notificación para recalls
-- ===========================================================================
INSERT INTO openemr.wsp_email_notification_templates (facility_id,notification_type,pc_catid,category_name,pc_apptstatus,recipient_type,wsp_message,email_subject,email_message) VALUES
	 (3,'recall',0,'Recall','recall','patient','Hola ***PATIENT_FIRSTNAME*** 👋

Le recordamos desde ****FACILITY_NAME**** que tiene pendiente una consulta de seguimiento.

📅 *Fecha sugerida:* ***RECALL_DATE***
👨‍⚕️ *Profesional:* ***PROVIDER_NAME***
📋 *Motivo:* ***RECALL_REASON***

Para coordinar su turno comuníquese con nosotros:
📞 ***FACILITY_PHONE***
📍 ***FACILITY_ADDRESS***

_Por favor no responda este mensaje si ya tiene turno agendado._','Recordatorio para Control','<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Recordatorio de Seguimiento</title>
</head>
<body style="margin:0; padding:0; background-color:#f4f4f4; font-family: Arial, sans-serif;">

  <table width="100%" cellpadding="0" cellspacing="0" style="background-color:#f4f4f4; padding: 30px 0;">
    <tr>
      <td align="center">
        <table width="600" cellpadding="0" cellspacing="0" style="background-color:#ffffff; border-radius:8px; overflow:hidden; box-shadow: 0 2px 8px rgba(0,0,0,0.08);">

          <!-- Header -->
          <tr>
            <td style="background-color:#2c6fad; padding: 30px; text-align:center;">
              <h1 style="margin:0; color:#ffffff; font-size:22px; font-weight:600;">
                Recordatorio de Consulta de Seguimiento
              </h1>
            </td>
          </tr>

          <!-- Saludo -->
          <tr>
            <td style="padding: 30px 40px 10px 40px;">
              <p style="margin:0; font-size:16px; color:#333333;">
                Estimado/a <strong>***PATIENT_NAME***</strong>,
              </p>
              <p style="margin:12px 0 0 0; font-size:15px; color:#555555; line-height:1.6;">
                Le recordamos desde <strong>***FACILITY_NAME***</strong> que tiene pendiente
                una consulta de seguimiento. Le pedimos que se comunique con nosotros para
                coordinar su turno.
              </p>
            </td>
          </tr>

          <!-- Datos del recall -->
          <tr>
            <td style="padding: 20px 40px;">
              <table width="100%" cellpadding="12" cellspacing="0"
                     style="background-color:#f0f6ff; border-radius:6px; border-left: 4px solid #2c6fad;">
                <tr>
                  <td style="font-size:14px; color:#444444;">
                    <p style="margin:0 0 8px 0;">📅 <strong>Fecha sugerida:</strong> ***RECALL_DATE***</p>
                    <p style="margin:0 0 8px 0;">👨‍⚕️ <strong>Profesional:</strong> ***PROVIDER_NAME***</p>
                    <p style="margin:0;">📋 <strong>Motivo:</strong> ***RECALL_REASON***</p>
                  </td>
                </tr>
              </table>
            </td>
          </tr>

          <!-- Contacto -->
          <tr>
            <td style="padding: 10px 40px 30px 40px;">
              <p style="margin:0 0 6px 0; font-size:15px; color:#555555;">
                Para agendar su turno comuníquese con nosotros:
              </p>
              <p style="margin:0; font-size:14px; color:#444444;">
                📞 <strong>***FACILITY_PHONE***</strong><br>
                📍 ***FACILITY_ADDRESS***
              </p>
            </td>
          </tr>

          <!-- Footer -->
          <tr>
            <td style="background-color:#f4f4f4; padding: 20px 40px; border-top: 1px solid #e0e0e0;">
              <p style="margin:0; font-size:12px; color:#999999; text-align:center;">
                Este es un mensaje automático de <strong>***FACILITY_NAME***</strong>.<br>
                Si ya tiene turno agendado, por favor ignore este mensaje.
              </p>
            </td>
          </tr>

        </table>
      </td>
    </tr>
  </table>

</body>
</html>');
