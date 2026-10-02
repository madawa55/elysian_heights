<?php
/**
 * Printable invoice. Use the browser's Print → "Save as PDF" to make a PDF.
 */
require __DIR__ . '/_init.php';
require_login();

$stmt = db()->prepare('SELECT * FROM bookings WHERE id = ?');
$stmt->execute([(int)($_GET['id'] ?? 0)]);
$b = $stmt->fetch();
if (!$b) {
    http_response_code(404);
    exit('Booking not found.');
}

$stmt = db()->prepare('SELECT * FROM payments WHERE booking_id = ? ORDER BY paid_on, id');
$stmt->execute([$b['id']]);
$payments = $stmt->fetchAll();

$total = (float)$b['total'];
$paid = (float)$b['amount_paid'];
$deposit = (float)$b['deposit_amount'];
$cancelled = $b['status'] === 'cancelled';
$balance = $cancelled ? 0 : max(0, $total - $paid);
$lines = json_decode((string)$b['price_breakdown'], true) ?: [[
    'from' => $b['check_in'], 'to' => $b['check_out'], 'nights' => (int)$b['nights'],
    'rate' => (float)$b['nightly_rate'], 'label' => '',
]];

if ($cancelled) {
    [$stampClass, $stampText] = ['cancelled', 'CANCELLED'];
} elseif ($paid >= $total) {
    [$stampClass, $stampText] = ['paid', 'PAID IN FULL'];
} elseif ($paid > 0) {
    [$stampClass, $stampText] = ['partial', 'DEPOSIT PAID'];
} else {
    [$stampClass, $stampText] = ['due', 'PAYMENT DUE'];
}
$invoiceNo = 'INV-' . $b['reference'];
$issued = date('d M Y', strtotime($b['created_at']));
$fmt = fn(string $d) => date('D, d M Y', strtotime($d));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title><?= e($invoiceNo) ?> · <?= e($config['site_name']) ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:wght@600&family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/invoice.css">
</head>
<body>
<div class="toolbar">
    <a href="booking.php?id=<?= (int)$b['id'] ?>">← Back to booking</a>
    <button type="button" onclick="window.print()">Print / Save as PDF</button>
</div>

<article class="invoice">
    <header class="inv-head">
        <div>
            <h1 class="brand"><?= e($config['site_name']) ?></h1>
            <p><?= implode('<br>', array_map('e', $config['address_lines'])) ?></p>
            <p><?= e($config['phones'][0]['display']) ?> · <?= e($config['email']) ?></p>
        </div>
        <div class="inv-title">
            <h2>INVOICE</h2>
            <table class="kv">
                <tr><th>Invoice no.</th><td><?= e($invoiceNo) ?></td></tr>
                <tr><th>Booking ref.</th><td><?= e($b['reference']) ?></td></tr>
                <tr><th>Issue date</th><td><?= e($issued) ?></td></tr>
                <?php if ($b['status'] === 'pending_payment' && $b['payment_due_at']): ?>
                    <tr><th>Pay by</th><td><?= e(date('d M Y, g:i A', strtotime($b['payment_due_at']))) ?></td></tr>
                <?php endif; ?>
            </table>
            <span class="stamp stamp-<?= $stampClass ?>"><?= $stampText ?></span>
        </div>
    </header>

    <section class="parties">
        <div>
            <h3>Billed to</h3>
            <p><strong><?= e($b['guest_name']) ?></strong><br>
                <?= e($b['email']) ?><?= $b['phone'] ? '<br>' . e($b['phone']) : '' ?></p>
        </div>
        <div>
            <h3>Stay</h3>
            <p>Check-in: <strong><?= e($fmt($b['check_in'])) ?></strong><br>
                Check-out: <strong><?= e($fmt($b['check_out'])) ?></strong><br>
                Guests: <?= (int)$b['guests'] ?></p>
        </div>
    </section>

    <table class="items">
        <thead>
        <tr><th>Description</th><th class="num">Nights</th><th class="num">Rate / night</th><th class="num">Amount</th></tr>
        </thead>
        <tbody>
        <?php foreach ($lines as $l): ?>
        <tr>
            <td>Villa accommodation — <?= e($config['site_name']) ?><?= !empty($l['label']) && $l['label'] !== 'Standard rate' ? ' · ' . e($l['label']) : '' ?><br>
                <small><?= e(date('d M', strtotime($l['from']))) ?> – <?= e(date('d M Y', strtotime($l['to']))) ?></small></td>
            <td class="num"><?= (int)$l['nights'] ?></td>
            <td class="num"><?= e(money((float)$l['rate'])) ?></td>
            <td class="num"><?= e(money($l['rate'] * $l['nights'])) ?></td>
        </tr>
        <?php endforeach; ?>
        <?php if (abs(array_sum(array_map(fn($l) => $l['rate'] * $l['nights'], $lines)) - $total) >= 0.01): ?>
        <tr>
            <td>Adjustment</td><td></td><td></td>
            <td class="num"><?= e(money($total - array_sum(array_map(fn($l) => $l['rate'] * $l['nights'], $lines)))) ?></td>
        </tr>
        <?php endif; ?>
        </tbody>
        <tfoot>
        <tr><th colspan="3">Total</th><td class="num"><?= e(money($total)) ?></td></tr>
        <?php foreach ($payments as $p): ?>
            <tr><th colspan="3">Paid <?= e(date('d M Y', strtotime($p['paid_on']))) ?><?= $p['reference'] ? ' · Ref ' . e($p['reference']) : '' ?></th>
                <td class="num">− <?= e(money((float)$p['amount'])) ?></td></tr>
        <?php endforeach; ?>
        <tr class="due"><th colspan="3">Balance due</th><td class="num"><?= e(money($balance)) ?></td></tr>
        </tfoot>
    </table>

    <?php if ($balance > 0): ?>
        <section class="payment">
            <h3>Payment — direct bank transfer</h3>
            <?php if ($b['status'] === 'pending_payment' && $deposit < $total && $paid < $deposit): ?>
                <p class="pay-now">Deposit to confirm your booking: <strong><?= e(money($deposit - $paid)) ?></strong>
                    <?= $b['payment_due_at'] ? ' by ' . e(date('d M Y, g:i A', strtotime($b['payment_due_at']))) : '' ?><br>
                    Remaining balance of <?= e(money($total - $deposit)) ?> payable before check-in.</p>
            <?php elseif ($b['status'] === 'pending_payment'): ?>
                <p class="pay-now">Amount due: <strong><?= e(money($balance)) ?></strong>
                    <?= $b['payment_due_at'] ? ' by ' . e(date('d M Y, g:i A', strtotime($b['payment_due_at']))) : '' ?></p>
            <?php else: ?>
                <p class="pay-now">Balance due before check-in: <strong><?= e(money($balance)) ?></strong></p>
            <?php endif; ?>
            <pre><?= e(setting('bank_details')) ?></pre>
            <p>Please use <strong><?= e($b['reference']) ?></strong> as the payment reference.</p>
            <p><?= e(setting('payment_note')) ?></p>
        </section>
    <?php endif; ?>

    <footer class="inv-foot"><?= nl2br(e(setting('invoice_footer'))) ?></footer>
</article>
</body>
</html>
