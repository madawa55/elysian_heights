<?php
/**
 * One-time installer: creates the tables, seeds the gallery with the existing
 * villa photos and creates the first admin account.
 * Locks itself once an admin exists.
 */
require __DIR__ . '/_init.php';

$errors = [];
$dbError = null;

try {
    $pdo = db();
} catch (PDOException $e) {
    // Database may not exist yet (XAMPP): try to create it.
    try {
        $c = $config['db'];
        $server = new PDO("mysql:host={$c['host']};port={$c['port']};charset=utf8mb4", $c['user'], $c['pass'],
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $server->exec('CREATE DATABASE IF NOT EXISTS `' . str_replace('`', '', $c['name']) . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
        $pdo = db();
    } catch (PDOException $e2) {
        $dbError = $e2->getMessage();
    }
}

if (!$dbError) {
    $schema = preg_replace('/^--.*$/m', '', file_get_contents(dirname(__DIR__) . '/database/schema.sql'));
    foreach (array_filter(array_map('trim', explode(';', $schema))) as $statement) {
        $pdo->exec($statement);
    }
    $hasAdmin = (int)$pdo->query('SELECT COUNT(*) FROM admins')->fetchColumn() > 0;

    if ($hasAdmin) {
        flash('Setup is already complete. Please log in.', 'info');
        redirect('login.php');
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        require_post_csrf();
        $username = trim((string)($_POST['username'] ?? ''));
        $password = (string)($_POST['password'] ?? '');

        if (!preg_match('/^[A-Za-z0-9_.-]{3,60}$/', $username)) {
            $errors[] = 'Username must be 3–60 letters, numbers, dots, dashes or underscores.';
        }
        if (strlen($password) < 10) {
            $errors[] = 'Password must be at least 10 characters.';
        }
        if ($password !== ($_POST['password_confirm'] ?? '')) {
            $errors[] = 'Passwords do not match.';
        }

        if (!$errors) {
            $pdo->prepare('INSERT INTO admins (username, password_hash) VALUES (?, ?)')
                ->execute([$username, password_hash($password, PASSWORD_DEFAULT)]);

            // Seed the gallery with the photos already on the site.
            if ((int)$pdo->query('SELECT COUNT(*) FROM gallery_images')->fetchColumn() === 0) {
                $galleryDir = dirname(__DIR__) . '/' . GALLERY_DIR;
                if (!is_dir($galleryDir)) {
                    mkdir($galleryDir, 0755, true);
                }
                $seed = $pdo->prepare('INSERT INTO gallery_images (filename, caption, sort_order) VALUES (?, ?, ?)');
                $order = 0;
                foreach (['villa.png' => 'The villa', 'villa2.png' => 'Gardens and balconies'] as $file => $caption) {
                    $src = dirname(__DIR__) . '/assets/images/' . $file;
                    if (is_file($src) && copy($src, $galleryDir . '/' . $file)) {
                        $seed->execute([$file, $caption, ++$order]);
                    }
                }
            }

            flash('Setup complete. Log in with your new admin account.');
            redirect('login.php');
        }
    }
}

admin_header('Setup');
?>
<div class="auth-box">
    <h1>Set up booking system</h1>
    <?php if ($dbError): ?>
        <div class="alert alert-error">
            Could not connect to the database. Check the <code>db</code> settings in <code>config.php</code>
            and make sure MySQL is running.<br><small><?= e($dbError) ?></small>
        </div>
    <?php else: ?>
        <p class="muted">Database tables are ready. Create the first admin account.</p>
        <?php foreach ($errors as $err): ?><div class="alert alert-error"><?= e($err) ?></div><?php endforeach; ?>
        <form method="post" class="stack">
            <?= csrf_field() ?>
            <label>Username <input name="username" required value="<?= e($_POST['username'] ?? '') ?>" autocomplete="username"></label>
            <label>Password (min 10 characters) <input type="password" name="password" required minlength="10" autocomplete="new-password"></label>
            <label>Confirm password <input type="password" name="password_confirm" required minlength="10" autocomplete="new-password"></label>
            <button class="btn">Create admin account</button>
        </form>
    <?php endif; ?>
</div>
<?php admin_footer();
