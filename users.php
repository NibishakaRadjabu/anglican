<?php
declare(strict_types=1);

require_once __DIR__ . '/config/app.php';
$user = require_login();
$isHeadquarters = $user['role'] === 'bishop' && empty($user['branch_id']);
$managedBranchId = $isHeadquarters ? null : (int) ($user['branch_id'] ?? 0);
if ($user['role'] !== 'bishop' || (!$isHeadquarters && $managedBranchId < 1)) {
    http_response_code(403);
    exit(t('Only a Bishop can manage parish user accounts.'));
}

$error = '';
$notice = $_SESSION['flash'] ?? '';
unset($_SESSION['flash']);
$membersQuery = $isHeadquarters
    ? db()->query('SELECT id, first_name, last_name, branch_id FROM members ORDER BY last_name, first_name')
    : db()->prepare('SELECT id, first_name, last_name, branch_id FROM members WHERE branch_id = ? ORDER BY last_name, first_name');
if (!$isHeadquarters) {
    $membersQuery->execute([$managedBranchId]);
}
$members = $membersQuery->fetchAll();
$branchesQuery = $isHeadquarters
    ? db()->query('SELECT id, name, active FROM branches ORDER BY name')
    : db()->prepare('SELECT id, name, active FROM branches WHERE id = ?');
if (!$isHeadquarters) {
    $branchesQuery->execute([$managedBranchId]);
}
$branches = $branchesQuery->fetchAll();
$branchesById = [];
foreach ($branches as $branch) {
    $branchesById[(int) $branch['id']] = $branch;
}
$membersById = [];
foreach ($members as $member) {
    $membersById[(int) $member['id']] = $member;
}
$editId = filter_input(INPUT_GET, 'edit', FILTER_VALIDATE_INT) ?: 0;
$editingAccount = null;
if ($editId > 0) {
    $statement = db()->prepare('SELECT u.id, u.member_id, u.branch_id, u.full_name, u.email, u.role, COALESCE(u.branch_id, m.branch_id) AS account_branch_id FROM users u LEFT JOIN members m ON m.id = u.member_id WHERE u.id = ?');
    $statement->execute([$editId]);
    $editingAccount = $statement->fetch() ?: null;
    if (!$editingAccount || $editId === (int) $user['id'] || (!$isHeadquarters && ((int) $editingAccount['account_branch_id'] !== $managedBranchId || in_array($editingAccount['role'], ['bishop', 'archdeacon'], true)))) {
        http_response_code($editingAccount ? 403 : 404);
        exit(t($editingAccount ? 'You cannot edit this account here.' : 'Account not found.'));
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'create' || $action === 'save') {
        $isEdit = $action === 'save';
        $name = trim((string) ($_POST['full_name'] ?? ''));
        $email = trim((string) ($_POST['email'] ?? ''));
        $password = (string) ($_POST['password'] ?? '');
        $role = (string) ($_POST['role'] ?? '');
        $memberId = filter_input(INPUT_POST, 'member_id', FILTER_VALIDATE_INT) ?: null;
        $branchId = $isHeadquarters ? (filter_input(INPUT_POST, 'branch_id', FILTER_VALIDATE_INT) ?: null) : $managedBranchId;
        $accountId = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT) ?: 0;
        $allowedRoles = $isHeadquarters
            ? ['bishop', 'archdeacon', 'priest', 'assistant_clergy', 'parish_council', 'secretary', 'treasurer', 'ministry_leader', 'member']
            : ['priest', 'assistant_clergy', 'parish_council', 'secretary', 'treasurer', 'ministry_leader', 'member'];

        if ($isEdit) {
            $scopeCheck = db()->prepare('SELECT u.role, u.active, COALESCE(u.branch_id, m.branch_id) AS account_branch_id FROM users u LEFT JOIN members m ON m.id = u.member_id WHERE u.id = ?');
            $scopeCheck->execute([$accountId]);
            $existingAccount = $scopeCheck->fetch();
            if (!$existingAccount || (!$isHeadquarters && ((int) $existingAccount['account_branch_id'] !== $managedBranchId || in_array($existingAccount['role'], ['bishop', 'archdeacon'], true)))) {
                $error = t('You cannot edit an account outside your parish.');
            }
        }

        if ($error !== '') {
            // Keep the form open and report the parish scope violation.
        } elseif ($isEdit && (!$accountId || $accountId === (int) $user['id'])) {
            $error = t('Choose another account to edit.');
        } elseif ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = t('Enter a name and a valid email address.');
        } elseif ((!$isEdit || $password !== '') && strlen($password) < 12) {
            $error = t('The password must be at least 12 characters.');
        } elseif (!in_array($role, $allowedRoles, true)) {
            $error = t('Select a valid account role.');
        } elseif ($role === 'member' && !$memberId) {
            $error = t('Select the member linked to this account.');
        } elseif ($role === 'member' && !isset($membersById[$memberId])) {
            $error = t('Select an existing member record.');
        } elseif ($branchId !== null && (!isset($branchesById[$branchId]) || !(int) $branchesById[$branchId]['active'])) {
            $error = t('Choose an active parish for this account.');
        } elseif (role_requires_branch_assignment($role) && $branchId === null && !($isHeadquarters && role_can_have_headquarters_access($role))) {
            $error = t('Choose an active parish for this staff account.');
        } else {
            try {
                if ($role === 'member') {
                    $branchId = (int) $membersById[$memberId]['branch_id'];
                }
                if ($isEdit) {
                    $removesLastHeadquartersBishop = $existingAccount['role'] === 'bishop'
                        && (int) $existingAccount['active'] === 1
                        && empty($existingAccount['account_branch_id'])
                        && ($role !== 'bishop' || $branchId !== null);
                    if ($removesLastHeadquartersBishop) {
                        $remaining = db()->query("SELECT COUNT(*) FROM users WHERE role = 'bishop' AND branch_id IS NULL AND active = 1")->fetchColumn();
                        if ((int) $remaining <= 1) {
                            $error = t('Assign another active Bishop to Headquarters before changing the last one.');
                        }
                    }
                    if ($error === '') {
                        $sql = 'UPDATE users SET member_id = ?, full_name = ?, email = ?, role = ?, branch_id = ?';
                        $params = [$role === 'member' ? $memberId : null, $name, $email, $role, $branchId];
                        if ($password !== '') {
                            $sql .= ', password_hash = ?, auth_version = auth_version + 1';
                            $params[] = password_hash($password, PASSWORD_DEFAULT);
                        }
                        $sql .= ' WHERE id = ?';
                        $params[] = $accountId;
                        db()->prepare($sql)->execute($params);
                        notify_user_action($accountId, $user, 'Updated', 'User accounts', 'profile.php');
                        if ($branchId !== null) {
                            notify_branch_action((int) $branchId, $user, 'Updated', 'User accounts', 'users.php');
                        } else {
                            notify_headquarters_action($user, 'Updated', 'User accounts', 'users.php');
                        }
                        $_SESSION['flash'] = 'User account updated.';
                        redirect('users.php');
                    }
                } else {
                    $statement = db()->prepare('INSERT INTO users (member_id, full_name, email, password_hash, role, branch_id) VALUES (?, ?, ?, ?, ?, ?)');
                    $statement->execute([$role === 'member' ? $memberId : null, $name, $email, password_hash($password, PASSWORD_DEFAULT), $role, $branchId]);
                    notify_user_action((int) db()->lastInsertId(), $user, 'Created', 'User accounts', 'profile.php');
                    if ($branchId !== null) {
                        notify_branch_action((int) $branchId, $user, 'Created', 'User accounts', 'users.php');
                    } else {
                        notify_headquarters_action($user, 'Created', 'User accounts', 'users.php');
                    }
                    $_SESSION['flash'] = 'User account created.';
                }
                redirect('users.php');
            } catch (PDOException $exception) {
                if ($exception->getCode() === '23000') {
                    $error = t('That email address is already in use, or the linked member already has an account.');
                } else {
                    throw $exception;
                }
            }
        }
    } elseif ($action === 'assign_branch') {
        $id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT) ?: 0;
        $branchId = $isHeadquarters ? (filter_input(INPUT_POST, 'branch_id', FILTER_VALIDATE_INT) ?: null) : $managedBranchId;
        $statement = db()->prepare('SELECT u.role, u.active, COALESCE(u.branch_id, m.branch_id) AS account_branch_id FROM users u LEFT JOIN members m ON m.id = u.member_id WHERE u.id = ?');
        $statement->execute([$id]);
        $targetAccount = $statement->fetch();
        $accountRole = $targetAccount['role'] ?? null;
        if (!$accountRole || (!$isHeadquarters && ((int) $targetAccount['account_branch_id'] !== $managedBranchId || in_array($accountRole, ['bishop', 'archdeacon'], true)))) {
            $error = t('You cannot change an account outside your parish.');
        } elseif (!role_can_have_headquarters_access($accountRole)) {
            $error = t('This account cannot be assigned to a parish.');
        } elseif ($branchId !== null && (!isset($branchesById[$branchId]) || !(int) $branchesById[$branchId]['active'])) {
            $error = t('Choose an active parish for this staff account.');
        } elseif ($branchId === null && (!$isHeadquarters || !role_can_have_headquarters_access($accountRole))) {
            $error = t('Choose an active parish for this staff account.');
        } else {
            if ($accountRole === 'bishop' && (int) $targetAccount['active'] === 1 && empty($targetAccount['account_branch_id']) && $branchId !== null) {
                $bishopCount = db()->query("SELECT COUNT(*) FROM users WHERE role = 'bishop' AND branch_id IS NULL AND active = 1")->fetchColumn();
                if ((int) $bishopCount <= 1) {
                    $error = t('Assign another active Bishop to Headquarters before moving the last one to a parish.');
                }
            }
            if ($error === '') {
                if (!$isHeadquarters) {
                    $branchId = $managedBranchId;
                }
                $statement = db()->prepare('UPDATE users SET branch_id = ? WHERE id = ?');
                $statement->execute([$branchId, $id]);
                notify_user_action($id, $user, 'Assigned', 'Parish', 'users.php');
                if ($branchId !== null) {
                    notify_branch_action((int) $branchId, $user, 'Assigned', 'User accounts', 'users.php');
                } else {
                    notify_headquarters_action($user, 'Assigned', 'User accounts', 'users.php');
                }
                $_SESSION['flash'] = 'Parish assignment updated.';
                redirect('users.php');
            }
        }
    } elseif ($action === 'delete') {
        $id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT) ?: 0;
        if (!$isHeadquarters) {
            http_response_code(403);
            exit(t('Only Headquarters can delete user accounts.'));
        } elseif ($id === (int) $user['id']) {
            $error = t('You cannot delete your own account.');
        } else {
            try {
                $targetQuery = db()->prepare('SELECT role, branch_id, active FROM users WHERE id = ?');
                $targetQuery->execute([$id]);
                $targetAccount = $targetQuery->fetch();
                if ($targetAccount && $targetAccount['role'] === 'bishop' && empty($targetAccount['branch_id']) && (int) $targetAccount['active'] === 1) {
                    $remaining = db()->query("SELECT COUNT(*) FROM users WHERE role = 'bishop' AND branch_id IS NULL AND active = 1")->fetchColumn();
                    if ((int) $remaining <= 1) {
                        $_SESSION['flash'] = 'Assign another active Bishop to Headquarters before deleting the last one.';
                        redirect('users.php');
                    }
                }
                $statement = db()->prepare('DELETE FROM users WHERE id = ?');
                $statement->execute([$id]);
                if ($statement->rowCount() > 0 && $targetAccount) {
                    $targetBranchId = (int) ($targetAccount['branch_id'] ?? 0);
                    if ($targetBranchId > 0) {
                        notify_branch_action($targetBranchId, $user, 'Deleted', 'User accounts', 'users.php');
                    } else {
                        notify_headquarters_action($user, 'Deleted', 'User accounts', 'users.php');
                    }
                }
                $_SESSION['flash'] = $statement->rowCount() ? 'User account deleted.' : 'Account not found.';
                redirect('users.php');
            } catch (PDOException $exception) {
                if ($exception->getCode() === '23000') {
                    $error = t('This account is linked to activity records and cannot be deleted. Deactivate it instead.');
                } else {
                    throw $exception;
                }
            }
        }
    } elseif ($action === 'toggle') {
        $id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT) ?: 0;
        if ($id === (int) $user['id']) {
            $error = t('You cannot deactivate your own account.');
        } else {
            $lookup = db()->prepare('SELECT u.active, u.role, COALESCE(u.branch_id, m.branch_id) AS account_branch_id FROM users u LEFT JOIN members m ON m.id = u.member_id WHERE u.id = ?');
            $lookup->execute([$id]);
            $targetAccount = $lookup->fetch();
            if (!$targetAccount) {
                $_SESSION['flash'] = 'Account not found.';
            } elseif (!$isHeadquarters && ((int) $targetAccount['account_branch_id'] !== $managedBranchId || in_array($targetAccount['role'], ['bishop', 'archdeacon'], true))) {
                http_response_code(403);
                exit(t('You cannot manage an account outside your parish.'));
            } elseif ((int) $targetAccount['active'] === 1 && $targetAccount['role'] === 'bishop' && empty($targetAccount['account_branch_id'])) {
                $remaining = db()->query("SELECT COUNT(*) FROM users WHERE role = 'bishop' AND branch_id IS NULL AND active = 1")->fetchColumn();
                if ((int) $remaining <= 1) {
                    $_SESSION['flash'] = 'Assign another active Bishop to Headquarters before deactivating the last one.';
                    redirect('users.php');
                }
                db()->prepare('UPDATE users SET active = 0 WHERE id = ?')->execute([$id]);
                notify_headquarters_action($user, 'Changed status', 'User accounts', 'users.php');
                $_SESSION['flash'] = 'Account status updated.';
            } elseif ((int) $targetAccount['active'] === 1 && in_array($targetAccount['role'], parish_worker_roles(), true) && !empty($targetAccount['account_branch_id'])) {
                if ($isHeadquarters) {
                    $_SESSION['flash'] = 'Use Staff departures to dismiss a parish worker and record the reason.';
                    redirect('staff.php');
                }
                $_SESSION['flash'] = 'Only the Archbishop can dismiss or deactivate a parish worker.';
            } else {
                db()->prepare('UPDATE users SET active = IF(active = 1, 0, 1) WHERE id = ?')->execute([$id]);
                if (!empty($targetAccount['account_branch_id'])) {
                    notify_branch_action((int) $targetAccount['account_branch_id'], $user, 'Changed status', 'User accounts', 'users.php');
                } else {
                    notify_headquarters_action($user, 'Changed status', 'User accounts', 'users.php');
                }
                $_SESSION['flash'] = 'Account status updated.';
            }
            redirect('users.php');
        }
    } else {
        http_response_code(400);
        exit(t('Invalid action.'));
    }
}

$accountSql = 'SELECT u.id, u.member_id, u.branch_id, u.full_name, u.email, u.role, u.active, m.first_name, m.last_name, b.name AS branch_name, (p.user_id IS NOT NULL) AS has_profile_picture FROM users u LEFT JOIN members m ON m.id = u.member_id LEFT JOIN branches b ON b.id = COALESCE(u.branch_id, m.branch_id) LEFT JOIN user_profile_pictures p ON p.user_id = u.id';
if ($isHeadquarters) {
    $accounts = db()->query($accountSql . ' ORDER BY u.full_name')->fetchAll();
} else {
    $accountQuery = db()->prepare($accountSql . ' WHERE COALESCE(u.branch_id, m.branch_id) = ? ORDER BY u.full_name');
    $accountQuery->execute([$managedBranchId]);
    $accounts = $accountQuery->fetchAll();
}
$formValues = array_merge(['full_name' => '', 'email' => '', 'role' => '', 'branch_id' => $managedBranchId ?? '', 'member_id' => ''], $editingAccount ?? []);
foreach (['full_name', 'email', 'role', 'branch_id', 'member_id'] as $formField) {
    if (array_key_exists($formField, $_POST)) {
        $formValues[$formField] = $_POST[$formField];
    }
}
$formIsEditing = $editingAccount !== null;
$pageTitle = 'User accounts';
require __DIR__ . '/includes/header.php';
?>
<section class="content-heading">
    <div><p class="eyebrow"><?= e(t($isHeadquarters ? 'Access control' : 'Parish administration')) ?></p><h2><?= e(t($isHeadquarters ? 'User accounts' : 'Parish accounts')) ?></h2><p class="muted"><?= e(t($isHeadquarters ? 'Create accounts and assign the appropriate church role.' : 'Manage staff and member accounts for your parish. Archbishop and Archdeacon accounts are managed by Headquarters.')) ?></p></div>
</section>
<?php if ($notice !== ''): ?><div class="notice success"><?= e(t($notice)) ?></div><?php endif; ?>
<?php if ($error !== ''): ?><div class="notice error"><?= e($error) ?></div><?php endif; ?>
<div class="dashboard-grid user-grid">
    <section class="panel">
        <div class="panel-heading"><div><p class="eyebrow"><?= e(t($formIsEditing ? 'Edit access' : 'New access')) ?></p><h2><?= e(t($formIsEditing ? 'Edit account' : 'Create account')) ?></h2></div></div>
        <form method="post" class="record-form single-column">
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="<?= e($formIsEditing ? 'save' : 'create') ?>">
            <?php if ($formIsEditing): ?><input type="hidden" name="id" value="<?= e((string) $editingAccount['id']) ?>"><?php endif; ?>
            <label><?= e(t('Full name')) ?><input name="full_name" required value="<?= e((string) ($formValues['full_name'] ?? '')) ?>"></label>
            <label><?= e(t('Email')) ?><input type="email" name="email" required value="<?= e((string) ($formValues['email'] ?? '')) ?>"></label>
            <label><?= e(t('Role')) ?><select name="role" required><option value=""><?= e(t('Select…')) ?></option><?php foreach (($isHeadquarters ? ['bishop', 'archdeacon', 'priest', 'assistant_clergy', 'parish_council', 'secretary', 'treasurer', 'ministry_leader', 'member'] : ['priest', 'assistant_clergy', 'parish_council', 'secretary', 'treasurer', 'ministry_leader', 'member']) as $role): ?><option value="<?= e($role) ?>" <?= ((string) ($formValues['role'] ?? '') === $role) ? 'selected' : '' ?>><?= e(role_label($role)) ?></option><?php endforeach; ?></select></label>
            <?php if ($isHeadquarters): ?><label><?= e(t('Assigned parish / office')) ?> <span class="muted"><?= e(t('(choose Headquarters oversight for an officer overseeing all parishes; Member accounts are assigned automatically)')) ?></span><select name="branch_id"><option value=""><?= e(t('Headquarters oversight / all parishes')) ?></option><?php foreach ($branches as $branch): ?><?php if ((int) $branch['active'] || (int) $branch['id'] === (int) ($formValues['branch_id'] ?? 0)): ?><option value="<?= e((string) $branch['id']) ?>" <?= ((string) ($formValues['branch_id'] ?? '') === (string) $branch['id']) ? 'selected' : '' ?>><?= e($branch['name']) ?></option><?php endif; ?><?php endforeach; ?></select></label><?php else: ?><p class="muted"><?= e(t('Parish')) ?>: <?= e($branches[0]['name'] ?? '') ?></p><?php endif; ?>
            <label><?= e(t('Linked member')) ?> <span class="muted"><?= e(t('(required for Member role)')) ?></span><select name="member_id"><option value=""><?= e(t('No linked member')) ?></option><?php foreach ($members as $member): ?><option value="<?= e((string) $member['id']) ?>" <?= ((string) ($formValues['member_id'] ?? '') === (string) $member['id']) ? 'selected' : '' ?>><?= e($member['first_name'] . ' ' . $member['last_name']) ?></option><?php endforeach; ?></select></label>
            <label><?= e(t($formIsEditing ? 'New password (optional)' : 'Temporary password')) ?> <span class="muted"><?= e(t('(at least 12 characters)')) ?></span><input type="password" name="password" minlength="12" <?= $formIsEditing ? '' : 'required' ?> autocomplete="new-password"></label>
            <button class="button primary" type="submit"><?= e(t($formIsEditing ? 'Save account' : 'Create account')) ?></button>
            <?php if ($formIsEditing): ?><a class="button secondary" href="users.php"><?= e(t('Cancel')) ?></a><?php endif; ?>
        </form>
    </section>
    <section class="panel">
        <div class="panel-heading"><div><p class="eyebrow"><?= e(t('System access')) ?></p><h2><?= e(t('Existing accounts')) ?></h2></div></div>
        <?php if ($accounts): ?>
            <div class="account-list">
                <?php foreach ($accounts as $account): ?>
                    <article class="account-row">
                        <div class="account-identity"><?php if ($account['has_profile_picture']): ?><a href="profile_image.php?id=<?= e((string) $account['id']) ?>" target="_blank" rel="noopener" aria-label="<?= e(t('Open full-size profile picture')) ?>"><img class="profile-picture" src="profile_image.php?id=<?= e((string) $account['id']) ?>" alt=""></a><?php else: ?><span class="profile-picture placeholder" aria-hidden="true"><?= e(strtoupper(substr((string) $account['full_name'], 0, 1))) ?></span><?php endif; ?><div><h3><?= e($account['full_name']) ?></h3><p><?= e($account['email']) ?><?= $account['member_id'] ? ' · ' . e($account['first_name'] . ' ' . $account['last_name']) : '' ?></p><span class="role-pill"><?= e(role_label($account['role'])) ?></span><span class="status-dot <?= $account['active'] ? 'is-active' : '' ?>"><?= e(t($account['active'] ? 'Active' : 'Inactive')) ?></span>
                            <?php if (role_can_have_headquarters_access($account['role']) && $isHeadquarters): ?>
                                <form method="post" class="account-branch-form"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="assign_branch"><input type="hidden" name="id" value="<?= e((string) $account['id']) ?>"><label><?= e(t('Assigned parish / office')) ?><select name="branch_id"><option value="" <?= empty($account['branch_id']) ? 'selected' : '' ?>><?= e(t('Headquarters oversight / all parishes')) ?></option><?php foreach ($branches as $branch): ?><?php if ((int) $branch['active'] || (int) $branch['id'] === (int) $account['branch_id']): ?><option value="<?= e((string) $branch['id']) ?>" <?= (string) $account['branch_id'] === (string) $branch['id'] ? 'selected' : '' ?>><?= e($branch['name']) ?></option><?php endif; ?><?php endforeach; ?></select></label><button class="button secondary small" type="submit"><?= e(t('Save assignment')) ?></button></form>
                            <?php elseif (role_can_have_headquarters_access($account['role'])): ?><p><?= e(t('Assigned parish')) ?>: <?= e($account['branch_name'] ?: t('Headquarters oversight · All parishes')) ?></p>
            <?php else: ?><p><?= e(role_can_have_headquarters_access($account['role']) && empty($account['branch_id']) ? t('Headquarters oversight · All parishes') : ($account['branch_name'] ?: t('Headquarters'))) ?></p><?php endif; ?>
                        </div></div>
                        <?php if ((int) $account['id'] !== (int) $user['id'] && ($isHeadquarters || !in_array($account['role'], ['bishop', 'archdeacon'], true))): ?><div class="actions"><a href="users.php?edit=<?= e((string) $account['id']) ?>"><?= e(t('Edit')) ?></a><?php if ($isHeadquarters || !in_array($account['role'], parish_worker_roles(), true) || !(int) $account['active'] || empty($account['branch_id'])): ?><form method="post"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="toggle"><input type="hidden" name="id" value="<?= e((string) $account['id']) ?>"><button class="button secondary small" type="submit"><?= e(t($account['active'] ? 'Deactivate' : 'Activate')) ?></button></form><?php endif; ?><?php if ($isHeadquarters): ?><form method="post" onsubmit="return confirm('<?= e(t('Delete this user account?')) ?>')"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= e((string) $account['id']) ?>"><button class="button secondary small" type="submit"><?= e(t('Delete')) ?></button></form><?php endif; ?></div><?php endif; ?>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php else: ?><p class="empty-state"><?= e(t('No accounts found.')) ?></p><?php endif; ?>
    </section>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
