<?php
require __DIR__ . '/_init.php';
require_login();

$status = (string)($_GET['status'] ?? '');
$q = trim((string)($_GET['q'] ?? ''));
$when = (string)($_GET['when'] ?? '');
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 25;

$where = [];
$params = [];
if (isset(BOOKING_STATUSES[$status])) {
    $where[] = 'status = ?';
    $params[] = $status;
}
if ($q !== '') {
    $where[] = '(reference LIKE ? OR guest_name LIKE ? OR email LIKE ? OR phone LIKE ?)';
    array_push($params, ...array_fill(0, 4, '%' . $q . '%'));
}
if ($when === 'upcoming') {
    $where[] = 'check_out >= CURDATE()';
} elseif ($when === 'past') {
    $where[] = 'check_out < CURDATE()';
}
$sqlWhere = $where ? 'WHERE ' . implode(' AND ', $where) : '';
$order = $when === 'upcoming' ? 'check_in ASC' : 'created_at DESC';

$stmt = db()->prepare("SELECT COUNT(*) FROM bookings $sqlWhere");
$stmt->execute($params);
$total = (int)$stmt->fetchColumn();
$pages = max(1, (int)ceil($total / $perPage));
$offset = ($page - 1) * $perPage;

$stmt = db()->prepare("SELECT * FROM bookings $sqlWhere ORDER BY $order LIMIT $perPage OFFSET $offset");
$stmt->execute($params);
$rows = $stmt->fetchAll();

$query = fn(array $over) => '?' . http_build_query(array_merge(['status' => $status, 'q' => $q, 'when' => $when], $over));

admin_header('Bookings', 'bookings.php');
?>
<div class="page-head">
    <h1>Bookings</h1>
    <a class="btn" href="booking.php">+ New booking</a>
</div>

<form class="filters" method="get">
    <input type="search" name="q" value="<?= e($q) ?>" placeholder="Search reference, name, email, phone">
    <select name="status">
        <option value="">All statuses</option>
        <?php foreach (BOOKING_STATUSES as $key => $label): ?>
            <option value="<?= $key ?>" <?= $status === $key ? 'selected' : '' ?>><?= $label ?></option>
        <?php endforeach; ?>
    </select>
    <select name="when">
        <option value="">Any dates</option>
        <option value="upcoming" <?= $when === 'upcoming' ? 'selected' : '' ?>>Upcoming / current</option>
        <option value="past" <?= $when === 'past' ? 'selected' : '' ?>>Past</option>
    </select>
    <button class="btn btn-outline">Filter</button>
</form>

<section class="panel">
    <p class="muted"><?= $total ?> booking<?= $total === 1 ? '' : 's' ?></p>
    <?php if ($rows): ?>
        <?php booking_table($rows); ?>
    <?php else: ?>
        <p class="muted">No bookings found.</p>
    <?php endif; ?>

    <?php if ($pages > 1): ?>
        <nav class="pager">
            <?php for ($p = 1; $p <= $pages; $p++): ?>
                <a href="<?= e($query(['page' => $p])) ?>" class="<?= $p === $page ? 'active' : '' ?>"><?= $p ?></a>
            <?php endfor; ?>
        </nav>
    <?php endif; ?>
</section>
<?php admin_footer();
