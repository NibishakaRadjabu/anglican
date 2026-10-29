<?php
declare(strict_types=1);

require_once __DIR__ . '/config/app.php';
$user = require_login();
$pageTitle = 'Dashboard';
$branchId = user_has_all_branch_access($user) ? 0 : (int) ($user['branch_id'] ?? 0);
$branchWhere = $branchId > 0 ? ' AND branch_id = ?' : '';
$branchParams = $branchId > 0 ? [$branchId] : [];

$stats = [];
if ($user['role'] === 'member') {
    $memberId = (int) ($user['member_id'] ?? 0);
    if ($memberId > 0) {
        $statement = db()->prepare('SELECT COUNT(*) FROM attendance WHERE member_id = ? AND status = ?');
        $statement->execute([$memberId, 'Present']);
        $stats[] = ['label' => t('Services attended'), 'value' => (string) $statement->fetchColumn(), 'icon' => '✦'];
        $statement = db()->prepare('SELECT COUNT(*) FROM baptisms WHERE member_id = ?');
        $statement->execute([$memberId]);
        $stats[] = ['label' => t('Baptism records'), 'value' => (string) $statement->fetchColumn(), 'icon' => '⌂'];
    }
} else {
    if (in_array($user['role'], church_record_manager_roles(), true)) {
        $statement = db()->prepare("SELECT COUNT(*) FROM members WHERE status = 'Active'{$branchWhere}");
        $statement->execute($branchParams);
        $stats[] = ['label' => t('Active members'), 'value' => number_format((int) $statement->fetchColumn()), 'icon' => '♧'];
        $statement = db()->prepare("SELECT COUNT(*) FROM clergy WHERE status = 'Active'{$branchWhere}");
        $statement->execute($branchParams);
        $stats[] = ['label' => t('Clergy & workers'), 'value' => number_format((int) $statement->fetchColumn()), 'icon' => '✦'];
    }
    if (in_array($user['role'], church_record_manager_roles(), true)) {
        $statement = db()->prepare("SELECT COUNT(*) FROM events WHERE starts_at >= NOW(){$branchWhere}");
        $statement->execute($branchParams);
        $stats[] = ['label' => t('Upcoming events'), 'value' => number_format((int) $statement->fetchColumn()), 'icon' => '◷'];
    }
    if (in_array($user['role'], church_record_manager_roles(), true)) {
        $monthStart = date('Y-m-01');
        $statement = db()->prepare("SELECT COALESCE(SUM(amount), 0) FROM offerings WHERE received_on >= ?{$branchWhere}");
        $statement->execute([$monthStart, ...$branchParams]);
        $stats[] = ['label' => t('Offerings this month'), 'value' => number_format((float) $statement->fetchColumn(), 2), 'icon' => '$'];
    }
}

$canSeeAttendance = in_array($user['role'], [...church_record_manager_roles(), 'member'], true);
$attendanceDays = [];
if ($canSeeAttendance) {
    $attendanceStart = date('Y-m-d', strtotime('-6 days'));
    if ($user['role'] === 'member') {
        $statement = db()->prepare("SELECT service_date, COUNT(*) AS total FROM attendance WHERE service_date >= ? AND status = 'Present' AND member_id = ?{$branchWhere} GROUP BY service_date");
        $statement->execute([$attendanceStart, (int) ($user['member_id'] ?? 0), ...$branchParams]);
    } else {
        $statement = db()->prepare("SELECT service_date, COUNT(*) AS total FROM attendance WHERE service_date >= ? AND status = 'Present'{$branchWhere} GROUP BY service_date");
        $statement->execute([$attendanceStart, ...$branchParams]);
    }
    $attendanceCounts = [];
    foreach ($statement->fetchAll() as $row) {
        $attendanceCounts[$row['service_date']] = (int) $row['total'];
    }
    for ($offset = 6; $offset >= 0; $offset--) {
        $day = date('Y-m-d', strtotime("-{$offset} days"));
        $attendanceDays[] = ['label' => localized_weekday($day), 'count' => $attendanceCounts[$day] ?? 0];
    }
}

$growthMonths = [];
if (in_array($user['role'], church_record_manager_roles(), true)) {
    $growthStart = date('Y-m-01', strtotime('-5 months'));
    $statement = db()->prepare("SELECT DATE_FORMAT(joined_on, '%Y-%m') AS month, COUNT(*) AS total FROM members WHERE joined_on >= ?{$branchWhere} GROUP BY month");
    $statement->execute([$growthStart, ...$branchParams]);
    $growthCounts = [];
    foreach ($statement->fetchAll() as $row) {
        $growthCounts[$row['month']] = (int) $row['total'];
    }
    for ($offset = 5; $offset >= 0; $offset--) {
        $month = date('Y-m', strtotime(date('Y-m-01') . " -{$offset} months"));
        $growthMonths[] = ['label' => localized_month($month . '-01'), 'count' => $growthCounts[$month] ?? 0];
    }
}

$financeSummary = null;
if (in_array($user['role'], church_record_manager_roles(), true)) {
    $monthStart = date('Y-m-01');
    $statement = db()->prepare("SELECT COALESCE(SUM(amount), 0) FROM offerings WHERE received_on >= ?{$branchWhere}");
    $statement->execute([$monthStart, ...$branchParams]);
    $income = (float) $statement->fetchColumn();
    $statement = db()->prepare("SELECT COALESCE(SUM(amount), 0) FROM expenses WHERE spent_on >= ?{$branchWhere}");
    $statement->execute([$monthStart, ...$branchParams]);
    $expenses = (float) $statement->fetchColumn();
    $financeSummary = ['income' => $income, 'expenses' => $expenses, 'balance' => $income - $expenses];
}

$eventsStatement = db()->prepare("SELECT title, event_type, starts_at, location FROM events WHERE starts_at >= NOW(){$branchWhere} ORDER BY starts_at LIMIT 5");
$eventsStatement->execute($branchParams);
$events = $eventsStatement->fetchAll();
$announcementsStatement = db()->prepare('SELECT title, message, publish_at FROM announcements WHERE (publish_at IS NULL OR publish_at <= NOW())' . $branchWhere . ' ORDER BY COALESCE(publish_at, created_at) DESC LIMIT 4');
$announcementsStatement->execute($branchParams);
$announcements = $announcementsStatement->fetchAll();

require __DIR__ . '/includes/header.php';
?>
<section class="welcome-panel">
    <div><p class="eyebrow light"><?= e(t('Welcome')) ?></p><h2><?= e($user['full_name']) ?></h2><p><?= e(t('Your church community at a glance.')) ?></p></div>
    <div class="welcome-symbol">✧</div>
</section>
<section class="stats-grid" aria-label="<?= e(t('Summary statistics')) ?>">
    <?php foreach ($stats as $stat): ?>
        <article class="stat-card"><span class="stat-icon"><?= e($stat['icon']) ?></span><div><p><?= e($stat['label']) ?></p><strong><?= e($stat['value']) ?></strong></div></article>
    <?php endforeach; ?>
</section>
<?php if ($attendanceDays || $growthMonths || $financeSummary): ?>
    <section class="reports-grid" aria-label="<?= e(t('Reports')) ?>">
        <?php if ($attendanceDays): ?>
            <?php $attendanceMax = max(1, max(array_column($attendanceDays, 'count'))); ?>
            <article class="panel chart-panel"><div class="panel-heading"><div><p class="eyebrow"><?= e(t('Last seven days')) ?></p><h2><?= e(t('Attendance')) ?></h2></div><a href="records.php?module=attendance"><?= e(t('View records')) ?></a></div><div class="bar-chart"><?php foreach ($attendanceDays as $day): ?><div class="bar-item"><span class="bar-value"><?= e((string) $day['count']) ?></span><div class="bar-track"><span style="height: <?= max(4, (int) round($day['count'] / $attendanceMax * 100)) ?>%"></span></div><span class="bar-label"><?= e($day['label']) ?></span></div><?php endforeach; ?></div></article>
        <?php endif; ?>
        <?php if ($growthMonths): ?>
            <?php $growthMax = max(1, max(array_column($growthMonths, 'count'))); ?>
            <article class="panel chart-panel"><div class="panel-heading"><div><p class="eyebrow"><?= e(t('Membership growth')) ?></p><h2><?= e(t('New members')) ?></h2></div><a href="records.php?module=members"><?= e(t('View members')) ?></a></div><div class="bar-chart"><?php foreach ($growthMonths as $month): ?><div class="bar-item"><span class="bar-value"><?= e((string) $month['count']) ?></span><div class="bar-track"><span class="growth-bar" style="height: <?= max(4, (int) round($month['count'] / $growthMax * 100)) ?>%"></span></div><span class="bar-label"><?= e($month['label']) ?></span></div><?php endforeach; ?></div></article>
        <?php endif; ?>
        <?php if ($financeSummary): ?>
            <article class="panel finance-panel"><div class="panel-heading"><div><p class="eyebrow"><?= e(t('This month')) ?></p><h2><?= e(t('Financial summary')) ?></h2></div><a href="records.php?module=offerings"><?= e(t('View records')) ?></a></div><div class="finance-lines"><div><span><?= e(t('Income')) ?></span><strong><?= e(number_format($financeSummary['income'], 2)) ?></strong></div><div><span><?= e(t('Expenses')) ?></span><strong><?= e(number_format($financeSummary['expenses'], 2)) ?></strong></div><div class="finance-balance"><span><?= e(t('Net balance')) ?></span><strong><?= e(number_format($financeSummary['balance'], 2)) ?></strong></div></div></article>
        <?php endif; ?>
    </section>
<?php endif; ?>
<div class="dashboard-grid">
    <section class="panel">
        <div class="panel-heading"><div><p class="eyebrow"><?= e(t('Church calendar')) ?></p><h2><?= e(t('Upcoming events')) ?></h2></div><a href="records.php?module=events"><?= e(t('View all')) ?></a></div>
        <?php if ($events): ?>
            <div class="event-list">
                <?php foreach ($events as $event): ?>
                    <article class="event-row"><div class="event-date"><strong><?= e(date('d', strtotime($event['starts_at']))) ?></strong><span><?= e(localized_month($event['starts_at'])) ?></span></div><div><h3><?= e($event['title']) ?></h3><p><?= e(date('g:i A', strtotime($event['starts_at']))) ?><?= $event['location'] ? ' · ' . e($event['location']) : '' ?></p></div><span class="tag"><?= e(t($event['event_type'] ?: 'Event')) ?></span></article>
                <?php endforeach; ?>
            </div>
        <?php else: ?><p class="empty-state"><?= e(t('No upcoming events have been scheduled.')) ?></p><?php endif; ?>
    </section>
    <section class="panel">
        <div class="panel-heading"><div><p class="eyebrow"><?= e(t('Stay connected')) ?></p><h2><?= e(t('Announcements')) ?></h2></div><a href="records.php?module=announcements"><?= e(t('View all')) ?></a></div>
        <?php if ($announcements): ?>
            <div class="announcement-list">
                <?php foreach ($announcements as $announcement): ?>
                    <article><h3><?= e($announcement['title']) ?></h3><p><?= nl2br(e($announcement['message'])) ?></p><?php if ($announcement['publish_at']): ?><small><?= e(localized_date(date('Y-m-d', strtotime($announcement['publish_at'])))) ?></small><?php endif; ?></article>
                <?php endforeach; ?>
            </div>
        <?php else: ?><p class="empty-state"><?= e(t('There are no announcements yet.')) ?></p><?php endif; ?>
    </section>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
