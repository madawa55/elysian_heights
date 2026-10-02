<?php
require __DIR__ . '/_init.php';
require dirname(__DIR__) . '/lib/mailer.php';
require_login();
require_post_csrf();

$pdo = db();
$id = (int)($_GET['id'] ?? 0);

function load_booking(int $id): ?array
{
    $stmt = db()->prepare('SELECT * FROM bookings WHERE id = ?');
    $stmt->execute([$id]);
    return $stmt->fetch() ?: null;
}

/** Re-sums the payments table into bookings.amount_paid. */
function refresh_amount_paid(int $id): void
{
    db()->prepare('UPDATE bookings SET amount_paid = (SELECT COALESCE(SUM(amount), 0) FROM payments WHERE booking_id = ?) WHERE id = ?')
        ->execute([$id, $id]);
}

function guest_email(array $b, string $kind): bool
{
    global $config;
    $site = $config['site_name'];
    $phone = $config['phones'][0]['display'];
    $sign = "\n\nWarm regards,\n{$site}";

    switch ($kind) {
        case 'confirmed':
            $balance = (float)$b['total'] - (float)$b['amount_paid'];
            return send_mail($b['email'], "Booking confirmed {$b['reference']} — {$site}",
                "Dear {$b['guest_name']},\n\nWe have received your payment and your booking is now CONFIRMED.\n\n"
                . booking_summary_text($b)
                . ($balance > 0 ? "\n\nThe remaining balance of " . money($balance) . " is payable before check-in." : '')
                . "\n\nWe look forward to welcoming you to {$site}. For any questions reply to this email or call {$phone}." . $sign);
        case 'payment':
            return send_mail($b['email'], "Payment received {$b['reference']} — {$site}",
                "Dear {$b['guest_name']},\n\nThank you — we have received your payment.\n\n" . booking_summary_text($b) . $sign);
        case 'cancelled':
            return send_mail($b['email'], "Booking cancelled {$b['reference']} — {$site}",
                "Dear {$b['guest_name']},\n\nYour booking {$b['reference']} ({$b['check_in']} to {$b['check_out']}) has been cancelled.\n"
                . "If you think this is a mistake, please contact us on {$phone}." . $sign);
        case 'pending':
            return send_mail($b['email'], "Payment details for booking {$b['reference']} — {$site}",
                "Dear {$b['guest_name']},\n\nYour dates are reserved and your booking is awaiting payment.\n\n"
                . booking_summary_text($b) . "\n\n" . payment_instructions_text($b) . $sign);
    }
    return false;
}

$booking = $id ? load_booking($id) : null;
if ($id && !$booking) {
    flash('Booking not found.', 'error');
    redirect('bookings.php');
}

$errors = [];
$action = (string)($_POST['action'] ?? '');
$notifyNote = fn(bool $sent) => $sent ? ' Guest notified by email.' : ' (The email to the guest could not be sent.)';

/* ---------- Record a payment ---------- */
if ($action === 'payment' && $booking) {
    $amount = round((float)($_POST['amount'] ?? 0), 2);
    $paidOn = (string)($_POST['paid_on'] ?? '');
    if ($amount <= 0) {
        flash('Enter the amount received.', 'error');
        redirect("booking.php?id=$id");
    }
    if (!valid_date($paidOn)) {
        $paidOn = date('Y-m-d');
    }
    // Confirming a cancelled (e.g. auto-expired) booking must not double-book.
    $confirm = !empty($_POST['confirm']) && in_array($booking['status'], ['pending_payment', 'cancelled'], true);
    if ($confirm && $booking['status'] === 'cancelled' && !range_available($booking['check_in'], $booking['check_out'], $id)) {
        flash('Those dates have been taken since this booking was cancelled, so it cannot be confirmed. Change the dates first.', 'error');
        redirect("booking.php?id=$id");
    }

    $pdo->prepare('INSERT INTO payments (booking_id, amount, reference, paid_on, note) VALUES (?, ?, ?, ?, ?)')->execute([
        $id, $amount,
        mb_substr(trim((string)($_POST['reference'] ?? '')), 0, 100),
        $paidOn,
        mb_substr(trim((string)($_POST['note'] ?? '')), 0, 255),
    ]);
    refresh_amount_paid($id);
    $message = 'Payment of ' . money($amount) . ' recorded.';

    if ($confirm) {
        $pdo->prepare("UPDATE bookings SET status = 'confirmed', confirmed_at = NOW(), payment_due_at = NULL WHERE id = ?")->execute([$id]);
        $message .= ' Booking confirmed.';
    }
    if (!empty($_POST['notify'])) {
        $message .= $notifyNote(guest_email(load_booking($id), $confirm ? 'confirmed' : 'payment'));
    }
    flash($message);
    redirect("booking.php?id=$id");
}

if ($action === 'delete_payment' && $booking) {
    $pdo->prepare('DELETE FROM payments WHERE id = ? AND booking_id = ?')->execute([(int)($_POST['payment_id'] ?? 0), $id]);
    refresh_amount_paid($id);
    flash('Payment removed. Check the booking status is still correct.', 'info');
    redirect("booking.php?id=$id");
}

/* ---------- Payment deadline ---------- */
if ($action === 'deadline' && $booking && $booking['status'] === 'pending_payment') {
    $due = trim((string)($_POST['payment_due_at'] ?? ''));
    if ($due === '') {
        $value = null;
        $msg = 'Payment deadline removed — this booking will not expire automatically.';
    } elseif (($ts = strtotime($due)) && $ts > time()) {
        $value = date('Y-m-d H:i:s', $ts);
        $msg = 'Payment deadline set to ' . date('d M Y, g:i A', $ts) . '.';
    } else {
        flash('Choose a deadline in the future, or leave it empty for no deadline.', 'error');
        redirect("booking.php?id=$id");
    }
    $pdo->prepare('UPDATE bookings SET payment_due_at = ? WHERE id = ?')->execute([$value, $id]);
    flash($msg);
    redirect("booking.php?id=$id");
}

/* ---------- Status change ---------- */
if ($action === 'status' && $booking) {
    $new = (string)($_POST['status'] ?? '');
    if (!isset(BOOKING_STATUSES[$new])) {
        flash('Unknown status.', 'error');
        redirect("booking.php?id=$id");
    }

    // Re-activating a cancelled booking must not double-book.
    if (in_array($new, BLOCKING_STATUSES, true) && !in_array($booking['status'], BLOCKING_STATUSES, true)
        && !range_available($booking['check_in'], $booking['check_out'], $id)) {
        flash('Those dates are no longer free, so this booking cannot be re-activated. Change the dates first.', 'error');
        redirect("booking.php?id=$id");
    }

    $dueAt = $booking['payment_due_at'];
    $confirmedAt = $booking['confirmed_at'];
    if ($new === 'pending_payment' && $booking['status'] !== 'pending_payment') {
        $dueAt = payment_due_from_now(); // fresh hold, otherwise it would expire again immediately
        $confirmedAt = null;
    } elseif ($new === 'confirmed' && !$confirmedAt) {
        $confirmedAt = date('Y-m-d H:i:s');
    }
    if ($new !== 'pending_payment') {
        $dueAt = null;
    }

    $pdo->prepare('UPDATE bookings SET status = ?, payment_due_at = ?, confirmed_at = ? WHERE id = ?')
        ->execute([$new, $dueAt, $confirmedAt, $id]);
    $message = 'Status changed to ' . BOOKING_STATUSES[$new] . '.';

    if (!empty($_POST['notify']) && in_array($new, ['confirmed', 'cancelled', 'pending_payment'], true)) {
        $message .= $notifyNote(guest_email(load_booking($id), $new === 'pending_payment' ? 'pending' : $new));
    }
    flash($message);
    redirect("booking.php?id=$id");
}

/* ---------- Delete ---------- */
if ($action === 'delete' && $booking) {
    $pdo->prepare('DELETE FROM bookings WHERE id = ?')->execute([$id]);
    flash("Booking {$booking['reference']} deleted.");
    redirect('bookings.php');
}

/* ---------- Create / edit details ---------- */
$form = $booking ?? [
    'guest_name' => '', 'email' => '', 'phone' => '', 'guests' => 2,
    'check_in' => '', 'check_out' => '', 'total' => '',
    'guest_notes' => '', 'admin_notes' => '', 'status' => 'pending_payment',
];
$form['recalc'] = !$booking;

if ($action === 'save') {
    $post = fn(string $k, int $max) => mb_substr(trim((string)($_POST[$k] ?? '')), 0, $max);
    foreach (['guest_name' => 100, 'email' => 150, 'phone' => 30, 'guests' => 3, 'check_in' => 10,
              'check_out' => 10, 'total' => 15, 'guest_notes' => 2000, 'admin_notes' => 2000] as $k => $max) {
        $form[$k] = $post($k, $max);
    }
    $form['recalc'] = !empty($_POST['recalc']);
    if (!$booking) {
        $form['status'] = isset(BOOKING_STATUSES[$_POST['status'] ?? '']) ? $_POST['status'] : 'pending_payment';
    }

    if ($form['guest_name'] === '') $errors[] = 'Guest name is required.';
    if (!filter_var($form['email'], FILTER_VALIDATE_EMAIL)) $errors[] = 'A valid email is required.';
    if (!ctype_digit((string)$form['guests']) || (int)$form['guests'] < 1) $errors[] = 'Guests must be at least 1.';
    if (!$form['recalc'] && (!is_numeric($form['total']) || (float)$form['total'] < 0)) {
        $errors[] = 'Enter the total price, or tick "Calculate from rates".';
    }
    if (!valid_date($form['check_in']) || !valid_date($form['check_out'])) {
        $errors[] = 'Check-in and check-out dates are required.';
    } elseif ($form['check_out'] <= $form['check_in']) {
        $errors[] = 'Check-out must be after check-in.';
    } elseif (in_array($form['status'], BLOCKING_STATUSES, true)) {
        $clash = unavailable_nights($form['check_in'], $form['check_out'], $id);
        if ($clash) {
            $errors[] = 'These nights are already booked or blocked: ' . implode(', ', $clash) . '.';
        }
    }

    if (!$errors) {
        $nights = nights_between($form['check_in'], $form['check_out']);
        if ($form['recalc']) {
            $price = price_stay($form['check_in'], $form['check_out']);
        } else {
            $total = round((float)$form['total'], 2);
            $price = [
                'total'    => $total,
                'avg_rate' => round($total / $nights, 2),
                'lines'    => [['from' => $form['check_in'], 'to' => $form['check_out'], 'nights' => $nights,
                                'rate' => round($total / $nights, 2), 'label' => 'Agreed rate']],
            ];
        }
        $data = [
            'guest_name'      => $form['guest_name'],
            'email'           => $form['email'],
            'phone'           => $form['phone'],
            'guests'          => (int)$form['guests'],
            'check_in'        => $form['check_in'],
            'check_out'       => $form['check_out'],
            'nights'          => $nights,
            'nightly_rate'    => $price['avg_rate'],
            'total'           => $price['total'],
            'price_breakdown' => json_encode($price['lines']),
            'deposit_amount'  => deposit_for($price['total']),
            'guest_notes'     => $form['guest_notes'],
            'admin_notes'     => $form['admin_notes'],
        ];

        if ($booking) {
            $set = implode(', ', array_map(fn($k) => "$k = ?", array_keys($data)));
            $pdo->prepare("UPDATE bookings SET $set WHERE id = ?")->execute([...array_values($data), $id]);
            flash('Booking updated.');
        } else {
            $data['reference'] = booking_reference();
            $data['status'] = $form['status'];
            if ($data['status'] === 'confirmed') {
                $data['confirmed_at'] = date('Y-m-d H:i:s');
            } else {
                $data['payment_due_at'] = payment_due_from_now();
            }
            $cols = implode(', ', array_keys($data));
            $marks = implode(', ', array_fill(0, count($data), '?'));
            $pdo->prepare("INSERT INTO bookings ($cols) VALUES ($marks)")->execute(array_values($data));
            $id = (int)$pdo->lastInsertId();
            $msg = "Booking {$data['reference']} created.";
            if (!empty($_POST['notify']) && $data['status'] === 'pending_payment') {
                $msg .= $notifyNote(guest_email(load_booking($id), 'pending'));
            }
            flash($msg);
        }
        redirect("booking.php?id=$id");
    }
}

$payments = [];
if ($booking) {
    $stmt = $pdo->prepare('SELECT * FROM payments WHERE booking_id = ? ORDER BY paid_on, id');
    $stmt->execute([$id]);
    $payments = $stmt->fetchAll();

    $total = (float)$booking['total'];
    $paid = (float)$booking['amount_paid'];
    $deposit = (float)$booking['deposit_amount'];
    $balance = max(0, $total - $paid);
    // Suggest the outstanding deposit first, then the remaining balance.
    $suggested = $paid < $deposit ? $deposit - $paid : $balance;
    $lines = json_decode((string)$booking['price_breakdown'], true) ?: [];
}

admin_header($booking ? 'Booking ' . $booking['reference'] : 'New booking', 'bookings.php');
?>
<div class="page-head">
    <h1><?= $booking ? 'Booking ' . e($booking['reference']) . ' ' . status_badge($booking['status']) : 'New booking' ?></h1>
    <?php if ($booking): ?>
        <a class="btn btn-outline" href="invoice.php?id=<?= $id ?>" target="_blank">View / print invoice</a>
    <?php endif; ?>
</div>
<p><a href="bookings.php">← All bookings</a></p>

<?php foreach ($errors as $err): ?><div class="alert alert-error"><?= e($err) ?></div><?php endforeach; ?>

<?php if ($booking && $booking['status'] === 'pending_payment' && $booking['payment_due_at']): ?>
    <div class="alert alert-info">
        Awaiting payment of <strong><?= e(money(max(0, $deposit - $paid))) ?></strong>.
        This booking will be cancelled automatically and its dates released if no payment is recorded by
        <strong><?= e(date('D, d M Y, g:i A', strtotime($booking['payment_due_at']))) ?></strong>.
    </div>
<?php endif; ?>

<div class="split">
    <div>
        <?php if ($booking): ?>
        <section class="panel">
            <h2>Price &amp; payments</h2>
            <div class="money-row">
                <div><span>Total</span><strong><?= e(money($total)) ?></strong></div>
                <?php if ($deposit < $total): ?><div><span>Deposit to confirm</span><strong><?= e(money($deposit)) ?></strong></div><?php endif; ?>
                <div><span>Paid</span><strong class="ok"><?= e(money($paid)) ?></strong></div>
                <div><span>Balance due</span><strong class="<?= $balance > 0 ? 'due' : 'ok' ?>"><?= e(money($balance)) ?></strong></div>
            </div>

            <?php if ($lines): ?>
                <table class="table compact">
                    <thead><tr><th>Nights</th><th>Rate</th><th></th><th class="num">Amount</th></tr></thead>
                    <tbody>
                    <?php foreach ($lines as $l): ?>
                        <tr>
                            <td data-label="Nights"><?= e(date('d M', strtotime($l['from']))) ?> – <?= e(date('d M', strtotime($l['to']))) ?> (<?= (int)$l['nights'] ?>)</td>
                            <td data-label="Rate"><?= e(money((float)$l['rate'])) ?></td>
                            <td data-label="Type"><small class="muted"><?= e($l['label']) ?></small></td>
                            <td data-label="Amount" class="num"><?= e(money($l['rate'] * $l['nights'])) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>

            <h3 class="sub">Payments received</h3>
            <?php if (!$payments): ?>
                <p class="muted">No payments recorded yet.</p>
            <?php else: ?>
                <table class="table compact">
                    <thead><tr><th>Date</th><th>Amount</th><th>Reference</th><th></th></tr></thead>
                    <tbody>
                    <?php foreach ($payments as $p): ?>
                        <tr>
                            <td data-label="Date"><?= e(date('d M Y', strtotime($p['paid_on']))) ?></td>
                            <td data-label="Amount"><strong><?= e(money((float)$p['amount'])) ?></strong></td>
                            <td data-label="Reference"><?= e($p['reference'] ?: '—') ?><?= $p['note'] ? '<br><small class="muted">' . e($p['note']) . '</small>' : '' ?></td>
                            <td class="actions">
                                <form method="post" onsubmit="return confirm('Remove this payment record?');">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="delete_payment">
                                    <input type="hidden" name="payment_id" value="<?= (int)$p['id'] ?>">
                                    <button class="btn btn-sm btn-danger">Remove</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>

            <?php if ($booking['status'] !== 'completed' && ($balance > 0 || $booking['status'] === 'pending_payment')): ?>
                <form method="post" class="form-grid payment-form">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="payment">
                    <h3 class="sub full">Record a bank transfer</h3>
                    <label>Amount received (<?= e(setting('currency')) ?>)
                        <input type="number" name="amount" min="0.01" step="0.01" required value="<?= e(number_format($suggested, 2, '.', '')) ?>"></label>
                    <label>Date received <input type="date" name="paid_on" value="<?= date('Y-m-d') ?>" max="<?= date('Y-m-d') ?>"></label>
                    <label>Bank / slip reference <input name="reference" maxlength="100" placeholder="e.g. slip no. or transaction ID"></label>
                    <label>Note <input name="note" maxlength="255" placeholder="optional"></label>
                    <?php if (in_array($booking['status'], ['pending_payment', 'cancelled'], true)): ?>
                        <label class="check full"><input type="checkbox" name="confirm" value="1" checked> Confirm the booking</label>
                    <?php endif; ?>
                    <label class="check full"><input type="checkbox" name="notify" value="1" checked> Email the guest a confirmation / receipt</label>
                    <div class="full"><button class="btn btn-success">Record payment</button></div>
                </form>
            <?php endif; ?>
        </section>
        <?php endif; ?>

        <section class="panel">
            <h2><?= $booking ? 'Booking details' : 'Guest & stay' ?></h2>
            <form method="post" class="form-grid">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="save">
                <label>Guest name * <input name="guest_name" required value="<?= e($form['guest_name']) ?>"></label>
                <label>Email * <input type="email" name="email" required value="<?= e($form['email']) ?>"></label>
                <label>Phone / WhatsApp <input name="phone" value="<?= e($form['phone']) ?>"></label>
                <label>Guests * <input type="number" name="guests" min="1" required value="<?= e((string)$form['guests']) ?>"></label>
                <label>Check-in * <input type="date" name="check_in" required value="<?= e($form['check_in']) ?>"></label>
                <label>Check-out * <input type="date" name="check_out" required value="<?= e($form['check_out']) ?>"></label>
                <label>Total price (<?= e(setting('currency')) ?>)
                    <input type="number" name="total" min="0" step="0.01" value="<?= e((string)$form['total']) ?>" placeholder="Calculated from rates"></label>
                <?php if (!$booking): ?>
                    <label>Status
                        <select name="status">
                            <?php foreach (['pending_payment', 'confirmed'] as $s): ?>
                                <option value="<?= $s ?>" <?= $form['status'] === $s ? 'selected' : '' ?>><?= BOOKING_STATUSES[$s] ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                <?php else: ?>
                    <div></div>
                <?php endif; ?>
                <label class="check full"><input type="checkbox" name="recalc" value="1" <?= $form['recalc'] ? 'checked' : '' ?>>
                    Calculate the price from the current rates (standard + seasonal). Untick to use the total entered above.</label>
                <?php if (!$booking): ?>
                    <label class="check full"><input type="checkbox" name="notify" value="1" checked> Email the guest the payment details (pending bookings)</label>
                <?php endif; ?>
                <label class="full">Guest notes <textarea name="guest_notes" rows="3"><?= e($form['guest_notes']) ?></textarea></label>
                <label class="full">Internal notes (not shown to guest) <textarea name="admin_notes" rows="3"><?= e($form['admin_notes']) ?></textarea></label>
                <div class="full">
                    <button class="btn"><?= $booking ? 'Save changes' : 'Create booking' ?></button>
                </div>
            </form>
        </section>
    </div>

    <?php if ($booking): ?>
    <aside>
        <section class="panel">
            <h2>Status</h2>
            <dl class="meta">
                <dt>Status</dt><dd><?= status_badge($booking['status']) ?></dd>
                <dt>Booked on</dt><dd><?= e(date('d M Y, H:i', strtotime($booking['created_at']))) ?></dd>
                <?php if ($booking['confirmed_at']): ?><dt>Confirmed</dt><dd><?= e(date('d M Y, H:i', strtotime($booking['confirmed_at']))) ?></dd><?php endif; ?>
            </dl>

            <form method="post" class="stack status-form">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="status">
                <label>Change status to
                    <select name="status">
                        <?php foreach (BOOKING_STATUSES as $key => $label): ?>
                            <option value="<?= $key ?>" <?= $booking['status'] === $key ? 'selected' : '' ?>><?= $label ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label class="check"><input type="checkbox" name="notify" value="1"> Email the guest</label>
                <button class="btn btn-outline">Update status</button>
            </form>
        </section>

        <?php if ($booking['status'] === 'pending_payment'): ?>
        <section class="panel">
            <h2>Payment deadline</h2>
            <p class="muted">Unpaid bookings are cancelled automatically at this time. Extend it if the guest asks for more time.</p>
            <form method="post" class="stack">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="deadline">
                <label>Pay by
                    <input type="datetime-local" name="payment_due_at"
                           value="<?= $booking['payment_due_at'] ? e(date('Y-m-d\TH:i', strtotime($booking['payment_due_at']))) : '' ?>"></label>
                <small class="muted">Leave empty for no deadline.</small>
                <button class="btn btn-outline">Save deadline</button>
            </form>
        </section>
        <?php endif; ?>

        <section class="panel">
            <h2>Contact guest</h2>
            <p class="contact-links">
                <a href="mailto:<?= e($booking['email']) ?>"><?= e($booking['email']) ?></a>
                <?php if ($booking['phone']): $wa = preg_replace('/\D/', '', $booking['phone']); ?>
                    <a href="tel:<?= e($booking['phone']) ?>"><?= e($booking['phone']) ?></a>
                    <a href="https://wa.me/<?= e($wa) ?>" target="_blank" rel="noopener">WhatsApp</a>
                <?php endif; ?>
            </p>
        </section>

        <section class="panel danger-zone">
            <h2>Delete booking</h2>
            <p class="muted">Permanently removes the booking and its payment records. To keep a record, set it to Cancelled instead.</p>
            <form method="post" onsubmit="return confirm('Permanently delete booking <?= e($booking['reference']) ?>?');">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="delete">
                <button class="btn btn-danger">Delete booking</button>
            </form>
        </section>
    </aside>
    <?php endif; ?>
</div>
<?php admin_footer();
