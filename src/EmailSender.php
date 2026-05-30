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
        $subject        = $config['email_subject']    ?? (LocalizationHelper::translate('Appointment reminder') . " — $facilityName");
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
            if (file_exists($base)) {
                require_once $base . 'PHPMailer.php';
                require_once $base . 'SMTP.php';
            }
        }

        if (!class_exists(PHPMailer::class)) {
            error_log('EmailSender: PHPMailer not available');
            return false;
        }

        $mail = new PHPMailer(true);
        try {
            // Try SMTP if configured
            if (!empty($GLOBALS['SMTP_HOST'])) {
                try {
                    $crypto = new CryptoGen();
                    $mail->isSMTP();
                    $mail->Host       = $GLOBALS['SMTP_HOST'];
                    $mail->Port       = (int)($GLOBALS['SMTP_PORT'] ?? 587);
                    $mail->SMTPAuth   = true;
                    $mail->Username   = $GLOBALS['SMTP_USER'] ?? '';
                    $mail->Password   = $crypto->decryptStandard($GLOBALS['SMTP_PASS'] ?? '');
                    $mail->SMTPSecure = $GLOBALS['SMTP_SECURE'] ?? PHPMailer::ENCRYPTION_STARTTLS;
                } catch (\Exception $e) {
                    // SMTP config failed, fall back to mail()
                    error_log('EmailSender: SMTP config failed, using mail(). Error: ' . $e->getMessage());
                }
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
        $logoTag  = '<img src="cid:logo" alt="' . htmlspecialchars($facilityName) . '" style="width:100%;height:auto;">';

        $mapBlock = '';
        if ($mapLinkUrl) {
            $mapBlock = '<p style="margin-top:24px;text-align:center;">
                <a href="' . htmlspecialchars($mapLinkUrl) . '" target="_blank"
                   style="display:inline-block;padding:12px 28px;background-color:#4285f4;color:#fff;text-decoration:none;border-radius:5px;font-size:16px;">
                   ' . htmlspecialchars(LocalizationHelper::translate('View location in Google Maps')) . '
                </a>
            </p>';
        }

        $gcalBlock = '';
        if ($gcalUrl) {
            $gcalBlock = '<p style="margin:16px 0;text-align:center;">
                <a href="' . htmlspecialchars($gcalUrl) . '" target="_blank"
                   style="display:inline-block;padding:12px 28px;background-color:#4285f4;color:#fff;text-decoration:none;border-radius:5px;font-size:16px;font-weight:bold;">
                   ' . htmlspecialchars(LocalizationHelper::translate('Add to Google Calendar')) . '
                </a>
            </p>';
        }

        return '<!DOCTYPE html>
<html lang="' . htmlspecialchars(LocalizationHelper::currentLanguageTag()) . '">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
</head>
        <body style="font-family:Arial,sans-serif;color:#333;margin:0;padding:0;font-size:16px;">
            <div style="text-align:center;padding:20px 20px 0;">' . $logoTag . '</div>
            <div style="background:#f9f9f9;border-radius:8px;padding:24px;margin:10px 20px 20px;border:1px solid #e0e0e0;font-size:16px;line-height:1.6;">
                <p style="white-space:pre-line;font-size:16px;">' . nl2br(htmlspecialchars($messageBody)) . '</p>
                <hr style="border:none;border-top:1px solid #e0e0e0;margin:16px 0;">
                <p style="font-size:15px;"><strong>' . htmlspecialchars($facilityName) . '</strong><br>
           ' . htmlspecialchars($facilityAddr) . '<br>
           ' . htmlspecialchars(LocalizationHelper::translate('Phone')) . ': ' . htmlspecialchars($facilityPhone) . '<br>
           ' . htmlspecialchars(LocalizationHelper::translate('Email')) . ': <a href="mailto:' . htmlspecialchars($facilityEmail) . '">' . htmlspecialchars($facilityEmail) . '</a><br>
           ' . htmlspecialchars(LocalizationHelper::translate('Website')) . ': <a href="' . htmlspecialchars($facilityUrl) . '">' . htmlspecialchars($facilityUrl) . '</a>
        </p>
        ' . $gcalBlock . '
        ' . $mapBlock . '
        <p style="margin-top:20px;font-size:13px;color:#888;">
            ' . htmlspecialchars(LocalizationHelper::translate('A calendar file (.ics) is attached — open it to save the appointment directly in your calendar application.')) . '
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
        // Escape values for iCalendar
        $summary_escaped = $this->escapeIcal($summary);
        $description_escaped = $this->escapeIcal($description);
        $location_escaped = $this->escapeIcal($location);
        $organizer_escaped = $this->escapeIcal($organizer);
        $attendeeName_escaped = $this->escapeIcal($attendeeName);

        // Generate dates
        $dtStart  = date('Ymd\THis', strtotime($startDate));
        $dtEnd    = date('Ymd\THis', strtotime($endDate));
        $stamp    = gmdate('Ymd\THis\Z');
        $uid      = gmdate('Ymd\THis') . '-' . mt_rand() . '@oe-module-wsp-email';

        // VTIMEZONE for America/Argentina/Buenos_Aires
        $vtimezone = "BEGIN:VTIMEZONE\r\n"
                   . "TZID:$zone\r\n"
                   . "BEGIN:STANDARD\r\n"
                   . "TZOFFSETFROM:-0300\r\n"
                   . "TZOFFSETTO:-0300\r\n"
                   . "TZNAME:ART\r\n"
                   . "DTSTART:19700101T000000\r\n"
                   . "END:STANDARD\r\n"
                   . "END:VTIMEZONE\r\n";

        // Build iCalendar content
        $ical = "BEGIN:VCALENDAR\r\n"
              . "VERSION:2.0\r\n"
              . "PRODID:-//oe-module-wsp-email//OpenEMR//EN\r\n"
              . "METHOD:PUBLISH\r\n"
              . $vtimezone
              . "BEGIN:VEVENT\r\n"
              . "DTSTART;TZID=$zone:$dtStart\r\n"
              . "DTEND;TZID=$zone:$dtEnd\r\n"
              . "DTSTAMP:$stamp\r\n"
              . "UID:$uid\r\n"
              . "SUMMARY:$summary_escaped\r\n"
              . "DESCRIPTION:$description_escaped\r\n"
              . "LOCATION:$location_escaped\r\n"
              . "URL:$url\r\n"
              . "ORGANIZER;CN=$organizer_escaped:mailto:$organizerEmail\r\n"
              . "ATTENDEE;PARTSTAT=NEEDS-ACTION;CN=$attendeeName_escaped;EMAIL=$attendeeEmail:mailto:$attendeeEmail\r\n"
              . "CONTACT:$organizer_escaped\r\n"
              . "CLASS:PUBLIC\r\n"
              . "PRIORITY:5\r\n"
              . "TRANSP:OPAQUE\r\n"
              . "STATUS:CONFIRMED\r\n"
              . "SEQUENCE:0\r\n"
              . "BEGIN:VALARM\r\n"
              . "TRIGGER:-PT60M\r\n"
              . "ACTION:DISPLAY\r\n"
              . "DESCRIPTION:" . $this->escapeIcal(LocalizationHelper::translate('Appointment reminder')) . "\r\n"
              . "END:VALARM\r\n"
              . "END:VEVENT\r\n"
              . "END:VCALENDAR\r\n";

        // Apply line folding (RFC 5545)
        return $this->foldIcalContent($ical);
    }

    /**
     * Escape special characters for iCalendar format (RFC 5545)
     */
    private function escapeIcal(string $value): string
    {
        // Order matters: backslash must be first
        return str_replace(
            ['\\', "\n", "\r", ',', ';'],
            ['\\\\', '\\n', '', '\\,', '\\;'],
            $value
        );
    }

    /**
     * Fold long lines to comply with RFC 5545 (max 75 octets per line)
     */
    private function foldIcalContent(string $content): string
    {
        $lines = explode("\r\n", $content);
        $foldedLines = [];

        foreach ($lines as $line) {
            // Fold lines longer than 75 octets (use 70 to be safe with UTF-8)
            while (strlen($line) > 70) {
                $foldedLines[] = substr($line, 0, 70);
                $line = ' ' . substr($line, 70); // Continuation line starts with space
            }
            $foldedLines[] = $line;
        }

        return implode("\r\n", $foldedLines);
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
