<?php
/**
 * Included by every admin page: auth guard, flash messages and the page layout.
 */
require dirname(__DIR__) . '/lib/bootstrap.php';

header('X-Frame-Options: DENY');
header('Cache-Control: no-store');

function is_logged_in(): bool
{
    return !empty($_SESSION['admin_id']);
}

function require_login(): void
{
    if (!is_logged_in()) {
        header('Location: login.php');
        exit;
    }
    // Idle timeout: 2 hours.
    if (time() - ($_SESSION['admin_seen'] ?? 0) > 7200) {
        $_SESSION = [];
        session_regenerate_id(true);
        header('Location: login.php?expired=1');
        exit;
    }
    $_SESSION['admin_seen'] = time();
    expire_pending_bookings();
}

/** Rejects POSTs without a valid CSRF token. */
function require_post_csrf(): void
{
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && !csrf_valid()) {
        http_response_code(400);
        exit('Invalid or expired form. Go back, refresh the page and try again.');
    }
}

function flash(string $message, string $type = 'success'): void
{
    $_SESSION['flash'][] = [$type, $message];
}

function redirect(string $url): void
{
    header('Location: ' . $url);
    exit;
}

function admin_header(string $title, string $active = ''): void
{
    global $config;
    $nav = [
        'index.php'    => 'Dashboard',
        'bookings.php' => 'Bookings',
        'calendar.php' => 'Availability',
        'rates.php'    => 'Rates',
        'gallery.php'  => 'Gallery',
        'settings.php' => 'Settings',
    ];
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title><?= e($title) ?> · <?= e($config['site_name']) ?> Admin</title>
    <link rel="stylesheet" href="assets/admin.css">
</head>
<body>
<?php if (is_logged_in()): ?>
<header class="topbar">
    <a href="index.php" class="topbar-brand"><?= e($config['site_name']) ?> <small>Admin</small></a>
    <button class="menu-btn" type="button" aria-label="Toggle menu" onclick="document.body.classList.toggle('nav-open')">☰</button>
    <nav class="topnav">
        <?php foreach ($nav as $href => $label): ?>
            <a href="<?= $href ?>" class="<?= $active === $href ? 'active' : '' ?>"><?= $label ?></a>
        <?php endforeach; ?>
        <a href="../" target="_blank" rel="noopener">View site ↗</a>
        <a href="logout.php" class="logout">Log out</a>
    </nav>
</header>
<?php endif; ?>
<main class="page">
    <?php foreach ($_SESSION['flash'] ?? [] as [$type, $msg]): ?>
        <div class="alert alert-<?= e($type) ?>"><?= e($msg) ?></div>
    <?php endforeach; unset($_SESSION['flash']); ?>
<?php
}

function booking_table(array $rows): void
{
    ?>
    <div class="table-wrap">
        <table class="table">
            <thead>
            <tr><th>Reference</th><th>Guest</th><th>Check-in</th><th>Check-out</th><th>Nights</th><th>Total</th><th>Status</th><th></th></tr>
            </thead>
            <tbody>
            <?php foreach ($rows as $b): ?>
                <tr>
                    <td data-label="Reference"><a href="booking.php?id=<?= (int)$b['id'] ?>"><strong><?= e($b['reference']) ?></strong></a></td>
                    <td data-label="Guest"><?= e($b['guest_name']) ?><br><small class="muted"><?= e($b['phone'] ?: $b['email']) ?></small></td>
                    <td data-label="Check-in"><?= e(date('d M Y', strtotime($b['check_in']))) ?></td>
                    <td data-label="Check-out"><?= e(date('d M Y', strtotime($b['check_out']))) ?></td>
                    <td data-label="Nights"><?= (int)$b['nights'] ?></td>
                    <td data-label="Total"><?= e(money((float)$b['total'])) ?>
                        <?php if ((float)$b['amount_paid'] > 0): ?><br><small class="muted">Paid <?= e(money((float)$b['amount_paid'])) ?></small><?php endif; ?></td>
                    <td data-label="Status"><?= status_badge($b['status']) ?>
                        <?php if ($b['status'] === 'pending_payment' && $b['payment_due_at']): ?><br><small class="muted">Pay by <?= e(date('d M, g:i A', strtotime($b['payment_due_at']))) ?></small><?php endif; ?></td>
                    <td class="actions">
                        <a class="btn btn-sm btn-outline" href="booking.php?id=<?= (int)$b['id'] ?>">Manage</a>
                        <a class="btn btn-sm btn-outline" href="invoice.php?id=<?= (int)$b['id'] ?>" target="_blank">Invoice</a>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php
}

function admin_footer(array $scripts = []): void
{
    echo "</main>\n";
    foreach ($scripts as $src) {
        echo '<script src="' . e($src) . '"></script>' . "\n";
    }
    echo "</body>\n</html>";
}
