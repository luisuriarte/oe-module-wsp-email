-- ---------------------------------------------------------------
-- Language Custom Translations for oe-module-wsp-email
-- Spanish (Latin American) / lang_code = el
-- ---------------------------------------------------------------

START TRANSACTION;

INSERT IGNORE INTO `lang_custom` (`lang_description`, `lang_code`, `constant_name`, `definition`) VALUES

-- openemr.bootstrap.php (menu labels)
('Spanish (Latin American)', 'el', 'Notificaciones WSP/Email',   'Notificaciones WSP/Email'),
('Spanish (Latin American)', 'el', 'Estado de Pacientes',       'Estado de Pacientes'),
('Spanish (Latin American)', 'el', 'Config. Centros',           'Config. Centros'),
('Spanish (Latin American)', 'el', 'Config. Notificaciones',    'Config. Notificaciones'),
('Spanish (Latin American)', 'el', 'WSP/Email Notificaciones',  'WSP/Email Notificaciones'),

-- Dashboard page title / header
('Spanish (Latin American)', 'el', 'WSP / Email Notifications',            'Notificaciones WSP / Email'),
('Spanish (Latin American)', 'el', 'WSP / Email Notification Center',       'Centro de Notificaciones WSP / Email'),
('Spanish (Latin American)', 'el', 'Automatic appointment reminders via WhatsApp and Email', 'Recordatorios automáticos de citas vía WhatsApp y Email'),

-- Tabs
('Spanish (Latin American)', 'el', 'Dashboard',      'Tablero'),
('Spanish (Latin American)', 'el', 'Patient Status', 'Estado de Pacientes'),
('Spanish (Latin American)', 'el', 'Schedules',       'Horarios'),
('Spanish (Latin American)', 'el', 'Facilities',      'Centros'),
('Spanish (Latin American)', 'el', 'Recalls',         'Reconvocatorias'),
('Spanish (Latin American)', 'el', 'Blacklist',       'Lista Negra'),

-- Summary cards
('Spanish (Latin American)', 'el', 'WhatsApp sent',  'WhatsApp enviados'),
('Spanish (Latin American)', 'el', 'Emails sent',    'Emails enviados'),
('Spanish (Latin American)', 'el', 'last 7 days',    'últimos 7 días'),
('Spanish (Latin American)', 'el', 'Pending',        'Pendientes'),
('Spanish (Latin American)', 'el', 'Errors',         'Errores'),

-- Dashboard filter labels
('Spanish (Latin American)', 'el', 'From',         'Desde'),
('Spanish (Latin American)', 'el', 'To',           'Hasta'),
('Spanish (Latin American)', 'el', 'Facility',     'Centro'),
('Spanish (Latin American)', 'el', 'All Facilities', 'Todos los Centros'),
('Spanish (Latin American)', 'el', 'Refresh',      'Actualizar'),
('Spanish (Latin American)', 'el', 'PDF Report',   'Reporte PDF'),
('Spanish (Latin American)', 'el', 'Filter by Status:', 'Filtrar por Estado:'),
('Spanish (Latin American)', 'el', 'Send Now',     'Enviar Ahora'),
('Spanish (Latin American)', 'el', 'Runs WSP and Email immediately, respecting the schedule.', 'Ejecuta WSP y Email inmediatamente, respetando el horario.'),
('Spanish (Latin American)', 'el', 'Log',          'Registro'),

-- Notification log filters
('Spanish (Latin American)', 'el', 'Search Patient',         'Buscar Paciente'),
('Spanish (Latin American)', 'el', 'Name, Phone or PID...',  'Nombre, Teléfono o ID...'),
('Spanish (Latin American)', 'el', 'Channels',              'Canales'),
('Spanish (Latin American)', 'el', 'Status',                'Estado'),
('Spanish (Latin American)', 'el', 'All Status',            'Todos los Estados'),
('Spanish (Latin American)', 'el', 'Queued',                'En Cola'),
('Spanish (Latin American)', 'el', 'Sent',                  'Enviado'),
('Spanish (Latin American)', 'el', 'Delivered',             'Entregado'),
('Spanish (Latin American)', 'el', 'Read',                  'Leído'),
('Spanish (Latin American)', 'el', 'Failed',                'Fallido'),
('Spanish (Latin American)', 'el', 'Invalid',               'Inválido'),
('Spanish (Latin American)', 'el', 'Error',                 'Error'),
('Spanish (Latin American)', 'el', 'Unsent',                'No Enviado'),
('Spanish (Latin American)', 'el', 'Search',                'Buscar'),

-- Notification log table
('Spanish (Latin American)', 'el', 'Patient',      'Paciente'),
('Spanish (Latin American)', 'el', 'Phone',        'Teléfono'),
('Spanish (Latin American)', 'el', 'Appt. Details', 'Detalles de Cita'),
('Spanish (Latin American)', 'el', 'Channel',      'Canal'),
('Spanish (Latin American)', 'el', 'Sent Date',    'Fecha de Envío'),
('Spanish (Latin American)', 'el', 'Last Status',  'Último Estado'),
('Spanish (Latin American)', 'el', 'Actions',      'Acciones'),
('Spanish (Latin American)', 'el', 'Enter a search term above.', 'Ingrese un término de búsqueda arriba.'),

-- Schedule tab
('Spanish (Latin American)', 'el', 'Upcoming Appointments - Manual Notifications', 'Próximas Citas - Notificaciones Manuales'),
('Spanish (Latin American)', 'el', 'From Date',    'Desde'),
('Spanish (Latin American)', 'el', 'To Date',      'Hasta'),
('Spanish (Latin American)', 'el', 'Appt Status',  'Estado de Cita'),
('Spanish (Latin American)', 'el', 'All Statuses', 'Todos los Estados'),
('Spanish (Latin American)', 'el', 'Load',         'Cargar'),
('Spanish (Latin American)', 'el', 'Date/Time',    'Fecha/Hora'),
('Spanish (Latin American)', 'el', 'Provider',     'Profesional'),
('Spanish (Latin American)', 'el', 'Type',         'Tipo'),
('Spanish (Latin American)', 'el', 'Pt. Actions',  'Acciones Pac.'),
('Spanish (Latin American)', 'el', 'Prov. Actions', 'Acciones Prof.'),
('Spanish (Latin American)', 'el', 'Click Load to fetch appointments.', 'Haga clic en Cargar para obtener las citas.'),

-- Facility config tab
('Spanish (Latin American)', 'el', 'Select a facility to configure',       'Seleccione un centro para configurar'),
('Spanish (Latin American)', 'el', 'Centro desactivado. Activelo para su uso', 'Centro desactivado. Actívalo para su uso'),
('Spanish (Latin American)', 'el', 'Not configured',                       'No configurado'),
('Spanish (Latin American)', 'el', 'Este centro está desactivado. No se permite editar su configuración.', 'Este centro está desactivado. No se permite editar su configuración.'),
('Spanish (Latin American)', 'el', 'WhatsApp Gateway',                    'Pasarela WhatsApp'),
('Spanish (Latin American)', 'el', 'Select Vendor',                       'Seleccionar Proveedor'),
('Spanish (Latin American)', 'el', 'Active vendor for sending WhatsApp messages', 'Proveedor activo para el envío de mensajes WhatsApp'),

-- UltraMsg
('Spanish (Latin American)', 'el', 'UltraMsg Credentials',     'Credenciales UltraMsg'),
('Spanish (Latin American)', 'el', 'Instance ID',              'ID de Instancia'),
('Spanish (Latin American)', 'el', 'API Token',                'Token API'),

-- WaSenderAPI
('Spanish (Latin American)', 'el', 'WaSenderAPI Credentials',      'Credenciales WaSenderAPI'),
('Spanish (Latin American)', 'el', 'API Key / Token',              'Clave API / Token'),
('Spanish (Latin American)', 'el', 'Webhook Secret',              'Secreto del Webhook'),

-- OpenWA
('Spanish (Latin American)', 'el', 'OpenWA Credentials',          'Credenciales OpenWA'),
('Spanish (Latin American)', 'el', 'Session ID (Instance)',       'ID de Sesión (Instancia)'),
('Spanish (Latin American)', 'el', 'API Key',                     'Clave API'),

-- Evolution-Go
('Spanish (Latin American)', 'el', 'Evolution-Go Credentials',                'Credenciales Evolution-Go'),
('Spanish (Latin American)', 'el', 'Base URL',                                'URL Base'),
('Spanish (Latin American)', 'el', 'Instance Name',                           'Nombre de Instancia'),
('Spanish (Latin American)', 'el', 'Webhook URL (configure in Evolution-Go dashboard)', 'URL del Webhook (configurar en el panel de Evolution-Go)'),
('Spanish (Latin American)', 'el', 'Copy this URL to your Evolution-Go instance webhook settings.', 'Copie esta URL en la configuración del webhook de su instancia Evolution-Go.'),

-- Email config
('Spanish (Latin American)', 'el', 'Email',                                      'Email'),
('Spanish (Latin American)', 'el', 'Email Logo',                                 'Logo de Email'),
('Spanish (Latin American)', 'el', 'Logos will be saved in images/logo_wsp and images/logo_email/', 'Los logos se guardarán en images/logo_wsp y images/logo_email/'),
('Spanish (Latin American)', 'el', 'WSP Logo',                                   'Logo WSP'),

-- Facility details
('Spanish (Latin American)', 'el', 'Facility Details',                'Detalles del Centro'),
('Spanish (Latin American)', 'el', 'Location Picker',                 'Selector de Ubicación'),
('Spanish (Latin American)', 'el', 'Click on the map to select coordinates', 'Haga clic en el mapa para seleccionar coordenadas'),
('Spanish (Latin American)', 'el', 'Search address or city...',       'Buscar dirección o ciudad...'),
('Spanish (Latin American)', 'el', 'Website URL is taken from the standard facility configuration.', 'La URL del sitio web se toma de la configuración estándar del centro.'),
('Spanish (Latin American)', 'el', 'Latitude',                        'Latitud'),
('Spanish (Latin American)', 'el', 'Longitude',                       'Longitud'),

-- Channel enable/disable
('Spanish (Latin American)', 'el', 'Channel Enable/Disable',       'Habilitar/Deshabilitar Canales'),
('Spanish (Latin American)', 'el', 'WhatsApp enabled',             'WhatsApp habilitado'),
('Spanish (Latin American)', 'el', 'Email enabled',                'Email habilitado'),
('Spanish (Latin American)', 'el', 'Notify on cancellation',       'Notificar al cancelar'),

-- Sending window
('Spanish (Latin American)', 'el', 'Notification Sending Window',      'Ventana de Envío de Notificaciones'),
('Spanish (Latin American)', 'el', 'Monday - Friday',                   'Lunes - Viernes'),
('Spanish (Latin American)', 'el', 'Start',                             'Inicio'),
('Spanish (Latin American)', 'el', 'End',                               'Fin'),
('Spanish (Latin American)', 'el', 'Saturday',                          'Sábado'),
('Spanish (Latin American)', 'el', 'Sunday',                            'Domingo'),
('Spanish (Latin American)', 'el', '(When disabled, no messages are sent on that day)', '(Cuando está deshabilitado, no se envían mensajes ese día)'),

-- Notification schedule
('Spanish (Latin American)', 'el', 'Notification Schedule',                                             'Programación de Notificaciones'),
('Spanish (Latin American)', 'el', 'Define how many notifications are sent per appointment and when.',   'Define cuántas notificaciones se envían por cita y cuándo.'),
('Spanish (Latin American)', 'el', '"Send on booking" fires immediately when the appointment is created.','"Enviar al reservar" se dispara inmediatamente al crear la cita.'),
('Spanish (Latin American)', 'el', 'Other slots fire N hours before the appointment (via cron).',         'Los demás slots se disparan N horas antes de la cita (vía cron).'),
('Spanish (Latin American)', 'el', 'Send on booking?',        '¿Enviar al reservar?'),
('Spanish (Latin American)', 'el', 'Hours before appt.',      'Horas antes de la cita'),
('Spanish (Latin American)', 'el', 'Via WSP',                 'Vía WSP'),
('Spanish (Latin American)', 'el', 'Via Email',               'Vía Email'),
('Spanish (Latin American)', 'el', 'Add notification slot',    'Agregar slot de notificación'),
('Spanish (Latin American)', 'el', 'Save Configuration',       'Guardar Configuración'),
('Spanish (Latin American)', 'el', 'Cancel',                   'Cancelar'),
('Spanish (Latin American)', 'el', 'Templates',                'Plantillas'),
('Spanish (Latin American)', 'el', 'Saved!',                   '¡Guardado!'),

-- Recall schedule
('Spanish (Latin American)', 'el', 'Recall Notification Schedule',                                            'Programación de Notificaciones de Reconvocatoria'),
('Spanish (Latin American)', 'el', 'Add sequence',                                                             'Agregar secuencia'),
('Spanish (Latin American)', 'el', 'Define how many recall notifications are sent per recall event and how many days before the recall date.', 'Define cuántas notificaciones de reconvocatoria se envían por evento y cuántos días antes de la fecha de reconvocatoria.'),
('Spanish (Latin American)', 'el', 'Days before recall date',  'Días antes de la fecha de reconvocatoria'),
('Spanish (Latin American)', 'el', 'Active',                   'Activo'),
('Spanish (Latin American)', 'el', 'Recall Message Template',  'Plantilla de Mensaje de Reconvocatoria'),
('Spanish (Latin American)', 'el', 'Available tokens:',        'Tokens disponibles:'),
('Spanish (Latin American)', 'el', 'WhatsApp Message',         'Mensaje WhatsApp'),
('Spanish (Latin American)', 'el', 'WhatsApp recall message...', 'Mensaje de reconvocatoria WhatsApp...'),
('Spanish (Latin American)', 'el', 'Email Subject',            'Asunto del Email'),
('Spanish (Latin American)', 'el', 'Recall reminder subject...', 'Asunto del recordatorio de reconvocatoria...'),
('Spanish (Latin American)', 'el', 'Email Body (HTML)',        'Cuerpo del Email (HTML)'),
('Spanish (Latin American)', 'el', 'HTML email body for recall reminder...', 'Cuerpo HTML del email para recordatorio de reconvocatoria...'),
('Spanish (Latin American)', 'el', 'Template enabled',         'Plantilla habilitada'),
('Spanish (Latin American)', 'el', 'Save Recall Config',       'Guardar Configuración de Reconvocatoria'),
('Spanish (Latin American)', 'el', 'Access to Facility Configuration requires administrator permissions.', 'El acceso a la Configuración de Centros requiere permisos de administrador.'),

-- Active recalls tab
('Spanish (Latin American)', 'el', 'Active Recalls - Pending Notifications', 'Reconvocatorias Activas - Notificaciones Pendientes'),
('Spanish (Latin American)', 'el', 'Horizon (days):',                        'Horizonte (días):'),
('Spanish (Latin American)', 'el', 'Send Recalls Now',                       'Enviar Reconvocatorias Ahora'),
('Spanish (Latin American)', 'el', 'My Recalls',                             'Mis Reconvocatorias'),
('Spanish (Latin American)', 'el', 'New Recall',                             'Nueva Reconvocatoria'),
('Spanish (Latin American)', 'el', 'Search All Recalls',                     'Buscar Todas las Reconvocatorias'),
('Spanish (Latin American)', 'el', 'Notif. Status',                          'Estado de Notif.'),
('Spanish (Latin American)', 'el', 'All',                                    'Todos'),
('Spanish (Latin American)', 'el', 'Not sent yet',                           'No enviado aún'),
('Spanish (Latin American)', 'el', 'Skipped',                                'Omitido'),

-- Recall table
('Spanish (Latin American)', 'el', 'Phone / Email',   'Teléfono / Email'),
('Spanish (Latin American)', 'el', 'Recall Date',     'Fecha de Reconvocatoria'),
('Spanish (Latin American)', 'el', 'Reason',          'Motivo'),
('Spanish (Latin American)', 'el', 'Sequences',       'Secuencias'),

-- Recall run log
('Spanish (Latin American)', 'el', 'Recall Run Log', 'Registro de Ejecución de Reconvocatorias'),

-- Blacklist tab
('Spanish (Latin American)', 'el', 'Blacklisted Numbers',               'Números en Lista Negra'),
('Spanish (Latin American)', 'el', 'Add Number',                         'Agregar Número'),
('Spanish (Latin American)', 'el', 'Global',                             'Global'),
('Spanish (Latin American)', 'el', 'Vendor',                             'Proveedor'),
('Spanish (Latin American)', 'el', 'All Vendors',                        'Todos los Proveedores'),
('Spanish (Latin American)', 'el', 'All (global)',                       'Todos (global)'),
('Spanish (Latin American)', 'el', 'All Reasons',                        'Todos los Motivos'),
('Spanish (Latin American)', 'el', 'Manual',                             'Manual'),
('Spanish (Latin American)', 'el', 'Invalid Number',                     'Número Inválido'),
('Spanish (Latin American)', 'el', 'Too Many Failures',                  'Demasiados Fallos'),
('Spanish (Latin American)', 'el', 'Tracking (not blocked)',             'Seguimiento (no bloqueado)'),
('Spanish (Latin American)', 'el', 'Active (blocked)',                   'Activo (bloqueado)'),
('Spanish (Latin American)', 'el', 'Inactive (released)',                'Inactivo (liberado)'),
('Spanish (Latin American)', 'el', 'Search Phone/Notes',                 'Buscar Teléfono/Notas'),
('Spanish (Latin American)', 'el', 'Search...',                          'Buscar...'),

-- Blacklist table
('Spanish (Latin American)', 'el', 'Failures',       'Fallos'),
('Spanish (Latin American)', 'el', 'Notes',          'Notas'),
('Spanish (Latin American)', 'el', 'Last Updated',   'Última Actualización'),
('Spanish (Latin American)', 'el', 'Created',        'Creado'),
('Spanish (Latin American)', 'el', 'Access to Blacklist requires administrator permissions.', 'El acceso a la Lista Negra requiere permisos de administrador.'),

-- Add to blacklist modal
('Spanish (Latin American)', 'el', 'Add to Blacklist',             'Agregar a Lista Negra'),
('Spanish (Latin American)', 'el', 'Global (all facilities)',      'Global (todos los centros)'),
('Spanish (Latin American)', 'el', 'All (global block)',           'Todos (bloqueo global)'),
('Spanish (Latin American)', 'el', 'Phone Number',                 'Número de Teléfono'),
('Spanish (Latin American)', 'el', 'International format without + (e.g. 5491134567890)', 'Formato internacional sin + (ej. 5491134567890)'),
('Spanish (Latin American)', 'el', 'Reason for manual blacklisting...', 'Motivo del bloqueo manual...'),

-- JS statuses / messages
('Spanish (Latin American)', 'el', 'Sending...',                    'Enviando...'),
('Spanish (Latin American)', 'el', 'Running...',                    'Ejecutando...'),
('Spanish (Latin American)', 'el', 'No output.',                    'Sin salida.'),
('Spanish (Latin American)', 'el', 'Please select at least one channel.', 'Seleccione al menos un canal.'),
('Spanish (Latin American)', 'el', 'No records found for the selected criteria.', 'No se encontraron registros para los criterios seleccionados.'),
('Spanish (Latin American)', 'el', 'Recall',                        'Reconvocatoria'),
('Spanish (Latin American)', 'el', 'View Details',                  'Ver Detalles'),
('Spanish (Latin American)', 'el', 'Sync Status',                   'Sincronizar Estado'),
('Spanish (Latin American)', 'el', 'Resend',                        'Reenviar'),
('Spanish (Latin American)', 'el', 'No history recorded for this notification.', 'No hay historial registrado para esta notificación.'),
('Spanish (Latin American)', 'el', 'INACTIVO',                      'INACTIVO'),
('Spanish (Latin American)', 'el', 'Configuration',                 'Configuración'),

-- Location
('Spanish (Latin American)', 'el', 'Location not found', 'Ubicación no encontrada'),

-- Appointment actions
('Spanish (Latin American)', 'el', 'Send WhatsApp to Patient', 'Enviar WhatsApp al Paciente'),
('Spanish (Latin American)', 'el', 'Send Email to Patient',    'Enviar Email al Paciente'),
('Spanish (Latin American)', 'el', 'Cancelled - no notifications sent', 'Cancelada - no se enviaron notificaciones'),
('Spanish (Latin American)', 'el', 'Already notified/confirmed',  'Ya notificado/confirmado'),
('Spanish (Latin American)', 'el', 'Appointment already passed',  'La cita ya pasó'),
('Spanish (Latin American)', 'el', 'Send WhatsApp to Provider',   'Enviar WhatsApp al Profesional'),
('Spanish (Latin American)', 'el', 'Send Email to Provider',      'Enviar Email al Profesional'),
('Spanish (Latin American)', 'el', 'Only for Telehealth and HBC', 'Solo para Telemedicina y Domicilio'),
('Spanish (Latin American)', 'el', 'Provider has no phone/email configured', 'El profesional no tiene teléfono/email configurado'),

-- Manual notification modal
('Spanish (Latin American)', 'el', 'Notification Lifecycle', 'Ciclo de Vida de la Notificación'),
('Spanish (Latin American)', 'el', 'Close',                  'Cerrar'),
('Spanish (Latin American)', 'el', 'Manual Notification',     'Notificación Manual'),
('Spanish (Latin American)', 'el', 'To:',                    'Para:'),
('Spanish (Latin American)', 'el', 'Contact:',               'Contacto:'),
('Spanish (Latin American)', 'el', 'Channel:',               'Canal:'),
('Spanish (Latin American)', 'el', 'Message Content:',       'Contenido del Mensaje:'),
('Spanish (Latin American)', 'el', 'Edit the message if needed.', 'Edite el mensaje si es necesario.'),
('Spanish (Latin American)', 'el', 'Open & Log',             'Abrir y Registrar'),

-- Templates modal
('Spanish (Latin American)', 'el', 'Category',    'Categoría'),
('Spanish (Latin American)', 'el', 'Recipient',   'Destinatario'),
('Spanish (Latin American)', 'el', 'Add',         'Agregar'),
('Spanish (Latin American)', 'el', 'Add Template', 'Agregar Plantilla'),
('Spanish (Latin American)', 'el', 'Save Changes', 'Guardar Cambios'),

-- Pagination
('Spanish (Latin American)', 'el', 'records', 'registros'),
('Spanish (Latin American)', 'el', 'Page',    'Página'),

-- JS confirmations
('Spanish (Latin American)', 'el', 'Delete permanently',        'Eliminar permanentemente'),
('Spanish (Latin American)', 'el', 'Error loading data',        'Error al cargar datos'),
('Spanish (Latin American)', 'el', 'Permanently delete this record for', 'Eliminar permanentemente este registro de'),

-- Recall pending section
('Spanish (Latin American)', 'el', 'No pending recall notifications in the next', 'No hay notificaciones de reconvocatoria pendientes en los próximos'),
('Spanish (Latin American)', 'el', 'days.', 'días.'),
('Spanish (Latin American)', 'el', 'Scheduled For', 'Programado Para'),
('Spanish (Latin American)', 'el', 'Recall Reason', 'Motivo de Reconvocatoria'),
('Spanish (Latin American)', 'el', 'Seq',           'Sec'),
('Spanish (Latin American)', 'el', 'Urgency',       'Urgencia'),

-- Recall entry modal
('Spanish (Latin American)', 'el', 'Patient Search',                    'Búsqueda de Paciente'),
('Spanish (Latin American)', 'el', 'Edit Recall',                       'Editar Reconvocatoria'),
('Spanish (Latin American)', 'el', 'Please select a patient first',     'Seleccione un paciente primero'),
('Spanish (Latin American)', 'el', 'Please select an event date',       'Seleccione una fecha de evento'),
('Spanish (Latin American)', 'el', 'Please select a facility',          'Seleccione un centro'),
('Spanish (Latin American)', 'el', 'Event Date',                         'Fecha del Evento'),
('Spanish (Latin American)', 'el', 'Select',                             'Seleccionar'),
('Spanish (Latin American)', 'el', 'None',                               'Ninguno'),
('Spanish (Latin American)', 'el', 'Save',                               'Guardar'),

-- xla strings (used in HTML attributes - must also be in lang_custom)
('Spanish (Latin American)', 'el', 'Click to select a patient', 'Haga clic para seleccionar un paciente'),

-- LocalizationHelper.php
('Spanish (Latin American)', 'el', 'Press the attachment to save your appointment.', 'Presione el adjunto para guardar su cita.'),

-- EmailSender.php
('Spanish (Latin American)', 'el', 'Appointment reminder',                                     'Recordatorio de cita'),
('Spanish (Latin American)', 'el', 'View location in Google Maps',                             'Ver ubicación en Google Maps'),
('Spanish (Latin American)', 'el', 'Add to Google Calendar',                                   'Agregar a Google Calendar'),
('Spanish (Latin American)', 'el', 'Website',                                                   'Sitio web'),
('Spanish (Latin American)', 'el', 'A calendar file (.ics) is attached — open it to save the appointment directly in your calendar application.', 'Se adjunta un archivo de calendario (.ics) — ábralo para guardar la cita directamente en su aplicación de calendario.'),

-- WspSender.php
('Spanish (Latin American)', 'el', 'Appointment at', 'Cita en'),
('Spanish (Latin American)', 'el', 'Clinic',         'Consultorio'),

-- install.sql list_options titles
('Spanish (Latin American)', 'el', 'WSP: Sent',      'WSP: Enviado'),
('Spanish (Latin American)', 'el', 'WSP: Delivered', 'WSP: Entregado'),
('Spanish (Latin American)', 'el', 'WSP: Read',      'WSP: Leído'),
('Spanish (Latin American)', 'el', 'WSP: Error',     'WSP: Error'),

-- Additional JS strings from dashboard.php
('Spanish (Latin American)', 'el', 'Delete',                              'Eliminar'),
('Spanish (Latin American)', 'el', 'Edit',                                'Editar'),
('Spanish (Latin American)', 'el', 'Released',                            'Liberado'),
('Spanish (Latin American)', 'el', 'Not sent',                            'No enviado'),
('Spanish (Latin American)', 'el', 'Blocked',                             'Bloqueado'),
('Spanish (Latin American)', 'el', 'Network error.',                      'Error de red.'),
('Spanish (Latin American)', 'el', 'Delete this recall entry?',           '¿Eliminar esta entrada de reconvocatoria?'),
('Spanish (Latin American)', 'el', 'Error saving recall configuration.',  'Error al guardar la configuración de reconvocatoria.'),
('Spanish (Latin American)', 'el', 'No recalls selected. Check at least one row.', 'No se seleccionaron reconvocatorias. Marque al menos una fila.'),
('Spanish (Latin American)', 'el', 'Phone number is required.',           'El número de teléfono es obligatorio.'),
('Spanish (Latin American)', 'el', 'Max Failures',                        'Máx. Fallos'),
('Spanish (Latin American)', 'el', 'No facility selected.',               'No se seleccionó ningún centro.'),
('Spanish (Latin American)', 'el', 'Saving...',                           'Guardando...'),
('Spanish (Latin American)', 'el', 'Tracking',                            'Seguimiento'),
('Spanish (Latin American)', 'el', 'No recalls found for the selected criteria.', 'No se encontraron reconvocatorias para los criterios seleccionados.'),
('Spanish (Latin American)', 'el', 'No records found.',                   'No se encontraron registros.'),
('Spanish (Latin American)', 'el', 'Loading...',                          'Cargando...'),
('Spanish (Latin American)', 'el', 'No appointments found.',              'No se encontraron citas.'),
('Spanish (Latin American)', 'el', 'Release - unblock this number',       'Liberar - desbloquear este número'),
('Spanish (Latin American)', 'el', 'Block - re-blacklist this number',    'Bloquear - volver a poner en lista negra este número'),
('Spanish (Latin American)', 'el', 'Today/Overdue',                       'Hoy/Vencido'),
('Spanish (Latin American)', 'el', 'Tomorrow',                            'Mañana'),
('Spanish (Latin American)', 'el', 'days',                                'días'),
('Spanish (Latin American)', 'el', 'Upcoming',                            'Próximo'),
('Spanish (Latin American)', 'el', 'No custom recall entries yet. Click New Recall to create one.', 'Aún no hay entradas de reconvocatoria personalizadas. Haga clic en Nueva Reconvocatoria para crear una.'),
('Spanish (Latin American)', 'el', 'Name...',                             'Nombre...'),
('Spanish (Latin American)', 'el', 'Contact',                             'Contacto'),
('Spanish (Latin American)', 'el', 'Select a facility',                   'Seleccione un centro'),

-- Catalogs tab
('Spanish (Latin American)', 'el', 'Catalogs',         'Catálogos'),
('Spanish (Latin American)', 'el', 'Editable Catalogs', 'Catálogos Editables'),

-- Template Manager modal
('Spanish (Latin American)', 'el', 'Manage Notification Templates',  'Gestionar Plantillas de Notificación'),
('Spanish (Latin American)', 'el', 'Edit messages for different scenarios. Tokens like ***NAME***, ***DATE*** will be replaced automatically.', 'Edite mensajes para diferentes escenarios. Los tokens como ***NAME***, ***DATE*** se reemplazarán automáticamente.'),
('Spanish (Latin American)', 'el', 'Scenario',     'Escenario'),
('Spanish (Latin American)', 'el', 'WhatsApp Message', 'Mensaje WhatsApp'),
('Spanish (Latin American)', 'el', 'Email Subject',    'Asunto del Email'),
('Spanish (Latin American)', 'el', 'HTML Email Body',  'Cuerpo HTML del Email'),
('Spanish (Latin American)', 'el', 'Remove',           'Eliminar'),
('Spanish (Latin American)', 'el', 'Select a category', 'Seleccione una categoría'),
('Spanish (Latin American)', 'el', 'Please select a facility first.', 'Seleccione un centro primero.'),
('Spanish (Latin American)', 'el', 'Remove this template?', '¿Eliminar esta plantilla?'),
('Spanish (Latin American)', 'el', 'Templates saved successfully.', 'Plantillas guardadas correctamente.'),
('Spanish (Latin American)', 'el', 'Save Changes',        'Guardar Cambios'),
('Spanish (Latin American)', 'el', '-- Select --',        '-- Seleccionar --'),
('Spanish (Latin American)', 'el', 'Patient',             'Paciente'),
('Spanish (Latin American)', 'el', 'Provider',            'Proveedor'),
('Spanish (Latin American)', 'el', 'Loading...',          'Cargando...'),
('Spanish (Latin American)', 'el', 'Saving...',           'Guardando...'),
('Spanish (Latin American)', 'el', 'Manual WSP',          'WSP Manual'),
('Spanish (Latin American)', 'el', 'Manual Email',        'Email Manual'),
('Spanish (Latin American)', 'el', 'Manual SMS',          'SMS Manual'),
('Spanish (Latin American)', 'el', 'Manual Voz',          'Voz Manual'),

-- Template status dropdowns
('Spanish (Latin American)', 'el', 'Scheduled', 'Programada'),
('Spanish (Latin American)', 'el', 'Cancelled', 'Cancelada'),
('Spanish (Latin American)', 'el', 'No Show',   'No Asistió'),
('Spanish (Latin American)', 'el', 'All',       'Todos'),

-- Access Denied (also in generate_report.php and sync_status.php)
('Spanish (Latin American)', 'el', 'Access Denied', 'Acceso Denegado'),

-- resend_notification.php messages
('Spanish (Latin American)', 'el', 'POST required',                          'Se requiere POST'),
('Spanish (Latin American)', 'el', 'Invalid log_id',                         'log_id inválido'),
('Spanish (Latin American)', 'el', 'Log entry not found',                    'Entrada de registro no encontrada'),
('Spanish (Latin American)', 'el', 'Facility not configured for notifications', 'Centro no configurado para notificaciones'),
('Spanish (Latin American)', 'el', 'Failed to resend: This number is blacklisted due to delivery failures.', 'Error al reenviar: Este número está en lista negra por fallos de entrega.'),
('Spanish (Latin American)', 'el', 'Notification resent successfully.',      'Notificación reenviada correctamente.'),
('Spanish (Latin American)', 'el', 'Failed to resend notification.',         'Error al reenviar la notificación.'),
('Spanish (Latin American)', 'el', 'Server error',                           'Error del servidor'),
('Spanish (Latin American)', 'el', 'Done',                                   'Listo'),
('Spanish (Latin American)', 'el', 'Resend this notification?',              '¿Reenviar esta notificación?'),
('Spanish (Latin American)', 'el', 'Resend failed: network or server error.', 'Reenvío fallido: error de red o del servidor.'),

-- ListOptionsManager translations
('Spanish (Latin American)', 'el', 'Select List to Manage',                  'Seleccionar Lista para Gestionar'),
('Spanish (Latin American)', 'el', 'No options found for',                   'No se encontraron opciones para'),
('Spanish (Latin American)', 'el', 'New Option',                             'Nueva Opción'),
('Spanish (Latin American)', 'el', 'Option ID',                              'ID de Opción'),
('Spanish (Latin American)', 'el', 'Alert Time',                             'Tiempo de Alerta'),
('Spanish (Latin American)', 'el', 'Check In',                               'Entrada'),
('Spanish (Latin American)', 'el', 'Check Out',                              'Salida'),
('Spanish (Latin American)', 'el', 'Code(s)',                                'Código(s)'),
('Spanish (Latin American)', 'el', 'Deactivate',                             'Desactivar'),
('Spanish (Latin American)', 'el', 'Network error',                          'Error de red'),
('Spanish (Latin American)', 'el', 'Option ID is required',                  'ID de Opción es requerido');

-- ============================================================================
-- SYNC: Populate lang_languages, lang_constants and lang_definitions
-- Idempotent: safe to re-run, preserves existing entries.
--
-- IMPORTANTE: lang_constants.constant_name usa utf8mb4_bin (case-sensitive),
-- mientras que lang_custom.constant_name usa utf8mb3_general_ci.
-- Por eso usamos CONVERT(lc.constant_name USING utf8mb4) en los JOINs
-- para que MySQL compare usando el collation utf8mb4_bin de lang_constants.
-- ============================================================================

-- 2. Create language if it does not exist
INSERT INTO lang_languages (lang_code, lang_description)
SELECT DISTINCT lc.lang_code, lc.lang_description
FROM lang_custom lc
WHERE NOT EXISTS (
    SELECT 1 FROM lang_languages l WHERE l.lang_code = lc.lang_code
);

-- 3. Create new constants (case-sensitive comparison)
INSERT INTO lang_constants (constant_name)
SELECT DISTINCT lc.constant_name
FROM lang_custom lc
WHERE lc.constant_name <> ''
  AND NOT EXISTS (
    SELECT 1 FROM lang_constants c
    WHERE c.constant_name = CONVERT(lc.constant_name USING utf8mb4)
  );

-- 4. Insert new definitions
INSERT INTO lang_definitions (cons_id, lang_id, definition)
SELECT c.cons_id, l.lang_id, lc.definition
FROM lang_custom lc
INNER JOIN lang_constants c
    ON c.constant_name = CONVERT(lc.constant_name USING utf8mb4)
INNER JOIN lang_languages l
    ON l.lang_code = lc.lang_code
WHERE NOT EXISTS (
    SELECT 1 FROM lang_definitions d
    WHERE d.cons_id = c.cons_id AND d.lang_id = l.lang_id
);

-- 5. Update modified translations
UPDATE lang_definitions d
INNER JOIN lang_constants c ON c.cons_id = d.cons_id
INNER JOIN lang_languages l ON l.lang_id = d.lang_id
INNER JOIN lang_custom lc
    ON l.lang_code = lc.lang_code
    AND c.constant_name = CONVERT(lc.constant_name USING utf8mb4)
SET d.definition = lc.definition
WHERE IFNULL(d.definition, '') <> IFNULL(lc.definition, '');

COMMIT;