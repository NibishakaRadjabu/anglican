<?php
declare(strict_types=1);

require_once __DIR__ . '/config/app.php';

$user = require_login();
if (!in_array($user['role'], church_record_manager_roles(), true)) {
    http_response_code(403);
    exit(t('You do not have permission to view this area.'));
}

$isAllBranchUser = user_has_all_branch_access($user);
$canSupervise = in_array($user['role'], activity_supervisor_roles(), true)
    || ($user['role'] === 'ministry_leader' && user_has_all_branch_access($user));
$assignedBranchId = (int) ($user['branch_id'] ?? 0) ?: null;
$branches = db()->query('SELECT id, name, active FROM branches ORDER BY name')->fetchAll();
$branchesById = [];
foreach ($branches as $branch) {
    $branchesById[(int) $branch['id']] = $branch;
}

$requestedBranchId = filter_input(INPUT_GET, 'branch_id', FILTER_VALIDATE_INT) ?: null;
$branchContextId = $isAllBranchUser ? $requestedBranchId : $assignedBranchId;
if ($branchContextId !== null && !isset($branchesById[$branchContextId])) {
    http_response_code(400);
    exit(t('Choose a valid parish.'));
}

$error = '';
$notice = $_SESSION['flash'] ?? '';
unset($_SESSION['flash']);
$action = (string) ($_GET['action'] ?? 'list');
$activityId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT) ?: 0;
$existing = null;
$statusLabels = [
    'planned' => 'Planned',
    'in_progress' => 'In progress',
    'completed' => 'Completed',
    'postponed' => 'Postponed',
    'cancelled' => 'Cancelled',
];
$weekdays = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];
$months = [
    1 => 'January', 2 => 'February', 3 => 'March', 4 => 'April',
    5 => 'May', 6 => 'June', 7 => 'July', 8 => 'August',
    9 => 'September', 10 => 'October', 11 => 'November', 12 => 'December',
];
$fields = [
    'title' => '',
    'ministry' => '',
    'schedule_type' => 'once',
    'plan_year' => '2027',
    'planned_month' => '',
    'weekday' => '',
    'planned_date' => '',
    'starts_at' => '',
    'location' => '',
    'objective' => '',
    'expected_result' => '',
    'supporting_team' => '',
    'expected_participants' => '',
    'budget' => '',
    'funding_source' => '',
    'materials' => '',
    'status' => 'planned',
    'actual_expenditure' => '',
    'actual_participants' => '',
    'completion_evidence' => '',
    'report_notes' => '',
    'challenges' => '',
    'follow_up' => '',
];
$values = $fields;
$characterLength = static fn (string $value): int => function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);

$scopeSql = '';
$scopeParams = [];
if (!$isAllBranchUser) {
    $scopeSql = 'a.branch_id = ?';
    $scopeParams[] = $assignedBranchId;
} elseif ($branchContextId !== null) {
    $scopeSql = 'a.branch_id = ?';
    $scopeParams[] = $branchContextId;
}

$loadActivity = static function (int $id) use ($scopeSql, $scopeParams): ?array {
    $sql = 'SELECT a.*, b.name AS branch_name, u.full_name AS responsible_name
        FROM activities a
        JOIN branches b ON b.id = a.branch_id
        JOIN users u ON u.id = a.responsible_user_id
        WHERE a.id = ?';
    $params = [$id];
    if ($scopeSql !== '') {
        $sql .= ' AND (' . $scopeSql . ')';
        $params = [...$params, ...$scopeParams];
    }
    $statement = db()->prepare($sql);
    $statement->execute($params);
    $record = $statement->fetch();

    return $record ?: null;
};

$canManageActivity = static function (array $record) use ($user, $canSupervise): bool {
    return $canSupervise || (int) $record['responsible_user_id'] === (int) $user['id'];
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $postAction = (string) ($_POST['action'] ?? '');
    $postedId = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT) ?: 0;
    $existing = $postedId > 0 ? $loadActivity($postedId) : null;

    if ($postedId > 0 && !$existing) {
        http_response_code(404);
        exit(t('Activity not found.'));
    }

    if ($postAction === 'delete') {
        if (!$existing || !$canManageActivity($existing)) {
            http_response_code(403);
            exit(t('You do not have permission to manage this activity.'));
        }
        $statement = db()->prepare('DELETE FROM activities WHERE id = ?');
        $statement->execute([$postedId]);
        if ($statement->rowCount() > 0) {
            notify_branch_action((int) $existing['branch_id'], $user, 'Deleted', (string) $existing['title'], 'activities.php');
        }
        $_SESSION['flash'] = 'Activity deleted.';
        redirect('activities.php');
    }

    if ($postAction !== 'save') {
        http_response_code(400);
        exit(t('Invalid action.'));
    }
    if ($existing && !$canManageActivity($existing)) {
        http_response_code(403);
        exit(t('You do not have permission to manage this activity.'));
    }

    $postedBranchId = $isAllBranchUser
        ? (filter_input(INPUT_POST, 'branch_id', FILTER_VALIDATE_INT) ?: null)
        : $assignedBranchId;
    $recordBranchId = $existing ? (int) $existing['branch_id'] : $postedBranchId;
    if (!$existing && $recordBranchId !== null) {
        $branchContextId = $recordBranchId;
    }
    if ($recordBranchId === null || !isset($branchesById[$recordBranchId]) || (!$existing && !(int) $branchesById[$recordBranchId]['active'])) {
        $error = t('Choose an active parish before adding an activity.');
    }

    foreach ($fields as $name => $default) {
        $values[$name] = trim((string) ($_POST[$name] ?? $default));
    }

    $validScheduleTypes = ['once', 'weekly', 'monthly', 'annual'];
    $validStatuses = array_keys($statusLabels);
    $scheduleType = $values['schedule_type'];
    if ($error === '' && trim($values['title']) === '') {
        $error = t('Activity name') . t(' is required.');
    } elseif ($error === '' && trim($values['ministry']) === '') {
        $error = t('Ministry') . t(' is required.');
    } elseif ($error === '' && !in_array($scheduleType, $validScheduleTypes, true)) {
        $error = t('Choose a valid schedule type.');
    } elseif ($error === '' && !in_array($values['status'], $validStatuses, true)) {
        $error = t('Choose a valid activity status.');
    } elseif ($error === '' && $scheduleType === 'once' && $values['planned_date'] === '') {
        $error = t('Planned date') . t(' is required.');
    } elseif ($error === '' && $scheduleType === 'weekly' && !in_array($values['weekday'], $weekdays, true)) {
        $error = t('Choose a valid weekday.');
    } elseif ($error === '' && $scheduleType === 'annual' && $values['planned_month'] === '') {
        $error = t('Choose a month for the annual activity.');
    } elseif ($error === '' && in_array($scheduleType, ['monthly', 'annual'], true) && $values['planned_month'] !== '' && (!ctype_digit($values['planned_month']) || (int) $values['planned_month'] < 1 || (int) $values['planned_month'] > 12)) {
        $error = t('Choose a valid month.');
    }
    if ($error === '' && ($characterLength($values['title']) > 180 || $characterLength($values['ministry']) > 120 || $characterLength($values['location']) > 180 || $characterLength($values['funding_source']) > 180)) {
        $error = t('Some activity details exceed the allowed length.');
    }

    foreach (['plan_year' => [2020, 2100], 'expected_participants' => [0, 100000000], 'actual_participants' => [0, 100000000]] as $name => [$minimum, $maximum]) {
        if ($error !== '' || $values[$name] === '') {
            continue;
        }
        if (!ctype_digit($values[$name]) || (int) $values[$name] < $minimum || (int) $values[$name] > $maximum) {
            $error = t('Enter a valid value for ') . t($name === 'plan_year' ? 'Plan year' : ($name === 'expected_participants' ? 'Expected participants' : 'Actual participants')) . '.';
            break;
        }
    }
    foreach (['budget', 'actual_expenditure'] as $name) {
        if ($error !== '' || $values[$name] === '') {
            continue;
        }
        if (!is_numeric($values[$name]) || !is_finite((float) $values[$name]) || (float) $values[$name] < 0 || (float) $values[$name] > 9999999999.99) {
            $error = t($name === 'budget' ? 'Budget' : 'Actual expenditure') . t(' must be a non-negative number.');
            break;
        }
    }
    if ($error === '' && $values['planned_date'] !== '') {
        $date = DateTime::createFromFormat('!Y-m-d', $values['planned_date']);
        if (!$date || $date->format('Y-m-d') !== $values['planned_date']) {
            $error = t('Enter a valid date for ') . t('Planned date') . '.';
        }
    }
    if ($error === '' && $values['starts_at'] !== '') {
        $time = DateTime::createFromFormat('!H:i', $values['starts_at']);
        if (!$time || $time->format('H:i') !== $values['starts_at']) {
            $error = t('Enter a valid time.');
        }
    }

    if ($error === '') {
        $data = [
            'title' => trim($values['title']),
            'ministry' => trim($values['ministry']),
            'schedule_type' => $scheduleType,
            'plan_year' => $scheduleType === 'once' && $values['planned_date'] !== ''
                ? (int) date('Y', strtotime($values['planned_date']))
                : ($values['plan_year'] !== '' ? (int) $values['plan_year'] : null),
            'planned_month' => in_array($scheduleType, ['monthly', 'annual'], true) && $values['planned_month'] !== '' ? (int) $values['planned_month'] : null,
            'weekday' => $scheduleType === 'weekly' ? $values['weekday'] : null,
            'planned_date' => $scheduleType === 'once' && $values['planned_date'] !== '' ? $values['planned_date'] : null,
            'starts_at' => $values['starts_at'] !== '' ? $values['starts_at'] : null,
            'location' => trim($values['location']) ?: null,
            'objective' => trim($values['objective']) ?: null,
            'expected_result' => trim($values['expected_result']) ?: null,
            'supporting_team' => trim($values['supporting_team']) ?: null,
            'expected_participants' => $values['expected_participants'] !== '' ? (int) $values['expected_participants'] : null,
            'budget' => $values['budget'] !== '' ? $values['budget'] : null,
            'funding_source' => trim($values['funding_source']) ?: null,
            'materials' => trim($values['materials']) ?: null,
            'status' => $values['status'],
            'actual_expenditure' => $values['actual_expenditure'] !== '' ? $values['actual_expenditure'] : null,
            'actual_participants' => $values['actual_participants'] !== '' ? (int) $values['actual_participants'] : null,
            'completion_evidence' => trim($values['completion_evidence']) ?: null,
            'report_notes' => trim($values['report_notes']) ?: null,
            'challenges' => trim($values['challenges']) ?: null,
            'follow_up' => trim($values['follow_up']) ?: null,
        ];

        if ($existing) {
            $assignments = implode(', ', array_map(static fn (string $name): string => "{$name} = ?", array_keys($data)));
            $statement = db()->prepare("UPDATE activities SET {$assignments} WHERE id = ?");
            $statement->execute([...array_values($data), $postedId]);
            notify_branch_action((int) $recordBranchId, $user, 'Updated', (string) $data['title'], 'activities.php');
            $_SESSION['flash'] = 'Activity updated.';
        } else {
            $columns = implode(', ', array_keys($data));
            $placeholders = implode(', ', array_fill(0, count($data), '?'));
            $statement = db()->prepare("INSERT INTO activities (branch_id, responsible_user_id, created_by, {$columns}) VALUES (?, ?, ?, {$placeholders})");
            $statement->execute([$recordBranchId, (int) $user['id'], (int) $user['id'], ...array_values($data)]);
            notify_branch_action((int) $recordBranchId, $user, 'Created', (string) $data['title'], 'activities.php');
            $_SESSION['flash'] = 'Activity created and shared with parish leadership.';
        }

        $redirect = 'activities.php';
        if ($isAllBranchUser && $recordBranchId !== null) {
            $redirect .= '?branch_id=' . $recordBranchId;
        }
        redirect($redirect);
    }

    $action = 'form';
    $activityId = $postedId;
    $activity = $existing;
}

$activity = $activity ?? null;
if ($action === 'edit') {
    $activity = $activityId > 0 ? $loadActivity($activityId) : null;
    if (!$activity) {
        http_response_code(404);
        exit(t('Activity not found.'));
    }
    if (!$canManageActivity($activity)) {
        http_response_code(403);
        exit(t('You do not have permission to manage this activity.'));
    }
    foreach ($fields as $name => $default) {
        if ($activity[$name] !== null) {
            $values[$name] = (string) $activity[$name];
        }
    }
    if ($values['starts_at'] !== '') {
        $values['starts_at'] = substr($values['starts_at'], 0, 5);
    }
    $branchContextId = (int) $activity['branch_id'];
    $action = 'form';
} elseif ($action === 'add') {
    if (!in_array($user['role'], church_record_manager_roles(), true)) {
        http_response_code(403);
        exit(t('You do not have permission to add activities.'));
    }
    $templateId = filter_input(INPUT_GET, 'template', FILTER_VALIDATE_INT) ?: 0;
    if ($templateId > 0) {
        $statement = db()->prepare('SELECT title, ministry, cadence, plan_year, planned_month, weekday, description FROM activity_templates WHERE id = ?');
        $statement->execute([$templateId]);
        $template = $statement->fetch();
        if ($template) {
            $values['title'] = $template['title'];
            $values['ministry'] = $template['ministry'];
            $values['schedule_type'] = $template['cadence'];
            $values['plan_year'] = (string) $template['plan_year'];
            $values['planned_month'] = (string) ($template['planned_month'] ?? '');
            $values['weekday'] = (string) ($template['weekday'] ?? '');
            $values['objective'] = (string) ($template['description'] ?? '');
        }
    }
    $action = 'form';
} elseif ($action !== 'list' && $action !== 'form') {
    http_response_code(400);
    exit(t('Invalid action.'));
}

$templates = db()->query("SELECT id, title, ministry, cadence, plan_year, planned_month, weekday, description, suggested_responsibility FROM activity_templates ORDER BY cadence, planned_month, FIELD(weekday, 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'), title")->fetchAll();
$search = trim((string) ($_GET['search'] ?? ''));
$reportYear = trim((string) ($_GET['year'] ?? '2027'));
if ($reportYear !== '' && (!ctype_digit($reportYear) || (int) $reportYear < 2020 || (int) $reportYear > 2100)) {
    http_response_code(400);
    exit(t('Choose a valid plan year.'));
}
$activities = [];
if ($action === 'list') {
    $sql = 'SELECT a.id, a.branch_id, a.title, a.ministry, a.schedule_type, a.plan_year, a.planned_month, a.weekday,
                   a.planned_date, a.starts_at, a.location, a.status, a.budget, a.actual_expenditure,
                   a.actual_participants, a.expected_participants, a.report_notes, a.responsible_user_id,
                   b.name AS branch_name, u.full_name AS responsible_name
            FROM activities a
            JOIN branches b ON b.id = a.branch_id
            JOIN users u ON u.id = a.responsible_user_id';
    $where = [];
    $params = $scopeParams;
    if ($scopeSql !== '') {
        $where[] = '(' . $scopeSql . ')';
    }
    if ($reportYear !== '') {
        $where[] = '(a.plan_year = ? OR YEAR(a.planned_date) = ?)';
        $params[] = (int) $reportYear;
        $params[] = (int) $reportYear;
    }
    if ($search !== '') {
        $where[] = '(a.title LIKE ? OR a.ministry LIKE ? OR a.report_notes LIKE ? OR u.full_name LIKE ?)';
        for ($index = 0; $index < 4; $index++) {
            $params[] = '%' . $search . '%';
        }
    }
    if ($where !== []) {
        $sql .= ' WHERE ' . implode(' AND ', $where);
    }
    $sql .= " ORDER BY COALESCE(a.planned_date, STR_TO_DATE(CONCAT(a.plan_year, '-', LPAD(a.planned_month, 2, '0'), '-01'), '%Y-%m-%d')) DESC, a.created_at DESC LIMIT 200";
    $statement = db()->prepare($sql);
    $statement->execute($params);
    $activities = $statement->fetchAll();
}
$summarySql = 'SELECT COUNT(*) AS total,
                      SUM(status = \'completed\') AS completed,
                      SUM(report_notes IS NOT NULL AND report_notes <> \'\') AS reported,
                      COALESCE(SUM(actual_expenditure), 0) AS expenditure
               FROM activities a';
$summaryWhere = [];
$summaryParams = [];
if ($scopeSql !== '') {
    $summaryWhere[] = '(' . $scopeSql . ')';
    $summaryParams = $scopeParams;
}
if ($reportYear !== '') {
    $summaryWhere[] = '(a.plan_year = ? OR YEAR(a.planned_date) = ?)';
    $summaryParams[] = (int) $reportYear;
    $summaryParams[] = (int) $reportYear;
}
if ($summaryWhere !== []) {
    $summarySql .= ' WHERE ' . implode(' AND ', $summaryWhere);
}
$summaryStatement = db()->prepare($summarySql);
$summaryStatement->execute($summaryParams);
$activitySummary = $summaryStatement->fetch();

$pageTitle = 'Parish activity plan';
require __DIR__ . '/includes/header.php';
?>
<section class="content-heading">
    <div>
        <p class="eyebrow"><?= e(t('Annual parish programme')) ?></p>
        <h2><?= e(t($action === 'form' ? ($activityId > 0 ? 'Update activity' : 'Plan an activity') : 'Activity register')) ?></h2>
        <p class="muted"><?= e(t('Plan, assign, monitor and report parish activities. Reports are shared immediately with parish leadership and headquarters.')) ?></p>
    </div>
    <?php if ($action === 'list'): ?><a class="button primary" href="activities.php?action=add<?= $isAllBranchUser && $branchContextId !== null ? '&amp;branch_id=' . e((string) $branchContextId) : '' ?>"><?= e(t('Add activity')) ?></a><?php else: ?><a class="button secondary" href="activities.php"><?= e(t('Back to activities')) ?></a><?php endif; ?>
</section>
<?php if ($notice !== ''): ?><div class="notice success"><?= e(t($notice)) ?></div><?php endif; ?>
<?php if ($error !== ''): ?><div class="notice error"><?= e($error) ?></div><?php endif; ?>

<?php if ($action === 'form'): ?>
    <section class="panel">
        <div class="notice"><?= e(t('Do not include confidential pastoral, safeguarding or sensitive personal information in activity reports.')) ?></div>
        <form method="post" class="record-form">
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="save">
            <input type="hidden" name="id" value="<?= e((string) $activityId) ?>">
            <?php if ($isAllBranchUser && !$activity): ?>
                <label><?= e(t('Parish')) ?><select name="branch_id" required><option value=""><?= e(t('Select a parish…')) ?></option><?php foreach ($branches as $branch): ?><?php if ((int) $branch['active']): ?><option value="<?= e((string) $branch['id']) ?>" <?= (string) $branchContextId === (string) $branch['id'] ? 'selected' : '' ?>><?= e($branch['name']) ?></option><?php endif; ?><?php endforeach; ?></select></label>
            <?php endif; ?>
            <label><?= e(t('Activity name')) ?><input name="title" maxlength="180" required value="<?= e($values['title']) ?>"></label>
            <label><?= e(t('Ministry')) ?><input name="ministry" maxlength="120" required value="<?= e($values['ministry']) ?>"></label>
            <label><?= e(t('Schedule')) ?><select name="schedule_type"><?php foreach (['once' => 'One-time', 'weekly' => 'Weekly', 'monthly' => 'Monthly', 'annual' => 'Annual'] as $value => $label): ?><option value="<?= e($value) ?>" <?= $values['schedule_type'] === $value ? 'selected' : '' ?>><?= e(t($label)) ?></option><?php endforeach; ?></select></label>
            <label><?= e(t('Plan year')) ?><input type="number" min="2020" max="2100" name="plan_year" value="<?= e($values['plan_year']) ?>"></label>
            <label><?= e(t('Month')) ?><select name="planned_month"><option value=""><?= e(t('No specific month')) ?></option><?php foreach ($months as $monthNumber => $monthName): ?><option value="<?= e((string) $monthNumber) ?>" <?= (string) $values['planned_month'] === (string) $monthNumber ? 'selected' : '' ?>><?= e(t($monthName)) ?></option><?php endforeach; ?></select></label>
            <label><?= e(t('Weekday')) ?><select name="weekday"><option value=""><?= e(t('Select…')) ?></option><?php foreach ($weekdays as $weekday): ?><option value="<?= e($weekday) ?>" <?= $values['weekday'] === $weekday ? 'selected' : '' ?>><?= e(t($weekday)) ?></option><?php endforeach; ?></select></label>
            <label><?= e(t('Planned date')) ?><input type="date" name="planned_date" value="<?= e($values['planned_date']) ?>"></label>
            <label><?= e(t('Time')) ?><input type="time" name="starts_at" value="<?= e($values['starts_at']) ?>"></label>
            <label><?= e(t('Location')) ?><input name="location" maxlength="180" value="<?= e($values['location']) ?>"></label>
            <label><?= e(t('Objective')) ?><textarea name="objective" rows="3"><?= e($values['objective']) ?></textarea></label>
            <label><?= e(t('Expected result')) ?><textarea name="expected_result" rows="3"><?= e($values['expected_result']) ?></textarea></label>
            <label><?= e(t('Supporting team')) ?><textarea name="supporting_team" rows="2"><?= e($values['supporting_team']) ?></textarea></label>
            <label><?= e(t('Expected participants')) ?><input type="number" min="0" name="expected_participants" value="<?= e($values['expected_participants']) ?>"></label>
            <label><?= e(t('Budget')) ?><input type="number" min="0" step="0.01" name="budget" value="<?= e($values['budget']) ?>"></label>
            <label><?= e(t('Funding source')) ?><input name="funding_source" maxlength="180" value="<?= e($values['funding_source']) ?>"></label>
            <label><?= e(t('Materials and logistics')) ?><textarea name="materials" rows="3"><?= e($values['materials']) ?></textarea></label>
            <label><?= e(t('Status')) ?><select name="status"><?php foreach ($statusLabels as $value => $label): ?><option value="<?= e($value) ?>" <?= $values['status'] === $value ? 'selected' : '' ?>><?= e(t($label)) ?></option><?php endforeach; ?></select></label>
            <label><?= e(t('Actual expenditure')) ?><input type="number" min="0" step="0.01" name="actual_expenditure" value="<?= e($values['actual_expenditure']) ?>"></label>
            <label><?= e(t('Actual participants')) ?><input type="number" min="0" name="actual_participants" value="<?= e($values['actual_participants']) ?>"></label>
            <label><?= e(t('Completion evidence')) ?><textarea name="completion_evidence" rows="3"><?= e($values['completion_evidence']) ?></textarea></label>
            <label><?= e(t('Activity report')) ?><textarea name="report_notes" rows="4"><?= e($values['report_notes']) ?></textarea></label>
            <label><?= e(t('Challenges')) ?><textarea name="challenges" rows="3"><?= e($values['challenges']) ?></textarea></label>
            <label><?= e(t('Follow-up actions')) ?><textarea name="follow_up" rows="3"><?= e($values['follow_up']) ?></textarea></label>
            <p class="muted"><?= e(t('Responsible leader')) ?>: <?= e($activity['responsible_name'] ?? $user['full_name']) ?>. <?= e(t('The activity owner can edit or delete it; parish supervisors can oversee activities in their parish.')) ?></p>
            <button class="button primary" type="submit"><?= e(t($activity ? 'Save activity' : 'Create activity and share')) ?></button>
        </form>
    </section>
<?php else: ?>
    <form method="get" class="search-toolbar">
        <?php if ($isAllBranchUser && $branchContextId !== null): ?><input type="hidden" name="branch_id" value="<?= e((string) $branchContextId) ?>"><?php endif; ?>
        <?php if ($search !== ''): ?><input type="hidden" name="search" value="<?= e($search) ?>"><?php endif; ?>
        <label for="plan-year"><?= e(t('Plan year')) ?></label>
        <select id="plan-year" name="year"><option value=""><?= e(t('All years')) ?></option><?php for ($yearOption = 2027; $yearOption >= 2020; $yearOption--): ?><option value="<?= e((string) $yearOption) ?>" <?= $reportYear === (string) $yearOption ? 'selected' : '' ?>><?= e((string) $yearOption) ?></option><?php endfor; ?><?php for ($yearOption = 2028; $yearOption <= 2100; $yearOption++): ?><option value="<?= e((string) $yearOption) ?>" <?= $reportYear === (string) $yearOption ? 'selected' : '' ?>><?= e((string) $yearOption) ?></option><?php endfor; ?></select>
        <button class="button secondary" type="submit"><?= e(t('Filter')) ?></button>
    </form>
    <section class="stats-grid" aria-label="<?= e(t('Activity monitoring summary')) ?>">
        <article class="stat-card"><div><p><?= e(t('Planned activities')) ?></p><strong><?= e((string) ($activitySummary['total'] ?? 0)) ?></strong></div></article>
        <article class="stat-card"><div><p><?= e(t('Completed activities')) ?></p><strong><?= e((string) ($activitySummary['completed'] ?? 0)) ?></strong></div></article>
        <article class="stat-card"><div><p><?= e(t('Reports recorded')) ?></p><strong><?= e((string) ($activitySummary['reported'] ?? 0)) ?></strong></div></article>
        <article class="stat-card"><div><p><?= e(t('Actual expenditure')) ?></p><strong><?= e(number_format((float) ($activitySummary['expenditure'] ?? 0), 2)) ?></strong></div></article>
    </section>
    <?php if ($isAllBranchUser): ?>
        <form method="get" class="search-toolbar">
            <input type="hidden" name="search" value="<?= e($search) ?>">
            <input type="hidden" name="year" value="<?= e($reportYear) ?>">
            <label for="branch-filter"><?= e(t('Parish')) ?></label>
            <select id="branch-filter" name="branch_id"><option value=""><?= e(t('All parishes')) ?></option><?php foreach ($branches as $branch): ?><option value="<?= e((string) $branch['id']) ?>" <?= (string) $branchContextId === (string) $branch['id'] ? 'selected' : '' ?>><?= e($branch['name']) ?></option><?php endforeach; ?></select>
            <button class="button secondary" type="submit"><?= e(t('Filter')) ?></button>
        </form>
    <?php endif; ?>
    <form method="get" class="search-toolbar">
        <?php if ($isAllBranchUser && $branchContextId !== null): ?><input type="hidden" name="branch_id" value="<?= e((string) $branchContextId) ?>"><?php endif; ?>
        <?php if ($reportYear !== ''): ?><input type="hidden" name="year" value="<?= e($reportYear) ?>"><?php endif; ?>
        <label for="activity-search"><?= e(t('Search activities')) ?></label>
        <input id="activity-search" type="search" name="search" value="<?= e($search) ?>" placeholder="<?= e(t('Search by activity, ministry, leader or report')) ?>">
        <button class="button secondary" type="submit"><?= e(t('Search')) ?></button>
    </form>
    <section class="panel">
        <div class="panel-heading"><div><p class="eyebrow"><?= e(t('Shared parish register')) ?></p><h2><?= e(t('Planned and reported activities')) ?></h2></div><a href="leadership.php"><?= e(t('Leadership guide')) ?></a></div>
        <?php if ($activities): ?>
            <div class="account-list">
                <?php foreach ($activities as $item): ?>
                    <?php
                    $canManageItem = $canSupervise || (int) $item['responsible_user_id'] === (int) $user['id'];
                    $schedule = match ($item['schedule_type']) {
                        'weekly' => t((string) $item['weekday']) . ' · ' . t('Weekly'),
                        'annual' => ($item['planned_month'] ? t($months[(int) $item['planned_month']]) : t('Annual')) . ' ' . e((string) ($item['plan_year'] ?? '')),
                        'monthly' => ($item['planned_month'] ? t($months[(int) $item['planned_month']]) . ' ' : '') . t('Monthly') . ($item['plan_year'] ? ' · ' . e((string) $item['plan_year']) : ''),
                        default => $item['planned_date'] ? localized_date($item['planned_date']) : '',
                    };
                    ?>
                    <article class="account-row">
                        <div>
                            <h3><?= e($item['title']) ?></h3>
                            <p><?= e($item['ministry']) ?> · <?= e($schedule) ?><?= $item['starts_at'] ? ' · ' . e(substr($item['starts_at'], 0, 5)) : '' ?><?= $item['location'] ? ' · ' . e($item['location']) : '' ?></p>
                            <p><?= e(t('Responsible leader')) ?>: <?= e($item['responsible_name']) ?><?= $isAllBranchUser ? ' · ' . e($item['branch_name']) : '' ?></p>
                            <?php if ($item['report_notes']): ?><p><?= e($item['report_notes']) ?></p><?php endif; ?>
                            <?php if ($item['actual_participants'] !== null || $item['actual_expenditure'] !== null): ?><p><?= e(t('Participants')) ?>: <?= e((string) ($item['actual_participants'] ?? '—')) ?> · <?= e(t('Actual expenditure')) ?>: <?= e((string) ($item['actual_expenditure'] ?? '—')) ?></p><?php endif; ?>
                            <span class="role-pill"><?= e(t($statusLabels[$item['status']])) ?></span>
                            <?php if ($item['budget'] !== null): ?><span class="role-pill"><?= e(t('Budget')) ?>: <?= e((string) $item['budget']) ?></span><?php endif; ?>
                        </div>
                        <?php if ($canManageItem): ?>
                            <div class="account-actions">
                                <a class="button secondary small" href="activities.php?action=edit&amp;id=<?= e((string) $item['id']) ?><?= $isAllBranchUser ? '&amp;branch_id=' . e((string) $item['branch_id']) : '' ?>"><?= e(t('Edit')) ?></a>
                                <form method="post" onsubmit="return confirm('<?= e(t('Delete this activity?')) ?>')"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= e((string) $item['id']) ?>"><button class="button secondary small" type="submit"><?= e(t('Delete')) ?></button></form>
                            </div>
                        <?php endif; ?>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php else: ?><p class="empty-state"><?= e(t('No activities found. Use a 2027 programme template below or add an activity.')) ?></p><?php endif; ?>
    </section>

    <section class="panel">
        <div class="panel-heading"><div><p class="eyebrow"><?= e(t('Editable examples')) ?></p><h2><?= e(t('2027 annual and weekly programme templates')) ?></h2><p class="muted"><?= e(t('Templates are examples, not official diocesan bookings. Confirm dates against the local Anglican calendar and parish approval process.')) ?></p></div></div>
        <div class="account-list">
            <?php foreach ($templates as $template): ?>
                <?php
                $templateSchedule = $template['cadence'] === 'annual'
                    ? t($months[(int) $template['planned_month']]) . ' 2027'
                    : ($template['weekday'] ? t($template['weekday']) . ' · ' . t('Weekly') : t('Monthly'));
                ?>
                <article class="account-row">
                    <div><h3><?= e($template['title']) ?></h3><p><?= e($template['ministry']) ?> · <?= e($templateSchedule) ?></p><p><?= e($template['description'] ?? '') ?></p><span class="muted"><?= e(t('Suggested leader')) ?>: <?= e($template['suggested_responsibility'] ?? '') ?></span></div>
                    <a class="button secondary small" href="activities.php?action=add&amp;template=<?= e((string) $template['id']) ?><?= $isAllBranchUser && $branchContextId !== null ? '&amp;branch_id=' . e((string) $branchContextId) : '' ?>"><?= e(t('Use template')) ?></a>
                </article>
            <?php endforeach; ?>
        </div>
    </section>
<?php endif; ?>
<?php require __DIR__ . '/includes/footer.php'; ?>
