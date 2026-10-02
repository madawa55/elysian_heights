<?php
/**
 * Shared bootstrap: config, session, database, settings and small helpers.
 * Every page and endpoint starts with: require __DIR__ . '/lib/bootstrap.php';
 */
date_default_timezone_set('Asia/Colombo');

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_set_cookie_params([
        'httponly' => true,
        'samesite' => 'Lax',
        'secure'   => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
    ]);
    session_start();
}

$config = require dirname(__DIR__) . '/config.php';

const BOOKING_STATUSES = [
    'pending_payment' => 'Payment Pending',
    'confirmed'       => 'Confirmed',
    'completed'       => 'Completed',
    'cancelled'       => 'Cancelled',
];

// Bookings in these states hold their dates on the calendar.
const BLOCKING_STATUSES = ['pending_payment', 'confirmed'];

const GALLERY_DIR = 'uploads/gallery';

// Guests can book this far ahead.
const BOOKING_WINDOW_MONTHS = 18;

function e(?string $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function db(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        global $config;
        $c = $config['db'];
        $pdo = new PDO(
            "mysql:host={$c['host']};port={$c['port']};dbname={$c['name']};charset=utf8mb4",
            $c['user'],
            $c['pass'],
            [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]
        );
        $pdo->exec("SET time_zone = '+05:30'");
    }
    return $pdo;
}

/* ---------- CSRF ---------- */

function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf_token" value="' . e(csrf_token()) . '">';
}

function csrf_valid(): bool
{
    return !empty($_POST['csrf_token']) && !empty($_SESSION['csrf_token'])
        && hash_equals($_SESSION['csrf_token'], (string)$_POST['csrf_token']);
}

/* ---------- Settings (key/value table, editable in admin) ---------- */

const SETTING_DEFAULTS = [
    'nightly_rate'    => '25000',
    'currency'        => 'LKR',
    'min_nights'      => '1',
    'max_guests'      => '10',
    'deposit_percent' => '0',      // 0 = full payment up front; e.g. 30 = a 30% deposit confirms the booking
    'pending_hold_hours' => '48',  // unpaid bookings are auto-cancelled after this many hours; 0 = never
    'bank_details'    => "Bank: \nAccount Name: \nAccount Number: \nBranch: ",
    'payment_note'    => 'Please send the payment slip with your booking reference via WhatsApp or email.',
    'invoice_footer'  => 'Thank you for choosing Elysian Heights.',
];

function settings(): array
{
    static $cache = null;
    if ($cache === null) {
        $cache = SETTING_DEFAULTS;
        foreach (db()->query('SELECT name, value FROM settings') as $row) {
            $cache[$row['name']] = $row['value'];
        }
    }
    return $cache;
}

function setting(string $key): string
{
    return settings()[$key] ?? '';
}

function save_setting(string $key, string $value): void
{
    db()->prepare('INSERT INTO settings (name, value) VALUES (?, ?) ON DUPLICATE KEY UPDATE value = VALUES(value)')
        ->execute([$key, $value]);
}

function money(float $amount): string
{
    return setting('currency') . ' ' . number_format($amount, 2);
}

/* ---------- Dates & availability ---------- */

function valid_date(string $d): bool
{
    $dt = DateTime::createFromFormat('!Y-m-d', $d);
    return $dt && $dt->format('Y-m-d') === $d;
}

function nights_between(string $checkIn, string $checkOut): int
{
    return (int)(new DateTime($checkIn))->diff(new DateTime($checkOut))->format('%r%a');
}

/* ---------- Pricing ---------- */

function rate_seasons(): array
{
    static $cache = null;
    if ($cache === null) {
        $cache = db()->query('SELECT * FROM rate_seasons ORDER BY start_date')->fetchAll();
    }
    return $cache;
}

function rate_for_night(string $date): array
{
    foreach (rate_seasons() as $season) {
        if ($date >= $season['start_date'] && $date <= $season['end_date']) {
            return [(float)$season['nightly_rate'], $season['name']];
        }
    }
    return [(float)setting('nightly_rate'), 'Standard rate'];
}

/**
 * Prices a stay night by night. Consecutive nights at the same rate are grouped into one line:
 * ['nights' => int, 'total' => float, 'avg_rate' => float, 'lines' => [[from, to, nights, rate, label], ...]]
 * where each line's 'to' is the morning after its last night.
 */
function price_stay(string $checkIn, string $checkOut): array
{
    $lines = [];
    $total = 0.0;
    for ($d = new DateTime($checkIn); $d->format('Y-m-d') < $checkOut; $d->modify('+1 day')) {
        $night = $d->format('Y-m-d');
        [$rate, $label] = rate_for_night($night);
        $total += $rate;
        $last = count($lines) - 1;
        if ($last >= 0 && $lines[$last]['rate'] === $rate && $lines[$last]['label'] === $label) {
            $lines[$last]['nights']++;
        } else {
            $lines[] = ['from' => $night, 'to' => null, 'nights' => 1, 'rate' => $rate, 'label' => $label];
            $last++;
        }
        $lines[$last]['to'] = (clone $d)->modify('+1 day')->format('Y-m-d');
    }
    $nights = nights_between($checkIn, $checkOut);
    return [
        'nights'   => $nights,
        'total'    => round($total, 2),
        'avg_rate' => $nights ? round($total / $nights, 2) : 0,
        'lines'    => $lines,
    ];
}

/** Amount the guest must pay to confirm: the deposit, or the full total when deposits are off. */
function deposit_for(float $total): float
{
    $pct = (float)setting('deposit_percent');
    return $pct > 0 && $pct < 100 ? round($total * $pct / 100, 2) : $total;
}

/** Deadline for a new pending booking, or null when holds never expire. */
function payment_due_from_now(): ?string
{
    $hours = (int)setting('pending_hold_hours');
    return $hours > 0 ? date('Y-m-d H:i:s', time() + $hours * 3600) : null;
}

/** Cancels pending bookings whose payment deadline has passed, freeing their dates. Runs at most once per request. */
function expire_pending_bookings(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    db()->exec("UPDATE bookings
        SET status = 'cancelled',
            admin_notes = CONCAT_WS(CHAR(10), NULLIF(admin_notes, ''),
                CONCAT('[Auto] Cancelled: payment not received by ', DATE_FORMAT(payment_due_at, '%d %b %Y %H:%i'), '.'))
        WHERE status = 'pending_payment' AND payment_due_at IS NOT NULL AND payment_due_at < NOW()");
}

/**
 * Every unavailable night (Y-m-d) between $from and $to (exclusive),
 * from blocked dates and from bookings that hold dates.
 * $ignoreBookingId lets admin edits ignore the booking being edited.
 */
function unavailable_nights(string $from, string $to, int $ignoreBookingId = 0): array
{
    expire_pending_bookings();
    $nights = [];

    $stmt = db()->prepare('SELECT blocked_date FROM blocked_dates WHERE blocked_date >= ? AND blocked_date < ?');
    $stmt->execute([$from, $to]);
    foreach ($stmt as $row) {
        $nights[$row['blocked_date']] = true;
    }

    $in = implode(',', array_fill(0, count(BLOCKING_STATUSES), '?'));
    $stmt = db()->prepare(
        "SELECT check_in, check_out FROM bookings
         WHERE status IN ($in) AND check_in < ? AND check_out > ? AND id <> ?"
    );
    $stmt->execute([...BLOCKING_STATUSES, $to, $from, $ignoreBookingId]);
    foreach ($stmt as $row) {
        $d = new DateTime(max($row['check_in'], $from));
        $end = min($row['check_out'], $to);
        while ($d->format('Y-m-d') < $end) {
            $nights[$d->format('Y-m-d')] = true;
            $d->modify('+1 day');
        }
    }

    $nights = array_keys($nights);
    sort($nights);
    return $nights;
}

function range_available(string $checkIn, string $checkOut, int $ignoreBookingId = 0): bool
{
    return !unavailable_nights($checkIn, $checkOut, $ignoreBookingId);
}

function booking_reference(): string
{
    $exists = db()->prepare('SELECT 1 FROM bookings WHERE reference = ?');
    do {
        $ref = 'EH' . date('ymd') . strtoupper(bin2hex(random_bytes(2)));
        $exists->execute([$ref]);
    } while ($exists->fetchColumn());
    return $ref;
}

/* ---------- Login throttling (per IP, stored server-side so clearing cookies doesn't reset it) ---------- */

const LOGIN_MAX_FAILS = 5;
const LOGIN_LOCK_SECONDS = 900;

function login_throttle_file(): string
{
    return dirname(__DIR__) . '/storage/login_attempts.json';
}

/** Seconds until this IP may try again (0 = allowed). */
function login_locked_for(): int
{
    $data = json_decode((string)@file_get_contents(login_throttle_file()), true) ?: [];
    $entry = $data[hash('sha256', $_SERVER['REMOTE_ADDR'] ?? '')] ?? null;
    if (!$entry || $entry['count'] < LOGIN_MAX_FAILS) {
        return 0;
    }
    return max(0, $entry['since'] + LOGIN_LOCK_SECONDS - time());
}

function login_record(bool $success): void
{
    $file = login_throttle_file();
    $fh = @fopen($file, 'c+');
    if (!$fh) {
        return;
    }
    flock($fh, LOCK_EX);
    $data = json_decode((string)stream_get_contents($fh), true) ?: [];
    $key = hash('sha256', $_SERVER['REMOTE_ADDR'] ?? '');
    $now = time();
    // Forget stale entries so the file stays small.
    $data = array_filter($data, fn($e) => $now - $e['since'] < LOGIN_LOCK_SECONDS);
    if ($success) {
        unset($data[$key]);
    } else {
        $data[$key] = ['count' => ($data[$key]['count'] ?? 0) + 1, 'since' => $data[$key]['since'] ?? $now];
    }
    ftruncate($fh, 0);
    rewind($fh);
    fwrite($fh, json_encode($data));
    flock($fh, LOCK_UN);
    fclose($fh);
}

function status_badge(string $status): string
{
    return '<span class="badge badge-' . e($status) . '">' . e(BOOKING_STATUSES[$status] ?? $status) . '</span>';
}

/* ---------- Gallery ---------- */

function gallery_images(): array
{
    return db()->query('SELECT * FROM gallery_images ORDER BY sort_order, id')->fetchAll();
}
