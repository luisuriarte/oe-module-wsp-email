<?php
/**
 * send_manual_email.php — Sends manual email notification via PHPMailer.
 *
 * POST params:
 *   - to (string): Recipient email
 *   - subject (string): Email subject
 *   - message (string): HTML message body
 *   - facility_id (int): Facility ID (for logo and settings)
 *
 * @package   OpenEMR\Modules\WspEmail
 */

$sessionAllowWrite = true;
require_once __DIR__ . '/../../../../../globals.php';

header('Content-Type: application/json');

use OpenEMR\Common\Acl\AclMain;
if (!AclMain::aclCheckCore('patients', 'demo')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Access denied']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'POST required']);
    exit;
}

$to         = trim($_POST['to'] ?? '');
$subject    = trim($_POST['subject'] ?? 'Recordatorio de Cita');
$message    = $_POST['message'] ?? '';
$facilityId = (int)($_POST['facility_id'] ?? 0);

if (empty($to) || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
    echo json_encode(['success' => false, 'message' => 'Invalid email address']);
    exit;
}

// Load PHPMailer
$phpmailerPath = $GLOBALS['fileroot'] . '/library/classes/PHPMailer/src/';
if (!file_exists($phpmailerPath . 'PHPMailer.php')) {
    // Fallback to vendor path
    $phpmailerPath = __DIR__ . '/../../vendor/phpmailer/phpmailer/src/';
}

if (file_exists($phpmailerPath . 'PHPMailer.php')) {
    require_once $phpmailerPath . 'PHPMailer.php';
    require_once $phpmailerPath . 'SMTP.php';
}

if (!class_exists('PHPMailer\PHPMailer\PHPMailer')) {
    echo json_encode(['success' => false, 'message' => 'PHPMailer not available']);
    exit;
}

// Get facility settings for sender info
$facility = sqlQuery("SELECT name, email, phone FROM facility WHERE id = ?", [$facilityId]);
$fromName  = $facility['name'] ?? 'Clinica';
$fromEmail = $facility['email'] ?? 'noreply@clinica.com';

// Get logo path if available
$logoPath = '';
if ($facilityId > 0) {
    $facConfig = sqlQuery("SELECT logo_email FROM wsp_email_facility_config WHERE facility_id = ?", [$facilityId]);
    if (!empty($facConfig['logo_email'])) {
        $logoPath = __DIR__ . '/../../public/images/logo_email/' . $facConfig['logo_email'];
    }
}

try {
    $mail = new \PHPMailer\PHPMailer\PHPMailer(true);

    // SMTP configuration (if available)
    if (!empty($GLOBALS['SMTP_HOST'])) {
        $crypto = new \OpenEMR\Common\Crypto\CryptoGen();
        $mail->isSMTP();
        $mail->Host       = $GLOBALS['SMTP_HOST'];
        $mail->Port       = (int)($GLOBALS['SMTP_PORT'] ?? 587);
        $mail->SMTPAuth   = true;
        $mail->Username   = $GLOBALS['SMTP_USER'] ?? '';
        $mail->Password   = $crypto->decryptStandard($GLOBALS['SMTP_PASS'] ?? '');
        $mail->SMTPSecure = $GLOBALS['SMTP_SECURE'] ?? \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
    }

    $mail->CharSet  = 'UTF-8';
    $mail->setFrom($fromEmail, $fromName);
    $mail->addAddress($to);
    $mail->isHTML(true);
    $mail->Subject = $subject;
    $mail->Body    = $message;
    $mail->AltBody = strip_tags($message);
    $mail->WordWrap = 70;

    // Embed logo if available
    if (!empty($logoPath) && file_exists($logoPath)) {
        $mail->addEmbeddedImage($logoPath, 'logo', 'logo.png');
    }

    $mail->send();

    echo json_encode([
        'success' => true,
        'message' => 'Email sent successfully'
    ]);

} catch (\Exception $e) {
    error_log("WspEmail Manual Email Error: " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => 'Failed to send email: ' . $e->getMessage()
    ]);
}
