<?php
declare(strict_types=1);

require_once __DIR__ . '/config/app.php';
$questions = recovery_questions();
$error = '';
$genericError = 'We could not verify those details, or recovery is temporarily locked. Check them and try again.';
$questionKey = (string) ($_POST['question_key'] ?? '');
$email = trim((string) ($_POST['email'] ?? ''));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $answer = trim((string) ($_POST['answer'] ?? ''));
    $newPassword = (string) ($_POST['new_password'] ?? '');
    $confirmPassword = (string) ($_POST['password_confirm'] ?? '');
    $throttleKey = hash('sha256', "recovery\0" . strtolower($email) . "\0" . (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown'));

    if (!isset($questions[$questionKey]) || $answer === '' || strlen($answer) > 256
        || strlen($newPassword) < 12 || strlen($newPassword) > 72 || $newPassword !== $confirmPassword) {
        $error = t($genericError);
    } else {
        $throttle = db()->prepare('SELECT attempts, locked_until FROM login_attempts WHERE throttle_key = ? LIMIT 1');
        $throttle->execute([$throttleKey]);
        $attempt = $throttle->fetch();
        $locked = $attempt && $attempt['locked_until'] !== null && strtotime($attempt['locked_until']) > time();
        if ($attempt && !$locked && $attempt['locked_until'] !== null) {
            db()->prepare('DELETE FROM login_attempts WHERE throttle_key = ?')->execute([$throttleKey]);
            $attempt['attempts'] = 0;
        }

        $lookup = db()->prepare('SELECT u.id, r.question_key, r.answer_hash FROM users u JOIN user_recovery r ON r.user_id = u.id WHERE u.email = ? AND u.active = 1 LIMIT 1');
        $lookup->execute([$email]);
        $recovery = $lookup->fetch();
        static $dummyHash = null;
        $dummyHash ??= password_hash('recovery placeholder answer', PASSWORD_DEFAULT);
        $answerMatches = password_verify(normalize_recovery_answer($answer), $recovery['answer_hash'] ?? $dummyHash);
        $questionMatches = $recovery && hash_equals((string) $recovery['question_key'], $questionKey);

        if (!$locked && $recovery && $questionMatches && $answerMatches) {
            db()->beginTransaction();
            try {
                $update = db()->prepare('UPDATE users SET password_hash = ?, auth_version = auth_version + 1 WHERE id = ? AND active = 1');
                $update->execute([password_hash($newPassword, PASSWORD_DEFAULT), (int) $recovery['id']]);
                db()->prepare('DELETE FROM login_attempts WHERE throttle_key = ?')->execute([$throttleKey]);
                db()->commit();
                redirect('login.php?reset=1');
            } catch (Throwable $exception) {
                if (db()->inTransaction()) {
                    db()->rollBack();
                }
                throw $exception;
            }
        }

        if (!$locked) {
            $count = min(5, (int) ($attempt['attempts'] ?? 0) + 1);
            $lockedUntil = $count >= 5 ? date('Y-m-d H:i:s', time() + 900) : null;
            db()->prepare('INSERT INTO login_attempts (throttle_key, attempts, locked_until, updated_at) VALUES (?, ?, ?, NOW()) ON DUPLICATE KEY UPDATE attempts = VALUES(attempts), locked_until = VALUES(locked_until), updated_at = NOW()')
                ->execute([$throttleKey, $count, $lockedUntil]);
        }
        $error = t($genericError);
    }
}
?>
<!doctype html>
<html lang="<?= e(current_language()) ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e(t('Reset password')) ?> · <?= e(t('Anglican Church Management')) ?></title>
    <link rel="stylesheet" href="assets/css/app.css">
</head>
<body class="auth-page">
<main class="auth-card">
    <div class="brand-mark">AC</div>
    <p class="eyebrow"><?= e(t('Account recovery')) ?></p>
    <h1><?= e(t('Reset password')) ?></h1>
    <p class="muted"><?= e(t('Enter the recovery question and answer you saved in your profile.')) ?></p>
    <?php if ($error !== ''): ?><div class="notice error"><?= e($error) ?></div><?php endif; ?>
    <form method="post">
        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
        <label><?= e(t('Email')) ?><input type="email" name="email" required autocomplete="username" value="<?= e($email) ?>"></label>
        <label><?= e(t('Your recovery question')) ?><select name="question_key" required><option value=""><?= e(t('Select the question you saved')) ?></option><?php foreach ($questions as $key => $question): ?><option value="<?= e($key) ?>" <?= $questionKey === $key ? 'selected' : '' ?>><?= e(t($question)) ?></option><?php endforeach; ?></select></label>
        <label><?= e(t('Answer')) ?><input type="text" name="answer" required autocomplete="off" maxlength="256"></label>
        <label><?= e(t('New password (12–72 characters)')) ?><input type="password" name="new_password" required minlength="12" maxlength="72" autocomplete="new-password"></label>
        <label><?= e(t('Confirm new password')) ?><input type="password" name="password_confirm" required minlength="12" maxlength="72" autocomplete="new-password"></label>
        <button class="button primary full-width" type="submit"><?= e(t('Reset password')) ?></button>
    </form>
    <p class="auth-foot"><a href="login.php"><?= e(t('Back to sign in')) ?></a></p>
</main>
</body>
</html>
