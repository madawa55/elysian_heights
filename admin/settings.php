<?php
require __DIR__ . '/_init.php';
require_login();
require_post_csrf();

$errors = [];
$action = (string)($_POST['action'] ?? '');

if ($action === 'settings') {
    $values = [
        'currency'           => strtoupper(trim((string)($_POST['currency'] ?? ''))),
        'min_nights'         => trim((string)($_POST['min_nights'] ?? '')),
        'max_guests'         => trim((string)($_POST['max_guests'] ?? '')),
        'deposit_percent'    => trim((string)($_POST['deposit_percent'] ?? '')),
        'pending_hold_hours' => trim((string)($_POST['pending_hold_hours'] ?? '')),
        'bank_details'       => trim((string)($_POST['bank_details'] ?? '')),
        'payment_note'       => trim((string)($_POST['payment_note'] ?? '')),
        'invoice_footer'     => trim((string)($_POST['invoice_footer'] ?? '')),
    ];
    if (!ctype_digit($values['deposit_percent']) || (int)$values['deposit_percent'] > 100) $errors[] = 'Deposit must be a whole percentage from 0 to 100.';
    if (!ctype_digit($values['pending_hold_hours']) || (int)$values['pending_hold_hours'] > 24 * 30) $errors[] = 'Payment deadline must be 0–720 hours.';
    if (!preg_match('/^[A-Z$€£]{1,5}$/u', $values['currency'])) $errors[] = 'Currency should be a short code like LKR or USD.';
    if (!ctype_digit($values['min_nights']) || (int)$values['min_nights'] < 1) $errors[] = 'Minimum nights must be 1 or more.';
    if (!ctype_digit($values['max_guests']) || (int)$values['max_guests'] < 1) $errors[] = 'Maximum guests must be 1 or more.';
    if ($values['bank_details'] === '') $errors[] = 'Bank details are required — guests need them to pay.';

    if (!$errors) {
        foreach ($values as $key => $value) {
            save_setting($key, mb_substr($value, 0, 2000));
        }
        flash('Settings saved. New bookings will use these values; existing bookings keep their price.');
        redirect('settings.php');
    }
}

if ($action === 'password') {
    $stmt = db()->prepare('SELECT password_hash FROM admins WHERE id = ?');
    $stmt->execute([$_SESSION['admin_id']]);
    $new = (string)($_POST['new_password'] ?? '');

    if (!password_verify((string)($_POST['current_password'] ?? ''), (string)$stmt->fetchColumn())) {
        $errors[] = 'Current password is incorrect.';
    } elseif (strlen($new) < 10) {
        $errors[] = 'New password must be at least 10 characters.';
    } elseif ($new !== ($_POST['new_password_confirm'] ?? '')) {
        $errors[] = 'New passwords do not match.';
    } else {
        db()->prepare('UPDATE admins SET password_hash = ? WHERE id = ?')
            ->execute([password_hash($new, PASSWORD_DEFAULT), $_SESSION['admin_id']]);
        flash('Password changed.');
        redirect('settings.php');
    }
}

$s = $action === 'settings' ? array_merge(settings(), $values) : settings();

admin_header('Settings', 'settings.php');
?>
<h1>Settings</h1>
<?php foreach ($errors as $err): ?><div class="alert alert-error"><?= e($err) ?></div><?php endforeach; ?>

<div class="split">
    <section class="panel">
        <h2>Booking &amp; payment rules</h2>
        <p class="muted">Nightly prices are set on the <a href="rates.php">Rates</a> page.</p>
        <form method="post" class="form-grid">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="settings">
            <label>Currency <input name="currency" maxlength="5" required value="<?= e($s['currency']) ?>"></label>
            <label>Minimum nights <input type="number" name="min_nights" min="1" required value="<?= e($s['min_nights']) ?>"></label>
            <label>Maximum guests <input type="number" name="max_guests" min="1" required value="<?= e($s['max_guests']) ?>"></label>
            <label>Deposit to confirm (%)
                <input type="number" name="deposit_percent" min="0" max="100" step="1" required value="<?= e($s['deposit_percent']) ?>">
                <small class="muted">0 or 100 = full payment up front. E.g. 30 = guest pays 30% to confirm, balance before check-in.</small></label>
            <label>Payment deadline (hours)
                <input type="number" name="pending_hold_hours" min="0" max="720" step="1" required value="<?= e($s['pending_hold_hours']) ?>">
                <small class="muted">Unpaid bookings are cancelled and their dates released after this. 0 = never.</small></label>
            <label class="full">Bank transfer details (shown to guests after booking and on invoices)
                <textarea name="bank_details" rows="5" required><?= e($s['bank_details']) ?></textarea></label>
            <label class="full">Payment instructions
                <textarea name="payment_note" rows="3"><?= e($s['payment_note']) ?></textarea></label>
            <label class="full">Invoice footer
                <textarea name="invoice_footer" rows="2"><?= e($s['invoice_footer']) ?></textarea></label>
            <div class="full"><button class="btn">Save settings</button></div>
        </form>
    </section>

    <aside>
        <section class="panel">
            <h2>Change password</h2>
            <form method="post" class="stack">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="password">
                <label>Current password <input type="password" name="current_password" required autocomplete="current-password"></label>
                <label>New password (min 10) <input type="password" name="new_password" required minlength="10" autocomplete="new-password"></label>
                <label>Confirm new password <input type="password" name="new_password_confirm" required minlength="10" autocomplete="new-password"></label>
                <button class="btn">Change password</button>
            </form>
        </section>
    </aside>
</div>
<?php admin_footer();
