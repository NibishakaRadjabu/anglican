<?php
declare(strict_types=1);

require_once __DIR__ . '/config/app.php';

$user = require_login();
$modules = module_definitions();
$moduleKey = (string) ($_GET['module'] ?? '');
if (!isset($modules[$moduleKey])) {
    http_response_code(404);
    exit(t('Record area not found.'));
}

$module = $modules[$moduleKey];
if (!in_array($user['role'], $module['read'], true)) {
    http_response_code(403);
    exit(t('You do not have permission to view this area.'));
}

$canWrite = in_array($user['role'], $module['write'], true);
$table = $module['table'];
$fields = $module['fields'];
$mode = (string) ($_GET['action'] ?? 'list');
$recordId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT) ?: 0;
$branches = db()->query('SELECT id, name, branch_type, active FROM branches ORDER BY name')->fetchAll();
$branchesById = [];
foreach ($branches as $branch) {
    $branchesById[(int) $branch['id']] = $branch;
}
$assignedBranchId = user_has_all_branch_access($user) ? null : ((int) ($user['branch_id'] ?? 0) ?: null);
$canWrite = $canWrite && ($assignedBranchId === null || (isset($branchesById[$assignedBranchId]) && (int) $branchesById[$assignedBranchId]['active'] === 1));
$requestedBranchId = filter_input(INPUT_GET, 'branch_id', FILTER_VALIDATE_INT) ?: null;
if ($assignedBranchId !== null) {
    $branchContextId = $assignedBranchId;
} else {
    $branchContextId = $requestedBranchId;
    if ($branchContextId !== null && !isset($branchesById[$branchContextId])) {
        http_response_code(400);
        exit(t('Choose a valid parish.'));
    }
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $assignedBranchId === null) {
    $postedBranchId = filter_input(INPUT_POST, 'branch_id', FILTER_VALIDATE_INT) ?: null;
    if ($postedBranchId !== null) {
        if (!isset($branchesById[$postedBranchId])) {
            http_response_code(400);
            exit(t('Choose a valid parish.'));
        }
        $branchContextId = $postedBranchId;
    }
}
$error = '';
$notice = $_SESSION['flash'] ?? '';
unset($_SESSION['flash']);
$values = array_fill_keys(array_keys($fields), '');

$scopeClauses = [];
$scopeParams = [];
if ($assignedBranchId !== null) {
    $scopeClauses[] = 'branch_id = ?';
    $scopeParams[] = $assignedBranchId;
} elseif ($branchContextId !== null) {
    $scopeClauses[] = 'branch_id = ?';
    $scopeParams[] = $branchContextId;
}
if ($user['role'] === 'member') {
    $memberId = (int) ($user['member_id'] ?? 0);
    if ($moduleKey === 'announcements') {
        $scopeClauses[] = '(publish_at IS NULL OR publish_at <= NOW())';
    } elseif ($moduleKey === 'members') {
        $scopeClauses[] = 'id = ?';
        $scopeParams[] = $memberId;
    } elseif (isset($module['member_scope_or'])) {
        $scopeClauses[] = '(' . $module['member_scope'] . ' = ? OR ' . $module['member_scope_or'] . ' = ?)';
        $scopeParams[] = $memberId;
        $scopeParams[] = $memberId;
    } elseif (isset($module['member_scope'])) {
        $scopeClauses[] = $module['member_scope'] . ' = ?';
        $scopeParams[] = $memberId;
    }
}
$scopeSql = implode(' AND ', $scopeClauses);

$memberOptions = null;
$optionBranchId = $assignedBranchId ?? $branchContextId;
$getOptions = static function (array $field) use (&$memberOptions, &$optionBranchId): array {
    if (($field['options'] ?? null) !== 'members') {
        return array_map(static fn ($option): array => ['value' => (string) $option, 'label' => (string) $option], $field['options'] ?? []);
    }
    if ($memberOptions === null) {
        $sql = 'SELECT id, first_name, last_name FROM members';
        if ($optionBranchId !== null) {
            $sql .= ' WHERE branch_id = ' . (int) $optionBranchId;
        }
        $sql .= ' ORDER BY last_name, first_name';
        $memberOptions = db()->query($sql)->fetchAll();
    }
    $options = [];
    foreach ($memberOptions as $member) {
        $options[] = ['value' => (string) $member['id'], 'label' => $member['first_name'] . ' ' . $member['last_name']];
    }
    return $options;
};

$loadRecord = static function (int $id) use ($table, $fields, $scopeSql, $scopeParams): ?array {
    $sql = 'SELECT id, branch_id, ' . implode(', ', array_keys($fields)) . " FROM {$table} WHERE id = ?";
    $params = [$id];
    if ($scopeSql !== '') {
        $sql .= " AND ({$scopeSql})";
        $params = [...$params, ...$scopeParams];
    }
    $statement = db()->prepare($sql);
    $statement->execute($params);
    $record = $statement->fetch();
    return $record ?: null;
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $postAction = (string) ($_POST['action'] ?? '');
    if ($postAction === 'delete') {
        if (!$canWrite) {
            http_response_code(403);
            exit(t('You do not have permission to delete records.'));
        }
        $deleteId = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT) ?: 0;
        $deleteRecord = $deleteId > 0 ? $loadRecord($deleteId) : null;
        if (!$deleteRecord) {
            $_SESSION['flash'] = 'Record was not found.';
        } else {
            $sql = "DELETE FROM {$table} WHERE id = ?";
            $params = [$deleteId];
            if ($scopeSql !== '') {
                $sql .= " AND ({$scopeSql})";
                $params = [...$params, ...$scopeParams];
            }
            $statement = db()->prepare($sql);
            $statement->execute($params);
            if ($statement->rowCount() > 0) {
                if ($recordBranchId = (int) ($deleteRecord['branch_id'] ?? 0)) {
                    notify_branch_action($recordBranchId, $user, 'Deleted', (string) $module['title'], 'records.php?module=' . rawurlencode($moduleKey));
                }
            }
            $_SESSION['flash'] = $statement->rowCount() ? 'Record deleted.' : 'Record was not found.';
        }
        $redirect = 'records.php?module=' . rawurlencode($moduleKey);
        if ($branchContextId !== null && $assignedBranchId === null) {
            $redirect .= '&branch_id=' . $branchContextId;
        }
        redirect($redirect);
    }

    if ($postAction === 'save') {
        if (!$canWrite) {
            http_response_code(403);
            exit(t('You do not have permission to change records.'));
        }
        $saveId = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT) ?: 0;
        $existingRecord = $saveId > 0 ? $loadRecord($saveId) : null;
        if ($saveId > 0 && !$existingRecord) {
            http_response_code(404);
            exit(t('Record not found.'));
        }
        $recordBranchId = $existingRecord
            ? (int) $existingRecord['branch_id']
            : ($assignedBranchId ?? $branchContextId);
        if ($recordBranchId === null || !isset($branchesById[$recordBranchId]) || (!$existingRecord && !(int) $branchesById[$recordBranchId]['active'])) {
            $error = t('Choose an active parish before adding a record.');
        }
        $data = [];
        foreach ($fields as $name => $field) {
            if ($error !== '') {
                break;
            }
            $value = trim((string) ($_POST[$name] ?? ''));
            $values[$name] = $value;
            if (($field['required'] ?? false) && $value === '') {
                $error = t($field['label']) . t(' is required.');
                break;
            }
            if ($value !== '' && ($field['type'] ?? '') === 'email' && !filter_var($value, FILTER_VALIDATE_EMAIL)) {
                $error = t('Enter a valid email address for ') . t($field['label']) . '.';
                break;
            }
            if ($value !== '' && ($field['type'] ?? '') === 'number' && (!is_numeric($value) || (float) $value < 0)) {
                $error = t($field['label']) . t(' must be a non-negative number.');
                break;
            }
            if ($value !== '' && isset($field['options'])) {
                $allowed = array_column($getOptions($field), 'value');
                if (!in_array($value, $allowed, true)) {
                    $error = t('Choose a valid value for ') . t($field['label']) . '.';
                    break;
                }
            }
            if ($value === '') {
                $data[$name] = null;
                continue;
            }
            if (($field['type'] ?? '') === 'datetime-local') {
                $date = DateTime::createFromFormat('!Y-m-d\TH:i', $value);
                if (!$date || $date->format('Y-m-d\TH:i') !== $value) {
                    $error = t('Enter a valid date and time for ') . t($field['label']) . '.';
                    break;
                }
                $value = $date->format('Y-m-d H:i:s');
            } elseif (($field['type'] ?? '') === 'date') {
                $date = DateTime::createFromFormat('!Y-m-d', $value);
                if (!$date || $date->format('Y-m-d') !== $value) {
                    $error = t('Enter a valid date for ') . t($field['label']) . '.';
                    break;
                }
            }
            $data[$name] = $value;
        }

        if ($error === '') {
            if ($saveId > 0) {
                $assignments = implode(', ', array_map(static fn (string $name): string => "{$name} = ?", array_keys($data)));
                $updateSql = "UPDATE {$table} SET {$assignments} WHERE id = ?";
                if ($scopeSql !== '') {
                    $updateSql .= " AND ({$scopeSql})";
                }
                $statement = db()->prepare($updateSql);
                $updateParams = [...array_values($data), $saveId];
                if ($scopeSql !== '') {
                    $updateParams = [...$updateParams, ...$scopeParams];
                }
                $statement->execute($updateParams);
                notify_branch_action((int) $recordBranchId, $user, 'Updated', (string) $module['title'], 'records.php?module=' . rawurlencode($moduleKey));
                $_SESSION['flash'] = 'Record updated.';
            } else {
                $data['branch_id'] = $recordBranchId;
                $columns = implode(', ', array_keys($data));
                $placeholders = implode(', ', array_fill(0, count($data), '?'));
                $statement = db()->prepare("INSERT INTO {$table} ({$columns}) VALUES ({$placeholders})");
                $statement->execute(array_values($data));
                notify_branch_action((int) $recordBranchId, $user, 'Created', (string) $module['title'], 'records.php?module=' . rawurlencode($moduleKey));
                $_SESSION['flash'] = 'Record added.';
            }
            $redirect = 'records.php?module=' . rawurlencode($moduleKey);
            if ($recordBranchId !== null && $assignedBranchId === null) {
                $redirect .= '&branch_id=' . $recordBranchId;
            }
            redirect($redirect);
        }
        $mode = 'form';
        $recordId = $saveId;
    }
}

if ($mode === 'edit') {
    if (!$canWrite) {
        http_response_code(403);
        exit(t('You do not have permission to edit records.'));
    }
    if ($recordId < 1 || !($record = $loadRecord($recordId))) {
        http_response_code(404);
        exit(t('Record not found.'));
    }
    $values = array_merge($values, $record);
    $branchContextId = (int) $record['branch_id'];
    $optionBranchId = $branchContextId;
    $memberOptions = null;
    $mode = 'form';
} elseif ($mode === 'add') {
    if (!$canWrite) {
        http_response_code(403);
        exit(t('You do not have permission to add records.'));
    }
    $mode = 'form';
} elseif ($mode !== 'list' && $mode !== 'form') {
    http_response_code(400);
    exit(t('Invalid action.'));
}

$records = [];
$search = trim((string) ($_GET['search'] ?? ''));
if ($mode === 'list') {
    $columns = implode(', ', array_keys($fields));
    $sql = "SELECT id, branch_id, {$columns} FROM {$table}";
    $params = $scopeParams;
    $whereClauses = [];
    if ($scopeSql !== '') {
        $whereClauses[] = "({$scopeSql})";
    }
    if ($search !== '') {
        $terms = preg_split('/\s+/', $search, -1, PREG_SPLIT_NO_EMPTY);
        if ($terms !== false && $terms !== []) {
            $searchClauses = [];
            foreach ($fields as $name => $field) {
                $type = $field['type'] ?? 'text';
                if (!in_array($type, ['text', 'textarea', 'email', 'select'], true)) {
                    continue;
                }
                foreach ($terms as $term) {
                    $searchClauses[] = $name . ' LIKE ?';
                    $params[] = '%' . $term . '%';
                }
            }
            if ($searchClauses !== []) {
                $whereClauses[] = '(' . implode(' OR ', $searchClauses) . ')';
            }
        }
    }
    if ($whereClauses !== []) {
        $sql .= ' WHERE ' . implode(' AND ', $whereClauses);
    }
    $sql .= ' ORDER BY id DESC LIMIT 100';
    $statement = db()->prepare($sql);
    $statement->execute($params);
    $records = $statement->fetchAll();
}

$pageTitle = $module['title'];
$activeModule = $moduleKey;
require __DIR__ . '/includes/header.php';
?>
<section class="content-heading">
    <div><p class="eyebrow"><?= e(t('Church records')) ?></p><h2><?= e(t($module['title'])) ?></h2><p class="muted"><?= e(t($mode === 'list' ? 'Showing the latest 100 records.' : 'Keep your church records accurate and up to date.')) ?></p></div>
    <?php if ($canWrite && $mode === 'list'): ?><a class="button primary" href="records.php?module=<?= e($moduleKey) ?>&amp;action=add<?= $branchContextId !== null ? '&amp;branch_id=' . e((string) $branchContextId) : '' ?>">＋ <?= e(t('Add record')) ?></a><?php endif; ?>
</section>
<?php if ($notice !== ''): ?><div class="notice success"><?= e(t($notice)) ?></div><?php endif; ?>
<?php if ($error !== ''): ?><div class="notice error"><?= e(t($error)) ?></div><?php endif; ?>
<?php if ($mode === 'form'): ?>
    <section class="panel form-panel">
        <div class="panel-heading"><div><p class="eyebrow"><?= e(t($recordId ? 'Edit entry' : 'New entry')) ?></p><h2><?= e($recordId ? t('Update record') : t('Add record')) ?></h2></div><a href="records.php?module=<?= e($moduleKey) ?><?= $branchContextId !== null && $assignedBranchId === null ? '&amp;branch_id=' . e((string) $branchContextId) : '' ?>"><?= e(t('Cancel')) ?></a></div>
        <form method="post" class="record-form">
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="save">
            <input type="hidden" name="id" value="<?= e((string) $recordId) ?>">
            <?php if ($assignedBranchId !== null): ?>
                <input type="hidden" name="branch_id" value="<?= e((string) $assignedBranchId) ?>">
            <?php elseif ($recordId > 0 && $branchContextId !== null): ?>
                <input type="hidden" name="branch_id" value="<?= e((string) $branchContextId) ?>">
                <label><?= e(t('Parish')) ?><input value="<?= e($branchesById[$branchContextId]['name'] ?? '') ?>" readonly></label>
            <?php elseif ($recordId === 0): ?>
                <label><?= e(t('Parish')) ?><select name="branch_id" required><option value=""><?= e(t('Select a parish…')) ?></option><?php foreach ($branches as $branch): ?><?php if ((int) $branch['active']): ?><option value="<?= e((string) $branch['id']) ?>" <?= (string) $branchContextId === (string) $branch['id'] ? 'selected' : '' ?>><?= e($branch['name']) ?></option><?php endif; ?><?php endforeach; ?></select></label>
            <?php endif; ?>
            <?php foreach ($fields as $name => $field): ?>
                <?php $type = $field['type'] ?? 'text'; $value = (string) ($values[$name] ?? ''); ?>
                <?php if ($type === 'textarea'): ?>
                    <label class="span-two"><?= e(t($field['label'])) ?><?= ($field['required'] ?? false) ? ' *' : '' ?><textarea name="<?= e($name) ?>" rows="3" <?= ($field['required'] ?? false) ? 'required' : '' ?>><?= e($value) ?></textarea></label>
                <?php elseif ($type === 'select'): ?>
                    <?php $options = $getOptions($field); ?>
                    <label><?= e(t($field['label'])) ?><?= ($field['required'] ?? false) ? ' *' : '' ?><select name="<?= e($name) ?>" <?= ($field['required'] ?? false) ? 'required' : '' ?>><option value=""><?= e(t('Select…')) ?></option><?php foreach ($options as $option): ?><option value="<?= e($option['value']) ?>" <?= $value === $option['value'] ? 'selected' : '' ?>><?= e(($field['options'] ?? null) === 'members' ? $option['label'] : t($option['label'])) ?></option><?php endforeach; ?></select></label>
                <?php else: ?>
                    <?php if ($type === 'datetime-local' && $value !== ''): $value = date('Y-m-d\TH:i', strtotime($value)); endif; ?>
                    <label><?= e(t($field['label'])) ?><?= ($field['required'] ?? false) ? ' *' : '' ?><input type="<?= e($type) ?>" name="<?= e($name) ?>" value="<?= e($value) ?>" <?= $type === 'number' ? 'min="0" step="0.01"' : '' ?> <?= ($field['required'] ?? false) ? 'required' : '' ?>></label>
                <?php endif; ?>
            <?php endforeach; ?>
            <div class="form-actions span-two"><button class="button primary" type="submit"><?= e(t('Save record')) ?></button><a class="button secondary" href="records.php?module=<?= e($moduleKey) ?><?= $branchContextId !== null && $assignedBranchId === null ? '&amp;branch_id=' . e((string) $branchContextId) : '' ?>"><?= e(t('Cancel')) ?></a></div>
        </form>
    </section>
<?php else: ?>
    <section class="panel table-panel">
        <div class="record-toolbar">
            <div class="record-toolbar-copy">
                <h3><?= e(t('Find records')) ?></h3>
                <p><?= e(t('Search records by keyword.')) ?></p>
            </div>
            <div class="record-toolbar-actions">
                <?php if ($assignedBranchId === null): ?>
                    <form method="get" class="branch-filter-form">
                        <input type="hidden" name="module" value="<?= e($moduleKey) ?>">
                        <label for="records-branch"><?= e(t('Parish')) ?></label>
                        <select id="records-branch" name="branch_id">
                            <option value=""><?= e(t('All parishes')) ?></option>
                            <?php foreach ($branches as $branch): ?><option value="<?= e((string) $branch['id']) ?>" <?= (string) $branchContextId === (string) $branch['id'] ? 'selected' : '' ?>><?= e($branch['name']) ?></option><?php endforeach; ?>
                        </select>
                        <button class="button secondary" type="submit"><?= e(t('Filter')) ?></button>
                    </form>
                <?php endif; ?>
                <form method="get" class="search-form" role="search" aria-label="<?= e(t('Search records')) ?>">
                    <input type="hidden" name="module" value="<?= e($moduleKey) ?>">
                    <?php if ($branchContextId !== null): ?><input type="hidden" name="branch_id" value="<?= e((string) $branchContextId) ?>"><?php endif; ?>
                    <label class="search-field">
                        <span class="sr-only"><?= e(t('Search')) ?></span>
                        <svg class="search-icon" aria-hidden="true" viewBox="0 0 20 20" fill="none">
                            <circle cx="8.75" cy="8.75" r="5.75"></circle>
                            <path d="m13 13 4 4"></path>
                        </svg>
                        <input type="search" name="search" value="<?= e($search) ?>" placeholder="<?= e(t('Search records…')) ?>">
                    </label>
                    <button class="button primary" type="submit"><?= e(t('Search')) ?></button>
                    <?php if ($search !== ''): ?><a class="button ghost" href="records.php?module=<?= e($moduleKey) ?><?= $branchContextId !== null ? '&amp;branch_id=' . e((string) $branchContextId) : '' ?>"><?= e(t('Clear')) ?></a><?php endif; ?>
                </form>
            </div>
        </div>
        <?php if ($records): ?>
            <div class="table-scroll"><table><thead><tr><?php if ($assignedBranchId === null): ?><th><?= e(t('Parish')) ?></th><?php endif; ?><?php foreach ($fields as $field): ?><th><?= e(t($field['label'])) ?></th><?php endforeach; ?><?php if ($canWrite): ?><th><?= e(t('Actions')) ?></th><?php endif; ?></tr></thead><tbody>
                <?php foreach ($records as $record): ?>
                    <tr>
                        <?php if ($assignedBranchId === null): ?><td><?= e($branchesById[(int) $record['branch_id']]['name'] ?? '') ?></td><?php endif; ?>
                        <?php foreach ($fields as $name => $field): ?>
                            <?php
                            $cell = $record[$name] ?? '';
                            if ($cell !== '' && $cell !== null && ($field['type'] ?? '') === 'datetime-local') {
                                $cell = localized_date(date('Y-m-d', strtotime($cell))) . ', ' . date('g:i A', strtotime($cell));
                            } elseif ($cell !== '' && $cell !== null && ($field['type'] ?? '') === 'date') {
                                $cell = localized_date($cell);
                            } elseif (($field['options'] ?? null) === 'members' && $cell !== '' && $cell !== null) {
                                foreach ($getOptions($field) as $option) {
                                    if ($option['value'] === (string) $cell) {
                                        $cell = $option['label'];
                                        break;
                                    }
                                }
                            } elseif ($cell !== '' && $cell !== null && isset($field['options'])) {
                                $cell = t((string) $cell);
                            }
                            ?>
                            <td><?= e((string) $cell) ?></td>
                        <?php endforeach; ?>
                        <?php if ($canWrite): ?><td class="actions"><a href="records.php?module=<?= e($moduleKey) ?>&amp;action=edit&amp;id=<?= e((string) $record['id']) ?>"><?= e(t('Edit')) ?></a><form method="post" onsubmit="return confirm('<?= e(t('Delete this record?')) ?>')"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= e((string) $record['id']) ?>"><?php if ($branchContextId !== null): ?><input type="hidden" name="branch_id" value="<?= e((string) $branchContextId) ?>"><?php endif; ?><button class="text-button danger-text" type="submit"><?= e(t('Delete')) ?></button></form></td><?php endif; ?>
                    </tr>
                <?php endforeach; ?>
            </tbody></table></div>
        <?php else: ?><p class="empty-state"><?= e(t('No records have been added yet.')) ?></p><?php endif; ?>
    </section>
<?php endif; ?>
<?php require __DIR__ . '/includes/footer.php'; ?>
