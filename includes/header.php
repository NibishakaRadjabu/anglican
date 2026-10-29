<?php
declare(strict_types=1);

$user = require_login();
$pageTitle = $pageTitle ?? 'Dashboard';
$languageTitles = [
    'members' => 'Members',
    'clergy' => 'Clergy & workers',
    'attendance' => 'Attendance',
    'baptisms' => 'Baptisms',
    'confirmations' => 'Confirmations',
    'marriages' => 'Marriages',
    'funerals' => 'Funerals',
    'offerings' => 'Tithes & offerings',
    'expenses' => 'Expenses',
    'budgets' => 'Budgets',
    'events' => 'Events',
    'announcements' => 'Announcements',
];
?>
<!doctype html>
<html lang="<?= e(current_language()) ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e(t($pageTitle)) ?> · <?= e(t('Anglican Church Management')) ?></title>
    <link rel="stylesheet" href="assets/css/app.css">
</head>
<body class="app-page">
<aside class="sidebar">
    <a class="brand" href="dashboard.php"><span class="brand-mark small">AC</span><span><?= e(t('Church')) ?><span class="brand-sub"><?= e(t('Management')) ?></span></span></a>
    <nav class="nav-links" aria-label="<?= e(t('Main navigation')) ?>">
        <a href="dashboard.php" class="<?= $pageTitle === 'Dashboard' ? 'active' : '' ?>"><?= e(t('Overview')) ?></a>
        <?php foreach (module_definitions() as $key => $definition): ?>
            <?php if (in_array($user['role'], $definition['read'], true)): ?>
                <a href="records.php?module=<?= e($key) ?>" class="<?= ($activeModule ?? '') === $key ? 'active' : '' ?>"><?= e(t($languageTitles[$key] ?? $definition['title'])) ?></a>
            <?php endif; ?>
        <?php endforeach; ?>
        <?php if ($user['role'] === 'bishop'): ?><a href="branches.php" class="<?= ($pageTitle === 'Parishes' ? 'active' : '') ?>"><?= e(t(empty($user['branch_id']) ? 'Parishes' : 'Request a parish')) ?></a><?php endif; ?>
        <?php if ($user['role'] === 'bishop' || user_has_all_branch_access($user)): ?><a href="staffing.php" class="<?= in_array($pageTitle, ['Parish team', 'Counterpart oversight'], true) ? 'active' : '' ?>"><?= e(t($user['role'] === 'bishop' ? 'Parish team' : 'My parish counterparts')) ?></a><?php endif; ?>
        <?php if ($user['role'] === 'bishop'): ?><a href="users.php" class="<?= in_array($pageTitle, ['User accounts', 'Parish accounts'], true) ? 'active' : '' ?>"><?= e(t(empty($user['branch_id']) ? 'User accounts' : 'Parish accounts')) ?></a><?php endif; ?>
        <?php if ($user['role'] === 'bishop' && empty($user['branch_id'])): ?><a href="parish_directory.php" class="<?= $pageTitle === 'Parish directory' ? 'active' : '' ?>"><?= e(t('Parish directory')) ?></a><?php endif; ?>
        <?php if (($user['role'] === 'bishop' && empty($user['branch_id'])) || user_is_parish_worker($user)): ?><a href="staff.php" class="<?= ($pageTitle === 'Staff departures' ? 'active' : '') ?>"><?= e(t($user['role'] === 'bishop' ? 'Staff departures' : 'Submit resignation')) ?></a><?php endif; ?>
        <?php if (in_array($user['role'], church_record_manager_roles(), true)): ?><a href="activities.php" class="<?= ($pageTitle === 'Parish activity plan' ? 'active' : '') ?>"><?= e(t('Parish activity plan')) ?></a><a href="leadership.php" class="<?= ($pageTitle === 'Leadership guide' ? 'active' : '') ?>"><?= e(t('Leadership guide')) ?></a><?php endif; ?>
    </nav>
    <div class="sidebar-bottom">
        <?php
        $avatarCheck = db()->prepare('SELECT 1 FROM user_profile_pictures WHERE user_id = ?');
        $avatarCheck->execute([(int) $user['id']]);
        $hasProfilePicture = (bool) $avatarCheck->fetchColumn();
        ?>
        <div class="sidebar-profile">
            <?php if ($hasProfilePicture): ?><a href="profile_image.php?id=<?= e((string) $user['id']) ?>" target="_blank" rel="noopener" aria-label="<?= e(t('Open full-size profile picture')) ?>"><img class="profile-picture" src="profile_image.php?id=<?= e((string) $user['id']) ?>" alt=""></a>
            <?php else: ?><span class="profile-picture placeholder" aria-hidden="true"><?= e(strtoupper(substr((string) $user['full_name'], 0, 1))) ?></span><?php endif; ?>
            <a class="user-name" href="profile.php"><?= e($user['full_name']) ?><span class="profile-link-label"><?= e(t('My profile')) ?></span></a>
        </div>
        <span class="role-pill"><?= e(role_label($user['role'])) ?></span>
        <?php if (!empty($user['branch_name'])): ?><span class="role-pill"><?= e($user['branch_name']) ?></span><?php endif; ?>
        <?php if (user_has_all_branch_access($user)): ?><span class="role-pill"><?= e(t('Headquarters oversight · All parishes')) ?></span><?php endif; ?>
        <form method="post" action="language.php" class="language-form">
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="return_to" value="<?= e($_SERVER['REQUEST_URI'] ?? '/dashboard.php') ?>">
            <label for="language-select"><?= e(t('Language')) ?></label>
            <select id="language-select" name="language" onchange="this.form.submit()">
                <?php foreach (available_languages() as $code => $label): ?>
                    <option value="<?= e($code) ?>" <?= current_language() === $code ? 'selected' : '' ?>><?= e($label) ?></option>
                <?php endforeach; ?>
            </select>
            <noscript><button class="language-submit" type="submit"><?= e(t('Apply')) ?></button></noscript>
        </form>
        <form method="post" action="logout.php">
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
            <button class="logout-button" type="submit"><?= e(t('Sign out')) ?></button>
        </form>
    </div>
</aside>
<main class="main-content">
    <header class="topbar"><div><p class="eyebrow"><?= e(t('Parish administration')) ?></p><h1><?= e(t($pageTitle)) ?></h1></div><div class="topbar-actions"><div class="notification-center" data-feed="notifications_feed.php" data-mark-read="notifications_mark_read.php" data-csrf="<?= e(csrf_token()) ?>" data-empty="<?= e(t('No new notifications.')) ?>">
        <button class="notification-toggle" type="button" aria-expanded="false" aria-controls="notification-popover"><svg class="notification-icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M18 8a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9M10 21h4" /></svg><?= e(t('Notifications')) ?><span class="notification-badge" hidden>0</span></button>
        <section class="notification-popover" id="notification-popover" aria-label="<?= e(t('Notifications')) ?>" hidden><div class="notification-heading"><strong><?= e(t('Notifications')) ?></strong></div><div class="notification-list" aria-live="polite"><p class="notification-empty"><?= e(t('No new notifications.')) ?></p></div></section>
        <div class="notification-toast" role="status" aria-live="polite" hidden></div>
    </div><span class="today"><?= e(localized_date(date('Y-m-d'))) ?></span></div></header>
