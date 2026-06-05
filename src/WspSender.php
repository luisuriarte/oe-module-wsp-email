<?php
/**
 * WspSender — Sends WhatsApp notifications via multiple vendor APIs.
 *
 * Supported vendors: ultramsg, wasenderapi, openwa
 * optionally an iCalendar (.ics) file and a location pin.
 *
 * @package   OpenEMR\Modules\WspEmail
 * @author    Luis A. Uriarte <luis.uriarte@gmail.com>
 * @copyright Copyright (c) 2024-2025 Luis A. Uriarte
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

namespace OpenEMR\Modules\WspEmail;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;

// Load UltraMsg SDK manually if not autoloaded
if (!class_exists('Ultramsg\WhatsAppApi')) {
    $ultramsgSdkPath = __DIR__ . '/../vendor/ultramsg/whatsapp-php-sdk/ultramsg.class.php';
    if (file_exists($ultramsgSdkPath)) {
        require_once $ultramsgSdkPath;
    }
}

class WspSender
{
    private Client $http;

    public function __construct()
    {
        $this->http = new Client(['timeout' => 30]);
    }

    /**
     * Send a WhatsApp notification for a single appointment.
     *
     * @param array $config   Row from wsp_email_facility_config
     * @param array $patient  Patient + appointment data row
     * @return array ['status' => 'success'|'error', 'msgId' => string|null, 'log' => string]
     */
    public function send(array $config, array $patient): array
    {
        $log    = [];
        $result = ['status' => 'error', 'msgId' => null, 'log' => ''];

        // --- Validate required fields ---
        $phone    = preg_replace('/\D/', '', $patient['phone_cell'] ?? '');
        $vendor   = strtolower($config['current_vendor'] ?? $config['vendor'] ?? 'wasenderapi');

        // Get credentials based on vendor
        $instance = '';
        $apiKey   = '';

        if ($vendor === 'ultramsg') {
            $instance = $config['ultramsg_instance'] ?? '';
            $apiKey   = $config['ultramsg_api_key'] ?? '';
        } elseif ($vendor === 'wasenderapi') {
            $instance = '';  // WaSenderAPI doesn't use instance
            $apiKey   = $config['wasenderapi_api_key'] ?? '';
        } elseif ($vendor === 'openwa') {
            $instance = $config['openwa_instance'] ?? '';
            $apiKey   = $config['openwa_api_key'] ?? '';
        } else {
            // Fallback to legacy fields
            $instance = $config['vendor_instance'] ?? '';
            $apiKey   = $config['vendor_api_key'] ?? '';
        }

        $message  = $patient['_message'] ?? '';
        // Fallback: resolve template from DB if message is empty
        if (empty($message)) {
            $facilityId = (int)($config['facility_id'] ?? $patient['pc_facility'] ?? 0);
            $pcCatid    = (int)($patient['pc_catid'] ?? 0);
            $pcStatus   = self::normalizeApptStatusForTemplate(
                (string)($patient['tracker_status'] ?? ''),
                (string)($patient['pc_apptstatus'] ?? '')
            );
            if (!empty($pcCatid)) {
                $template = self::resolveTemplate($facilityId, $pcCatid, $pcStatus, 'wsp', 'patient');
                if (!empty($template)) {
                    $message = self::buildMessage($template, $patient);
                }
            }
        }

        // Build logo URL using facility website URL as base
        $logoFilename = $config['logo_wsp'] ?? '';
        $logoUrl      = '';
        if (!empty($logoFilename)) {
            // Use facility website URL as base (e.g., https://myclinic.com)
            $baseUrl = rtrim($config['website_url'] ?? '', '/');
            if (!empty($baseUrl)) {
                $logoUrl = "{$baseUrl}/interface/modules/custom_modules/oe-module-wsp-email/public/images/logo_wsp/{$logoFilename}";
            }
        }

        $icsUrl   = $patient['_ics_url'] ?? '';  // URL of a pre-generated .ics file

        if (empty($phone) || strlen($phone) < 8 || empty($vendor) || empty($apiKey)) {
            $result['log'] = 'Missing required parameters (phone, vendor or api_key).';
            return $result;
        }

        $log[] = "WspSender::send() — vendor=$vendor, phone=$phone";
        try {
            switch ($vendor) {
                case 'ultramsg':
                    $result = $this->sendViaUltraMsg($instance, $apiKey, $phone, $message, $logoUrl, $icsUrl, $config, $log);
                    break;

                case 'wasenderapi':
                    $result = $this->sendViaWaSenderApi($apiKey, $phone, $message, $logoUrl, $icsUrl, $config, $log);
                    break;

                case 'openwa':
                    $result = $this->sendViaOpenWA($instance, $apiKey, $phone, $message, $logoUrl, $icsUrl, $config, $log);
                    break;

                default:
                    $log[] = "Unknown vendor: $vendor";
                    break;
            }
        } catch (\Throwable $e) {
            $log[] = 'Exception: ' . $e->getMessage();
        }

        $result['log'] = implode("\n", $log);

        // Write detailed log to local module file
        $logMessage = date('Y-m-d H:i:s') . " — " . $result['log'] . "\n" . str_repeat('-', 80) . "\n";
        $logFile = __DIR__ . '/../logs/wsp_notify.log';
        $logDir = dirname($logFile);
        if (!is_dir($logDir)) {
            @mkdir($logDir, 0755, true);
        }
        @file_put_contents($logFile, $logMessage, FILE_APPEND | LOCK_EX);

        // Write standard info to PHP system error log
        $statusStr = $result['status'] ?? 'unknown';
        $msgIdStr = $result['msgId'] ?? 'none';
        error_log("WspSender: Sent message to phone=$phone via vendor=$vendor. Status=$statusStr, MsgId=$msgIdStr");
        if ($statusStr === 'error') {
            error_log("WspSender Error Details:\n" . $result['log']);
        }

        return $result;
    }

    // -------------------------------------------------------------------------
    // UltraMsg  (https://ultramsg.com) via official PHP SDK
    // -------------------------------------------------------------------------
    private function sendViaUltraMsg(
        string $instance, string $apiKey, string $phone,
        string $message,  string $logoUrl, string $icsUrl,
        array  $config,   array  &$log
    ): array {
        $result = ['status' => 'error', 'msgId' => null, 'log' => ''];
        
        try {
            // Validate required parameters
            if (empty($instance) || empty($apiKey)) {
                $log[] = 'UltraMsg: Missing credentials (instance or api_key)';
                $result['log'] = implode("\n", $log);
                return $result;
            }

            // Use global namespace for WhatsAppApi class
            // UltraMsg accepts: +5493404540440 OR 5493404540440@c.us
            // Clean phone: remove all non-digits, then ensure international format with +
            $cleanPhone = preg_replace('/\D/', '', $phone);
            $to = '+' . $cleanPhone;  // E.164 format: +5493404540440
            $ultramsg = new \Ultramsg\WhatsAppApi($apiKey, $instance);
            $msgId = null;
            $status = 'error';
            $resp = null;

            // 1. Send logo image with caption
            if (!empty($logoUrl)) {
                $resp = $ultramsg->sendImageMessage($to, $logoUrl, $message);
                $log[] = 'UltraMsg: image sent. Response: ' . json_encode($resp);
            } else {
                $resp = $ultramsg->sendChatMessage($to, $message);
                $log[] = 'UltraMsg: text message sent. Response: ' . json_encode($resp);
            }
            
            // Check if response indicates an error
            if (is_array($resp) && isset($resp['error'])) {
                $log[] = 'UltraMsg ERROR: ' . json_encode($resp['error']);
                $result['log'] = implode("\n", $log);
                return $result;
            } elseif (is_object($resp) && isset($resp->error)) {
                $log[] = 'UltraMsg ERROR: ' . json_encode($resp->error);
                $result['log'] = implode("\n", $log);
                return $result;
            }

            // 2. Send iCalendar document
            if (!empty($icsUrl)) {
                $caption = LocalizationHelper::appointmentAttachmentCaption((string)($config['facility_name'] ?? ''));
                $docResp = $ultramsg->sendDocumentMessage($to, 'appointment.ics', $icsUrl, $caption);
                $log[] = 'UltraMsg: .ics sent. Response: ' . json_encode($docResp);

                // Capture message ID and status from document response
                if (is_array($docResp)) {
                    $msgId  = $docResp['id'] ?? null;
                    $status = $docResp['status'] ?? ($msgId ? 'sent' : 'error');
                } elseif (is_object($docResp)) {
                    $msgId  = $docResp->id ?? null;
                    $status = $docResp->status ?? ($msgId ? 'sent' : 'error');
                } elseif (is_string($docResp)) {
                    $body   = json_decode($docResp, true);
                    $msgId  = $body['id'] ?? null;
                    $status = $body['status'] ?? ($msgId ? 'sent' : 'error');
                }
            } else {
                // If no ICS, use the response from image/text message
                if (is_array($resp)) {
                    $msgId  = $resp['id'] ?? null;
                    $status = $resp['status'] ?? ($msgId ? 'sent' : 'error');
                } elseif (is_object($resp)) {
                    $msgId  = $resp->id ?? null;
                    $status = $resp->status ?? ($msgId ? 'sent' : 'error');
                } elseif (is_string($resp)) {
                    $body   = json_decode($resp, true);
                    $msgId  = $body['id'] ?? null;
                    $status = $body['status'] ?? ($msgId ? 'sent' : 'error');
                }
            }

            // 3. Send location (if coordinates available)
            if (!empty($config['latitude']) && !empty($config['longitude'])) {
                $address = ($config['facility_name'] ?? 'Facility') . "\n" . ($config['facility_address'] ?? '');
                $locResp = $ultramsg->sendLocationMessage(
                    $to,
                    $address,
                    (float)$config['latitude'],
                    (float)$config['longitude']
                );
                $log[] = 'UltraMsg: location sent. Response: ' . json_encode($locResp);

                // Update msgId from location response if available
                if (is_array($locResp) && isset($locResp['id'])) {
                    $msgId = $locResp['id'];
                } elseif (is_object($locResp) && isset($locResp->id)) {
                    $msgId = $locResp->id;
                }
            } else {
                $log[] = 'UltraMsg: No location sent (coordinates not configured)';
            }

            $result['status'] = $status;
            $result['msgId'] = $msgId;
        } catch (\Throwable $e) {
            $log[] = 'UltraMsg EXCEPTION: ' . $e->getMessage();
            $log[] = 'Trace: ' . $e->getTraceAsString();
            $result['status'] = 'error';
            $result['msgId'] = null;
        }

        $result['log'] = implode("\n", $log);
        return $result;
    }

    // -------------------------------------------------------------------------
    // WaSenderAPI  (https://wasenderapi.com)
    // -------------------------------------------------------------------------
    private function sendViaWaSenderApi(
        string $apiKey,  string $phone,
        string $message, string $logoUrl, string $icsUrl,
        array  $config,  array  &$log
    ): array {
        // Ensure E.164 format (International)
        $cleanPhone = preg_replace('/\D/', '', $phone);
        $to = '+' . $cleanPhone;

        $headers = [
            'Authorization' => "Bearer $apiKey",
            'Content-Type'  => 'application/json',
            'Accept'        => 'application/json'
        ];
        $url     = 'https://www.wasenderapi.com/api/send-message';
        $msgId   = null;

        // 1. Send image + message
        if (!empty($logoUrl)) {
            try {
                $resp = $this->http->post($url, [
                    'headers' => $headers,
                    'json'    => ['to' => $to, 'text' => $message, 'imageUrl' => $logoUrl],
                ]);
                $log[] = 'WaSenderAPI (imagen): image+text sent.';
            } catch (RequestException $e) {
                $log[] = "Error en WaSenderAPI (imagen): " . $e->getMessage();
                if ($e->hasResponse()) {
                    $log[] = "Respuesta: " . $e->getResponse()->getBody();
                }
                return ['status' => 'error', 'msgId' => null, 'log' => implode("\n", $log)];
            }
        }

        // 2. Send iCalendar document
        if (!empty($icsUrl)) {
            //$log[] = "WaSenderAPI: Esperando 60 segundos antes de enviar el archivo .ics (restricción de la versión de prueba)";
            //sleep(60);

            try {
                $resp  = $this->http->post($url, [
                    'headers' => $headers,
                        'json'    => [
                            'to'          => $to,
                            'text'        => LocalizationHelper::appointmentAttachmentCaption((string)($config['facility_name'] ?? '')),
                            'documentUrl' => $icsUrl,
                            'fileName'    => 'appointment.ics',
                            'mimeType'    => 'text/calendar',
                    ],
                ]);
                $body  = json_decode((string)$resp->getBody(), true);
                $log[] = "WaSenderAPI (.ics): " . $resp->getBody();
                if (!empty($body['success']) && isset($body['data']['msgId'])) {
                    $msgId = $body['data']['msgId'];
                    $log[] = 'WaSenderAPI (.ics): success. msgId=' . $msgId;
                } else {
                    $log[] = 'WaSenderAPI (.ics): failed. Response: ' . json_encode($body);
                }
            } catch (RequestException $e) {
                $log[] = "Error en WaSenderAPI (.ics): " . $e->getMessage();
                if ($e->hasResponse()) {
                    $log[] = "Respuesta: " . $e->getResponse()->getBody();
                }
            }
        } else {
            $log[] = "WaSenderAPI: No se envía .ics porque icsUrl está vacío";
        }

        // 3. Send location (if coordinates available)
        if (!empty($config['latitude']) && !empty($config['longitude'])) {
            //$log[] = "WaSenderAPI: Esperando 60 segundos antes de enviar la ubicación (restricción de la versión de prueba)";
            //sleep(60);

            try {
                $resp = $this->http->post($url, [
                    'headers' => $headers,
                    'json'    => [
                        'to'       => $to,
                        'text'     => "Ubicación de " . ($config['facility_name'] ?? 'Facility'),
                        'location' => [
                            'latitude'  => (float)$config['latitude'],
                            'longitude' => (float)$config['longitude'],
                            'name'      => $config['facility_name'] ?? '',
                            'address'   => ($config['facility_address'] ?? '') . ', ' . ($config['facility_name'] ?? ''),
                        ],
                    ],
                ]);
                $body = json_decode((string)$resp->getBody(), true);
                $log[] = "WaSenderAPI (ubicación): " . $resp->getBody();
                if (!empty($body['success']) && isset($body['data']['msgId'])) {
                    $msgId = $body['data']['msgId'];
                    $log[] = 'WaSenderAPI (ubicación): success. msgId=' . $msgId;
                } else {
                    $log[] = 'WaSenderAPI (ubicación): failed. Response: ' . json_encode($body);
                }
            } catch (RequestException $e) {
                $log[] = "Error en WaSenderAPI (ubicación): " . $e->getMessage();
                if ($e->hasResponse()) {
                    $log[] = "Respuesta: " . $e->getResponse()->getBody();
                }
            }
        } else {
            $log[] = "WaSenderAPI: No se envía ubicación porque no hay coordenadas configuradas";
        }

        return ['status' => $msgId ? 'success' : 'error', 'msgId' => $msgId, 'log' => ''];
    }

    // -------------------------------------------------------------------------
    // OpenWA  (https://wa.origen.ar)  — REST API with X-API-Key header
    // Docs: https://github.com/rmyndharis/OpenWA
    // Auth: X-API-Key: owa_xxx...
    // Send: POST /api/sessions/{sessionId}/messages
    // Webhook events: message.received, message.sent, message.ack, session.status
    // -------------------------------------------------------------------------
    private function sendViaOpenWA(
        string $sessionId, string $apiKey,  string $phone,
        string $message,   string $logoUrl, string $icsUrl,
        array  $config,    array  &$log
    ): array {
        $result = ['status' => 'error', 'msgId' => null, 'log' => ''];

        if (empty($sessionId) || empty($apiKey)) {
            $log[] = 'OpenWA: Missing credentials (session ID or API key)';
            $result['log'] = implode("\n", $log);
            return $result;
        }

        // OpenWA chatId format: {phone}@c.us  (e.g. 5493404540440@c.us)
        $cleanPhone = preg_replace('/\D/', '', $phone);
        $chatId     = $cleanPhone . '@c.us';

        $baseUrl  = 'https://wa.origen.ar/api';
        $textUrl  = "{$baseUrl}/sessions/{$sessionId}/messages/send-text";
        $imageUrl = "{$baseUrl}/sessions/{$sessionId}/messages/send-image";
        $docUrl   = "{$baseUrl}/sessions/{$sessionId}/messages/send-document";

        $headers = [
            'X-API-Key'    => $apiKey,
            'Content-Type' => 'application/json',
            'Accept'       => 'application/json',
        ];
        $msgId = null;

        try {
            // Helper: extract message ID from any OpenWA response format
            $extractMsgId = function (array $body): ?string {
                return $body['messageId']
                    ?? $body['data']['messageId']
                    ?? $body['data']['id']
                    ?? null;
            };

            // Helper: POST with safe error handling (non-critical failures are logged, not thrown)
            $safePost = function (string $url, array $payload, string $label) use ($headers, &$log): ?array {
                try {
                    $resp = $this->http->post($url, ['headers' => $headers, 'json' => $payload]);
                    $body = json_decode((string)$resp->getBody(), true);
                    $log[] = "OpenWA ($label): " . $resp->getBody();
                    return $body;
                } catch (RequestException $e) {
                    $log[] = "OpenWA ($label) error: " . $e->getMessage();
                    if ($e->hasResponse()) {
                        $response = $e->getResponse();
                        $statusCode = $response->getStatusCode();
                        $body = (string)$response->getBody();
                        $log[] = "Response: " . $body;

                        if ($statusCode === 401 || $statusCode === 404) {
                            throw $e;
                        }

                        // Check if it's an invalid number
                        $bodyData = json_decode($body, true);
                        $msg = $bodyData['message'] ?? $bodyData['error'] ?? '';
                        if (stripos($msg, 'invalid number') !== false || stripos($msg, 'not on whatsapp') !== false || stripos($msg, 'does not exist') !== false) {
                            throw new \Exception("INVALID: " . $msg);
                        }
                    }
                    return null;
                } catch (\Throwable $e) {
                    $log[] = "OpenWA ($label) unexpected error: " . $e->getMessage();
                    if (strpos($e->getMessage(), 'INVALID:') === 0) {
                        throw $e;
                    }
                    return null;
                }
            };

            // 1. Send image with message as caption (appends map link if coordinates configured)
            if (!empty($logoUrl)) {
                $imgPayload = ['chatId' => $chatId, 'url' => $logoUrl];
                $caption = $message;
                if (!empty($config['latitude']) && !empty($config['longitude'])) {
                    $lat = (float)$config['latitude'];
                    $lon = (float)$config['longitude'];
                    $mapLink = "https://www.google.com/maps/search/?api=1&query={$lat},{$lon}";
                    $caption .= "\n\n📍 " . $mapLink;
                }
                if (!empty($caption)) {
                    $imgPayload['caption'] = mb_substr($caption, 0, 1024);
                }
                $imgBody = $safePost($imageUrl, $imgPayload, 'imagen');
                if ($imgBody) {
                    $msgId = $extractMsgId($imgBody) ?? $msgId;
                }
            } else {
                // Send plain text message
                $textPayload = ['chatId' => $chatId, 'text' => $message];
                if (!empty($config['latitude']) && !empty($config['longitude'])) {
                    $lat = (float)$config['latitude'];
                    $lon = (float)$config['longitude'];
                    $mapLink = "https://www.google.com/maps/search/?api=1&query={$lat},{$lon}";
                    $textPayload['text'] .= "\n\n📍 " . $mapLink;
                }
                $textBody = $safePost($textUrl, $textPayload, 'texto');
                if ($textBody) {
                    $msgId = $extractMsgId($textBody) ?? $msgId;
                }
            }

            // 2. Try sending .ics document (optional)
            if (!empty($icsUrl)) {
                $docBody = $safePost($docUrl, [
                    'chatId'   => $chatId,
                    'url'      => $icsUrl,
                    'filename' => 'calendario.ics',
                    'caption'  => mb_substr(LocalizationHelper::appointmentAttachmentCaption(
                        (string)($config['facility_name'] ?? '')
                    ), 0, 1024),
                ], '.ics');
                if ($docBody) {
                    $msgId = $extractMsgId($docBody) ?? $msgId;
                }
            }

        } catch (RequestException $e) {
            $log[] = 'OpenWA REQUEST ERROR: ' . $e->getMessage();
            $statusCode = 0;
            if ($e->hasResponse()) {
                $response = $e->getResponse();
                $statusCode = $response->getStatusCode();
                $log[] = 'Response: ' . $response->getBody();
            }
            
            $status = 'error';
            if ($statusCode === 401) {
                $status = 'UNAUTHORIZED';
            } elseif ($statusCode === 404) {
                $status = 'NOT_FOUND';
            }
            
            $result['status'] = $status;
            $result['log'] = implode("\n", $log);
            return $result;
        } catch (\Throwable $e) {
            $log[] = 'OpenWA EXCEPTION: ' . $e->getMessage();
            $status = 'error';
            if (strpos($e->getMessage(), 'INVALID:') === 0) {
                $status = 'INVALID';
            }
            $result['status'] = $status;
            $result['log'] = implode("\n", $log);
            return $result;
        }

        return ['status' => $msgId ? 'success' : 'error', 'msgId' => $msgId, 'log' => implode("\n", $log)];
    }

    public function syncStatus(array $config, string $msgId): array
    {
        $vendor = strtolower($config['vendor'] ?? '');
        $apiKey = $config['vendor_api_key'] ?? '';
        $instance = $config['vendor_instance'] ?? '';

        if ($vendor === 'ultramsg') {
            try {
                $params = [
                    'token' => $apiKey,
                    'id'    => $msgId
                ];
                $url = "https://api.ultramsg.com/{$instance}/messages?" . http_build_query($params);
                $resp = $this->http->get($url);
                $body = json_decode((string)$resp->getBody(), true);
                
                // UltraMsg returns a list of messages. We take the first match.
                $msg = $body[0] ?? null;
                if ($msg && isset($msg['ack'])) {
                    return ['status' => $msg['ack'], 'raw' => $body];
                }
            } catch (\Exception $e) {
                return ['status' => 'error', 'error' => $e->getMessage()];
            }
        }

        if ($vendor === 'wasenderapi') {
            // For WaSenderAPI, we'll try to get-message if they have such endpoint,
            // otherwise we return current status as-is or error.
            // Placeholder: currently returning null as research didn't confirm a polling endpoint by ID for wasender yet.
        }

        return ['status' => null];
    }

    // -------------------------------------------------------------------------
    // Helper — plain cURL POST for vendors that don't accept Guzzle multipart
    // -------------------------------------------------------------------------
    private function curlPost(string $url, array $params): string
    {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_SSL_VERIFYHOST => 0,
            CURLOPT_SSL_VERIFYPEER => 0,
            CURLOPT_CUSTOMREQUEST  => 'POST',
            CURLOPT_POSTFIELDS     => http_build_query($params),
            CURLOPT_HTTPHEADER     => ['content-type: application/x-www-form-urlencoded'],
        ]);
        $response = (string)curl_exec($ch);
        curl_close($ch);
        return $response;
    }

    // -------------------------------------------------------------------------
    // Template resolution from wsp_email_notification_templates
    // -------------------------------------------------------------------------

    public static function normalizeApptStatusForTemplate(string $trackerStatus, string $eventStatus): string
    {
        $blockedStatuses = [
            'x' => '', '%' => '', '?' => '', '*' => '', '@' => '',
            '~' => '', '!' => '', '#' => '', '<' => '', '>' => '', '$' => '',
            'AVM' => '', 'SMS' => '', 'EMAIL' => '',
            'wsp-sent' => '', 'wsp-deliv' => '', 'wsp-read' => '', 'wsp-err' => '',
        ];
        if (!empty($trackerStatus) && $trackerStatus !== '-' && isset($blockedStatuses[$trackerStatus])) {
            return $blockedStatuses[$trackerStatus];
        }
        $allowMap = ['^' => '-pending', 'CALL' => '-callback'];
        if (!empty($trackerStatus) && isset($allowMap[$trackerStatus])) {
            return $allowMap[$trackerStatus];
        }
        if ($trackerStatus === '-' || empty($trackerStatus)) {
            return '-scheduled';
        }
        if (!empty($eventStatus) && str_starts_with($eventStatus, '-')) {
            if (in_array($eventStatus, ['-scheduled', '-pending'])) {
                return $eventStatus;
            }
            return '';
        }
        return '-scheduled';
    }

    public static function resolveTemplate(int $facilityId, int $pcCatid, string $pcApptstatus, string $channel, string $recipientType = 'patient', string $field = 'wsp_message'): string
    {
        // 1. Exact match: facility_id + pc_catid + pc_apptstatus + channel + recipient_type
        $sql = "SELECT $field FROM wsp_email_notification_templates
                WHERE facility_id = ? AND pc_catid = ? AND pc_apptstatus = ?
                  AND channel = ? AND recipient_type = ? AND enabled = 1
                LIMIT 1";
        $row = sqlQuery($sql, [$facilityId, $pcCatid, $pcApptstatus, $channel, $recipientType]);
        if (!empty($row[$field])) {
            return $row[$field];
        }
        // 2. Fallback: facility_id + pc_catid, any status (prefer scheduled over cancelled)
        $sql = "SELECT $field FROM wsp_email_notification_templates
                WHERE facility_id = ? AND pc_catid = ?
                  AND channel = ? AND recipient_type = ? AND enabled = 1
                ORDER BY CASE pc_apptstatus
                    WHEN '-scheduled' THEN 0 WHEN '-confirmed' THEN 1
                    WHEN '-' THEN 2
                    WHEN '-cancelled' THEN 3 WHEN '-noshow' THEN 4
                    ELSE 5
                END
                LIMIT 1";
        $row = sqlQuery($sql, [$facilityId, $pcCatid, $channel, $recipientType]);
        if (!empty($row[$field])) {
            return $row[$field];
        }
        // 3. Fallback: facility_id only, wildcard category
        $sql = "SELECT $field FROM wsp_email_notification_templates
                WHERE facility_id = ? AND pc_catid = 0
                  AND channel = ? AND recipient_type = ? AND enabled = 1
                LIMIT 1";
        $row = sqlQuery($sql, [$facilityId, $channel, $recipientType]);
        return $row[$field] ?? '';
    }

    // -------------------------------------------------------------------------
    // Build message body by replacing tokens in the template
    // -------------------------------------------------------------------------

    /**
     * Tokens: ***NAME***, ***PROVIDER***, ***USER_PREFFIX***, ***DATE***,
     *         ***STARTTIME***, ***ENDTIME***, ***FACILITY_NAME***,
     *         ***FACILITY_ADDRESS***, ***FACILITY_PHONE***, ***FACILITY_EMAIL***
     */
    public static function buildMessage(string $template, array $patient): string
    {
        $dtWrk     = strtotime($patient['pc_eventDate'] . ' ' . $patient['pc_startTime']);

        $name      = trim(($patient['title'] ?? '') . ' ' . ($patient['fname'] ?? '') . ' ' . ($patient['lname'] ?? ''));
        $date      = LocalizationHelper::formatAppointmentDate(
            (string)($patient['pc_eventDate'] ?? ''),
            (string)($patient['pc_startTime'] ?? '00:00:00')
        );

        $find    = [
            '***NAME***', '***PROVIDER***', '***USER_PREFFIX***', '***DATE***',
            '***STARTTIME***', '***ENDTIME***', '***FACILITY_NAME***',
            '***FACILITY_ADDRESS***', '***FACILITY_PHONE***', '***FACILITY_EMAIL***',
            '***FACILITY_MAP_LINK***', '***FACILITY_WEBSITE***',
            '***PID***', '***REASON***', '***TITLE***'
        ];

        $mapLink = '';
        if (!empty($patient['latitude']) && !empty($patient['longitude'])) {
            $mapLink = "https://www.google.com/maps/search/?api=1&query=" . $patient['latitude'] . "," . $patient['longitude'];
        }

        $replace = [
            $name,
            trim(($patient['user_name'] ?? '')),
            $patient['user_preffix'] ?? '',
            $date,
            date('H:i', $dtWrk),
            date('H:i', strtotime($patient['pc_endTime'])),
            $patient['facility_name']    ?? '',
            $patient['facility_address'] ?? '',
            $patient['facility_phone']   ?? '',
            $patient['facility_email']   ?? '',
            $mapLink,
            $patient['website_url']      ?? '',
            $patient['pid']              ?? '',
            $patient['pc_hometext']       ?? '',
            $patient['pc_title']          ?? ''
        ];

        return str_replace($find, $replace, $template);
    }

    /**
     * Creates a temporary .ics file on disk and returns its basename.
     * The caller is responsible for deleting it after the message is sent.
     */
    public static function buildIcsFile(array $patient, array $config): string
    {
        $zone      = $GLOBALS['gbl_time_zone'] ?? 'America/Argentina/Buenos_Aires';
        $startDate = $patient['pc_eventDate'] . ' ' . $patient['pc_startTime'];
        $endDate   = $patient['pc_eventDate'] . ' ' . $patient['pc_endTime'];
        $dtStart   = date('Ymd\THis', strtotime($startDate));
        $dtEnd     = date('Ymd\THis', strtotime($endDate));
        $stamp     = gmdate("Ymd\THis\Z");
        $uid       = date('Ymd') . 'T' . date('His') . '-' . mt_rand() . '@wsp-email';

        $ics = "BEGIN:VCALENDAR\r\n"
             . "METHOD:REQUEST\r\n"
             . "VERSION:2.0\r\n"
             . "PRODID:-//oe-module-wsp-email//OpenEMR//EN\r\n"
             . "BEGIN:VEVENT\r\n"
             . "DTSTART;TZID=$zone:$dtStart\r\n"
             . "DTEND;TZID=$zone:$dtEnd\r\n"
             . "DTSTAMP:$stamp\r\n"
             . "UID:$uid\r\n"
             . "SUMMARY:" . self::escapeIcalValue(
                 LocalizationHelper::translate('Appointment at') . ' ' . ($config['facility_name'] ?? LocalizationHelper::translate('Clinic'))
             ) . "\r\n"
             . "DESCRIPTION:" . str_replace(["\r\n","\n"], "\\n", ($patient['_message'] ?? '')) . "\r\n"
             . "LOCATION:" . ($config['facility_address'] ?? '') . "\r\n"
             . "URL:" . ($config['website_url'] ?? '') . "\r\n"
             . "CLASS:PUBLIC\r\n"
             . "PRIORITY:5\r\n"
             . "STATUS:CONFIRMED\r\n"
             . "SEQUENCE:0\r\n"
             . "BEGIN:VALARM\r\n"
             . "TRIGGER:-PT60M\r\n"
             . "ACTION:DISPLAY\r\n"
             . "DESCRIPTION:" . self::escapeIcalValue(LocalizationHelper::translate('Appointment reminder')) . "\r\n"
             . "END:VALARM\r\n"
             . "END:VEVENT\r\n"
             . "END:VCALENDAR\r\n";

        $filename = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'calendario-' . substr(md5(uniqid()), 0, 8) . '.ics';
        file_put_contents($filename, $ics);
        return $filename;
    }

    private static function escapeIcalValue(string $value): string
    {
        return str_replace(['\\', "\n", "\r", ',', ';'], ['\\\\', '\\n', '', '\\,', '\\;'], $value);
    }
}
