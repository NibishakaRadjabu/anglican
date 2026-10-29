<?php
declare(strict_types=1);

require_once __DIR__ . '/config/app.php';

$user = require_login();
if (!in_array($user['role'], church_record_manager_roles(), true)) {
    http_response_code(403);
    exit(t('You do not have permission to view this area.'));
}

$pageTitle = 'Leadership guide';
require __DIR__ . '/includes/header.php';
?>
<section class="content-heading">
    <div>
        <p class="eyebrow"><?= e(t('Parish governance')) ?></p>
        <h2><?= e(t('Leadership structure and responsibilities')) ?></h2>
        <p class="muted"><?= e(t('A practical reference for planning, accountability and diocesan reporting.')) ?></p>
    </div>
    <a class="button primary" href="activities.php"><?= e(t('Open activity register')) ?></a>
</section>
<div class="reports-grid">
    <section class="panel">
        <div class="panel-heading"><div><p class="eyebrow"><?= e(t('Recommended working model')) ?></p><h2><?= e(t('Leadership structure')) ?></h2></div></div>
        <ol class="leadership-list">
            <li><strong><?= e(t('Diocesan Bishop and administration')) ?></strong><span><?= e(t('Diocesan oversight, guidance and reporting requirements.')) ?></span></li>
            <li><strong><?= e(t('Parish Priest / Rector / Vicar')) ?></strong><span><?= e(t('Spiritual leadership, annual programme coordination, supervision and reporting to the diocese.')) ?></span></li>
            <li><strong><?= e(t('Parish Council')) ?></strong><span><?= e(t('Planning, decisions, review and oversight within the authority granted by diocesan rules.')) ?></span></li>
            <li><strong><?= e(t('Secretary and Treasurer')) ?></strong><span><?= e(t('Records, minutes, correspondence, budgets, financial records and consolidated reports.')) ?></span></li>
            <li><strong><?= e(t('Ministry and activity leaders')) ?></strong><span><?= e(t('Plan and deliver worship, evangelism, Christian education, youth, fellowship, choir, pastoral care and development activities.')) ?></span></li>
            <li><strong><?= e(t('Congregation, volunteers and local groups')) ?></strong><span><?= e(t('Participate in worship, fellowship, mission and community service.')) ?></span></li>
        </ol>
    </section>
    <section class="panel">
        <div class="panel-heading"><div><p class="eyebrow"><?= e(t('Responsibilities')) ?></p><h2><?= e(t('Who does what')) ?></h2></div></div>
        <dl class="responsibility-list">
            <dt><?= e(t('Parish Priest')) ?></dt><dd><?= e(t('Coordinates parish activities, provides pastoral leadership, assigns responsibilities and reports to the diocese.')) ?></dd>
            <dt><?= e(t('Parish Council')) ?></dt><dd><?= e(t('Reviews the plan, resources, activity progress and decisions within its authority.')) ?></dd>
            <dt><?= e(t('Parish Secretary')) ?></dt><dd><?= e(t('Maintains the calendar, meeting minutes, activity reports, attendance summaries and correspondence.')) ?></dd>
            <dt><?= e(t('Treasurer')) ?></dt><dd><?= e(t('Tracks budgets and expenditure, retains supporting records and prepares financial summaries.')) ?></dd>
            <dt><?= e(t('Ministry leaders')) ?></dt><dd><?= e(t('Prepare plans, coordinate teams, deliver activities and submit completion reports.')) ?></dd>
            <dt><?= e(t('Diocesan administration')) ?></dt><dd><?= e(t('Receives parish reports and provides oversight through the channels and formats set by the diocese.')) ?></dd>
        </dl>
    </section>
</div>
<section class="panel">
    <div class="panel-heading"><div><p class="eyebrow"><?= e(t('Monthly management cycle')) ?></p><h2><?= e(t('Plan, implement, monitor and report')) ?></h2></div></div>
    <div class="stats-grid">
        <article class="stat-card"><div><p><?= e(t('Week 1')) ?></p><strong><?= e(t('Plan and approve')) ?></strong><span><?= e(t('Confirm activities, leaders, dates and available resources.')) ?></span></div></article>
        <article class="stat-card"><div><p><?= e(t('Week 2')) ?></p><strong><?= e(t('Implement and coordinate')) ?></strong><span><?= e(t('Deliver activities and track participation and resources.')) ?></span></div></article>
        <article class="stat-card"><div><p><?= e(t('Week 3')) ?></p><strong><?= e(t('Monitor and support')) ?></strong><span><?= e(t('Address scheduling, staffing, funding and logistics issues.')) ?></span></div></article>
        <article class="stat-card"><div><p><?= e(t('Week 4')) ?></p><strong><?= e(t('Report and evaluate')) ?></strong><span><?= e(t('Submit reports, reconcile expenditure and prepare the next month.')) ?></span></div></article>
    </div>
</section>
<section class="panel">
    <div class="panel-heading"><div><p class="eyebrow"><?= e(t('Parish-specific leadership')) ?></p><h2><?= e(t('Each parish maintains its own structure')) ?></h2></div></div>
    <p><?= e(t('Assign each parish leader an account for that parish and maintain its clergy and workers in the parish records. Parish staff can manage their own parish activities and records; diocesan leadership can oversee information across parishes.')) ?></p>
    <p class="muted"><?= e(t('This guide is shared as a reference. Local parish structures and titles should follow the relevant diocesan rules.')) ?></p>
</section>
<section class="panel">
    <div class="panel-heading"><div><p class="eyebrow"><?= e(t('Reporting flow')) ?></p><h2><?= e(t('Parish to diocesan headquarters')) ?></h2></div></div>
    <p><?= e(t('Activity leader submits a report; the secretary and treasurer consolidate and verify activity and financial information; the parish priest and council review it; the parish sends the consolidated report to diocesan headquarters using the official channel. Reports recorded in this system are visible immediately to authorized parish users and headquarters staff.')) ?></p>
    <p class="muted"><?= e(t('Protect confidential pastoral information and personal data. Record only the minimum necessary details in activity reports.')) ?></p>
    <div class="notice"><?= e(t('This is a recommended working model, not a verified official organigramme or binding reporting standard for every Anglican diocese in the DRC. Confirm titles, authority, approvals, safeguarding, finance and reporting deadlines with the relevant diocesan constitution and administration.')) ?></div>
</section>
<section class="panel">
    <div class="panel-heading"><div><p class="eyebrow"><?= e(t('Monitoring indicators')) ?></p><h2><?= e(t('Review results, not only meeting counts')) ?></h2></div></div>
    <ul>
        <li><?= e(t('Percentage of planned activities completed, reviewed monthly.')) ?></li>
        <li><?= e(t('Percentage of activities with a named responsible leader, reviewed monthly.')) ?></li>
        <li><?= e(t('Ministry reports submitted on time and financial expenditure supported by records.')) ?></li>
        <li><?= e(t('Christian formation, evangelism, pastoral care and development results, reviewed quarterly.')) ?></li>
    </ul>
    <p class="muted"><?= e(t('Suggested starting targets such as 90% of planned activities completed and 100% assigned to a leader are proposals for local approval, not official Anglican performance standards.')) ?></p>
</section>
<?php require __DIR__ . '/includes/footer.php'; ?>
