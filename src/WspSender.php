<?php
/**
 * WspSender — Sends WhatsApp notifications via multiple vendor APIs.
 *
 * Supported vendors: ultramsg, wasenderapi
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
use Ultramsg\WhatsAppApi;

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
        $vendor   = strtolower($config['vendor'] ?? '');
        $instance = $config['vendor_instance'] ?? '';
        $apiKey   = $config['vendor_api_key'] ?? '';
        $message  = $patient['_message'] ?? '';  // pre-built message body
        $logoFilename = $config['logo_wsp'] ?? '';
        $logoUrl      = '';
        if (!empty($logoFilename)) {
            $proto = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
            $host  = $_SERVER['HTTP_HOST'] ?? 'localhost';
            $logoUrl = "{$proto}://{$host}{$GLOBALS['webroot']}/interface/modules/custom_modules/oe-module-wsp-email/public/images/logo_wsp/{$logoFilename}";
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

                default:
                    $log[] = "Unknown vendor: $vendor";
                    break;
            }
        } catch (\Throwable $e) {
            $log[] = 'Exception: ' . $e->getMessage();
        }

        $result['log'] = implode("\n", $log);
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
        $to = '+549' . $phone;
        $ultramsg = new WhatsAppApi($apiKey, $instance);
        $msgId = null;
        $status = 'invalid';

        // 1. Send logo image with caption
        if (!empty($logoUrl)) {
            $resp = $ultramsg->sendImage($to, $logoUrl, $message);
            $log[] = 'UltraMsg: image sent. Response: ' . json_encode($resp);
        } else {
            $resp = $ultramsg->sendChatMessage($to, $message);
            $log[] = 'UltraMsg: text message sent. Response: ' . json_encode($resp);
        }

        // 2. Send iCalendar document
        if (!empty($icsUrl)) {
            $caption = ($config['facility_name'] ?? '') . ': Press the attachment to save your appointment.';
            $resp = $ultramsg->sendDocument($to, 'appointment.ics', $icsUrl, $caption);
            $log[] = 'UltraMsg: .ics sent. Response: ' . json_encode($resp);

            // Capture message ID and status from last action (the document)
            if (is_array($resp)) {
                $msgId  = $resp['id'] ?? null;
                $status = $resp['status'] ?? ($msgId ? 'sent' : 'invalid');
            } elseif (is_object($resp)) {
                $msgId  = $resp->id ?? null;
                $status = $resp->status ?? ($msgId ? 'sent' : 'invalid');
            } elseif (is_string($resp)) {
                $body   = json_decode($resp, true);
                $msgId  = $body['id'] ?? null;
                $status = $body['status'] ?? ($msgId ? 'sent' : 'invalid');
            }
        } else {
            // If no ICS, use the response from previous message
            if (is_array($resp)) {
                $msgId  = $resp['id'] ?? null;
                $status = $resp['status'] ?? ($msgId ? 'sent' : 'invalid');
            }
        }

        return ['status' => $status, 'msgId' => $msgId, 'log' => ''];
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
            $log[] = "WaSenderAPI: Esperando 60 segundos antes de enviar el archivo .ics (restricción de la versión de prueba)";
            sleep(60);

            try {
                $resp  = $this->http->post($url, [
                    'headers' => $headers,
                    'json'    => [
                        'to'          => $to,
                        'text'        => ($config['facility_name'] ?? '') . ': Press the attachment to save your appointment.',
                        'documentUrl' => $icsUrl,
                        'fileName'    => 'appointment.ics',
                        'mimeType'    => 'text/calendar',
                    ],
                ]);
                $body  = json_decode((string)$resp->getBody(), true);
                if (!empty($body['success']) && isset($body['data']['msgId'])) {
                    $msgId = $body['data']['msgId'];
                }
                $log[] = 'WaSenderAPI (.ics): success. msgId=' . $msgId;
            } catch (RequestException $e) {
                $log[] = "Error en WaSenderAPI (.ics): " . $e->getMessage();
                if ($e->hasResponse()) {
                    $log[] = "Respuesta: " . $e->getResponse()->getBody();
                }
            }
        }

        // 3. Send location (if coordinates available)
        if (!empty($config['latitude']) && !empty($config['longitude'])) {
            $log[] = "WaSenderAPI: Esperando 60 segundos antes de enviar la ubicación (restricción de la versión de prueba)";
            sleep(60);

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
                if (!empty($body['success']) && isset($body['data']['msgId'])) {
                    $msgId = $body['data']['msgId'];
                }
                $log[] = 'WaSenderAPI (ubicación): location sent. msgId=' . $msgId;
            } catch (RequestException $e) {
                $log[] = "Error en WaSenderAPI (ubicación): " . $e->getMessage();
                if ($e->hasResponse()) {
                    $log[] = "Respuesta: " . $e->getResponse()->getBody();
                }
            }
        }

        return ['status' => $msgId ? 'success' : 'error', 'msgId' => $msgId, 'log' => ''];
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
        $days      = ['Sunday','Monday','Tuesday','Wednesday','Thursday','Friday','Saturday'];
        $months    = ['January','February','March','April','May','June',
                      'July','August','September','October','November','December'];

        $name      = trim(($patient['title'] ?? '') . ' ' . ($patient['fname'] ?? '') . ' ' . ($patient['lname'] ?? ''));
        $date      = $days[date('w', $dtWrk)] . ' ' . date('d', $dtWrk) . ' ' .
                     $months[(int)date('n', $dtWrk) - 1] . ' ' . date('Y', $dtWrk);

        $find    = ['***NAME***','***PROVIDER***','***USER_PREFFIX***','***DATE***',
                    '***STARTTIME***','***ENDTIME***','***FACILITY_NAME***',
                    '***FACILITY_ADDRESS***','***FACILITY_PHONE***','***FACILITY_EMAIL***','***FACILITY_MAP_LINK***'];
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
            $mapLink
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
             . "SUMMARY:Appointment at " . ($config['facility_name'] ?? 'Clinic') . "\r\n"
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
             . "DESCRIPTION:Appointment reminder\r\n"
             . "END:VALARM\r\n"
             . "END:VEVENT\r\n"
             . "END:VCALENDAR\r\n";

        $filename = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'appt-' . substr(md5(uniqid()), 0, 8) . '.ics';
        file_put_contents($filename, $ics);
        return $filename;
    }
}
