<?php
/**
 * Availability manager: month calendar showing bookings and blocked dates.
 * Click a free date to block it (closed / maintenance / private use), click a blocked date to reopen it.
 */
require __DIR__ . '/_init.php';
require_login();
require_post_csrf();

$pdo = db();

$month = (string)($_GET['month'] ?? date('Y-m'));
if (!preg_match('/^\d{4}-\d{2}$/', $month) || !valid_date($month . '-01')) {
    $month = date('Y-m');
}
$back = 'calendar.php?month=' . $month;

$action = (string)($_POST['action'] ?? '');

if ($action === 'toggle') {
    $date = (string)($_POST['date'] ?? '');
    if (valid_date($date)) {
        $stmt = $pdo->prepare('DELETE FROM blocked_dates WHERE blocked_date = ?');
        $stmt->execute([$date]);
        if ($stmt->rowCount()) {
            flash(date('d M Y', strtotime($date)) . ' is open for booking again.');
        } elseif (!range_available($date, date('Y-m-d', strtotime($date . ' +1 day')))) {
            flash('That date already has a booking.', 'error');
        } else {
            $pdo->prepare('INSERT INTO blocked_dates (blocked_date, reason) VALUES (?, ?)')
                ->execute([$date, mb_substr(trim((string)($_POST['reason'] ?? '')), 0, 150)]);
            flash(date('d M Y', strtotime($date)) . ' blocked.');
        }
    }
    redirect($back);
}

if ($action === 'block_range' || $action === 'unblock_range') {
    $from = (string)($_POST['from'] ?? '');
    $to = (string)($_POST['to'] ?? '');
    if (!valid_date($from) || !valid_date($to) || $to < $from) {
        flash('Please choose a valid date range (the end date is included).', 'error');
        redirect($back);
    }
    if (nights_between($from, $to) > 366) {
        flash('Please choose a range of one year or less.', 'error');
        redirect($back);
    }
    $toExclusive = date('Y-m-d', strtotime($to . ' +1 day'));

    if ($action === 'unblock_range') {
        $stmt = $pdo->prepare('DELETE FROM blocked_dates WHERE blocked_date BETWEEN ? AND ?');
        $stmt->execute([$from, $to]);
        flash($stmt->rowCount() . ' date(s) reopened.');
    } else {
        $reason = mb_substr(trim((string)($_POST['reason'] ?? '')), 0, 150);
        $taken = array_flip(unavailable_nights($from, $toExclusive));
        $insert = $pdo->prepare('INSERT IGNORE INTO blocked_dates (blocked_date, reason) VALUES (?, ?)');
        $added = $skipped = 0;
        for ($d = new DateTime($from); $d->format('Y-m-d') <= $to; $d->modify('+1 day')) {
            $day = $d->format('Y-m-d');
            if (isset($taken[$day])) {
                $skipped++;
                continue;
            }
            $insert->execute([$day, $reason]);
            $added += $insert->rowCount();
        }
        flash("$added date(s) blocked." . ($skipped ? " $skipped date(s) skipped because they are already booked or blocked." : ''));
    }
    redirect($back);
}

/* ---------- Build the month grid ---------- */
$first = new DateTime($month . '-01');
$start = $first->format('Y-m-d');
$end = (clone $first)->modify('first day of next month')->format('Y-m-d');

$blocked = [];
$stmt = $pdo->prepare('SELECT blocked_date, reason FROM blocked_dates WHERE blocked_date >= ? AND blocked_date < ?');
$stmt->execute([$start, $end]);
foreach ($stmt as $row) {
    $blocked[$row['blocked_date']] = $row['reason'];
}

$booked = [];
$in = implode(',', array_fill(0, count(BLOCKING_STATUSES), '?'));
$stmt = $pdo->prepare("SELECT id, reference, guest_name, status, check_in, check_out FROM bookings
    WHERE status IN ($in) AND check_in < ? AND check_out > ?");
$stmt->execute([...BLOCKING_STATUSES, $end, $start]);
foreach ($stmt as $b) {
    for ($d = new DateTime(max($b['check_in'], $start)); $d->format('Y-m-d') < min($b['check_out'], $end); $d->modify('+1 day')) {
        $booked[$d->format('Y-m-d')] = $b;
    }
}

$prev = (clone $first)->modify('-1 month')->format('Y-m');
$next = (clone $first)->modify('+1 month')->format('Y-m');
$today = date('Y-m-d');
$leading = (int)$first->format('N') - 1; // Monday-first grid

$upcomingBlocks = $pdo->query('SELECT blocked_date, reason FROM blocked_dates WHERE blocked_date >= CURDATE() ORDER BY blocked_date LIMIT 60')->fetchAll();

admin_header('Availability', 'calendar.php');
?>
<h1>Availability</h1>
<p class="muted">Dates with a pending or confirmed booking are unavailable automatically. Click a free date to block it, or a blocked date to open it again. Guests see blocked and booked dates as unavailable on the website calendar.</p>

<div class="split">
    <section class="panel">
        <div class="cal-nav">
            <a class="btn btn-sm btn-outline" href="?month=<?= $prev ?>">‹ Prev</a>
            <h2><?= $first->format('F Y') ?></h2>
            <a class="btn btn-sm btn-outline" href="?month=<?= $next ?>">Next ›</a>
        </div>

        <form method="post" class="admin-cal">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="toggle">
            <?php foreach (['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'] as $dow): ?>
                <div class="dow"><?= $dow ?></div>
            <?php endforeach; ?>
            <?php for ($i = 0; $i < $leading; $i++): ?><div class="day empty"></div><?php endfor; ?>

            <?php for ($d = clone $first; $d->format('Y-m-d') < $end; $d->modify('+1 day')):
                $date = $d->format('Y-m-d');
                $num = $d->format('j');
                $past = $date < $today ? ' past' : '';
                $isToday = $date === $today ? ' today' : '';
                if (isset($booked[$date])):
                    $b = $booked[$date]; ?>
                    <a class="day booked status-<?= e($b['status']) ?><?= $past . $isToday ?>" href="booking.php?id=<?= (int)$b['id'] ?>"
                       title="<?= e($b['reference'] . ' — ' . $b['guest_name']) ?>">
                        <span class="num"><?= $num ?></span>
                        <span class="label"><?= e($b['guest_name']) ?></span>
                    </a>
                <?php elseif (isset($blocked[$date])): ?>
                    <button class="day blocked<?= $past . $isToday ?>" name="date" value="<?= $date ?>" title="Blocked<?= $blocked[$date] !== '' ? ': ' . e($blocked[$date]) : '' ?> — click to open">
                        <span class="num"><?= $num ?></span>
                        <span class="label"><?= e($blocked[$date] ?: 'Blocked') ?></span>
                    </button>
                <?php else: ?>
                    <button class="day free<?= $past . $isToday ?>" name="date" value="<?= $date ?>" title="Free — click to block" <?= $past ? 'disabled' : '' ?>>
                        <span class="num"><?= $num ?></span>
                    </button>
                <?php endif; ?>
            <?php endfor; ?>
        </form>

        <div class="legend">
            <span><i class="sw free"></i> Available</span>
            <span><i class="sw pending"></i> Payment pending</span>
            <span><i class="sw confirmed"></i> Confirmed</span>
            <span><i class="sw blocked"></i> Blocked</span>
        </div>
    </section>

    <aside>
        <section class="panel">
            <h2>Block or open a date range</h2>
            <form method="post" class="stack">
                <?= csrf_field() ?>
                <label>From <input type="date" name="from" required></label>
                <label>To (inclusive) <input type="date" name="to" required></label>
                <label>Reason (optional) <input name="reason" maxlength="150" placeholder="e.g. Maintenance, owner's stay"></label>
                <div class="btn-row">
                    <button class="btn" name="action" value="block_range">Block dates</button>
                    <button class="btn btn-outline" name="action" value="unblock_range">Open dates</button>
                </div>
            </form>
        </section>

        <section class="panel">
            <h2>Upcoming blocked dates</h2>
            <?php if (!$upcomingBlocks): ?>
                <p class="muted">No blocked dates.</p>
            <?php else: ?>
                <ul class="block-list">
                    <?php foreach ($upcomingBlocks as $row): ?>
                        <li>
                            <a href="?month=<?= e(substr($row['blocked_date'], 0, 7)) ?>"><?= e(date('D, d M Y', strtotime($row['blocked_date']))) ?></a>
                            <?php if ($row['reason']): ?><small class="muted"><?= e($row['reason']) ?></small><?php endif; ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </section>
    </aside>
</div>
<?php admin_footer();
