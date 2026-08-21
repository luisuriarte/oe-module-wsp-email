<?php
/**
 * StatusNormalizer — Normaliza estados de mensajes entre diferentes proveedores.
 * 
 * Proporciona métodos para:
 * - Normalizar estados nativos a estados canónicos
 * - Determinar el estado más reciente basado en prioridad
 * - Obtener información de estado (color, icono, label)
 * 
 * @package   OpenEMR\Modules\WspEmail
 * @author    Luis A. Uriarte <luis.uriarte@gmail.com>
 * @copyright Copyright (c) 2024-2025 Luis A. Uriarte
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

namespace OpenEMR\Modules\WspEmail;

class StatusNormalizer
{
    /** @var array Configuración cargada */
    private static array $config = [];

    /**
     * Cargar configuración
     */
    private static function loadConfig(): void
    {
        if (empty(self::$config)) {
            $configFile = __DIR__ . '/../config/config_status.messages.php';
            if (file_exists($configFile)) {
                self::$config = require $configFile;
            } else {
                // Configuración por defecto si no existe el archivo
                self::$config = [
                    'canonical' => ['QUEUED', 'SENT', 'DELIVERED', 'READ', 'FAILED', 'INVALID', 'ERROR', 'UNSENT', 'WSP_NOT_ON_WA', 'WSP_CHECK_UNAVAILABLE'],
                    'priority'  => ['QUEUED' => 1, 'SENT' => 2, 'DELIVERED' => 3, 'READ' => 4, 'FAILED' => 5, 'INVALID' => 5, 'ERROR' => 5, 'UNSENT' => 0, 'WSP_NOT_ON_WA' => 5, 'WSP_CHECK_UNAVAILABLE' => 0],
                    'providers' => [],
                    'colors'    => [],
                    'icons'     => [],
                    'labels'    => []
                ];
            }
        }
    }

    /**
     * Normaliza un estado nativo del proveedor a un estado canónico.
     * 
     * @param string $provider Nombre del proveedor (ultramsg, wasenderapi, meta, etc.)
     * @param string $status Estado nativo del proveedor
     * @return string Estado canónico normalizado
     */
    public static function normalize(string $provider, string $status): string
    {
        self::loadConfig();
        
        // Limpiar y normalizar input
        $status = strtolower(trim($status));
        
        // Intentar mapeo específico del proveedor
        if (isset(self::$config['providers'][$provider][$status])) {
            return self::$config['providers'][$provider][$status];
        }
        
        // Intentar mapeo por defecto
        if (isset(self::$config['providers']['default'][$status])) {
            return self::$config['providers']['default'][$status];
        }
        
        // Si el estado ya es canónico, devolverlo
        if (in_array(strtoupper($status), self::$config['canonical'])) {
            return strtoupper($status);
        }
        
        // Fallback: ERROR para estados no reconocidos
        return 'ERROR';
    }

    /**
     * Determina el estado más reciente basado en prioridad.
     * 
     * Útil cuando se reciben múltiples eventos desordenados.
     * 
     * @param array $statuses Array de estados canónicos
     * @return string Estado con mayor prioridad
     */
    public static function getLatestStatus(array $statuses): string
    {
        self::loadConfig();
        
        if (empty($statuses)) {
            return 'UNSENT';
        }
        
        $maxPriority = -1;
        $latestStatus = 'UNSENT';
        
        foreach ($statuses as $status) {
            $status = strtoupper(trim($status));
            $priority = self::$config['priority'][$status] ?? 0;
            
            // Estados de error tienen mayor prioridad (son finales)
            if ($priority > $maxPriority) {
                $maxPriority = $priority;
                $latestStatus = $status;
            }
        }
        
        return $latestStatus;
    }

    /**
     * Obtiene el color para un estado canónico.
     * 
     * @param string $status Estado canónico
     * @param bool $primary true para color principal, false para color secundario (border)
     * @return string Color en formato hex
     */
    public static function getColor(string $status, bool $primary = true): string
    {
        self::loadConfig();
        
        $status = strtoupper($status);
        $colors = self::$config['colors'][$status] ?? ['#9E9E9E', '#616161'];
        
        return $primary ? $colors[0] : $colors[1];
    }

    /**
     * Obtiene el icono FontAwesome para un estado canónico.
     * 
     * @param string $status Estado canónico
     * @return string Clase de icono FontAwesome (sin prefijo 'fa-')
     */
    public static function getIcon(string $status): string
    {
        self::loadConfig();
        
        $status = strtoupper($status);
        return self::$config['icons'][$status] ?? 'fa-question-circle';
    }

    /**
     * Obtiene la etiqueta amigable para un estado canónico.
     * 
     * @param string $status Estado canónico
     * @return string Etiqueta traducible
     */
    public static function getLabel(string $status): string
    {
        self::loadConfig();
        
        $status = strtoupper($status);
        return self::$config['labels'][$status] ?? $status;
    }

    /**
     * Verifica si un estado es terminal (no tendrá más actualizaciones).
     * 
     * @param string $status Estado canónico
     * @return bool true si es estado terminal
     */
    public static function isTerminal(string $status): bool
    {
        $status = strtoupper($status);
        return in_array($status, ['READ', 'FAILED', 'INVALID', 'ERROR', 'UNSENT', 'WSP_NOT_ON_WA', 'WSP_CHECK_UNAVAILABLE']);
    }

    /**
     * Verifica si un estado indica éxito en la entrega.
     * 
     * @param string $status Estado canónico
     * @return bool true si es estado de éxito
     */
    public static function isSuccess(string $status): bool
    {
        $status = strtoupper($status);
        return in_array($status, ['DELIVERED', 'READ']);
    }

    /**
     * Verifica si un estado indica fallo.
     * 
     * @param string $status Estado canónico
     * @return bool true si es estado de fallo
     */
    public static function isFailure(string $status): bool
    {
        $status = strtoupper($status);
        return in_array($status, ['FAILED', 'INVALID', 'ERROR', 'WSP_NOT_ON_WA', 'WSP_CHECK_UNAVAILABLE']);
    }

    /**
     * Obtiene todos los estados canónicos.
     * 
     * @return array Array de estados canónicos
     */
    public static function getCanonicalStatuses(): array
    {
        self::loadConfig();
        return self::$config['canonical'];
    }

    /**
     * Obtiene la prioridad de un estado.
     * 
     * @param string $status Estado canónico
     * @return int Valor de prioridad (mayor = más avanzado/final)
     */
    public static function getPriority(string $status): int
    {
        self::loadConfig();
        $status = strtoupper($status);
        return self::$config['priority'][$status] ?? 0;
    }

    /**
     * Normaliza y determina el estado más reciente desde un payload de webhook.
     * 
     * @param string $provider Nombre del proveedor
     * @param array $webhookData Datos completos del webhook
     * @return array ['canonical' => string, 'priority' => int, 'is_terminal' => bool]
     */
    public static function processWebhook(string $provider, array $webhookData): array
    {
        // Extraer estado nativo del webhook (depende del proveedor)
        $nativeStatus = '';
        
        switch ($provider) {
            case 'meta':
                $nativeStatus = $webhookData['status'] ?? '';
                break;
            
            case 'ultramsg':
                $nativeStatus = $webhookData['status'] ?? $webhookData['ack'] ?? '';
                break;
            
            case 'wasenderapi':
                $nativeStatus = $webhookData['status'] ?? '';
                break;
            
            case 'openwa':
                $event = $webhookData['event'] ?? '';
                if ($event === 'message.sent') {
                    $nativeStatus = 'sent';
                } elseif ($event === 'message.failed') {
                    $nativeStatus = 'failed';
                } elseif ($event === 'message.revoked') {
                    $nativeStatus = 'revoked';
                } elseif ($event === 'message.ack') {
                    $statusStr = $webhookData['data']['status'] ?? '';
                    $ackVal    = $webhookData['data']['ack'] ?? '';
                    if ($statusStr !== '') {
                        $nativeStatus = (string)$statusStr;
                    } elseif ($ackVal !== '') {
                        $nativeStatus = (string)$ackVal;
                    } else {
                        $nativeStatus = 'ack';
                    }
                } else {
                    $nativeStatus = (string)($webhookData['data']['status'] ?? $event);
                }
                break;

            case 'httpsms':
                // Payload: { "event_id": "...", "type": "message.phone.sent", "data": { "id": "...", "status": "sent", ... } }
                $nativeStatus = $webhookData['data']['status'] ?? '';
                if (empty($nativeStatus)) {
                    // Fallback: usar el tipo de evento directamente
                    $nativeStatus = $webhookData['type'] ?? '';
                }
                break;

            case 'waha':
                $event = $webhookData['event'] ?? '';
                $payload = $webhookData['payload'] ?? $webhookData['data'] ?? [];
                if ($event === 'message.ack') {
                    $nativeStatus = (string)($payload['ackName'] ?? (isset($payload['ack']) ? $payload['ack'] : ($payload['status'] ?? 'ack')));
                } elseif ($event === 'message.status') {
                    $nativeStatus = (string)($payload['status'] ?? $payload['ackName'] ?? 'status');
                } elseif ($event === 'message.revoked') {
                    $nativeStatus = 'revoked';
                } elseif ($event === 'message.sent') {
                    $nativeStatus = 'sent';
                } elseif ($event === 'message.failed') {
                    $nativeStatus = 'failed';
                } else {
                    $nativeStatus = (string)($payload['status'] ?? $payload['ackName'] ?? (isset($payload['ack']) ? $payload['ack'] : ($webhookData['status'] ?? $event)));
                }
                break;

            case 'evolution-go':
                // Status may ride at payload top-level, flattened (data.keyId + data.status),
                // nested (data.status), or batched v1 style (data[].update.status)
                $nativeStatus = (string)($webhookData['status'] ?? '');
                if ($nativeStatus === '') {
                    $d = $webhookData['data'] ?? [];
                    if (isset($d[0]) && is_array($d[0])) {
                        $d = $d[0];
                    }
                    $nativeStatus = (string)($d['status'] ?? $d['update']['status'] ?? 'unknown');
                }
                break;

            case 'twilio':
                $nativeStatus = $webhookData['message_status'] ?? '';
                break;
            
            default:
                $nativeStatus = $webhookData['status'] ?? 'unknown';
        }
        
        // Normalizar
        $canonical = self::normalize($provider, $nativeStatus);
        
        return [
            'canonical'   => $canonical,
            'priority'    => self::getPriority($canonical),
            'is_terminal' => self::isTerminal($canonical),
            'raw_status'  => $nativeStatus,
            'provider'    => $provider
        ];
    }
}
