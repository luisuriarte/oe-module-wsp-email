<?php
/**
 * EmailSender — Sends HTML email notifications with iCalendar attachment and map.
 *
 * Uses PHPMailer (bundled with OpenEMR) and Geoapify for static map images.
 * iCalendar data is attached inline so Gmail and Outlook can parse the event.
 *
 * @package   OpenEMR\Modules\WspEmail
 * @author    Luis A. Uriarte <luis.uriarte@gmail.com>
 * @copyright Copyright (c) 2024-2025 Luis A. Uriarte
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

namespace OpenEMR\Modules\WspEmail;

use OpenEMR\Common\Crypto\CryptoGen;
use PHPMailer\PHPMailer\PHPMailer;

class EmailSender
{
    /**
     * Send the HTML appointment reminder email.
     *
     * @param array $config   Row from wsp_email_facility_config (joined with facility)
     * @param array $patient  Patient + appointment data row
     * @return bool           True on success, false on failure
     */
    public function send(array $config, array $patient): bool
    {
        $to             = trim($patient['email'] ?? '');
        $patientName    = trim(($patient['fname'] ?? '') . ' ' . ($patient['lname'] ?? ''));
        $facilityName   = $config['facility_name']    ?? '';
        $facilityAddr   = $config['facility_address'] ?? '';
        $facilityPhone  = $config['facility_phone']   ?? '';
        $facilityEmail  = $config['facility_email']   ?? '';
        $facilityUrl    = $config['website_url']      ?? '';
        $logoFilename   = $config['logo_email']       ?? '';
        $logoPath       = !empty($logoFilename) ? __DIR__ . '/../public/images/logo_email/' . $logoFilename : '';
        $geoapifyKey    = $config['geoapify_key']     ?? '';
        $latitude       = $config['latitude']         ?? null;
        $longitude      = $config['longitude']        ?? null;
        $subject        = $config['email_subject']    ?? "Appointment reminder — $facilityName";
        $messageBody    = $patient['_message']        ?? '';
        $zone           = $GLOBALS['gbl_time_zone']   ?? 'America/Argentina/Buenos_Aires';

        if (empty($to)) {
            error_log("EmailSender: empty recipient for pid=" . ($patient['pid'] ?? ''));
            return false;
        }

        // Build start/end datetime strings
        $startDate = $patient['pc_eventDate'] . ' ' . $patient['pc_startTime'];
        $endDate   = $patient['pc_eventDate'] . ' ' . $patient['pc_endTime'];

        // Build Google Calendar link
        $gcalUrl = $this->buildGoogleCalendarUrl($subject, $messageBody, $startDate, $endDate, $facilityAddr);

        // Build map URL
        $mapLinkUrl = '';
        if ($latitude && $longitude) {
            $mapLinkUrl = "https://www.google.com/maps/search/?api=1&query={$latitude},{$longitude}";
        }

        // Build iCalendar content
        $icsContent = $this->buildIcsContent($startDate, $endDate, $subject, $messageBody, $facilityName,
                                              $facilityEmail, $facilityAddr, $facilityUrl, $patientName, $to, $zone);

        // Build HTML email body
        $htmlBody = $this->buildHtmlBody($messageBody, $facilityName, $facilityAddr, $facilityPhone,
                                          $facilityEmail, $facilityUrl, $gcalUrl, $mapLinkUrl);

        // Configure and send via PHPMailer
        return $this->dispatch($to, $patientName, $facilityEmail, $facilityName, $subject,
                                $htmlBody, $icsContent, $logoPath);
    }

    // -------------------------------------------------------------------------
    // PHPMailer dispatch
    // -------------------------------------------------------------------------
    private function dispatch(
        string $to, string $toName, string $fromEmail, string $fromName,
        string $subject, string $htmlBody, string $icsContent, string $logoPath
    ): bool {
        // Ensure PHPMailer class is available (OpenEMR bundles it)
        if (!class_exists(PHPMailer::class)) {
            $base = __DIR__ . '/../../../../library/classes/PHPMailer/src/';
            require_once $base . 'PHPMailer.php';
            require_once $base . 'SMTP.php';
        }

        $mail = new PHPMailer(true);
        try {
            if (!empty($GLOBALS['SMTP_HOST'])) {
                $crypto = new CryptoGen();
                $mail->isSMTP();
                $mail->Host       = $GLOBALS['SMTP_HOST'];
                $mail->Port       = (int)($GLOBALS['SMTP_PORT'] ?? 587);
                $mail->SMTPAuth   = true;
                $mail->Username   = $GLOBALS['SMTP_USER'] ?? '';
                $mail->Password   = $crypto->decryptStandard($GLOBALS['SMTP_PASS'] ?? '');
                $mail->SMTPSecure = $GLOBALS['SMTP_SECURE'] ?? PHPMailer::ENCRYPTION_STARTTLS;
            }

            $mail->CharSet  = 'UTF-8';
            $mail->setFrom($fromEmail, $fromName);
            $mail->addAddress($to, $toName);
            $mail->isHTML(true);
            $mail->Subject = $subject;
            $mail->WordWrap = 70;

            // Embed logo (cid:logo)
            if (!empty($logoPath) && file_exists($logoPath)) {
                $mail->addEmbeddedImage($logoPath, 'logo', 'logo.png');
            }

            // Add iCalendar header so mail clients display the "Add to calendar" button
            $mail->addCustomHeader('Content-Class', 'urn:content-classes:calendarmessage');
            $mail->addStringAttachment(
                $icsContent,
                'appointment.ics',
                'base64',
                'text/calendar; charset=utf-8; method=PUBLISH'
            );

            $mail->Body    = $htmlBody;
            $mail->AltBody = strip_tags($htmlBody);

            $mail->send();
            return true;
        } catch (\Exception $e) {
            error_log('EmailSender::dispatch() error: ' . $e->getMessage());
            return false;
        }
    }

    // -------------------------------------------------------------------------
    // HTML body builder
    // -------------------------------------------------------------------------
    private function buildHtmlBody(
        string $messageBody, string $facilityName, string $facilityAddr,
        string $facilityPhone, string $facilityEmail, string $facilityUrl,
        string $gcalUrl, string $mapLinkUrl
    ): string {
        $logoTag  = '<img src="cid:logo" alt="' . htmlspecialchars($facilityName) . '" style="max-width:200px;">';
        $mapBlock = '';
        if ($mapLinkUrl) {
            $mapBlock = '
            <p style="margin-top:24px;">
                <a href="' . htmlspecialchars($mapLinkUrl) . '" target="_blank"
                   style="display:inline-block;padding:10px 20px;background-color:#007bff;color:#fff;text-decoration:none;border-radius:5px;">
                   View Location on Google Maps
                </a>
            </p>';
        }
        $gcalLink = $gcalUrl ? '<p><a href="' . htmlspecialchars($gcalUrl) . '" target="_blank">Add to Google Calendar</a></p>' : '';

        return '<!DOCTYPE html>
<html lang="en">
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head>
<body style="font-family:Arial,sans-serif;color:#333;max-width:600px;margin:auto;padding:20px;">
    <div style="text-align:center;margin-bottom:20px;">' . $logoTag . '</div>
    <div style="background:#f9f9f9;border-radius:8px;padding:24px;border:1px solid #e0e0e0;">
        <p style="white-space:pre-line;">' . nl2br(htmlspecialchars($messageBody)) . '</p>
        <hr style="border:none;border-top:1px solid #e0e0e0;margin:16px 0;">
        <p><strong>' . htmlspecialchars($facilityName) . '</strong><br>
           ' . htmlspecialchars($facilityAddr) . '<br>
           Tel: ' . htmlspecialchars($facilityPhone) . '<br>
           Email: <a href="mailto:' . htmlspecialchars($facilityEmail) . '">' . htmlspecialchars($facilityEmail) . '</a><br>
           Web: <a href="' . htmlspecialchars($facilityUrl) . '">' . htmlspecialchars($facilityUrl) . '</a>
        </p>
        ' . $gcalLink . '
        ' . $mapBlock . '
        <p style="margin-top:20px;font-size:12px;color:#888;">
            An appointment calendar file (.ics) is attached — open it to save the appointment
            directly to your calendar application.
        </p>
    </div>
</body>
</html>';
    }

    private function buildIcsContent(
        string $startDate, string $endDate, string $summary, string $description,
        string $organizer, string $organizerEmail, string $location, string $url,
        string $attendeeName, string $attendeeEmail, string $zone
    ): string {
        $dtStart  = date('Ymd\THis', strtotime($startDate));
        $dtEnd    = date('Ymd\THis', strtotime($endDate));
        $stamp    = gmdate("Ymd\THis\Z");
        $uid      = gmdate('YmdThis') . '-' . mt_rand() . '@oe-module-wsp-email';

        $vtimezone = "BEGIN:VTIMEZONE\r\n"
                   . "TZID:$zone\r\n"
                   . "BEGIN:STANDARD\r\n"
                   . "TZOFFSETFROM:-0300\r\n"
                   . "TZOFFSETTO:-0300\r\n"
                   . "TZNAME:ART\r\n"
                   . "DTSTART:19700101T000000\r\n"
                   . "END:STANDARD\r\n"
                   . "END:VTIMEZONE\r\n";

        return "BEGIN:VCALENDAR\r\n"
             . "VERSION:2.0\r\n"
             . "PRODID:-//oe-module-wsp-email//OpenEMR//EN\r\n"
             . "METHOD:PUBLISH\r\n"
             . $vtimezone
             . "BEGIN:VEVENT\r\n"
             . "DTSTART;TZID=$zone:$dtStart\r\n"
             . "DTEND;TZID=$zone:$dtEnd\r\n"
             . "DTSTAMP:$stamp\r\n"
             . "UID:$uid\r\n"
             . "SUMMARY:" . $this->escapeIcal($summary) . "\r\n"
             . "DESCRIPTION:" . $this->escapeIcal($description) . "\r\n"
             . "LOCATION:" . $this->escapeIcal($location) . "\r\n"
             . "URL:" . $url . "\r\n"
             . "ORGANIZER;CN=" . $this->escapeIcal($organizer) . ":mailto:$organizerEmail\r\n"
             . "ATTENDEE;PARTSTAT=NEEDS-ACTION;CN=" . $this->escapeIcal($attendeeName)
             .        ";EMAIL=$attendeeEmail:mailto:$attendeeEmail\r\n"
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
    }

    private function escapeIcal(string $value): string
    {
        return str_replace(['\\', "\n", "\r", ',', ';'], ['\\\\', '\\n', '', '\\,', '\\;'], $value);
    }

    private function buildGoogleCalendarUrl(
        string $title, string $details, string $start, string $end, string $location
    ): string {
        return 'https://www.google.com/calendar/render?action=TEMPLATE'
             . '&text='     . rawurlencode($title)
             . '&dates='    . date('Ymd\THis', strtotime($start)) . '/' . date('Ymd\THis', strtotime($end))
             . '&details='  . rawurlencode($details)
             . '&location=' . rawurlencode($location);
    }
}
