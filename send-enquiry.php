<?php
/**
 * Handles the enquiry form (AJAX, returns JSON) and emails it to config['enquiry_to'].
 */
require __DIR__ . '/lib/bootstrap.php';
require __DIR__ . '/lib/mailer.php';
header('Content-Type: application/json; charset=UTF-8');

function respond(bool $ok, string $message, int $status = 200): void
{
    http_response_code($status);
    echo json_encode(['ok' => $ok, 'message' => $message]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond(false, 'Method not allowed.', 405);
}

if (empty($_POST['csrf_token']) || empty($_SESSION['csrf_token'])
    || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
    respond(false, 'Your session has expired. Please refresh the page and try again.', 400);
}

// Honeypot — pretend success so bots don't retry.
if (!empty($_POST['website'])) {
    respond(true, 'Thank you! Your enquiry has been sent.');
}

// Simple rate limit: one enquiry per 60 seconds per session.
if (!empty($_SESSION['last_enquiry']) && time() - $_SESSION['last_enquiry'] < 60) {
    respond(false, 'Please wait a minute before sending another enquiry.', 429);
}

function field(string $key, int $maxLen): string
{
    $value = trim((string)($_POST[$key] ?? ''));
    return mb_substr($value, 0, $maxLen);
}

$name     = header_safe(field('name', 100));
$email    = header_safe(field('email', 150));
$phone    = field('phone', 30);
$guests   = field('guests', 3);
$checkIn  = field('check_in', 10);
$checkOut = field('check_out', 10);
$message  = field('message', 3000);

$errors = [];
if ($name === '') {
    $errors[] = 'Please enter your name.';
}
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $errors[] = 'Please enter a valid email address.';
}
if ($message === '') {
    $errors[] = 'Please enter a message.';
}
if ($guests !== '' && (!ctype_digit($guests) || (int)$guests < 1)) {
    $errors[] = 'Number of guests must be a positive number.';
}

$validDate = fn(string $d) => $d === '' || (bool)DateTime::createFromFormat('Y-m-d', $d);
if (!$validDate($checkIn) || !$validDate($checkOut)) {
    $errors[] = 'Please enter valid dates.';
} elseif ($checkIn !== '' && $checkOut !== '' && $checkOut <= $checkIn) {
    $errors[] = 'Check-out date must be after check-in date.';
}

if ($errors) {
    respond(false, implode(' ', $errors), 422);
}

$siteName = $config['site_name'];
$subject  = "New Enquiry from {$name} — {$siteName}";

$body = implode("\n", [
    "You have received a new enquiry via the {$siteName} website.",
    '',
    "Name:       {$name}",
    "Email:      {$email}",
    'Phone:      ' . ($phone ?: '-'),
    'Guests:     ' . ($guests ?: '-'),
    'Check-in:   ' . ($checkIn ?: '-'),
    'Check-out:  ' . ($checkOut ?: '-'),
    '',
    'Message:',
    $message,
    '',
    '---',
    'Sent: ' . date('Y-m-d H:i:s'),
    'IP:   ' . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'),
]);

$sent = send_mail($config['enquiry_to'], $subject, $body, $email, $name);

if (!$sent) {
    error_log('Elysian Heights: sending failed for enquiry from ' . $email);
    respond(false, 'Sorry, we could not send your enquiry right now. Please call or WhatsApp us instead.', 500);
}

$_SESSION['last_enquiry'] = time();
respond(true, 'Thank you! Your enquiry has been sent. We will get back to you shortly.');
