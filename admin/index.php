<?php
require __DIR__ . '/_init.php';
require_login();

$pdo = db();
$today = date('Y-m-d');

$counts = array_fill_keys(array_keys(BOOKING_STATUSES), 0);
foreach ($pdo->query('SELECT status, COUNT(*) n FROM bookings GROUP BY status') as $row) {
    $counts[$row['status']] = (int)$row['n'];
}

$monthRevenue = (float)$pdo->query("SELECT COALESCE(SUM(amount), 0) FROM payments
    WHERE paid_on >= DATE_FORMAT(CURDATE(), '%Y-%m-01')")->fetchColumn();

// Confirmed stays that still owe a balance (deposit paid, rest due before arrival).
$owing = $pdo->query("SELECT * FROM bookings
    WHERE status = 'confirmed' AND amount_paid < total ORDER BY check_in LIMIT 10")->fetchAll();

$pending = $pdo->query("SELECT * FROM bookings WHERE status = 'pending_payment' ORDER BY created_at DESC LIMIT 10")->fetchAll();

$stmt = $pdo->prepare("SELECT * FROM bookings WHERE status = 'confirmed' AND check_out >= ? ORDER BY check_in LIMIT 10");
$stmt->execute([$today]);
$upcoming = $stmt->fetchAll();

admin_header('Dashboard', 'index.php');
?>
<h1>Dashboard</h1>

<div class="stats">
    <a class="stat" href="bookings.php?status=pending_payment"><span><?= $counts['pending_payment'] ?></span>Payment pending</a>
    <a class="stat" href="bookings.php?status=confirmed"><span><?= $counts['confirmed'] ?></span>Confirmed</a>
    <a class="stat" href="bookings.php?status=completed"><span><?= $counts['completed'] ?></span>Completed</a>
    <div class="stat"><span><?= e(money($monthRevenue)) ?></span>Payments received, <?= date('F') ?></div>
</div>

<?php if ($owing): ?>
<section class="panel">
    <div class="panel-head">
        <h2>Confirmed — balance still due</h2>
    </div>
    <?php booking_table($owing); ?>
</section>
<?php endif; ?>

<section class="panel">
    <div class="panel-head">
        <h2>Awaiting payment</h2>
        <a href="bookings.php?status=pending_payment">View all</a>
    </div>
    <?php if (!$pending): ?>
        <p class="muted">No bookings waiting for payment.</p>
    <?php else: ?>
        <?php booking_table($pending); ?>
    <?php endif; ?>
</section>

<section class="panel">
    <div class="panel-head">
        <h2>Upcoming confirmed stays</h2>
        <a href="bookings.php?status=confirmed">View all</a>
    </div>
    <?php if (!$upcoming): ?>
        <p class="muted">No upcoming confirmed stays.</p>
    <?php else: ?>
        <?php booking_table($upcoming); ?>
    <?php endif; ?>
</section>
<?php admin_footer();
