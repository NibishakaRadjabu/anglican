<?php
declare(strict_types=1);

require_once __DIR__ . '/config/app.php';
$user = require_login();
$error = '';
$notice = '';
$questions = recovery_questions();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = (string) ($_POST['action'] ?? 'upload');
    if ($action === 'remove') {
        $removePicture = db()->prepare('DELETE FROM user_profile_pictures WHERE user_id = ?');
        $removePicture->execute([(int) $user['id']]);
        if ($removePicture->rowCount() > 0) {
            notify_user_action((int) $user['id'], $user, 'Deleted', 'Profile picture', 'profile.php');
        }
        $notice = 'Profile picture removed.';
    } elseif ($action === 'save_recovery') {
        $questionKey = (string) ($_POST['question_key'] ?? '');
        $answer = normalize_recovery_answer((string) ($_POST['answer'] ?? ''));
        $currentPassword = (string) ($_POST['current_password'] ?? '');
        $passwordQuery = db()->prepare('SELECT password_hash FROM users WHERE id = ? AND active = 1');
        $passwordQuery->execute([(int) $user['id']]);
        $passwordHash = $passwordQuery->fetchColumn();
        if (!isset($questions[$questionKey]) || strlen($answer) < 3 || strlen($answer) > 256
            || !$passwordHash || !password_verify($currentPassword, (string) $passwordHash)) {
            $error = 'Enter a valid recovery question and answer, and confirm your current password.';
        } else {
            db()->prepare('INSERT INTO user_recovery (user_id, question_key, answer_hash) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE question_key = VALUES(question_key), answer_hash = VALUES(answer_hash)')
                ->execute([(int) $user['id'], $questionKey, password_hash($answer, PASSWORD_DEFAULT)]);
            notify_user_action((int) $user['id'], $user, 'Updated', 'Password recovery', 'profile.php');
            $notice = 'Password recovery details saved.';
        }
    } elseif ($action === 'delete_account') {
        $currentPassword = (string) ($_POST['current_password'] ?? '');
        $confirmation = (string) ($_POST['confirmation'] ?? '');
        $passwordQuery = db()->prepare('SELECT password_hash, role, branch_id FROM users WHERE id = ? AND active = 1');
        $passwordQuery->execute([(int) $user['id']]);
        $account = $passwordQuery->fetch();
        if (!$account || !password_verify($currentPassword, $account['password_hash']) || $confirmation !== 'DELETE MY ACCOUNT') {
            $error = 'Enter your current password and type DELETE MY ACCOUNT exactly to continue.';
        } elseif ($account['role'] === 'bishop' && empty($account['branch_id'])) {
            $otherBishops = db()->prepare("SELECT COUNT(*) FROM users WHERE role = 'bishop' AND branch_id IS NULL AND active = 1 AND id <> ?");
            $otherBishops->execute([(int) $user['id']]);
            if ((int) $otherBishops->fetchColumn() === 0) {
                $error = 'The last active Archbishop account cannot be deleted. Assign another Archbishop first.';
            }
        }

        if ($error === '') {
            db()->beginTransaction();
            try {
                db()->prepare('DELETE FROM user_recovery WHERE user_id = ?')->execute([(int) $user['id']]);
                db()->prepare('DELETE FROM user_profile_pictures WHERE user_id = ?')->execute([(int) $user['id']]);
                db()->prepare("UPDATE users SET full_name = ?, email = ?, password_hash = ?, role = 'member', member_id = NULL, branch_id = NULL, active = 0, auth_version = auth_version + 1 WHERE id = ?")
                    ->execute(['Deleted account #' . $user['id'], 'deleted+' . $user['id'] . '@deleted.invalid', password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT), (int) $user['id']]);
                db()->commit();
                $_SESSION = ['account_deleted' => true];
                session_regenerate_id(true);
                redirect('login.php');
            } catch (Throwable $exception) {
                if (db()->inTransaction()) {
                    db()->rollBack();
                }
                throw $exception;
            }
        }
    } elseif ($action === 'upload') {
        $file = $_FILES['profile_picture'] ?? null;
        if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            $error = 'Choose an image file to upload.';
        } elseif ((int) ($file['size'] ?? 0) < 1 || (int) $file['size'] > 2 * 1024 * 1024) {
            $error = 'The image must be smaller than 2 MB.';
        } else {
            $image = file_get_contents((string) $file['tmp_name']);
            $imageInfo = $image === false ? false : @getimagesizefromstring($image);
            $mime = (new finfo(FILEINFO_MIME_TYPE))->buffer($image ?: '');
            $allowedMimes = ['image/jpeg', 'image/png', 'image/webp'];
            if ($image === false || !$imageInfo || !in_array($mime, $allowedMimes, true)
                || ($imageInfo['mime'] ?? '') !== $mime
                || (int) $imageInfo[0] > 4096 || (int) $imageInfo[1] > 4096
                || (int) $imageInfo[0] * (int) $imageInfo[1] > 10000000) {
                $error = 'Upload a valid JPEG, PNG, or WebP image up to 4096 pixels wide or high.';
            } else {
                $statement = db()->prepare('INSERT INTO user_profile_pictures (user_id, image_mime, image_data) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE image_mime = VALUES(image_mime), image_data = VALUES(image_data)');
                $statement->bindValue(1, (int) $user['id'], PDO::PARAM_INT);
                $statement->bindValue(2, $mime, PDO::PARAM_STR);
                $statement->bindValue(3, $image, PDO::PARAM_LOB);
                $statement->execute();
                notify_user_action((int) $user['id'], $user, 'Updated', 'Profile picture', 'profile.php');
                $notice = 'Profile picture updated.';
            }
        }
    } else {
        http_response_code(400);
        exit(t('Invalid action.'));
    }
}

$pictureQuery = db()->prepare('SELECT updated_at FROM user_profile_pictures WHERE user_id = ?');
$pictureQuery->execute([(int) $user['id']]);
$pictureUpdated = $pictureQuery->fetchColumn();
$recoveryQuery = db()->prepare('SELECT question_key FROM user_recovery WHERE user_id = ?');
$recoveryQuery->execute([(int) $user['id']]);
$savedQuestion = $recoveryQuery->fetchColumn();
$pageTitle = 'My profile';
require __DIR__ . '/includes/header.php';
?>
<section class="content-heading">
    <div><p class="eyebrow"><?= e(t('Your account')) ?></p><h2><?= e(t('My profile')) ?></h2><p class="muted"><?= e(t('Manage your profile picture.')) ?></p></div>
</section>
<?php if ($notice !== ''): ?><div class="notice success"><?= e(t($notice)) ?></div><?php endif; ?>
<?php if ($error !== ''): ?><div class="notice error"><?= e(t($error)) ?></div><?php endif; ?>
<section class="panel profile-panel">
    <div class="profile-identity">
        <?php if ($pictureUpdated): ?><a href="profile_image.php?id=<?= e((string) $user['id']) ?>&v=<?= e((string) strtotime((string) $pictureUpdated)) ?>" target="_blank" rel="noopener"><img class="profile-picture large" src="profile_image.php?id=<?= e((string) $user['id']) ?>&v=<?= e((string) strtotime((string) $pictureUpdated)) ?>" alt="<?= e(t('Open full-size profile picture')) ?>"></a>
        <?php else: ?><span class="profile-picture placeholder large" aria-hidden="true"><?= e(strtoupper(substr((string) $user['full_name'], 0, 1))) ?></span><?php endif; ?>
        <div><h2><?= e($user['full_name']) ?></h2><p><?= e($user['email']) ?></p><span class="role-pill"><?= e(role_label($user['role'])) ?></span></div>
    </div>
    <form method="post" enctype="multipart/form-data" class="record-form single-column profile-picture-form">
        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="action" value="upload">
        <label><?= e(t('Profile picture')) ?><input type="file" name="profile_picture" accept="image/jpeg,image/png,image/webp" required></label>
        <p class="muted"><?= e(t('JPEG, PNG, or WebP; maximum 2 MB. Visible to the Bishop in account management.')) ?></p>
        <button class="button primary" type="submit"><?= e(t('Save picture')) ?></button>
    </form>
    <?php if ($pictureUpdated): ?><form method="post"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="remove"><button class="button secondary" type="submit"><?= e(t('Remove picture')) ?></button></form><?php endif; ?>
</section>
<section class="panel profile-panel">
    <div class="panel-heading"><div><p class="eyebrow"><?= e(t('Account security')) ?></p><h2><?= e(t('Password recovery')) ?></h2></div></div>
    <?php if ($savedQuestion && isset($questions[$savedQuestion])): ?><p class="muted"><?= e(t('Saved question')) ?>: <?= e(t($questions[$savedQuestion])) ?></p><?php else: ?><p class="muted"><?= e(t('Set a recovery question and answer so you can reset a forgotten password.')) ?></p><?php endif; ?>
    <form method="post" class="record-form single-column profile-picture-form"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="save_recovery">
        <label><?= e(t('Recovery question')) ?><select name="question_key" required><option value=""><?= e(t('Choose a question')) ?></option><?php foreach ($questions as $key => $question): ?><option value="<?= e($key) ?>" <?= $savedQuestion === $key ? 'selected' : '' ?>><?= e(t($question)) ?></option><?php endforeach; ?></select></label>
        <label><?= e(t('Answer')) ?><input name="answer" required minlength="3" maxlength="256" autocomplete="off"></label>
        <label><?= e(t('Confirm with your current password')) ?><input type="password" name="current_password" required autocomplete="current-password"></label>
        <button class="button primary" type="submit"><?= e(t('Save recovery details')) ?></button>
    </form>
</section>
<section class="panel profile-panel danger-panel">
    <div class="panel-heading"><div><p class="eyebrow"><?= e(t('Permanent action')) ?></p><h2><?= e(t('Delete my account')) ?></h2></div></div>
    <p class="muted"><?= e(t('This removes your profile picture and recovery details, disables sign-in, and anonymizes your account. Linked church and audit records are retained.')) ?></p>
    <form method="post" class="record-form single-column profile-picture-form" onsubmit="return confirm('Delete your account and sign out? This cannot be undone.');"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="delete_account">
        <label><?= e(t('Current password')) ?><input type="password" name="current_password" required autocomplete="current-password"></label>
        <label><?= e(t('Type DELETE MY ACCOUNT to confirm')) ?><input name="confirmation" required autocomplete="off"></label>
        <button class="button secondary" type="submit"><?= e(t('Delete my account')) ?></button>
    </form>
</section>
<?php require __DIR__ . '/includes/footer.php'; ?>
