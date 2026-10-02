<?php
/**
 * Command-line SMTP test. Run from the project folder:
 *   C:\xampp\php\php.exe test-mail.php
 * Uses the 'smtp' settings in config.php and prints the full SMTP conversation.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$config = require __DIR__ . '/config.php';
require __DIR__ . '/lib/PHPMailer/Exception.php';
require __DIR__ . '/lib/PHPMailer/PHPMailer.php';
require __DIR__ . '/lib/PHPMailer/SMTP.php';

if ($config['smtp']['username'] === '' || $config['smtp']['password'] === '') {
    exit("SMTP username/password are empty in config.php\n");
}

$mail = new PHPMailer\PHPMailer\PHPMailer(true);
try {
    $mail->SMTPDebug  = 2;
    $mail->isSMTP();
    $mail->Host       = $config['smtp']['host'];
    $mail->Port       = (int)$config['smtp']['port'];
    $mail->SMTPAuth   = true;
    $mail->SMTPSecure = $config['smtp']['secure'];
    $mail->Username   = $config['smtp']['username'];
    $mail->Password   = $config['smtp']['password'];
    $mail->setFrom($config['mail_from'], $config['site_name']);
    $mail->addAddress($config['enquiry_to']);
    $mail->Subject = 'SMTP test - ' . $config['site_name'];
    $mail->Body    = 'If you can read this, the enquiry form email settings work.';
    $mail->send();
    echo "\nSUCCESS: test email sent to {$config['enquiry_to']}\n";
} catch (PHPMailer\PHPMailer\Exception $e) {
    echo "\nFAILED: {$mail->ErrorInfo}\n";
}
