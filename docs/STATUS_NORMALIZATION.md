# Sistema de Normalización de Estados de WhatsApp

## 📋 Descripción

Este sistema resuelve el problema de inconsistencia de estados entre diferentes proveedores de WhatsApp API (UltraMsg, WaSenderAPI, Meta, Twilio, etc.).

## 🔍 Problema

Cada proveedor usa diferentes nombres para los mismos estados:

| Evento | UltraMsg | WaSenderAPI | Meta | Twilio |
|--------|----------|-------------|------|--------|
| En cola | `queued` | `queued` | - | `queued` |
| Enviado | `sent`/`ack` | `sent`/`sending` | `sent` | `sent` |
| Entregado | `delivered` | `delivered` | `delivered` | `delivered` |
| Leído | `read` | `read` | `read` | `read` |
| Audio reproducido | `played` | `played` | - | - |
| Fallido | `failed` | `failed`/`fail` | `failed` | `failed` |
| Error | `error` | `error` | `warning` | - |

## ✅ Solución

### 1. Estados Canónicos

El sistema usa 8 estados canónicos:

```php
[
    'QUEUED',      // En cola / pendiente de envío
    'SENT',        // Enviado al proveedor WhatsApp
    'DELIVERED',   // Entregado al dispositivo del destinatario
    'READ',        // Leído por el destinatario (o played en audio)
    'FAILED',      // Falló el envío (reintentos agotados)
    'INVALID',     // Número inválido o no existe
    'ERROR',       // Error técnico del proveedor
    'UNSENT'       // Cancelado / no enviado
]
```

### 2. Normalización

```php
use OpenEMR\Modules\WspEmail\StatusNormalizer;

// Normalizar estado de WaSenderAPI
$canonical = StatusNormalizer::normalize('wasenderapi', 'sending');
// Retorna: 'SENT'

// Normalizar estado de UltraMsg
$canonical = StatusNormalizer::normalize('ultramsg', 'ack');
// Retorna: 'SENT'
```

### 3. Prioridad de Estados

Cada estado tiene una prioridad numérica:

```php
$priority = [
    'QUEUED'    => 1,  // Menos avanzado
    'SENT'      => 2,
    'DELIVERED' => 3,
    'READ'      => 4,  // Más avanzado (éxito)
    'FAILED'    => 5,  // Terminal (fallo)
    'INVALID'   => 5,  // Terminal
    'ERROR'     => 5,  // Terminal
    'UNSENT'    => 0   // Sin enviar
];
```

## 🏗️ Arquitectura

### Base de Datos

**notification_log:**
- `status` - Estado raw del proveedor (ej: `ack`, `sending`)
- `status_current` - Estado canónico normalizado (ej: `SENT`)
- `provider_raw_status` - Copia del estado raw
- `status_priority` - Prioridad numérica del estado
- `provider_payload` - JSON completo del webhook

**wsp_email_status_history:**
- `status` - Estado canónico
- `provider_raw_status` - Estado raw del proveedor
- `provider_name` - Nombre del proveedor (ultramsg, wasenderapi, etc.)
- `provider_payload` - JSON completo del webhook

### Flujo de Webhook

```
1. Webhook recibido del proveedor
   ↓
2. Extraer estado raw (ej: "ack", "sending")
   ↓
3. Detectar proveedor (ultramsg, wasenderapi, etc.)
   ↓
4. Normalizar a estado canónico (StatusNormalizer)
   ↓
5. Calcular prioridad
   ↓
6. Guardar en notification_log (status_current, priority)
   ↓
7. Guardar en status_history (con payload completo)
   ↓
8. Si es estado de menor/equal prioridad, ignorar (evita duplicados)
```

## 📖 Uso

### Normalizar Estado

```php
use OpenEMR\Modules\WspEmail\StatusNormalizer;

// Desde webhook de WaSenderAPI
$rawStatus = 'sending';
$canonical = StatusNormalizer::normalize('wasenderapi', $rawStatus);
// Resultado: 'SENT'

// Desde webhook de UltraMsg
$rawStatus = 'played';
$canonical = StatusNormalizer::normalize('ultramsg', $rawStatus);
// Resultado: 'READ'
```

### Procesar Webhook Completo

```php
$webhookData = [
    'status' => 'delivered',
    'key' => ['id' => 'msg123']
];

$result = StatusNormalizer::processWebhook('wasenderapi', $webhookData);
// Resultado:
// [
//     'canonical' => 'DELIVERED',
//     'priority' => 3,
//     'is_terminal' => false,
//     'raw_status' => 'delivered',
//     'provider' => 'wasenderapi'
// ]
```

### Obtener Información de UI

```php
// Color para el estado
$color = StatusNormalizer::getColor('DELIVERED'); 
// Retorna: '#2196F3' (azul)

$colorBorder = StatusNormalizer::getColor('DELIVERED', false); 
// Retorna: '#1565C0' (azul oscuro)

// Icono
$icon = StatusNormalizer::getIcon('DELIVERED');
// Retorna: 'fa-box'

// Label
$label = StatusNormalizer::getLabel('DELIVERED');
// Retorna: 'Entregado'
```

### Determinar Estado Más Reciente

```php
// Útil cuando hay eventos desordenados
$states = ['SENT', 'QUEUED', 'DELIVERED'];
$latest = StatusNormalizer::getLatestStatus($states);
// Retorna: 'DELIVERED' (mayor prioridad)
```

### Verificar Tipo de Estado

```php
StatusNormalizer::isTerminal('READ');    // true (no habrá más actualizaciones)
StatusNormalizer::isSuccess('DELIVERED'); // true (entregado exitosamente)
StatusNormalizer::isFailure('FAILED');    // true (falló)
```

## 🔧 Configuración

### Agregar Nuevo Proveedor

Editar `config/config_status.messages.php`:

```php
'providers' => [
    // ... otros proveedores ...
    
    'mi_proveedor' => [
        'pending'   => 'QUEUED',
        'sending'   => 'SENT',
        'delivered' => 'DELIVERED',
        'read'      => 'READ',
        'failed'    => 'FAILED',
    ]
]
```

### Personalizar Colores

```php
'colors' => [
    'QUEUED' => ['#FFC107', '#FFA000'],    // [principal, borde]
    'SENT' => ['#25D366', '#128C7E'],
    // ...
]
```

## 🎯 Beneficios

1. **Consistencia**: Todos los proveedores usan los mismos estados
2. **Histórico Completo**: Se guarda payload completo para debugging
3. **Prioridad**: Maneja eventos desordenados correctamente
4. **Extensible**: Fácil agregar nuevos proveedores
5. **UI Consistente**: Mismos colores/iconos para todos los proveedores

## 📊 Ejemplo Real

### WaSenderAPI Webhook

```json
{
  "event": "messages.update",
  "data": {
    "key": {"id": "msg123"},
    "status": "delivered"
  }
}
```

**Procesamiento:**
1. Extraer: `rawStatus = "delivered"`
2. Proveedor: `wasenderapi`
3. Normalizar: `canonical = "DELIVERED"`
4. Prioridad: `3`
5. Guardar en DB

### UltraMsg Webhook

```json
{
  "status": "ack",
  "id": "msg123"
}
```

**Procesamiento:**
1. Extraer: `rawStatus = "ack"`
2. Proveedor: `ultramsg`
3. Normalizar: `canonical = "SENT"` (ack → SENT)
4. Prioridad: `2`
5. Guardar en DB

## 🐛 Casos Especiales

### Eventos Duplicados
El sistema detecta y evita duplicados por prioridad.

### Eventos Desordenados
Si llega `DELIVERED` antes que `SENT`, se guarda correctamente gracias a la prioridad.

### Estados Desconocidos
Estados no reconocidos se mapean a `ERROR` para investigación.

### Webhooks de Grupos
Los mensajes a grupos (@g.us) se ignoran.

## 📝 Referencias

- [Meta WhatsApp API](https://developers.facebook.com/docs/whatsapp/on-premises/webhooks/components#statuses-object)
- [UltraMsg Docs](https://ultramsg.com/docs/)
- [WaSenderAPI Docs](https://wasenderapi.com/docs)
- [Twilio WhatsApp API](https://www.twilio.com/docs/whatsapp/api)
