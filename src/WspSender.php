<?php
/**
 * WspSender — Sends WhatsApp notifications via multiple vendor APIs.
 *
 * Supported vendors: waapi, ultramsg, wasenderapi
 * Each vendor receives: a media image (logo), the message body, and
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
        $logoUrl  = $config['logo_wsp'] ?? '';
        $icsUrl   = $patient['_ics_url'] ?? '';  // URL of a pre-generated .ics file

        if (empty($phone) || strlen($phone) < 8 || empty($vendor) || empty($apiKey)) {
            $result['log'] = 'Missing required parameters (phone, vendor or api_key).';
            return $result;
        }

        $log[] = "WspSender::send() — vendor=$vendor, phone=$phone";

        try {
            switch ($vendor) {
                case 'waapi':
                    $result = $this->sendViaWaApi($instance, $apiKey, $phone, $message, $logoUrl, $icsUrl, $config, $log);
                    break;

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
    // WaApi  (https://waapi.app)
    // -------------------------------------------------------------------------
    private function sendViaWaApi(
        string $instance, string $apiKey, string $phone,
        string $message,  string $logoUrl, string $icsUrl,
        array  $config,   array  &$log
    ): array {
        $chatId  = '549' . $phone . '@c.us';
        $baseUrl = "https://waapi.app/api/v1/instances/$instance/client/action/send-media";
        $headers = [
            'accept'        => 'application/json',
            'content-type'  => 'application/json',
            'authorization' => "Bearer $apiKey",
        ];

        // 1. Send logo image with caption
        if (!empty($logoUrl)) {
            $this->http->post($baseUrl, [
                'headers' => $headers,
                'json'    => ['chatId' => $chatId, 'mediaUrl' => $logoUrl, 'mediaCaption' => $message],
            ]);
            $log[] = 'WaApi: image sent.';
        }

        // 2. Send iCalendar file
        $msgId = null;
        if (!empty($icsUrl)) {
            $resp  = $this->http->post($baseUrl, [
                'headers' => $headers,
                'json'    => [
                    'chatId'       => $chatId,
                    'mediaUrl'     => $icsUrl,
                    'mediaName'    => 'appointment.ics',
                    'mediaCaption' => ($config['facility_name'] ?? '') . ': Press the attachment to save your appointment.',
                ],
            ]);
            $body  = json_decode((string)$resp->getBody(), true);
            $msgId = $body['data']['msgId'] ?? null;
            $log[] = 'WaApi: .ics sent. msgId=' . $msgId;
        }

        $status = $msgId ? 'in_progress' : 'error';
        return ['status' => $msgId ? 'success' : 'error', 'msgId' => $msgId, 'log' => ''];
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

        // 1. Send logo image with caption
        if (!empty($logoUrl)) {
            $resp = $ultramsg->sendImage($to, $logoUrl, $message);
            $log[] = 'UltraMsg: image sent. Response: ' . json_encode($resp);
        } else {
            // Optional: fallback to text message if no image logo
            $resp = $ultramsg->sendChatMessage($to, $message);
            $log[] = 'UltraMsg: text message sent. Response: ' . json_encode($resp);
        }

        // 2. Send iCalendar document
        if (!empty($icsUrl)) {
            $caption = ($config['facility_name'] ?? '') . ': Press the attachment to save your appointment.';
            $resp = $ultramsg->sendDocument($to, 'appointment.ics', $icsUrl, $caption);
            
            // UltraMsg SDK sometimes returns an array or an object
            if (is_array($resp) && isset($resp['id'])) {
                $msgId = $resp['id'];
            } elseif (is_object($resp) && isset($resp->id)) {
                $msgId = $resp->id;
            } elseif (is_string($resp)) {
                $body = json_decode($resp, true);
                $msgId = $body['id'] ?? null;
            }
            $log[] = 'UltraMsg: .ics sent. Response: ' . json_encode($resp);
        }

        return ['status' => $msgId ? 'success' : 'error', 'msgId' => $msgId, 'log' => ''];
    }

    // -------------------------------------------------------------------------
    // WaSenderAPI  (https://wasenderapi.com)
    // -------------------------------------------------------------------------
    private function sendViaWaSenderApi(
        string $apiKey,  string $phone,
        string $message, string $logoUrl, string $icsUrl,
        array  $config,  array  &$log
    ): array {
        $to      = '+549' . $phone;
        $headers = ['Authorization' => "Bearer $apiKey", 'Content-Type' => 'application/json', 'Accept' => 'application/json'];
        $url     = 'https://www.wasenderapi.com/api/send-message';
        $msgId   = null;

        // 1. Send image + message
        if (!empty($logoUrl)) {
            $resp = $this->http->post($url, [
                'headers' => $headers,
                'json'    => ['to' => $to, 'text' => $message, 'imageUrl' => $logoUrl],
            ]);
            $log[] = 'WaSenderAPI: image+text sent.';
        }

        // 2. Send iCalendar document
        if (!empty($icsUrl)) {
            $resp  = $this->http->post($url, [
                'headers' => $headers,
                'json'    => [
                    'to'          => $to,
                    'text'        => ($config['facility_name'] ?? '') . ': Press the attachment to save your appointment.',
                    'documentUrl' => $icsUrl,
                    'mimeType'    => 'text/calendar',
                ],
            ]);
            $body  = json_decode((string)$resp->getBody(), true);
            $msgId = $body['data']['msgId'] ?? null;
            $log[] = 'WaSenderAPI: .ics sent. msgId=' . $msgId;
        }

        // 3. Send location (if coordinates available)
        if (!empty($config['latitude']) && !empty($config['longitude'])) {
            $this->http->post($url, [
                'headers' => $headers,
                'json'    => [
                    'to'       => $to,
                    'location' => [
                        'latitude'  => (float)$config['latitude'],
                        'longitude' => (float)$config['longitude'],
                        'name'      => $config['facility_name'] ?? '',
                        'address'   => $config['facility_address'] ?? '',
                    ],
                ],
            ]);
            $log[] = 'WaSenderAPI: location sent.';
        }

        return ['status' => $msgId ? 'success' : 'error', 'msgId' => $msgId, 'log' => ''];
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
                    '***FACILITY_ADDRESS***','***FACILITY_PHONE***','***FACILITY_EMAIL***'];
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
