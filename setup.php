<?php
declare(strict_types=1);

require_once __DIR__ . '/config/app.php';

if (!in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1', '::ffff:127.0.0.1'], true)) {
    http_response_code(403);
    exit(t('Initial setup is available only from the local machine.'));
}

$error = '';
$ready = false;
try {
    $ready = (int) db()->query('SELECT COUNT(*) FROM users')->fetchColumn() === 0;
} catch (PDOException $exception) {
    $error = is_missing_database_table($exception)
        ? 'Database tables are missing or unavailable. Restore them from a backup or repair the MySQL database, then refresh this page.'
        : 'Could not connect to the database. Check that MySQL is running and the database settings are correct.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $error === '') {
    verify_csrf();
    $name = trim((string) ($_POST['full_name'] ?? ''));
    $email = trim((string) ($_POST['email'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');
    $confirm = (string) ($_POST['password_confirm'] ?? '');

    if (!$ready) {
        $error = 'Initial setup has already been completed. Sign in instead.';
    } elseif ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($password) < 12) {
        $error = 'Enter a name, a valid email, and a password of at least 12 characters.';
    } elseif ($password !== $confirm) {
        $error = 'The passwords do not match.';
    } else {
        $statement = db()->prepare("INSERT INTO users (full_name, email, password_hash, role) VALUES (?, ?, ?, 'bishop')");
        $statement->execute([$name, $email, password_hash($password, PASSWORD_DEFAULT)]);
        redirect('login.php?setup=complete');
    }
}
?>
<!doctype html>
<html lang="<?= e(current_language()) ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e(t('Initial setup')) ?> · <?= e(t('Anglican Church Management')) ?></title>
    <link rel="stylesheet" href="assets/css/app.css">
</head>
<body class="auth-page">
<main class="auth-card">
    <div class="brand-mark">AC</div>
    <p class="eyebrow"><?= e(t('Anglican Church Management')) ?></p>
    <h1><?= e(t('Initial setup')) ?></h1>
    <?php if ($error !== ''): ?><div class="notice error"><?= e(t($error)) ?></div><?php endif; ?>
    <?php if ($ready && $error === ''): ?>
        <p class="muted"><?= e(t('Create the first account. It will have Bishop access.')) ?></p>
        <form method="post">
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
            <label><?= e(t('Full name')) ?><input name="full_name" required autocomplete="name"></label>
            <label><?= e(t('Email')) ?><input type="email" name="email" required autocomplete="email"></label>
            <label><?= e(t('Password')) ?> <span class="muted"><?= e(t('(at least 12 characters)')) ?></span><input type="password" name="password" minlength="12" required autocomplete="new-password"></label>
            <label><?= e(t('Confirm password')) ?><input type="password" name="password_confirm" minlength="12" required autocomplete="new-password"></label>
            <button class="button primary full-width" type="submit"><?= e(t('Create Bishop account')) ?></button>
        </form>
    <?php elseif ($error === ''): ?>
        <p class="muted"><?= e(t('Initial setup is complete. Please sign in.')) ?></p>
        <a class="button primary full-width" href="login.php"><?= e(t('Go to sign in')) ?></a>
    <?php endif; ?>
    <p class="auth-foot"><a href="login.php"><?= e(t('Back to sign in')) ?></a></p>
    <form method="post" action="language.php" class="auth-language">
        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="return_to" value="<?= e($_SERVER['REQUEST_URI'] ?? '/setup.php') ?>">
        <label for="language-select"><?= e(t('Language')) ?></label>
        <select id="language-select" name="language" onchange="this.form.submit()"><?php foreach (available_languages() as $code => $label): ?><option value="<?= e($code) ?>" <?= current_language() === $code ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?></select>
        <noscript><button class="language-submit" type="submit"><?= e(t('Apply')) ?></button></noscript>
    </form>
</main>
</body>
</html>
