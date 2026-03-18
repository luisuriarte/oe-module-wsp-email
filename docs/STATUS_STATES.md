# Estados de Notificaciones - WaSenderAPI vs UltraMsg

Este documento describe los estados soportados por cada vendor y su equivalencia en el sistema.

## 📊 Tabla de Estados

| Estado | WaSenderAPI | UltraMsg | Descripción | Icono | Color |
|--------|-------------|----------|-------------|-------|-------|
| `sent` | ✅ | ✅ | Mensaje enviado exitosamente | ✓ Check | Verde |
| `delivered` | ✅ | ✅ | Mensaje entregado al dispositivo | 📦 Caja | Azul |
| `read` | ✅ | ✅ | Mensaje leído por el destinatario | 👁️ Ojo | Violeta |
| `played` | ❌ | ✅ | Mensaje de voz reproducido | ▶️ Play | Violeta Oscuro |
| `pending` | ✅ | ✅ | Mensaje en espera de envío | 🕐 Reloj | Naranja |
| `queue` | ✅ | ✅ | Mensaje en cola de envío | 📋 Lista | Naranja |
| `in_progress` | ✅ | ✅ | Mensaje enviándose | ⏳ Spinner | Naranja |
| `server` | ❌ | ✅ | Mensaje en servidor del vendor | 🖥️ Server | Naranja |
| `device` | ✅ | ❌ | Mensaje en dispositivo del destinatario | 📱 Mobile | Azul |
| `ack` | ✅ | ❌ | Confirmación de recepción | ✓✓ Check Double | Azul |
| `error` | ✅ | ✅ | Error al enviar | ✗ X | Rojo |
| `failed` | ✅ | ✅ | Envío fallido | ⚠️ Advertencia | Rojo |
| `invalid` | ✅ | ✅ | Número o mensaje inválido | ❓ Interrogación | Gris |
| `unsent` | ✅ | ✅ | Mensaje no enviado | ✉️ Sobre | Gris Claro |

## 🔄 Flujo de Estados Típico

### WaSenderAPI
```
pending → queue → sent → device → read
                         ↓
                       error (si falla)
```

### UltraMsg
```
pending → queue → server → sent → delivered → read → played
                                  ↓
                                error (si falla)
```

## 🎨 Badges en el Dashboard

Los estados se muestran con badges de colores en la tabla de pacientes:

- **Verde** (`badge-sent`): Enviado exitosamente
- **Azul** (`badge-delivered`): Entregado/En dispositivo
- **Violeta** (`badge-read`): Leído/Reproducido
- **Naranja** (`badge-pending`, `badge-queue`): En espera/En cola
- **Rojo** (`badge-error`): Error/Fallido
- **Gris** (`badge-invalid`, `badge-unsent`): Inválido/No enviado

## 🔍 Filtros Disponibles

En el Dashboard → Patient Status, puedes filtrar por:

1. **Todos los estados**: Muestra todos los registros
2. **Sent**: Mensajes enviados
3. **Delivered**: Mensajes entregados
4. **Device (WaSender)**: En dispositivo del destinatario
5. **Read**: Mensajes leídos
6. **Played**: Mensajes de voz reproducidos (UltraMsg)
7. **Pending**: Pendientes de envío
8. **Queue**: En cola de envío
9. **Server (UltraMsg)**: En servidor del vendor
10. **In Progress**: Enviándose actualmente
11. **Error**: Con errores
12. **Failed**: Fallidos
13. **Invalid**: Inválidos
14. **Unsent**: No enviados

## 📡 Webhook de Actualización

Ambos vendors envían actualizaciones de estado via webhook:

### WaSenderAPI Webhook
```json
{
  "event": "messages.update",
  "data": {
    "key": { "id": "msg_id_aqui" },
    "status": "read"
  }
}
```

### UltraMsg Webhook
```json
{
  "event": "messages.status",
  "data": {
    "id": "msg_id_aqui",
    "status": "delivered"
  }
}
```

El webhook actualiza automáticamente el estado en:
- `notification_log.status`
- `wsp_email_status_history` (historial completo)

## 🛠️ Sincronización Manual

Puedes sincronizar manualmente el estado desde el dashboard:

1. Ir a **Dashboard → Patient Status**
2. Buscar el paciente
3. Click en **🔄 (Sync Status)**
4. El sistema consulta al vendor y actualiza el estado

## 💡 Consejos

- **WaSenderAPI**: Los estados `device` y `ack` son específicos de este vendor
- **UltraMsg**: El estado `server` indica que el mensaje está en el servidor del vendor
- **Played**: Solo aplica para mensajes de voz (UltraMsg)
- **Error vs Failed**: `error` es error de envío, `failed` es fallo después de intentos

## 📈 Estadísticas

Para ver estadísticas por estado:

```sql
SELECT 
    status,
    COUNT(*) AS total,
    type AS canal
FROM notification_log
WHERE type = 'WSP'
GROUP BY status, type
ORDER BY total DESC;
```

## 🔗 Enlaces Útiles

- [WaSenderAPI Documentation](https://wasenderapi.com/docs)
- [UltraMsg Documentation](https://ultramsg.com/docs)
- [WhatsApp Message Status](https://faq.whatsapp.com/general/26000028/)
