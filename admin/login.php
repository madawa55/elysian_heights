<?php
require __DIR__ . '/_init.php';

if (is_logged_in()) {
    redirect('index.php');
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_post_csrf();

    // Slow down brute force: 5 failures from one IP locks it out for 15 minutes.
    if ($wait = login_locked_for()) {
        $error = 'Too many failed attempts. Please try again in ' . ceil($wait / 60) . ' minute(s).';
    } else {
        $stmt = db()->prepare('SELECT id, username, password_hash FROM admins WHERE username = ?');
        $stmt->execute([trim((string)($_POST['username'] ?? ''))]);
        $admin = $stmt->fetch();

        if ($admin && password_verify((string)($_POST['password'] ?? ''), $admin['password_hash'])) {
            login_record(true);
            session_regenerate_id(true);
            $_SESSION['admin_id'] = (int)$admin['id'];
            $_SESSION['admin_name'] = $admin['username'];
            $_SESSION['admin_seen'] = time();
            redirect('index.php');
        }

        login_record(false);
        sleep(1);
        $error = 'Incorrect username or password.';
    }
}

admin_header('Log in');
?>
<div class="auth-box">
    <h1><?= e($config['site_name']) ?> Admin</h1>
    <?php if (isset($_GET['expired'])): ?><div class="alert alert-info">Your session expired. Please log in again.</div><?php endif; ?>
    <?php if ($error): ?><div class="alert alert-error"><?= e($error) ?></div><?php endif; ?>
    <form method="post" class="stack">
        <?= csrf_field() ?>
        <label>Username <input name="username" required autofocus autocomplete="username"></label>
        <label>Password <input type="password" name="password" required autocomplete="current-password"></label>
        <button class="btn">Log in</button>
    </form>
</div>
<?php admin_footer();
