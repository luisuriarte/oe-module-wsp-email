-- ===========================================================================
-- Ejemplos de configuración de slots de notificación
-- ===========================================================================
-- Cada fila en wsp_email_notification_schedule representa un envío de notificación.
-- 
-- Campos:
--   facility_id:     ID de la facility (de facility.id)
--   seq:             Orden de envío (1=primero, 2=segundo, etc.)
--   hours_before:    Horas antes de la cita para enviar (ignorado si send_on_booking=1)
--   send_on_booking: 1=enviar inmediatamente al crear la cita, 0=usar hours_before
--   enabled_wsp:     1=enviar por WhatsApp, 0=no enviar
--   enabled_email:   1=enviar por Email, 0=no enviar
-- ===========================================================================

-- ---------------------------------------------------------------------------
-- EJEMPLO 1: Notificación única 48 horas antes (configuración básica)
-- ---------------------------------------------------------------------------
DELETE FROM wsp_email_notification_schedule WHERE facility_id = 3;

INSERT INTO wsp_email_notification_schedule 
    (facility_id, seq, hours_before, send_on_booking, enabled_wsp, enabled_email)
VALUES 
    (3, 1, 48, 0, 1, 1);

-- ---------------------------------------------------------------------------
-- EJEMPLO 2: Dos notificaciones (72h y 24h antes)
-- ---------------------------------------------------------------------------
DELETE FROM wsp_email_notification_schedule WHERE facility_id = 3;

INSERT INTO wsp_email_notification_schedule 
    (facility_id, seq, hours_before, send_on_booking, enabled_wsp, enabled_email)
VALUES 
    (3, 1, 72, 0, 1, 1),  -- 72 horas antes (3 días)
    (3, 2, 24, 0, 1, 1);  -- 24 horas antes (1 día)

-- ---------------------------------------------------------------------------
-- EJEMPLO 3: Tres notificaciones (confirmación + 2 recordatorios)
-- ---------------------------------------------------------------------------
DELETE FROM wsp_email_notification_schedule WHERE facility_id = 3;

INSERT INTO wsp_email_notification_schedule 
    (facility_id, seq, hours_before, send_on_booking, enabled_wsp, enabled_email)
VALUES 
    (3, 1, 0,  1, 1, 1),  -- Inmediato al crear (on booking)
    (3, 2, 48, 0, 1, 1),  -- 48 horas antes
    (3, 3, 24, 0, 1, 1);  -- 24 horas antes

-- ---------------------------------------------------------------------------
-- EJEMPLO 4: Solo WhatsApp, múltiples recordatorios
-- ---------------------------------------------------------------------------
DELETE FROM wsp_email_notification_schedule WHERE facility_id = 3;

INSERT INTO wsp_email_notification_schedule 
    (facility_id, seq, hours_before, send_on_booking, enabled_wsp, enabled_email)
VALUES 
    (3, 1, 0,  1, 1, 0),  -- Inmediato al crear (solo WhatsApp)
    (3, 2, 72, 0, 1, 0),  -- 72 horas antes (solo WhatsApp)
    (3, 3, 24, 0, 1, 0);  -- 24 horas antes (solo WhatsApp)

-- ---------------------------------------------------------------------------
-- EJEMPLO 5: Solo Email, múltiples recordatorios
-- ---------------------------------------------------------------------------
DELETE FROM wsp_email_notification_schedule WHERE facility_id = 3;

INSERT INTO wsp_email_notification_schedule 
    (facility_id, seq, hours_before, send_on_booking, enabled_wsp, enabled_email)
VALUES 
    (3, 1, 0,  1, 0, 1),  -- Inmediato al crear (solo Email)
    (3, 2, 48, 0, 0, 1),  -- 48 horas antes (solo Email)
    (3, 3, 12, 0, 0, 1);  -- 12 horas antes (solo Email)

-- ---------------------------------------------------------------------------
-- EJEMPLO 6: Configuración intensiva (máximos recordatorios)
-- ---------------------------------------------------------------------------
DELETE FROM wsp_email_notification_schedule WHERE facility_id = 3;

INSERT INTO wsp_email_notification_schedule 
    (facility_id, seq, hours_before, send_on_booking, enabled_wsp, enabled_email)
VALUES 
    (3, 1, 0,   1, 1, 1),  -- Inmediato al crear
    (3, 2, 168, 0, 1, 1),  -- 7 días antes (168 horas)
    (3, 3, 72,  0, 1, 1),  -- 3 días antes (72 horas)
    (3, 4, 48,  0, 1, 1),  -- 2 días antes (48 horas)
    (3, 5, 24,  0, 1, 1),  -- 1 día antes (24 horas)
    (3, 6, 12,  0, 1, 1),  -- 12 horas antes
    (3, 7, 2,   0, 1, 1);  -- 2 horas antes

-- ---------------------------------------------------------------------------
-- CONSULTAS ÚTILES
-- ---------------------------------------------------------------------------

-- Ver configuración actual de una facility
SELECT 
    wns.seq,
    wns.hours_before,
    CASE WHEN wns.send_on_booking = 1 THEN 'INMEDIATO' 
         ELSE CONCAT(wns.hours_before, 'h antes') 
    END AS cuando,
    CASE WHEN wns.enabled_wsp = 1 THEN '✓' ELSE '✗' END AS whatsapp,
    CASE WHEN wns.enabled_email = 1 THEN '✓' ELSE '✗' END AS email,
    f.name AS facility_name
FROM wsp_email_notification_schedule wns
LEFT JOIN facility f ON f.id = wns.facility_id
WHERE wns.facility_id = 3
ORDER BY wns.seq;

-- Ver todas las facilities con su configuración
SELECT 
    f.id,
    f.name,
    COUNT(wns.id) AS slots_configurados,
    GROUP_CONCAT(
        CASE WHEN wns.send_on_booking = 1 THEN 'ON_BOOKING'
             ELSE CONCAT(wns.hours_before, 'h')
        END
        ORDER BY wns.seq SEPARATOR ', '
    ) AS slots
FROM facility f
LEFT JOIN wsp_email_notification_schedule wns ON wns.facility_id = f.id
GROUP BY f.id, f.name
ORDER BY f.name;

-- ---------------------------------------------------------------------------
-- RECOMENDACIONES
-- ---------------------------------------------------------------------------
-- 1. Para consultorios médicos: Usar Ejemplo 3 (confirmación + 2 recordatorios)
-- 2. Para clínicas grandes: Usar Ejemplo 6 (máximos recordatorios)
-- 3. Para bajo costo: Usar Ejemplo 1 (solo 48h antes)
-- 4. Evitar más de 5 slots para no molestar a los pacientes
-- 5. Usar send_on_booking=1 solo para el primer slot (seq=1)
