<?php
declare(strict_types=1);

require_once __DIR__ . '/config/app.php';
$user = require_login();
$isBishop = $user['role'] === 'bishop';
$isHeadquarters = empty($user['branch_id']);
if (!$isBishop && !user_has_all_branch_access($user)) {
    http_response_code(403);
    exit(t('This page is available to Headquarters supervisors and Bishops.'));
}
$requiredRoles = [
    'bishop' => 'Bishop',
    'archdeacon' => 'Archdeacon',
    'priest' => 'Priest / Pastor',
    'assistant_clergy' => 'Assistant clergy',
    'parish_council' => 'Parish council',
    'secretary' => 'Secretary',
    'treasurer' => 'Treasurer',
    'ministry_leader' => 'Ministry leader',
    'member' => 'Member',
];
if (!$isBishop) {
    $requiredRoles = [$user['role'] => role_label($user['role'])];
}

$branchQuery = $isHeadquarters
    ? db()->query("SELECT id, name, active FROM branches WHERE branch_type IN ('Parish', 'Branch') AND active = 1 ORDER BY name")
    : db()->prepare('SELECT id, name, active FROM branches WHERE id = ?');
if (!$isHeadquarters) {
    $branchQuery->execute([(int) $user['branch_id']]);
}
$parishes = $branchQuery->fetchAll();

$coverage = [];
if ($parishes) {
    $accountRows = db()->query("SELECT u.role, u.full_name, COALESCE(u.branch_id, m.branch_id) AS branch_id FROM users u LEFT JOIN members m ON m.id = u.member_id WHERE u.active = 1 AND u.role IN ('bishop','archdeacon','priest','assistant_clergy','parish_council','secretary','treasurer','ministry_leader','member') ORDER BY u.full_name")->fetchAll();
    foreach ($accountRows as $account) {
        foreach ($parishes as $parish) {
            if (!empty($account['branch_id']) && (int) $account['branch_id'] === (int) $parish['id']) {
                $coverage[(int) $parish['id']][$account['role']][] = $account['full_name'];
            }
        }
    }
}

$pageTitle = $isBishop ? 'Parish team' : 'Counterpart oversight';
require __DIR__ . '/includes/header.php';
?>
<section class="content-heading">
    <div><p class="eyebrow"><?= e(t($isHeadquarters ? 'Headquarters oversight' : 'Parish administration')) ?></p><h2><?= e(t($isBishop ? 'Parish team coverage' : 'Parish counterparts')) ?></h2><p class="muted"><?= e(t(!$isBishop ? 'Review your role counterparts across parishes.' : ($isHeadquarters ? 'Check that each parish has the required leadership, staff, and member accounts.' : 'Manage your parish team and keep local information accurate for Headquarters.'))) ?></p></div>
    <?php if ($isBishop): ?><a class="button primary" href="users.php"><?= e(t($isHeadquarters ? 'Manage all accounts' : 'Manage parish accounts')) ?></a><?php endif; ?>
</section>
<?php if (!$parishes): ?><section class="panel"><p class="empty-state"><?= e(t($isHeadquarters ? 'No active parishes are available.' : 'Your account is not assigned to a parish. Contact Headquarters.')) ?></p></section><?php endif; ?>
<div class="dashboard-grid parish-team-grid">
    <?php foreach ($parishes as $parish): ?>
        <?php
        $parishCoverage = $coverage[(int) $parish['id']] ?? [];
        $missing = [];
        foreach ($requiredRoles as $role => $label) {
            if (empty($parishCoverage[$role])) {
                $missing[] = $role;
            }
        }
        ?>
        <section class="panel parish-team-card">
            <div class="panel-heading"><div><p class="eyebrow"><?= e(t('Parish team')) ?></p><h2><?= e($parish['name']) ?></h2></div><span class="status-dot <?= $missing ? '' : 'is-active' ?>"><?= e(t($missing ? 'Incomplete' : 'Complete')) ?></span></div>
            <ul class="parish-role-list">
                <?php foreach ($requiredRoles as $role => $label): ?>
                    <?php $assigned = $parishCoverage[$role] ?? []; ?>
                    <li class="<?= $assigned ? 'is-covered' : 'is-missing' ?>"><span class="parish-role-status"><?= $assigned ? '✓' : '!' ?></span><span><strong><?= e($isBishop ? t($label) : $label) ?></strong><?php if ($role === 'member'): ?><small><?= e(t('Active member accounts')) ?></small><?php endif; ?></span><span class="parish-role-names"><?= e($assigned ? implode(', ', $assigned) : t('Missing')) ?></span></li>
                <?php endforeach; ?>
            </ul>
            <?php if ($missing): ?><p class="notice warning"><?= e(t($isBishop ? 'Missing roles' : 'Counterpart missing')) ?>: <?= e(implode(', ', array_map(static fn ($role) => $isBishop ? t($requiredRoles[$role]) : $requiredRoles[$role], $missing))) ?></p><?php elseif ($isBishop): ?><p class="notice success"><?= e(t('All required parish roles have active accounts.')) ?></p><?php else: ?><p class="notice success"><?= e(t('Counterpart assigned.')) ?></p><?php endif; ?>
        </section>
    <?php endforeach; ?>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
