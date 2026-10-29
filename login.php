<?php
declare(strict_types=1);

require_once __DIR__ . '/config/app.php';

if (current_user()) {
    redirect('dashboard.php');
}

$error = '';
$accountDeleted = !empty($_SESSION['account_deleted']);
unset($_SESSION['account_deleted']);
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $email = trim((string) ($_POST['email'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');
    $throttleKey = login_throttle_key($email);

    try {
        $throttle = db()->prepare('SELECT attempts, locked_until FROM login_attempts WHERE throttle_key = ? LIMIT 1');
        $throttle->execute([$throttleKey]);
        $attempt = $throttle->fetch();
        $locked = $attempt && $attempt['locked_until'] !== null && strtotime($attempt['locked_until']) > time();
        if ($attempt && !$locked && $attempt['locked_until'] !== null) {
            // Start a fresh window after a lock expires so the counter cannot grow indefinitely.
            $attempt['attempts'] = 0;
            db()->prepare('DELETE FROM login_attempts WHERE throttle_key = ?')->execute([$throttleKey]);
        }
        $statement = db()->prepare('SELECT id, member_id, full_name, email, password_hash, role, branch_id, auth_version FROM users WHERE email = ? AND active = 1 LIMIT 1');
        $statement->execute([$email]);
        $user = $statement->fetch();
    } catch (PDOException $exception) {
        if (!is_missing_database_table($exception)) {
            throw $exception;
        }

        $error = 'Database tables are missing or unavailable. Restore them from a backup or repair the MySQL database, then refresh this page.';
        $user = false;
    }

    if ($error === '' && !$locked && $user && password_verify($password, $user['password_hash'])) {
        db()->prepare('DELETE FROM login_attempts WHERE throttle_key = ?')->execute([$throttleKey]);
        session_regenerate_id(true);
        $_SESSION['created_at'] = time();
        $_SESSION['last_activity'] = time();
        $_SESSION['regenerated_at'] = time();
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        unset($user['password_hash']);
        $_SESSION['user'] = $user;
        redirect('dashboard.php');
    }

    if ($error === '') {
        if (!$locked) {
            $count = min(5, (int) ($attempt['attempts'] ?? 0) + 1);
            $lockedUntil = $count >= 5 ? date('Y-m-d H:i:s', time() + 900) : null;
            db()->prepare('INSERT INTO login_attempts (throttle_key, attempts, locked_until, updated_at) VALUES (?, ?, ?, NOW()) ON DUPLICATE KEY UPDATE attempts = VALUES(attempts), locked_until = VALUES(locked_until), updated_at = NOW()')
                ->execute([$throttleKey, $count, $lockedUntil]);
        }
        $error = 'The email or password is incorrect.';
    }
}
?>
<!doctype html>
<html lang="<?= e(current_language()) ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e(t('Sign in')) ?> · <?= e(t('Anglican Church Management')) ?></title>
    <link rel="stylesheet" href="assets/css/app.css">
</head>
<body class="auth-page">
<main class="auth-card">
    <div class="brand-mark">AC</div>
    <p class="eyebrow"><?= e(t('Anglican Church Management')) ?></p>
    <h1><?= e(t('Welcome back')) ?></h1>
    <p class="muted"><?= e(t('Sign in to manage your church community.')) ?></p>
    <?php if (isset($_GET['setup'])): ?><div class="notice success"><?= e(t('Your Bishop account is ready. Sign in to continue.')) ?></div><?php endif; ?>
    <?php if (isset($_GET['reset'])): ?><div class="notice success"><?= e(t('Your password was reset. Sign in with the new password.')) ?></div><?php endif; ?>
    <?php if ($accountDeleted): ?><div class="notice success"><?= e(t('Your account has been deleted and your personal account details anonymized.')) ?></div><?php endif; ?>
    <?php if (isset($_GET['expired'])): ?><div class="notice error"><?= e(t('Your account is inactive or your session has expired. Please sign in again.')) ?></div><?php endif; ?>
    <?php if ($error !== ''): ?><div class="notice error"><?= e(t($error)) ?></div><?php endif; ?>
    <form method="post">
        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
        <label><?= e(t('Email')) ?><input type="email" name="email" required autocomplete="username"></label>
        <label><?= e(t('Password')) ?><input type="password" name="password" required autocomplete="current-password"></label>
        <button class="button primary full-width" type="submit"><?= e(t('Sign in')) ?></button>
    </form>
    <p class="auth-foot"><a href="forgot_password.php"><?= e(t('Forgot your password?')) ?></a></p>
    <p class="auth-foot"><a href="setup.php"><?= e(t('First time? Set up the system')) ?></a></p>
    <form method="post" action="language.php" class="auth-language">
        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="return_to" value="<?= e($_SERVER['REQUEST_URI'] ?? '/login.php') ?>">
        <label for="language-select"><?= e(t('Language')) ?></label>
        <select id="language-select" name="language" onchange="this.form.submit()"><?php foreach (available_languages() as $code => $label): ?><option value="<?= e($code) ?>" <?= current_language() === $code ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?></select>
        <noscript><button class="language-submit" type="submit"><?= e(t('Apply')) ?></button></noscript>
    </form>
</main>
</body>
</html>
