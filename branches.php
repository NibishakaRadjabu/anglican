<?php
declare(strict_types=1);

require_once __DIR__ . '/config/app.php';
$user = require_login();
if ($user['role'] !== 'bishop') {
    http_response_code(403);
    exit(t('Only a Bishop can access parish setup.'));
}
$isHeadquarters = empty($user['branch_id']);

$error = '';
$notice = $_SESSION['flash'] ?? '';
unset($_SESSION['flash']);
$branchId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT) ?: 0;
$branchTypes = ['Church', 'School', 'Parish', 'Headquarters'];
$values = ['name' => '', 'branch_type' => 'Parish', 'location' => ''];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = (string) ($_POST['action'] ?? '');
    if ($action === 'request' && !$isHeadquarters) {
        $name = trim((string) ($_POST['name'] ?? ''));
        $location = trim((string) ($_POST['location'] ?? ''));
        if ($name === '') {
            $error = t('Enter a parish name.');
        } else {
            $statement = db()->prepare("INSERT INTO parish_requests (requested_name, location, requested_by, requester_branch_id) VALUES (?, ?, ?, ?) ");
            $statement->execute([$name, $location !== '' ? $location : null, (int) $user['id'], (int) $user['branch_id']]);
            notify_headquarters_bishops('new_parish_request', ['parish' => $name, 'name' => $user['full_name']], 'New parish request', $name . ' was submitted by ' . $user['full_name'] . '.', 'branches.php');
            $_SESSION['flash'] = 'Parish request sent to Headquarters.';
            redirect('branches.php');
        }
    } elseif (in_array($action, ['approve_request', 'reject_request'], true) && $isHeadquarters) {
        $requestId = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT) ?: 0;
        $statement = db()->prepare('SELECT requested_name, location, requested_by, status FROM parish_requests WHERE id = ?');
        $statement->execute([$requestId]);
        $request = $statement->fetch();
        if (!$request || $request['status'] !== 'pending') {
            $error = t('Parish request not found or already reviewed.');
        } elseif ($action === 'approve_request') {
            db()->beginTransaction();
            try {
                $create = db()->prepare('INSERT INTO branches (name, branch_type, location) VALUES (?, ?, ?)');
                $create->execute([$request['requested_name'], 'Parish', $request['location']]);
                $update = db()->prepare("UPDATE parish_requests SET status = 'approved', reviewed_by = ?, reviewed_at = NOW() WHERE id = ? AND status = 'pending'");
                $update->execute([(int) $user['id'], $requestId]);
                db()->commit();
                notify_user_action((int) $request['requested_by'], $user, 'Approved', (string) $request['requested_name'], 'branches.php');
                notify_headquarters_action($user, 'Created', (string) $request['requested_name'], 'branches.php');
                $_SESSION['flash'] = 'Parish request approved and parish created.';
                redirect('branches.php');
            } catch (Throwable $exception) {
                db()->rollBack();
                if ($exception instanceof PDOException && $exception->getCode() === '23000') {
                    $error = t('A parish with this name already exists.');
                } else {
                    throw $exception;
                }
            }
        } else {
            $update = db()->prepare("UPDATE parish_requests SET status = 'rejected', reviewed_by = ?, reviewed_at = NOW() WHERE id = ? AND status = 'pending'");
            $update->execute([(int) $user['id'], $requestId]);
            if ($update->rowCount() > 0) {
                notify_user_action((int) $request['requested_by'], $user, 'Rejected', (string) $request['requested_name'], 'branches.php');
            }
            $_SESSION['flash'] = 'Parish request rejected.';
            redirect('branches.php');
        }
    } elseif (!$isHeadquarters) {
        http_response_code(403);
        exit(t('Headquarters must approve new parishes.'));
    } elseif ($action === 'save') {
        $branchId = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT) ?: 0;
        $name = trim((string) ($_POST['name'] ?? ''));
        $branchType = (string) ($_POST['branch_type'] ?? '');
        $location = trim((string) ($_POST['location'] ?? ''));
        $values = ['name' => $name, 'branch_type' => $branchType, 'location' => $location];

        if ($name === '') {
            $error = t('Enter a parish name.');
        } elseif (!in_array($branchType, $branchTypes, true)) {
            $error = t('Choose a valid parish type.');
        } else {
            try {
                if ($branchId > 0) {
                    $statement = db()->prepare('UPDATE branches SET name = ?, branch_type = ?, location = ? WHERE id = ?');
                    $statement->execute([$name, $branchType, $location !== '' ? $location : null, $branchId]);
                    notify_headquarters_action($user, 'Updated', $name, 'branches.php');
                    $_SESSION['flash'] = 'Parish updated.';
                } else {
                    $statement = db()->prepare('INSERT INTO branches (name, branch_type, location) VALUES (?, ?, ?)');
                    $statement->execute([$name, $branchType, $location !== '' ? $location : null]);
                    notify_headquarters_action($user, 'Created', $name, 'branches.php');
                    $_SESSION['flash'] = 'Parish created.';
                }
                redirect('branches.php');
            } catch (PDOException $exception) {
                if ($exception->getCode() === '23000') {
                    $error = t('A parish with this name already exists.');
                } else {
                    throw $exception;
                }
            }
        }
    } elseif ($action === 'toggle') {
        $branchId = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT) ?: 0;
        $statement = db()->prepare('UPDATE branches SET active = IF(active = 1, 0, 1) WHERE id = ?');
        $statement->execute([$branchId]);
        if ($statement->rowCount() > 0) {
            notify_headquarters_action($user, 'Changed status', 'Parishes', 'branches.php');
        }
        $_SESSION['flash'] = $statement->rowCount() ? 'Parish status updated.' : 'Parish not found.';
        redirect('branches.php');
    } else {
        http_response_code(400);
        exit(t('Invalid action.'));
    }
}

$editingBranch = null;
if ($branchId > 0) {
    $statement = db()->prepare('SELECT id, name, branch_type, location FROM branches WHERE id = ?');
    $statement->execute([$branchId]);
    $editingBranch = $statement->fetch();
    if (!$editingBranch) {
        http_response_code(404);
        exit(t('Parish not found.'));
    }
    if ($error === '') {
        $values = $editingBranch;
        if ($values['branch_type'] === 'Branch') {
            $values['branch_type'] = 'Parish';
        }
    }
}

$branches = db()->query(
    'SELECT b.id, b.name, b.branch_type, b.location, b.active, COUNT(DISTINCT u.id) AS assigned_users, COUNT(DISTINCT m.id) AS members
     FROM branches b
     LEFT JOIN users u ON u.branch_id = b.id
     LEFT JOIN members m ON m.branch_id = b.id
     GROUP BY b.id, b.name, b.branch_type, b.location, b.active
     ORDER BY b.name'
)->fetchAll();
$parishRequests = $isHeadquarters ? db()->query("SELECT pr.*, u.full_name AS requester_name, b.name AS requester_branch FROM parish_requests pr JOIN users u ON u.id = pr.requested_by JOIN branches b ON b.id = pr.requester_branch_id WHERE pr.status = 'pending' ORDER BY pr.created_at")->fetchAll() : [];
$myParishRequests = !$isHeadquarters ? db()->prepare('SELECT requested_name, status, created_at FROM parish_requests WHERE requested_by = ? ORDER BY created_at DESC') : null;
if ($myParishRequests) {
    $myParishRequests->execute([(int) $user['id']]);
    $myParishRequests = $myParishRequests->fetchAll();
}
$pageTitle = 'Parishes';
require __DIR__ . '/includes/header.php';
?>
<section class="content-heading">
    <div><p class="eyebrow"><?= e(t($isHeadquarters ? 'Diocesan controls' : 'Parish governance')) ?></p><h2><?= e(t($isHeadquarters ? 'Parishes' : 'Request a parish')) ?></h2><p class="muted"><?= e(t($isHeadquarters ? 'Manage parishes and assign parish staff from User accounts.' : 'Send new parish proposals to Headquarters for approval.')) ?></p></div>
</section>
<?php if ($notice !== ''): ?><div class="notice success"><?= e(t($notice)) ?></div><?php endif; ?>
<?php if ($error !== ''): ?><div class="notice error"><?= e($error) ?></div><?php endif; ?>
<div class="dashboard-grid user-grid">
    <?php if ($isHeadquarters): ?><section class="panel">
        <div class="panel-heading"><div><p class="eyebrow"><?= e(t($editingBranch ? 'Parish details' : 'New parish')) ?></p><h2><?= e(t($editingBranch ? 'Edit parish' : 'Add parish')) ?></h2></div></div>
        <form method="post" class="record-form single-column">
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="save">
            <input type="hidden" name="id" value="<?= e((string) $branchId) ?>">
            <label><?= e(t('Parish name')) ?><input name="name" required maxlength="150" value="<?= e($values['name']) ?>"></label>
            <label><?= e(t('Parish type')) ?><select name="branch_type" required><?php foreach ($branchTypes as $type): ?><option value="<?= e($type) ?>" <?= $values['branch_type'] === $type ? 'selected' : '' ?>><?= e(t($type)) ?></option><?php endforeach; ?></select></label>
            <label><?= e(t('Location')) ?><input name="location" maxlength="255" value="<?= e((string) $values['location']) ?>"></label>
            <button class="button primary" type="submit"><?= e(t($editingBranch ? 'Save parish' : 'Create parish')) ?></button>
            <?php if ($editingBranch): ?><a class="button secondary" href="branches.php"><?= e(t('Cancel')) ?></a><?php endif; ?>
        </form>
    </section>
    <section class="panel">
        <div class="panel-heading"><div><p class="eyebrow"><?= e(t('Diocesan overview')) ?></p><h2><?= e(t('Parishes')) ?></h2></div></div>
        <?php if ($branches): ?>
            <div class="account-list">
                <?php foreach ($branches as $branch): ?>
                    <article class="account-row">
                        <div>
                            <h3><?= e($branch['name']) ?></h3>
                            <p><?= e(t($branch['branch_type'] === 'Branch' ? 'Parish' : $branch['branch_type'])) ?><?= $branch['location'] ? ' · ' . e($branch['location']) : '' ?></p>
                            <p><?= e(t('Assigned staff')) ?>: <?= e((string) $branch['assigned_users']) ?> · <?= e(t('Members')) ?>: <?= e((string) $branch['members']) ?></p>
                            <span class="status-dot <?= $branch['active'] ? 'is-active' : '' ?>"><?= e(t($branch['active'] ? 'Active' : 'Inactive')) ?></span>
                        </div>
                        <div class="actions">
                            <a href="branches.php?id=<?= e((string) $branch['id']) ?>"><?= e(t('Edit')) ?></a>
                            <form method="post">
                                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                <input type="hidden" name="action" value="toggle">
                                <input type="hidden" name="id" value="<?= e((string) $branch['id']) ?>">
                                <button class="button secondary small" type="submit"><?= e(t($branch['active'] ? 'Deactivate' : 'Activate')) ?></button>
                            </form>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <p class="empty-state"><?= e(t('No parishes have been added yet.')) ?></p>
        <?php endif; ?>
    </section><?php else: ?><section class="panel">
        <div class="panel-heading"><div><p class="eyebrow"><?= e(t('Headquarters approval')) ?></p><h2><?= e(t('Request a new parish')) ?></h2></div></div>
        <p class="muted"><?= e(t('Headquarters must approve the request before a new parish is created.')) ?></p>
        <form method="post" class="record-form single-column"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="request"><label><?= e(t('Proposed parish name')) ?><input name="name" maxlength="150" required></label><label><?= e(t('Location')) ?><input name="location" maxlength="255"></label><button class="button primary" type="submit"><?= e(t('Send request')) ?></button></form>
        <?php if ($myParishRequests): ?><h3><?= e(t('Your parish requests')) ?></h3><div class="account-list"><?php foreach ($myParishRequests as $request): ?><article class="account-row"><div><strong><?= e($request['requested_name']) ?></strong><p><?= e(t(ucfirst($request['status']))) ?> · <?= e(localized_date(date('Y-m-d', strtotime($request['created_at'])))) ?></p></div></article><?php endforeach; ?></div><?php endif; ?>
    </section><?php endif; ?>
</div>
<?php if ($isHeadquarters && $parishRequests): ?><section class="panel"><div class="panel-heading"><div><p class="eyebrow"><?= e(t('Headquarters review')) ?></p><h2><?= e(t('Parish requests')) ?></h2></div></div><div class="account-list"><?php foreach ($parishRequests as $request): ?><article class="account-row"><div><h3><?= e($request['requested_name']) ?></h3><p><?= e(t('Requested by')) ?> <?= e($request['requester_name']) ?> (<?= e($request['requester_branch']) ?>)<?= $request['location'] ? ' · ' . e($request['location']) : '' ?></p></div><div class="actions"><form method="post"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="approve_request"><input type="hidden" name="id" value="<?= e((string) $request['id']) ?>"><button class="button primary small" type="submit"><?= e(t('Approve')) ?></button></form><form method="post"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="reject_request"><input type="hidden" name="id" value="<?= e((string) $request['id']) ?>"><button class="button secondary small" type="submit"><?= e(t('Reject')) ?></button></form></div></article><?php endforeach; ?></div></section><?php endif; ?>
<?php require __DIR__ . '/includes/footer.php'; ?>
