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
        } elseif ($vendor === 'evolution-go') {
            $instance = $config['evolution_go_instance_name'] ?? '';
            $apiKey   = $config['evolution_go_api_key'] ?? '';
        } elseif ($vendor === 'httpsms') {
            $instance = $config['httpsms_from_number'] ?? '';  // 'instance' holds from_number for httpsms
            $apiKey   = $config['httpsms_api_key']    ?? '';
        } else {
            $instance = '';
            $apiKey   = '';
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
                $template = self::resolveTemplate($facilityId, $pcCatid, $pcStatus, 'patient');
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
                $logoUrl = "{$baseUrl}/public/images/wsp_email/logo_wsp/{$logoFilename}";
            }
        }

        $icsUrl   = $patient['_ics_url'] ?? '';  // URL of a pre-generated .ics file

        if (empty($phone) || strlen($phone) < 8 || empty($vendor) || empty($apiKey)) {
            $result['log'] = 'Missing required parameters (phone, vendor or api_key).';
            return $result;
        }

        $log[] = "WspSender::send() — vendor=$vendor, phone=$phone";
        $log[] = "logoUrl=" . ($logoUrl ?: 'empty') . ", logoFilename=" . ($logoFilename ?: 'empty') . ", website_url=" . ($config['website_url'] ?? 'empty');
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

                case 'evolution-go':
                    $baseUrl = $config['evolution_go_base_url'] ?? '';
                    $result = $this->sendViaEvolutionGo($baseUrl, $apiKey, $instance, $phone, $message, $logoUrl, $icsUrl, $config, $log);
                    break;

                case 'httpsms':
                    // $instance holds the 'from' number for httpsms
                    $fromNumber = $instance;
                    $baseUrl    = rtrim($config['httpsms_base_url'] ?? 'https://sms.origen.ar', '/');
                    $result = $this->sendViaHttpSms($baseUrl, $apiKey, $fromNumber, $phone, $message, $log);
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

            // Build text with optional map link (reused in fallback scenarios)
            $textWithMap = $message;
            if (!empty($config['latitude']) && !empty($config['longitude'])) {
                $lat     = (float)$config['latitude'];
                $lon     = (float)$config['longitude'];
                $mapLink = "https://www.google.com/maps/search/?api=1&query={$lat},{$lon}";
                $textWithMap .= "\n\n📍 " . $mapLink;
            }

            // 1. Always send text first (guarantees delivery to the patient)
            $textBody = $safePost($textUrl, [
                'chatId' => $chatId,
                'text'   => mb_substr($textWithMap, 0, 4096),
            ], 'texto');
            if ($textBody) {
                $msgId = $extractMsgId($textBody);
            }

            // 2. Send image as a bonus (do NOT overwrite primary text msgId)
            if (!empty($logoUrl)) {
                $imgPayload          = ['chatId' => $chatId, 'url' => $logoUrl];
                $imgPayload['caption'] = mb_substr($textWithMap, 0, 1024);
                $imgBody = $safePost($imageUrl, $imgPayload, 'imagen');
                if ($imgBody) {
                    $log[] = 'OpenWA: image sent successfully (non-critical)';
                } else {
                    $log[] = 'OpenWA: image skipped (URL may not be publicly accessible) — text already sent';
                }
            }

            // 3. Try sending .ics document (optional — do NOT overwrite primary text msgId)
            if (!empty($icsUrl)) {
                $docBody = $safePost($docUrl, [
                    'chatId'   => $chatId,
                    'url'      => $icsUrl,
                    'filename' => 'calendario.ics',
                    'caption'  => mb_substr(LocalizationHelper::appointmentAttachmentCaption(
                        (string)($config['facility_name'] ?? '')
                    ), 0, 1024),
                ], '.ics');
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

    // -------------------------------------------------------------------------
    // Evolution-Go  (https://github.com/Evolution-Go/evolution-api)
    // REST API with instance-based messaging
    // Auth: apiKey in X-API-Key header or query param
    // Send text: POST {baseUrl}/message/sendText/{instance}
    // Send media: POST {baseUrl}/message/sendMedia/{instance}
    // Docs: https://github.com/Evolution-Go/evolution-api
    // -------------------------------------------------------------------------
    private function sendViaEvolutionGo(
        string $baseUrl,    string $apiKey,  string $instanceName,
        string $phone,      string $message, string $logoUrl, string $icsUrl,
        array  $config,     array  &$log
    ): array {
        $result = ['status' => 'error', 'msgId' => null, 'log' => ''];

        if (empty($baseUrl) || empty($apiKey) || empty($instanceName)) {
            $log[] = 'Evolution-Go: Missing credentials (base_url, api_key or instance_name)';
            $result['log'] = implode("\n", $log);
            return $result;
        }

        // Clean phone to international format — Evolution Go uses bare number (no @s.whatsapp.net)
        $number = preg_replace('/\D/', '', $phone);

        $headers = [
            'Content-Type' => 'application/json',
            'Accept'       => 'application/json',
            'apikey'       => $apiKey,
        ];

        $baseUrl  = rtrim($baseUrl, '/');
        $textUrl  = "{$baseUrl}/send/text";
        $mediaUrl = "{$baseUrl}/send/media";
        $msgId = null;

        try {
            // Helper: POST with safe error handling
            $safePost = function (string $url, array $payload, string $label) use ($headers, &$log): ?array {
                try {
                    $resp = $this->http->post($url, ['headers' => $headers, 'json' => $payload, 'timeout' => 30]);
                    $body = json_decode((string)$resp->getBody(), true);
                    $log[] = "Evolution-Go ($label): " . $resp->getBody();
                    return $body;
                } catch (RequestException $e) {
                    $log[] = "Evolution-Go ($label) error: " . $e->getMessage();
                    if ($e->hasResponse()) {
                        $response = $e->getResponse();
                        $statusCode = $response->getStatusCode();
                        $body = (string)$response->getBody();
                        $log[] = "Response: $statusCode - " . $body;

                        if ($statusCode === 401 || $statusCode === 403) {
                            throw new \Exception('UNAUTHORIZED: ' . $body);
                        }
                        if ($statusCode === 404) {
                            throw new \Exception('NOT_FOUND: Instance not found');
                        }
                    }
                    return null;
                } catch (\Throwable $e) {
                    $log[] = "Evolution-Go ($label) unexpected error: " . $e->getMessage();
                    if (strpos($e->getMessage(), 'UNAUTHORIZED') === 0 || strpos($e->getMessage(), 'NOT_FOUND') === 0) {
                        throw $e;
                    }
                    return null;
                }
            };

            // Helper: extract message ID from Evolution-Go response
            $extractMsgId = function (array $body): ?string {
                return $body['key']['id']
                    ?? $body['messageId']
                    ?? $body['data']['messageId']
                    ?? $body['data']['key']['id']
                    ?? $body['data']['Info']['ID']
                    ?? $body['data']['ID']
                    ?? null;
            };

            // Build text with optional map link
            $textWithMap = $message;
            if (!empty($config['latitude']) && !empty($config['longitude'])) {
                $lat = (float)$config['latitude'];
                $lon = (float)$config['longitude'];
                $mapLink = "https://www.google.com/maps/search/?api=1&query={$lat},{$lon}";
                $textWithMap .= "\n\n📍 " . $mapLink;
            }

            // 1. Send image with caption — fallback to plain text if image fails
            if (!empty($logoUrl)) {
                $mediaPayload = [
                    'number'  => $number,
                    'type'    => 'image',
                    'url'     => $logoUrl,
                    'caption' => mb_substr($textWithMap, 0, 1024),
                ];
                $mediaBody = $safePost($mediaUrl, $mediaPayload, 'image');
                if ($mediaBody) {
                    $msgId = $extractMsgId($mediaBody) ?? $msgId;
                } else {
                    $log[] = 'Evolution-Go: image failed, falling back to send-text';
                    $textBody = $safePost($textUrl, [
                        'number' => $number,
                        'text'   => mb_substr($textWithMap, 0, 4096),
                    ], 'text (fallback)');
                    if ($textBody) {
                        $msgId = $extractMsgId($textBody) ?? $msgId;
                    }
                }
            } else {
                // No logo — send plain text
                $textBody = $safePost($textUrl, [
                    'number' => $number,
                    'text'   => mb_substr($textWithMap, 0, 4096),
                ], 'text');
                if ($textBody) {
                    $msgId = $extractMsgId($textBody) ?? $msgId;
                }
            }

            // 2. Try sending .ics document (optional)
            if (!empty($icsUrl)) {
                $docPayload = [
                    'number'   => $number,
                    'type'     => 'document',
                    'url'      => $icsUrl,
                    'filename' => 'appointment.ics',
                    'caption'  => mb_substr(LocalizationHelper::appointmentAttachmentCaption(
                        (string)($config['facility_name'] ?? '')
                    ), 0, 1024),
                ];
                $docBody = $safePost($mediaUrl, $docPayload, '.ics');
                if ($docBody) {
                    $msgId = $extractMsgId($docBody) ?? $msgId;
                }
            }
        } catch (RequestException $e) {
            $log[] = 'Evolution-Go REQUEST ERROR: ' . $e->getMessage();
            $result['log'] = implode("\n", $log);
            return $result;
        } catch (\Throwable $e) {
            $log[] = 'Evolution-Go EXCEPTION: ' . $e->getMessage();
            $status = 'error';
            if (strpos($e->getMessage(), 'UNAUTHORIZED') === 0) {
                $status = 'UNAUTHORIZED';
            } elseif (strpos($e->getMessage(), 'NOT_FOUND') === 0) {
                $status = 'NOT_FOUND';
            }
            $result['status'] = $status;
            $result['log'] = implode("\n", $log);
            return $result;
        }

        return ['status' => $msgId ? 'success' : 'error', 'msgId' => $msgId, 'log' => implode("\n", $log)];
    }

    // -------------------------------------------------------------------------
    // HttpSMS  (https://docs.httpsms.com)
    // Convierte un teléfono Android en SMS gateway vía API REST.
    // Auth: x-api-key header
    // Send: POST {baseUrl}/v1/messages/send
    // Payload: { "content": "...", "from": "+...", "to": "+..." }
    // Response: { "data": { "id": "uuid", ... }, "status": "ok" }
    // -------------------------------------------------------------------------
    private function sendViaHttpSms(
        string $baseUrl,    string $apiKey,  string $fromNumber,
        string $phone,      string $message, array  &$log
    ): array {
        $result = ['status' => 'error', 'msgId' => null, 'log' => ''];

        if (empty($apiKey)) {
            $log[] = 'HttpSMS: Missing API key';
            $result['log'] = implode("\n", $log);
            return $result;
        }
        if (empty($fromNumber)) {
            $log[] = 'HttpSMS: Missing from_number (the Android phone number)';
            $result['log'] = implode("\n", $log);
            return $result;
        }

        // Ensure E.164 format for recipient
        $cleanPhone = preg_replace('/\D/', '', $phone);
        $to = '+' . $cleanPhone;

        // Ensure E.164 format for sender (may already have +)
        $from = $fromNumber;
        if (!str_starts_with($from, '+')) {
            $from = '+' . preg_replace('/\D/', '', $from);
        }

        // SMS supports text only — trim to 918 chars (6 concatenated SMS messages)
        $content = mb_substr($message, 0, 918);

        $url = $baseUrl . '/v1/messages/send';
        $log[] = "HttpSMS::send() — to={$to}, from={$from}, url={$url}";

        try {
            $resp = $this->http->post($url, [
                'headers' => [
                    'x-api-key'    => $apiKey,
                    'Content-Type' => 'application/json',
                    'Accept'       => 'application/json',
                ],
                'json' => [
                    'content' => $content,
                    'from'    => $from,
                    'to'      => $to,
                ],
            ]);

            $body = json_decode((string)$resp->getBody(), true);
            $log[] = 'HttpSMS response: ' . json_encode($body);

            // Response: { "data": { "id": "uuid", ... }, "status": "ok" }
            $msgId = $body['data']['id'] ?? null;

            if (!empty($msgId)) {
                $log[] = "HttpSMS: sent OK. msgId={$msgId}";
                $result['status'] = 'success';
                $result['msgId']  = (string)$msgId;
            } else {
                $log[] = 'HttpSMS: send failed — no message ID in response. Body: ' . json_encode($body);
                $result['status'] = 'error';
            }
        } catch (RequestException $e) {
            $log[] = 'HttpSMS REQUEST ERROR: ' . $e->getMessage();
            if ($e->hasResponse()) {
                $response   = $e->getResponse();
                $statusCode = $response->getStatusCode();
                $body       = (string)$response->getBody();
                $log[] = "Response: {$statusCode} - {$body}";

                if ($statusCode === 401 || $statusCode === 403) {
                    $result['status'] = 'UNAUTHORIZED';
                } elseif ($statusCode === 404) {
                    $result['status'] = 'NOT_FOUND';
                }
            }
        } catch (\Throwable $e) {
            $log[] = 'HttpSMS EXCEPTION: ' . $e->getMessage();
        }

        $result['log'] = implode("\n", $log);
        return $result;
    }

    // -------------------------------------------------------------------------
    // OpenWA — pre-send contact validation
    // -------------------------------------------------------------------------

    /**
     * Checks whether a phone number exists on WhatsApp via OpenWA's contacts endpoint.
     *
     * Called ONLY before the initial notification (seq 1 / on-booking).
     * NOT called during recall / escalation sequences.
     *
     * Endpoint: GET https://wa.origen.ar/api/contacts/check/{number}
     * Headers:  X-API-Key, Accept: application/json
     *
     * @param string $sessionId OpenWA session ID  (openwa_instance config field)
     * @param string $apiKey    OpenWA API key     (openwa_api_key config field)
     * @param string $phone     Raw phone number from patient_data.phone_cell
     *
     * @return string One of:
     *   'exists'              – number is registered on WhatsApp → proceed with send
     *   'not_found'           – number does NOT exist on WhatsApp → trigger email fallback
     *   'service_unavailable' – OpenWA returned 503 or the request failed entirely
     *                           → fail-closed: skip WhatsApp AND email, log for manual review
     */
    public function checkOpenWaContact(string $sessionId, string $apiKey, string $phone): string
    {
        if (empty($sessionId) || empty($apiKey) || empty($phone)) {
            // Missing credentials: treat as unavailable so we don't silently skip
            error_log('WspSender::checkOpenWaContact — missing sessionId, apiKey or phone; returning service_unavailable');
            return 'service_unavailable';
        }

        $cleanPhone = preg_replace('/\D/', '', $phone);
        $url = "https://wa.origen.ar/api/sessions/{$sessionId}/contacts/check/{$cleanPhone}";

        try {
            $resp = $this->http->get($url, [
                'headers' => [
                    'X-API-Key' => $apiKey,
                    'Accept'    => 'application/json',
                ],
                'timeout' => 15,
            ]);

            $httpCode = $resp->getStatusCode();
            $body     = json_decode((string)$resp->getBody(), true);

            error_log("WspSender::checkOpenWaContact — phone={$cleanPhone} http={$httpCode} body=" . json_encode($body));

            if ($httpCode === 503) {
                return 'service_unavailable';
            }

            // OpenWA returns { "exists": true|false } (or similar boolean field)
            $exists = $body['exists'] ?? $body['registered'] ?? $body['isRegistered'] ?? null;

            if ($exists === true || $exists === 1 || $exists === '1' || strtolower((string)$exists) === 'true') {
                return 'exists';
            }

            if ($exists === false || $exists === 0 || $exists === '0' || strtolower((string)$exists) === 'false') {
                return 'not_found';
            }

            // Unexpected payload — treat conservatively as service_unavailable
            error_log('WspSender::checkOpenWaContact — unexpected response payload: ' . json_encode($body));
            return 'service_unavailable';

        } catch (RequestException $e) {
            $httpCode = 0;
            if ($e->hasResponse()) {
                $httpCode = $e->getResponse()->getStatusCode();
            }
            error_log("WspSender::checkOpenWaContact — RequestException http={$httpCode}: " . $e->getMessage());

            if ($httpCode === 503) {
                return 'service_unavailable';
            }

            // Any other HTTP error (e.g. 500, connection timeout) → fail-closed
            return 'service_unavailable';

        } catch (\Throwable $e) {
            error_log('WspSender::checkOpenWaContact — Throwable: ' . $e->getMessage());
            return 'service_unavailable';
        }
    }

    public function syncStatus(array $config, string $msgId): array
    {
        $vendor   = strtolower($config['sms_gateway_type'] ?? $config['current_vendor'] ?? $config['vendor'] ?? '');
        $apiKey   = $config['openwa_api_key'] ?? $config['vendor_api_key'] ?? $config['ultramsg_api_key'] ?? '';
        $instance = $config['openwa_instance'] ?? $config['vendor_instance'] ?? $config['ultramsg_instance'] ?? '';

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

        if ($vendor === 'openwa' || !empty($config['openwa_api_key'])) {
            $sessionId = !empty($config['openwa_instance']) ? $config['openwa_instance'] : $instance;
            $apiKey    = !empty($config['openwa_api_key'])  ? $config['openwa_api_key']  : $apiKey;
            if (!empty($sessionId) && !empty($apiKey) && !empty($msgId)) {
                try {
                    // OpenWA API expects full serialized ID (e.g. false_5493404540440@c.us_3EB0...)
                    $targetMsgIds = [$msgId];
                    if (strpos($msgId, '_') === false) {
                        $rawPhone = $config['phone_cell'] ?? $config['phone_home'] ?? $config['phone'] ?? '';
                        if (empty($rawPhone) && !empty($config['patient_info'])) {
                            $parts = explode('|||', $config['patient_info']);
                            $rawPhone = end($parts);
                        }
                        $phone = preg_replace('/\D/', '', (string)$rawPhone);
                        if (!empty($phone)) {
                            $targetMsgIds[] = "false_{$phone}@c.us_{$msgId}";
                            $targetMsgIds[] = "true_{$phone}@c.us_{$msgId}";

                            if (str_starts_with($phone, '0')) {
                                $noZero = substr($phone, 1);
                                $targetMsgIds[] = "false_549{$noZero}@c.us_{$msgId}";
                                $targetMsgIds[] = "true_549{$noZero}@c.us_{$msgId}";
                            } elseif (!str_starts_with($phone, '549') && strlen($phone) === 10) {
                                $targetMsgIds[] = "false_549{$phone}@c.us_{$msgId}";
                                $targetMsgIds[] = "true_549{$phone}@c.us_{$msgId}";
                            }
                        }
                    }

                    $body = null;
                    foreach ($targetMsgIds as $idToTry) {
                        try {
                            $url = "https://wa.origen.ar/api/sessions/{$sessionId}/messages/" . rawurlencode($idToTry);
                            $resp = $this->http->get($url, [
                                'headers' => [
                                    'X-API-Key' => $apiKey,
                                    'Accept'    => 'application/json',
                                ],
                                'timeout' => 15,
                            ]);
                            $body = json_decode((string)$resp->getBody(), true);
                            if (!empty($body) && (isset($body['status']) || isset($body['ack']) || isset($body['data']))) {
                                break;
                            }
                        } catch (\Exception $e) {
                            // Try next ID format
                            continue;
                        }
                    }

                    if ($body !== null) {
                        $status = $body['status'] ?? $body['data']['status'] ?? $body['ack'] ?? $body['data']['ack'] ?? null;
                        if ($status !== null) {
                            return ['status' => (string)$status, 'raw' => $body];
                        }
                    }
                } catch (\Exception $e) {
                    error_log("WspSender::syncStatus openwa error: " . $e->getMessage());
                    return ['status' => 'error', 'error' => $e->getMessage()];
                }
            }
        }

        if ($vendor === 'wasenderapi') {
            // For WaSenderAPI, placeholder for polling endpoint if available
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
        // Map core appointment statuses to template suffixes
        $coreStatusMap = [
            'x' => '-cancelled',
            '%' => '-noshow',
        ];
        // Check eventStatus first when tracker is empty (e.g. sendCancellation path)
        if (empty($trackerStatus) || $trackerStatus === '-') {
            if (isset($coreStatusMap[$eventStatus])) {
                return $coreStatusMap[$eventStatus];
            }
            return '-scheduled';
        }

        $blockedStatuses = [
            'x' => '', '%' => '', '?' => '', '*' => '', '@' => '',
            '~' => '', '!' => '', '#' => '', '<' => '', '>' => '', '$' => '',
            'AVM' => '', 'SMS' => '', 'EMAIL' => '',
            'wsp-sent' => '', 'wsp-deliv' => '', 'wsp-read' => '', 'wsp-err' => '',
        ];
        if ($trackerStatus !== '-' && isset($blockedStatuses[$trackerStatus])) {
            return $blockedStatuses[$trackerStatus];
        }
        $allowMap = ['^' => '-pending', 'CALL' => '-callback'];
        if (isset($allowMap[$trackerStatus])) {
            return $allowMap[$trackerStatus];
        }
        if (!empty($eventStatus) && str_starts_with($eventStatus, '-')) {
            if (in_array($eventStatus, ['-scheduled', '-pending'])) {
                return $eventStatus;
            }
            return '';
        }
        return '-scheduled';
    }

    public static function resolveTemplate(int $facilityId, int $pcCatid, string $pcApptstatus, string $recipientType = 'patient', string $field = 'wsp_message'): string
    {
        // 1. Exact match: facility_id + pc_catid + pc_apptstatus + recipient_type
        $sql = "SELECT $field FROM wsp_email_notification_templates
                WHERE facility_id = ? AND pc_catid = ? AND pc_apptstatus = ?
                  AND recipient_type = ? AND enabled = 1
                LIMIT 1";
        $row = sqlQuery($sql, [$facilityId, $pcCatid, $pcApptstatus, $recipientType]);
        if (!empty($row[$field])) {
            return $row[$field];
        }
        // 2. Fallback: facility_id + pc_catid, any status (prefer scheduled over cancelled)
        $sql = "SELECT $field FROM wsp_email_notification_templates
                WHERE facility_id = ? AND pc_catid = ?
                  AND recipient_type = ? AND enabled = 1
                ORDER BY CASE pc_apptstatus
                    WHEN '-scheduled' THEN 0 WHEN '-confirmed' THEN 1
                    WHEN '-' THEN 2
                    WHEN '-cancelled' THEN 3 WHEN '-noshow' THEN 4
                    ELSE 5
                END
                LIMIT 1";
        $row = sqlQuery($sql, [$facilityId, $pcCatid, $recipientType]);
        if (!empty($row[$field])) {
            return $row[$field];
        }
        // 3. Fallback: facility_id only, wildcard category
        $sql = "SELECT $field FROM wsp_email_notification_templates
                WHERE facility_id = ? AND pc_catid = 0
                  AND recipient_type = ? AND enabled = 1
                LIMIT 1";
        $row = sqlQuery($sql, [$facilityId, $recipientType]);
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

        $startTime = date('H:i', $dtWrk);
        $endTime   = date('H:i', strtotime($patient['pc_endTime']));
        $provider  = trim(($patient['user_name'] ?? ''));
        $facilityName    = $patient['facility_name']    ?? '';
        $facilityAddress = $patient['facility_address'] ?? '';
        $facilityPhone   = $patient['facility_phone']   ?? '';
        $facilityEmail   = $patient['facility_email']   ?? '';
        $websiteUrl      = $patient['website_url']      ?? '';

        $mapLink = '';
        if (!empty($patient['latitude']) && !empty($patient['longitude'])) {
            $mapLink = "https://www.google.com/maps/search/?api=1&query=" . $patient['latitude'] . "," . $patient['longitude'];
        }

        $find    = [
            // Appointment tokens
            '***NAME***', '***PROVIDER***', '***USER_PREFFIX***', '***DATE***',
            '***STARTTIME***', '***ENDTIME***', '***FACILITY_NAME***',
            '***FACILITY_ADDRESS***', '***FACILITY_PHONE***', '***FACILITY_EMAIL***',
            '***FACILITY_MAP_LINK***', '***FACILITY_WEBSITE***',
            '***PID***', '***REASON***', '***TITLE***',
            // Telehealth / provider template aliases
            '***PATIENT_NAME***', '***PROVIDER_NAME***', '***TIME***',
            // Jitsi / telehealth link
            '***JITSI_LINK***',
        ];
        $replace = [
            $name,
            $provider,
            $patient['user_preffix'] ?? '',
            $date,
            $startTime,
            $endTime,
            $facilityName,
            $facilityAddress,
            $facilityPhone,
            $facilityEmail,
            $mapLink,
            $websiteUrl,
            $patient['pid']              ?? '',
            $patient['pc_hometext']       ?? '',
            $patient['pc_title']          ?? '',
            // Telehealth / provider template aliases
            $name,
            $provider,
            $startTime,
            // Jitsi link — build from config if available
            self::buildJitsiLink($patient),
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

    /**
     * Build a Jitsi meeting URL from config + patient data.
     */
    private static function buildJitsiLink(array $patient): string
    {
        $patientName = trim(($patient['title'] ?? '') . ' ' . ($patient['fname'] ?? '') . ' ' . ($patient['lname'] ?? ''));
        $nameSuffix  = !empty($patientName) ? '#userInfo.displayName=' . rawurlencode('"' . $patientName . '"') : '';

        // If a pre-built link exists (from telehealth_videocalls), use it
        if (!empty($patient['_jitsi_link_full'])) {
            return $patient['_jitsi_link_full'] . $nameSuffix;
        }
        $domain = $patient['th_jitsi_domain'] ?? '';
        $base   = $patient['th_jitsi_base_url'] ?? '';
        $prefix = $patient['th_room_prefix'] ?? 'telehealth-';
        $eid    = $patient['pc_eid'] ?? '';

        $link = '';
        if (!empty($base)) {
            $link = rtrim($base, '/') . '/' . $prefix . $eid;
        } elseif (!empty($domain)) {
            $link = 'https://' . $domain . '/' . $prefix . $eid;
        }
        return $link . $nameSuffix;
    }
}
