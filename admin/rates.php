<?php
/**
 * Pricing: the standard nightly rate plus seasonal rates for date ranges (e.g. December, Avurudu).
 * A seasonal rate applies to every night that falls inside its range; seasons cannot overlap.
 */
require __DIR__ . '/_init.php';
require_login();
require_post_csrf();

$pdo = db();
$errors = [];
$action = (string)($_POST['action'] ?? '');
$editId = (int)($_GET['edit'] ?? 0);

if ($action === 'base_rate') {
    $rate = trim((string)($_POST['nightly_rate'] ?? ''));
    if (!is_numeric($rate) || (float)$rate < 0) {
        $errors[] = 'Standard rate must be a number.';
    } else {
        save_setting('nightly_rate', (string)round((float)$rate, 2));
        flash('Standard nightly rate saved. Existing bookings keep their price.');
        redirect('rates.php');
    }
}

$season = ['id' => 0, 'name' => '', 'start_date' => '', 'end_date' => '', 'nightly_rate' => ''];
if ($editId) {
    $stmt = $pdo->prepare('SELECT * FROM rate_seasons WHERE id = ?');
    $stmt->execute([$editId]);
    $season = $stmt->fetch() ?: $season;
}

if ($action === 'season') {
    $season = [
        'id'           => (int)($_POST['id'] ?? 0),
        'name'         => mb_substr(trim((string)($_POST['name'] ?? '')), 0, 100),
        'start_date'   => (string)($_POST['start_date'] ?? ''),
        'end_date'     => (string)($_POST['end_date'] ?? ''),
        'nightly_rate' => trim((string)($_POST['nightly_rate'] ?? '')),
    ];
    if ($season['name'] === '') $errors[] = 'Give the season a name, e.g. "December peak".';
    if (!is_numeric($season['nightly_rate']) || (float)$season['nightly_rate'] < 0) $errors[] = 'Nightly rate must be a number.';
    if (!valid_date($season['start_date']) || !valid_date($season['end_date'])) {
        $errors[] = 'Choose the first and last night of the season.';
    } elseif ($season['end_date'] < $season['start_date']) {
        $errors[] = 'The last night must be on or after the first night.';
    } else {
        $stmt = $pdo->prepare('SELECT name, start_date, end_date FROM rate_seasons WHERE start_date <= ? AND end_date >= ? AND id <> ?');
        $stmt->execute([$season['end_date'], $season['start_date'], $season['id']]);
        foreach ($stmt as $o) {
            $errors[] = 'Overlaps "' . $o['name'] . '" (' . date('d M Y', strtotime($o['start_date'])) . ' – '
                . date('d M Y', strtotime($o['end_date'])) . '). Seasons cannot overlap.';
        }
    }

    if (!$errors) {
        $values = [$season['name'], $season['start_date'], $season['end_date'], round((float)$season['nightly_rate'], 2)];
        if ($season['id']) {
            $pdo->prepare('UPDATE rate_seasons SET name = ?, start_date = ?, end_date = ?, nightly_rate = ? WHERE id = ?')
                ->execute([...$values, $season['id']]);
            flash('Season updated.');
        } else {
            $pdo->prepare('INSERT INTO rate_seasons (name, start_date, end_date, nightly_rate) VALUES (?, ?, ?, ?)')->execute($values);
            flash('Season added.');
        }
        redirect('rates.php');
    }
}

if ($action === 'delete_season') {
    $pdo->prepare('DELETE FROM rate_seasons WHERE id = ?')->execute([(int)($_POST['id'] ?? 0)]);
    flash('Season removed.');
    redirect('rates.php');
}

$seasons = $pdo->query('SELECT * FROM rate_seasons ORDER BY start_date')->fetchAll();
$today = date('Y-m-d');
$cur = setting('currency');

admin_header('Rates', 'rates.php');
?>
<h1>Rates</h1>
<p class="muted">Guests are charged the standard rate for every night, except nights that fall inside a seasonal rate below.
    Changes apply to new bookings only; existing bookings keep the price they were booked at.</p>

<?php foreach ($errors as $err): ?><div class="alert alert-error"><?= e($err) ?></div><?php endforeach; ?>

<div class="split">
    <section class="panel">
        <h2>Seasonal rates</h2>
        <?php if (!$seasons): ?>
            <p class="muted">No seasonal rates yet — every night uses the standard rate.</p>
        <?php else: ?>
            <div class="table-wrap">
                <table class="table">
                    <thead><tr><th>Season</th><th>First night</th><th>Last night</th><th>Rate / night</th><th></th></tr></thead>
                    <tbody>
                    <?php foreach ($seasons as $s): $past = $s['end_date'] < $today; ?>
                        <tr class="<?= $past ? 'is-past' : '' ?>">
                            <td data-label="Season"><strong><?= e($s['name']) ?></strong><?= $past ? ' <small class="muted">(past)</small>' : '' ?></td>
                            <td data-label="First night"><?= e(date('D, d M Y', strtotime($s['start_date']))) ?></td>
                            <td data-label="Last night"><?= e(date('D, d M Y', strtotime($s['end_date']))) ?></td>
                            <td data-label="Rate / night"><?= e(money((float)$s['nightly_rate'])) ?></td>
                            <td class="actions">
                                <a class="btn btn-sm btn-outline" href="?edit=<?= (int)$s['id'] ?>">Edit</a>
                                <form method="post" style="display:inline" onsubmit="return confirm('Remove this seasonal rate?');">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="delete_season">
                                    <input type="hidden" name="id" value="<?= (int)$s['id'] ?>">
                                    <button class="btn btn-sm btn-danger">Remove</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>

    <aside>
        <section class="panel">
            <h2>Standard nightly rate</h2>
            <form method="post" class="stack">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="base_rate">
                <label>Whole villa, per night (<?= e($cur) ?>)
                    <input type="number" name="nightly_rate" min="0" step="0.01" required value="<?= e(setting('nightly_rate')) ?>"></label>
                <button class="btn">Save rate</button>
            </form>
        </section>

        <section class="panel" id="season-form">
            <h2><?= $season['id'] ? 'Edit season' : 'Add a seasonal rate' ?></h2>
            <form method="post" class="stack" action="rates.php<?= $season['id'] ? '?edit=' . (int)$season['id'] : '' ?>">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="season">
                <input type="hidden" name="id" value="<?= (int)$season['id'] ?>">
                <label>Name <input name="name" maxlength="100" required value="<?= e($season['name']) ?>" placeholder="e.g. December peak"></label>
                <label>First night <input type="date" name="start_date" required value="<?= e($season['start_date']) ?>"></label>
                <label>Last night (inclusive) <input type="date" name="end_date" required value="<?= e($season['end_date']) ?>"></label>
                <small class="muted">A stay checking out on the day after the last night is fully inside the season.</small>
                <label>Rate per night (<?= e($cur) ?>) <input type="number" name="nightly_rate" min="0" step="0.01" required value="<?= e((string)$season['nightly_rate']) ?>"></label>
                <div class="btn-row">
                    <button class="btn"><?= $season['id'] ? 'Save season' : 'Add season' ?></button>
                    <?php if ($season['id']): ?><a class="btn btn-outline" href="rates.php">Cancel</a><?php endif; ?>
                </div>
            </form>
        </section>
    </aside>
</div>
<?php admin_footer();
