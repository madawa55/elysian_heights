<?php
/**
 * Creates a booking in "Payment Pending" status (AJAX, returns JSON).
 * Dates are re-checked under a MySQL named lock so two guests can't book the same nights.
 */
require dirname(__DIR__) . '/lib/bootstrap.php';
require dirname(__DIR__) . '/lib/mailer.php';
header('Content-Type: application/json; charset=UTF-8');

function respond(bool $ok, string $message, int $status = 200, array $extra = []): void
{
    http_response_code($status);
    echo json_encode(['ok' => $ok, 'message' => $message] + $extra);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond(false, 'Method not allowed.', 405);
}
if (!csrf_valid()) {
    respond(false, 'Your session has expired. Please refresh the page and try again.', 400);
}
if (!empty($_POST['website'])) { // honeypot
    respond(false, 'Booking could not be completed.', 400);
}
if (!empty($_SESSION['last_booking']) && time() - $_SESSION['last_booking'] < 60) {
    respond(false, 'Please wait a minute before making another booking.', 429);
}

$post = fn(string $key, int $max) => mb_substr(trim((string)($_POST[$key] ?? '')), 0, $max);

$name     = header_safe($post('name', 100));
$email    = header_safe($post('email', 150));
$phone    = $post('phone', 30);
$guests   = $post('guests', 3);
$checkIn  = $post('check_in', 10);
$checkOut = $post('check_out', 10);
$notes    = $post('notes', 2000);

try {
    $minNights = max(1, (int)setting('min_nights'));
    $maxGuests = max(1, (int)setting('max_guests'));
} catch (Throwable $e) {
    error_log('Elysian Heights booking error: ' . $e->getMessage());
    respond(false, 'Online booking is temporarily unavailable. Please contact us directly.', 500);
}

$errors = [];
if ($name === '') $errors[] = 'Please enter your name.';
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Please enter a valid email address.';
if ($phone === '') $errors[] = 'Please enter a phone / WhatsApp number.';
if (!ctype_digit($guests) || (int)$guests < 1 || (int)$guests > $maxGuests) {
    $errors[] = "Number of guests must be between 1 and {$maxGuests}.";
}
if (!valid_date($checkIn) || !valid_date($checkOut)) {
    $errors[] = 'Please select your check-in and check-out dates.';
} elseif ($checkIn < date('Y-m-d')) {
    $errors[] = 'Check-in date cannot be in the past.';
} elseif ($checkOut > date('Y-m-d', strtotime('+' . BOOKING_WINDOW_MONTHS . ' months'))) {
    $errors[] = 'Online bookings are open up to ' . BOOKING_WINDOW_MONTHS . ' months ahead. Please contact us for later dates.';
} elseif ($checkOut <= $checkIn) {
    $errors[] = 'Check-out must be after check-in.';
} else {
    $nights = nights_between($checkIn, $checkOut);
    if ($nights < $minNights) $errors[] = "The minimum stay is {$minNights} night" . ($minNights > 1 ? 's' : '') . '.';
    if ($nights > 60) $errors[] = 'For stays longer than 60 nights please contact us directly.';
}
if ($errors) {
    respond(false, implode(' ', $errors), 422);
}

$pdo = db();
try {
    if ((int)$pdo->query("SELECT GET_LOCK('eh_booking', 10)")->fetchColumn() !== 1) {
        respond(false, 'The booking system is busy. Please try again in a moment.', 503);
    }

    if (!range_available($checkIn, $checkOut)) {
        $pdo->query("SELECT RELEASE_LOCK('eh_booking')");
        respond(false, 'Sorry, some of those dates were just booked. Please choose different dates.', 409, ['unavailable' => true]);
    }

    $price = price_stay($checkIn, $checkOut);
    $booking = [
        'reference'       => booking_reference(),
        'guest_name'      => $name,
        'email'           => $email,
        'phone'           => $phone,
        'guests'          => (int)$guests,
        'check_in'        => $checkIn,
        'check_out'       => $checkOut,
        'nights'          => $nights,
        'nightly_rate'    => $price['avg_rate'],
        'total'           => $price['total'],
        'price_breakdown' => json_encode($price['lines']),
        'deposit_amount'  => deposit_for($price['total']),
        'amount_paid'     => 0,
        'status'          => 'pending_payment',
        'payment_due_at'  => payment_due_from_now(),
        'guest_notes'     => $notes,
    ];
    $cols = implode(', ', array_keys($booking));
    $marks = implode(', ', array_fill(0, count($booking), '?'));
    $pdo->prepare("INSERT INTO bookings ($cols) VALUES ($marks)")->execute(array_values($booking));
    $pdo->query("SELECT RELEASE_LOCK('eh_booking')");
} catch (Throwable $e) {
    error_log('Elysian Heights booking error: ' . $e->getMessage());
    respond(false, 'Sorry, we could not save your booking. Please try again or contact us directly.', 500);
}

$_SESSION['last_booking'] = time();

// Notifications are best-effort — the booking is already saved.
$site = $config['site_name'];
$summary = booking_summary_text($booking);

send_mail(
    $config['booking_notify_to'] ?? $config['enquiry_to'],
    "New booking {$booking['reference']} — {$name} ({$checkIn} to {$checkOut})",
    "A new booking was made on the {$site} website and is awaiting payment.\n\n{$summary}\n\nGuest notes:\n" . ($notes ?: '-'),
    $email,
    $name
);
send_mail(
    $email,
    "Your booking request {$booking['reference']} — {$site}",
    "Dear {$name},\n\nThank you for booking {$site}. Your dates are reserved and your booking is awaiting payment.\n\n"
    . $summary . "\n\n" . payment_instructions_text($booking) . "\n\n"
    . "We will confirm your booking as soon as the payment is received.\n\nWarm regards,\n{$site}"
);

$isDeposit = $booking['deposit_amount'] < $booking['total'];
respond(true, 'Your booking has been received.', 200, [
    'reference'    => $booking['reference'],
    'nights'       => $nights,
    'total'        => money($booking['total']),
    'pay_now'      => money($booking['deposit_amount']),
    'is_deposit'   => $isDeposit,
    'balance'      => $isDeposit ? money($booking['total'] - $booking['deposit_amount']) : null,
    'pay_by'       => $booking['payment_due_at'] ? date('D, d M Y \a\t g:i A', strtotime($booking['payment_due_at'])) : null,
    'bank_details' => setting('bank_details'),
    'payment_note' => setting('payment_note'),
]);
