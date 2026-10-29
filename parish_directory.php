<?php
declare(strict_types=1);

require_once __DIR__ . '/config/app.php';
$user = require_login();
if ($user['role'] !== 'bishop' || !empty($user['branch_id'])) {
    http_response_code(403);
    exit(t('Only a Headquarters Bishop can view the parish directory.'));
}

$parishes = db()->query("SELECT id, name, location, active FROM branches WHERE branch_type IN ('Parish', 'Branch') ORDER BY name")->fetchAll();
$directory = [];
foreach ($parishes as $parish) {
    $directory[(int) $parish['id']] = ['parish' => $parish, 'members' => [], 'leaders' => []];
}

$members = db()->query(
    'SELECT m.id, m.branch_id, m.first_name, m.last_name, m.status, u.id AS user_id, (p.user_id IS NOT NULL) AS has_picture
     FROM members m
     LEFT JOIN users u ON u.member_id = m.id
     LEFT JOIN user_profile_pictures p ON p.user_id = u.id
     ORDER BY m.last_name, m.first_name'
)->fetchAll();
foreach ($members as $member) {
    $branchId = (int) $member['branch_id'];
    if (isset($directory[$branchId])) {
        $directory[$branchId]['members'][] = $member;
    }
}

$leaders = db()->query(
    "SELECT u.id, u.branch_id, u.full_name, u.role, (p.user_id IS NOT NULL) AS has_picture
     FROM users u
     LEFT JOIN user_profile_pictures p ON p.user_id = u.id
     WHERE u.active = 1 AND u.branch_id IS NOT NULL AND u.role <> 'member'
     ORDER BY FIELD(u.role, 'bishop', 'archdeacon', 'priest', 'assistant_clergy', 'parish_council', 'secretary', 'treasurer', 'ministry_leader'), u.full_name"
)->fetchAll();
foreach ($leaders as $leader) {
    $branchId = (int) $leader['branch_id'];
    if (isset($directory[$branchId])) {
        $directory[$branchId]['leaders'][] = $leader;
    }
}

$pageTitle = 'Parish directory';
require __DIR__ . '/includes/header.php';
?>
<section class="content-heading">
    <div>
        <p class="eyebrow"><?= e(t('Headquarters oversight')) ?></p>
        <h2><?= e(t('Parishes, members and leaders')) ?></h2>
        <p class="muted"><?= e(t('Browse parish members and active leaders. Profile pictures appear when an account has one.')) ?></p>
    </div>
</section>

<?php if (!$directory): ?>
    <section class="panel"><p class="empty-state"><?= e(t('No parishes are available.')) ?></p></section>
<?php else: ?>
    <div class="parish-directory">
        <?php foreach ($directory as $entry): ?>
            <?php $parish = $entry['parish']; ?>
            <section class="panel directory-parish">
                <div class="panel-heading directory-parish-heading">
                    <div>
                        <p class="eyebrow"><?= e(t('Parish')) ?><?= $parish['location'] ? ' · ' . e($parish['location']) : '' ?></p>
                        <h2><?= e($parish['name']) ?></h2>
                    </div>
                    <span class="status-dot <?= (int) $parish['active'] === 1 ? 'is-active' : '' ?>"><?= e(t((int) $parish['active'] === 1 ? 'Active' : 'Inactive')) ?></span>
                </div>

                <div class="directory-columns">
                    <section aria-labelledby="leaders-<?= e((string) $parish['id']) ?>">
                        <div class="directory-section-heading"><h3 id="leaders-<?= e((string) $parish['id']) ?>"><?= e(t('Leaders')) ?></h3><span><?= count($entry['leaders']) ?></span></div>
                        <?php if ($entry['leaders']): ?>
                            <ul class="directory-people">
                                <?php foreach ($entry['leaders'] as $leader): ?>
                                    <li>
                                        <?php if ($leader['has_picture']): ?><a class="directory-avatar-link" href="profile_image.php?id=<?= e((string) $leader['id']) ?>" target="_blank" rel="noopener" aria-label="<?= e(t('Open full-size profile picture')) ?>"><img class="directory-avatar" src="profile_image.php?id=<?= e((string) $leader['id']) ?>" alt=""></a>
                                        <?php else: ?><span class="directory-avatar directory-avatar-placeholder" aria-hidden="true"><?= e(strtoupper(substr((string) $leader['full_name'], 0, 1))) ?></span><?php endif; ?>
                                        <span class="directory-person-copy"><strong><?= e($leader['full_name']) ?></strong><small><?= e(role_label((string) $leader['role'])) ?></small></span>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        <?php else: ?><p class="directory-empty"><?= e(t('No active leaders assigned.')) ?></p><?php endif; ?>
                    </section>

                    <section aria-labelledby="members-<?= e((string) $parish['id']) ?>">
                        <div class="directory-section-heading"><h3 id="members-<?= e((string) $parish['id']) ?>"><?= e(t('Members')) ?></h3><span><?= count($entry['members']) ?></span></div>
                        <?php if ($entry['members']): ?>
                            <ul class="directory-people">
                                <?php foreach ($entry['members'] as $member): ?>
                                    <?php $memberName = trim($member['first_name'] . ' ' . $member['last_name']); ?>
                                    <li>
                                        <?php if ($member['has_picture'] && $member['user_id']): ?><a class="directory-avatar-link" href="profile_image.php?id=<?= e((string) $member['user_id']) ?>" target="_blank" rel="noopener" aria-label="<?= e(t('Open full-size profile picture')) ?>"><img class="directory-avatar" src="profile_image.php?id=<?= e((string) $member['user_id']) ?>" alt=""></a>
                                        <?php else: ?><span class="directory-avatar directory-avatar-placeholder" aria-hidden="true"><?= e(strtoupper(substr($memberName, 0, 1))) ?></span><?php endif; ?>
                                        <span class="directory-person-copy"><strong><?= e($memberName) ?></strong><small><?= e(t((string) $member['status'])) ?></small></span>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        <?php else: ?><p class="directory-empty"><?= e(t('No members recorded.')) ?></p><?php endif; ?>
                    </section>
                </div>
            </section>
        <?php endforeach; ?>
    </div>
<?php endif; ?>
<?php require __DIR__ . '/includes/footer.php'; ?>
