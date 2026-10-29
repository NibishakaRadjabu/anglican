<?php
declare(strict_types=1);

require_once __DIR__ . '/config/app.php';
$user = require_login();
$isArchbishop = $user['role'] === 'bishop' && empty($user['branch_id']);
$isParishWorker = user_is_parish_worker($user);
if (!$isArchbishop && !$isParishWorker) {
    http_response_code(403);
    exit(t('You do not have access to staff departures.'));
}

$error = '';
$notice = $_SESSION['flash'] ?? '';
unset($_SESSION['flash']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = (string) ($_POST['action'] ?? '');
    if ($action === 'submit_resignation' && $isParishWorker) {
        $letter = trim((string) ($_POST['letter'] ?? ''));
        $pending = db()->prepare("SELECT id FROM staff_departures WHERE worker_id = ? AND action_type = 'resignation' AND status = 'pending' LIMIT 1");
        $pending->execute([(int) $user['id']]);
        if (strlen($letter) < 20 || strlen($letter) > 10000) {
            $error = t('Write a resignation letter between 20 and 10000 characters.');
        } elseif ($pending->fetch()) {
            $error = t('You already have a resignation awaiting review.');
        } else {
            db()->prepare("INSERT INTO staff_departures (worker_id, submitted_by, action_type, letter_or_reason) VALUES (?, ?, 'resignation', ?)")
                ->execute([(int) $user['id'], (int) $user['id'], $letter]);
            notify_headquarters_bishops('new_resignation_letter', ['name' => $user['full_name']], 'New resignation letter', $user['full_name'] . ' submitted a resignation letter.', 'staff.php');
            $_SESSION['flash'] = 'Your resignation letter was submitted to the Archbishop.';
            redirect('staff.php');
        }
    } elseif (in_array($action, ['approve_resignation', 'decline_resignation'], true) && $isArchbishop) {
        $departureId = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT) ?: 0;
        $status = $action === 'approve_resignation' ? 'approved' : 'rejected';
        db()->beginTransaction();
        try {
            $update = db()->prepare("UPDATE staff_departures SET status = ?, reviewed_by = ?, reviewed_at = NOW() WHERE id = ? AND action_type = 'resignation' AND status = 'pending'");
            $update->execute([$status, (int) $user['id'], $departureId]);
            if ($update->rowCount() !== 1) {
                db()->rollBack();
                $error = t('This resignation was already reviewed or could not be found.');
            } else {
                if ($status === 'approved') {
                    db()->prepare('UPDATE users SET active = 0 WHERE id = (SELECT worker_id FROM staff_departures WHERE id = ?)')->execute([$departureId]);
                }
                db()->commit();
                $workerQuery = db()->prepare('SELECT worker_id FROM staff_departures WHERE id = ?');
                $workerQuery->execute([$departureId]);
                $workerId = (int) $workerQuery->fetchColumn();
                if ($status === 'rejected') {
                    notify_user_action($workerId, $user, 'Rejected', 'Staff departures', 'staff.php');
                } else {
                    $workerBranch = db()->prepare('SELECT branch_id FROM users WHERE id = ?');
                    $workerBranch->execute([$workerId]);
                    notify_branch_action((int) $workerBranch->fetchColumn(), $user, 'Approved', 'Staff departures', 'staff.php');
                }
                $_SESSION['flash'] = $status === 'approved' ? 'Resignation accepted and account deactivated.' : 'Resignation declined.';
                redirect('staff.php');
            }
        } catch (Throwable $exception) {
            if (db()->inTransaction()) {
                db()->rollBack();
            }
            throw $exception;
        }
    } elseif ($action === 'dismiss' && $isArchbishop) {
        $workerId = filter_input(INPUT_POST, 'worker_id', FILTER_VALIDATE_INT) ?: 0;
        $reason = trim((string) ($_POST['reason'] ?? ''));
        $workerQuery = db()->prepare('SELECT id, role, branch_id, full_name FROM users WHERE id = ? AND active = 1');
        $workerQuery->execute([$workerId]);
        $worker = $workerQuery->fetch();
        if ($workerId === (int) $user['id'] || !$worker || empty($worker['branch_id']) || !in_array($worker['role'], parish_worker_roles(), true)) {
            $error = t('Select an active parish worker to dismiss.');
        } elseif (strlen($reason) < 10 || strlen($reason) > 2000) {
            $error = t('Enter a dismissal reason between 10 and 2000 characters.');
        } else {
            db()->beginTransaction();
            try {
                db()->prepare("UPDATE staff_departures SET status = 'rejected', reviewed_by = ?, reviewed_at = NOW() WHERE worker_id = ? AND action_type = 'resignation' AND status = 'pending'")
                    ->execute([(int) $user['id'], $workerId]);
                db()->prepare("INSERT INTO staff_departures (worker_id, submitted_by, action_type, letter_or_reason, status, reviewed_by, reviewed_at) VALUES (?, ?, 'dismissal', ?, 'approved', ?, NOW())")
                    ->execute([$workerId, (int) $user['id'], $reason, (int) $user['id']]);
                db()->prepare('UPDATE users SET active = 0 WHERE id = ?')->execute([$workerId]);
                db()->commit();
                notify_branch_action((int) $worker['branch_id'], $user, 'Dismissed', (string) $worker['full_name'], 'staff.php');
                $_SESSION['flash'] = 'Parish worker dismissed and account deactivated.';
                redirect('staff.php');
            } catch (Throwable $exception) {
                if (db()->inTransaction()) {
                    db()->rollBack();
                }
                throw $exception;
            }
        }
    } else {
        http_response_code(403);
        exit(t('Invalid action or insufficient permissions.'));
    }
}

$pendingResignations = $isArchbishop ? db()->query("SELECT d.id, d.letter_or_reason, d.created_at, u.full_name, u.role, b.name AS branch_name FROM staff_departures d JOIN users u ON u.id = d.worker_id LEFT JOIN branches b ON b.id = COALESCE(u.branch_id, (SELECT m.branch_id FROM members m WHERE m.id = u.member_id)) WHERE d.action_type = 'resignation' AND d.status = 'pending' ORDER BY d.created_at ASC")->fetchAll() : [];
$activeWorkers = $isArchbishop ? db()->query("SELECT u.id, u.full_name, u.role, b.name AS branch_name FROM users u LEFT JOIN branches b ON b.id = u.branch_id WHERE u.active = 1 AND u.branch_id IS NOT NULL AND u.role IN ('archdeacon','priest','assistant_clergy','parish_council','secretary','treasurer','ministry_leader') ORDER BY u.full_name")->fetchAll() : [];
$historyQuery = $isArchbishop
    ? db()->query('SELECT d.*, w.full_name AS worker_name, w.role, s.full_name AS submitter_name, r.full_name AS reviewer_name, b.name AS branch_name FROM staff_departures d JOIN users w ON w.id = d.worker_id JOIN users s ON s.id = d.submitted_by LEFT JOIN users r ON r.id = d.reviewed_by LEFT JOIN branches b ON b.id = w.branch_id ORDER BY d.created_at DESC LIMIT 100')
    : db()->prepare('SELECT d.*, r.full_name AS reviewer_name FROM staff_departures d LEFT JOIN users r ON r.id = d.reviewed_by WHERE d.worker_id = ? ORDER BY d.created_at DESC');
if (!$isArchbishop) {
    $historyQuery->execute([(int) $user['id']]);
}
$history = $historyQuery->fetchAll();
$pageTitle = 'Staff departures';
require __DIR__ . '/includes/header.php';
?>
<section class="content-heading">
    <div><p class="eyebrow"><?= e(t($isArchbishop ? 'Archbishop office' : 'Your employment')) ?></p><h2><?= e(t('Staff departures')) ?></h2><p class="muted"><?= e(t($isArchbishop ? 'Review resignations, record dismissals, and create a replacement account when needed.' : 'Submit a resignation letter to the Archbishop and follow its review status.')) ?></p></div>
    <?php if ($isArchbishop): ?><a class="button primary" href="users.php"><?= e(t('Create or replace an account')) ?></a><?php endif; ?>
</section>
<?php if ($notice !== ''): ?><div class="notice success"><?= e(t($notice)) ?></div><?php endif; ?>
<?php if ($error !== ''): ?><div class="notice error"><?= e($error) ?></div><?php endif; ?>

<?php if ($isParishWorker): ?>
<section class="panel">
    <div class="panel-heading"><div><p class="eyebrow"><?= e(t('Submit to the Archbishop')) ?></p><h2><?= e(t('Resignation letter')) ?></h2></div></div>
    <form method="post" class="record-form single-column">
        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="submit_resignation">
        <label><?= e(t('Letter')) ?><textarea name="letter" rows="8" minlength="20" maxlength="10000" required placeholder="<?= e(t('Write your resignation letter here.')) ?>"></textarea></label>
        <button class="button primary" type="submit"><?= e(t('Submit resignation')) ?></button>
    </form>
</section>
<?php endif; ?>

<?php if ($isArchbishop): ?>
<section class="panel">
    <div class="panel-heading"><div><p class="eyebrow"><?= e(t('Awaiting review')) ?></p><h2><?= e(t('Resignation letters')) ?></h2></div></div>
    <?php if ($pendingResignations): ?><div class="account-list"><?php foreach ($pendingResignations as $request): ?><article class="account-row departure-row"><div><h3><?= e($request['full_name']) ?> <span class="role-pill"><?= e(role_label($request['role'])) ?></span></h3><p><?= e($request['branch_name'] ?? t('No parish assigned')) ?> · <?= e(localized_date(date('Y-m-d', strtotime($request['created_at'])))) ?></p><div class="departure-letter"><?= nl2br(e($request['letter_or_reason'])) ?></div></div><div class="actions"><form method="post"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="approve_resignation"><input type="hidden" name="id" value="<?= e((string) $request['id']) ?>"><button class="button primary small" type="submit"><?= e(t('Accept and deactivate')) ?></button></form><form method="post"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="decline_resignation"><input type="hidden" name="id" value="<?= e((string) $request['id']) ?>"><button class="button secondary small" type="submit"><?= e(t('Decline')) ?></button></form></div></article><?php endforeach; ?></div><?php else: ?><p class="empty-state"><?= e(t('No resignation letters are awaiting review.')) ?></p><?php endif; ?>
</section>
<section class="panel">
    <div class="panel-heading"><div><p class="eyebrow"><?= e(t('Employment action')) ?></p><h2><?= e(t('Dismiss a parish worker')) ?></h2></div></div>
    <?php if ($activeWorkers): ?><div class="account-list"><?php foreach ($activeWorkers as $worker): ?><article class="account-row"><div><h3><?= e($worker['full_name']) ?></h3><p><?= e(role_label($worker['role'])) ?> · <?= e($worker['branch_name'] ?? t('No parish assigned')) ?></p></div><form method="post" class="dismiss-form"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="dismiss"><input type="hidden" name="worker_id" value="<?= e((string) $worker['id']) ?>"><label><?= e(t('Reason for dismissal')) ?><textarea name="reason" rows="2" minlength="10" maxlength="2000" required></textarea></label><button class="button secondary small" type="submit"><?= e(t('Dismiss and deactivate')) ?></button></form></article><?php endforeach; ?></div><?php else: ?><p class="empty-state"><?= e(t('No active parish workers found.')) ?></p><?php endif; ?>
</section>
<?php endif; ?>

<section class="panel">
    <div class="panel-heading"><div><p class="eyebrow"><?= e(t('Record')) ?></p><h2><?= e(t($isArchbishop ? 'Departure history' : 'My resignation history')) ?></h2></div></div>
    <?php if ($history): ?><div class="account-list"><?php foreach ($history as $item): ?><article class="account-row"><div><h3><?= e(t(ucfirst($item['action_type']))) ?><?= $isArchbishop ? ' · ' . e($item['worker_name']) : '' ?></h3><p><?= $isArchbishop ? e(role_label($item['role'])) . ' · ' . e($item['branch_name'] ?? '') . ' · ' : '' ?><?= e(t(ucfirst($item['status']))) ?> · <?= e(localized_date(date('Y-m-d', strtotime($item['created_at'])))) ?><?= !empty($item['reviewer_name']) ? ' · ' . e(t('Reviewed by')) . ' ' . e($item['reviewer_name']) : '' ?></p><div class="departure-letter"><?= nl2br(e($item['letter_or_reason'])) ?></div></div></article><?php endforeach; ?></div><?php else: ?><p class="empty-state"><?= e(t('No departure records yet.')) ?></p><?php endif; ?>
</section>
<?php require __DIR__ . '/includes/footer.php'; ?>
