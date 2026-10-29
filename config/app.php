<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';
$languageCatalog = require __DIR__ . '/languages.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    ini_set('session.use_only_cookies', '1');
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_trans_sid', '0');
    ini_set('session.cookie_httponly', '1');
    ini_set('session.cookie_samesite', 'Lax');
    session_set_cookie_params([
        'httponly' => true,
        'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        'samesite' => 'Lax',
        'path' => '/',
    ]);
    session_start();
}

// Apply browser protections to every response, including authentication pages.
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
    header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
}

// A session expires after 30 minutes idle or 8 hours total.
if (isset($_SESSION['user'])) {
    $now = time();
    if (($now - (int) ($_SESSION['last_activity'] ?? $now)) > 1800
        || ($now - (int) ($_SESSION['created_at'] ?? $now)) > 28800) {
        $_SESSION = [];
        session_regenerate_id(true);
    } else {
        $_SESSION['last_activity'] = $now;
        if (($now - (int) ($_SESSION['regenerated_at'] ?? $now)) > 900) {
            session_regenerate_id(true);
            $_SESSION['regenerated_at'] = $now;
        }
    }
}

function e(?string $value): string
{
    return htmlspecialchars($value ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function current_language(): string
{
    global $languageCatalog;
    $language = $_SESSION['language'] ?? 'en';

    return isset($languageCatalog[$language]) ? $language : 'en';
}

function t(string $text): string
{
    global $languageCatalog;
    $translations = $languageCatalog[current_language()]['translations'];

    return $translations[$text] ?? $text;
}

function available_languages(): array
{
    global $languageCatalog;
    $languages = [];
    foreach ($languageCatalog as $code => $language) {
        $languages[$code] = $language['label'];
    }

    return $languages;
}

function localized_date(string $date): string
{
    $timestamp = strtotime($date);
    if ($timestamp === false) {
        return $date;
    }

    if (current_language() === 'fr') {
        $days = ['dim.', 'lun.', 'mar.', 'mer.', 'jeu.', 'ven.', 'sam.'];
        $months = ['janv.', 'févr.', 'mars', 'avr.', 'mai', 'juin', 'juil.', 'août', 'sept.', 'oct.', 'nov.', 'déc.'];
    } elseif (current_language() === 'sw') {
        $days = ['Jumapili', 'Jumatatu', 'Jumanne', 'Jumatano', 'Alhamisi', 'Ijumaa', 'Jumamosi'];
        $months = ['Jan', 'Feb', 'Mac', 'Apr', 'Mei', 'Jun', 'Jul', 'Ago', 'Sep', 'Okt', 'Nov', 'Des'];
    } elseif (current_language() === 'rw') {
        $days = ['Ku cyumweru', 'Kuwa mbere', 'Kuwa kabiri', 'Kuwa gatatu', 'Kuwa kane', 'Kuwa gatanu', 'Kuwa gatandatu'];
        $months = ['Mut.', 'Gas.', 'Wer.', 'Mat.', 'Gic.', 'Kam.', 'Nya.', 'Kan.', 'Nze.', 'Ukw.', 'Ugu.', 'Uku.'];
    } else {
        return date('D, j M Y', $timestamp);
    }

    return $days[(int) date('w', $timestamp)] . ', ' . date('j', $timestamp) . ' ' . $months[(int) date('n', $timestamp) - 1] . ' ' . date('Y', $timestamp);
}

function localized_month(string $date): string
{
    $timestamp = strtotime($date);
    if ($timestamp === false || current_language() === 'en') {
        return $timestamp === false ? $date : date('M', $timestamp);
    }

    $months = match (current_language()) {
        'fr' => ['janv.', 'févr.', 'mars', 'avr.', 'mai', 'juin', 'juil.', 'août', 'sept.', 'oct.', 'nov.', 'déc.'],
        'rw' => ['Mut.', 'Gas.', 'Wer.', 'Mat.', 'Gic.', 'Kam.', 'Nya.', 'Kan.', 'Nze.', 'Ukw.', 'Ugu.', 'Uku.'],
        default => ['Jan', 'Feb', 'Mac', 'Apr', 'Mei', 'Jun', 'Jul', 'Ago', 'Sep', 'Okt', 'Nov', 'Des'],
    };

    return $months[(int) date('n', $timestamp) - 1];
}

function localized_weekday(string $date): string
{
    $timestamp = strtotime($date);
    if ($timestamp === false || current_language() === 'en') {
        return $timestamp === false ? $date : date('D', $timestamp);
    }

    $days = match (current_language()) {
        'fr' => ['dim.', 'lun.', 'mar.', 'mer.', 'jeu.', 'ven.', 'sam.'],
        'rw' => ['Ku cyum.', 'Kuwa 1', 'Kuwa 2', 'Kuwa 3', 'Kuwa 4', 'Kuwa 5', 'Kuwa 6'],
        default => ['Jpl', 'Jtt', 'Jnn', 'Jtn', 'Alh', 'Ijm', 'Jms'],
    };

    return $days[(int) date('w', $timestamp)];
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['csrf_token'];
}

function verify_csrf(): void
{
    $token = $_POST['csrf_token'] ?? '';
    if (!is_string($token) || !hash_equals(csrf_token(), $token)) {
        http_response_code(400);
        exit(t('Invalid form token.') . ' ' . t('Please go back, refresh, and try again.'));
    }
}

function recovery_questions(): array
{
    return [
        'first_school' => 'What was the name of your first school?',
        'childhood_friend' => 'What was the first name of your childhood best friend?',
        'first_job' => 'What was your first job?',
        'birth_city' => 'In what city were you born?',
        'special_teacher' => 'What was the surname of a favorite teacher?',
    ];
}

function normalize_recovery_answer(string $answer): string
{
    $answer = strtolower(trim(preg_replace('/\s+/', ' ', $answer) ?? $answer));
    return function_exists('mb_strtolower') ? mb_strtolower($answer, 'UTF-8') : $answer;
}

function login_throttle_key(string $email): string
{
    $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
    return hash('sha256', strtolower(trim($email)) . "\0" . $ip);
}

function current_user(): ?array
{
    return $_SESSION['user'] ?? null;
}

function require_login(): array
{
    $user = current_user();
    if (!$user || !isset($user['id'])) {
        header('Location: login.php');
        exit;
    }

    $statement = db()->prepare('SELECT u.id, u.member_id, u.full_name, u.email, u.role, u.auth_version, COALESCE(u.branch_id, m.branch_id) AS branch_id, b.name AS branch_name FROM users u LEFT JOIN members m ON m.id = u.member_id LEFT JOIN branches b ON b.id = COALESCE(u.branch_id, m.branch_id) WHERE u.id = ? AND u.active = 1 LIMIT 1');
    $statement->execute([(int) $user['id']]);
    $activeUser = $statement->fetch();
    if (!$activeUser || (int) ($user['auth_version'] ?? 0) !== (int) $activeUser['auth_version']) {
        $_SESSION = [];
        session_regenerate_id(true);
        header('Location: login.php?expired=1');
        exit;
    }
    if (!role_has_all_branch_access($activeUser['role']) && empty($activeUser['branch_id'])) {
        http_response_code(403);
        exit(t('Your account must be assigned to a parish by the Bishop.'));
    }

    $_SESSION['user'] = $activeUser;
    return $activeUser;
}

function redirect(string $url): never
{
    header('Location: ' . $url);
    exit;
}

function role_label(string $role): string
{
    $label = match ($role) {
        'bishop' => 'Bishop',
        'archdeacon' => 'Archdeacon',
        'priest' => 'Priest / Pastor',
        'assistant_clergy' => 'Assistant clergy',
        'parish_council' => 'Parish council',
        'secretary' => 'Secretary',
        'treasurer' => 'Treasurer',
        'ministry_leader' => 'Ministry leader',
        'member' => 'Member',
        default => ucfirst($role),
    };

    return t($label);
}

function role_has_all_branch_access(string $role): bool
{
    return role_can_have_headquarters_access($role);
}

function role_can_have_headquarters_access(string $role): bool
{
    return in_array($role, ['bishop', 'archdeacon', 'priest', 'assistant_clergy', 'parish_council', 'secretary', 'treasurer', 'ministry_leader'], true);
}

function user_has_all_branch_access(array $user): bool
{
    return role_can_have_headquarters_access((string) $user['role']) && empty($user['branch_id']);
}

function role_requires_branch_assignment(string $role): bool
{
    return !in_array($role, ['bishop', 'archdeacon', 'member'], true);
}

function parish_worker_roles(): array
{
    return ['archdeacon', 'priest', 'assistant_clergy', 'parish_council', 'secretary', 'treasurer', 'ministry_leader'];
}

function user_is_parish_worker(array $user): bool
{
    return !empty($user['branch_id']) && in_array($user['role'], parish_worker_roles(), true);
}

function notify_headquarters_bishops(string $type, array $data, string $title, string $message, string $link): void
{
    $recipients = db()->query("SELECT id FROM users WHERE role = 'bishop' AND branch_id IS NULL AND active = 1")->fetchAll(PDO::FETCH_COLUMN);
    if (!$recipients) {
        return;
    }
    $statement = db()->prepare('INSERT INTO notifications (user_id, notification_type, data_json, title, message, link) VALUES (?, ?, ?, ?, ?, ?)');
    $dataJson = json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    foreach ($recipients as $recipientId) {
        $statement->execute([(int) $recipientId, $type, $dataJson, $title, $message, $link]);
    }
}

function notify_action(array $recipientIds, array $actor, string $action, string $item, string $link, bool $includeActor = false): void
{
    $recipientIds = array_values(array_unique(array_filter(
        array_map('intval', $recipientIds),
        static fn (int $id): bool => $id > 0 && ($includeActor || $id !== (int) $actor['id'])
    )));
    if (!$recipientIds) {
        return;
    }

    $statement = db()->prepare('INSERT INTO notifications (user_id, notification_type, data_json, title, message, link) VALUES (?, ?, ?, ?, ?, ?)');
    $dataJson = json_encode([
        'actor' => (string) ($actor['full_name'] ?? ''),
        'action' => $action,
        'item' => $item,
    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    foreach ($recipientIds as $recipientId) {
        $statement->execute([$recipientId, 'action', $dataJson, 'Website activity', '', $link]);
    }
}

function notify_branch_action(int $branchId, array $actor, string $action, string $item, string $link): void
{
    $statement = db()->prepare('SELECT id FROM users WHERE active = 1 AND (branch_id = ? OR branch_id IS NULL)');
    $statement->execute([$branchId]);
    notify_action($statement->fetchAll(PDO::FETCH_COLUMN), $actor, $action, $item, $link);
}

function notify_headquarters_action(array $actor, string $action, string $item, string $link): void
{
    $recipients = db()->query('SELECT id FROM users WHERE active = 1 AND branch_id IS NULL')->fetchAll(PDO::FETCH_COLUMN);
    notify_action($recipients, $actor, $action, $item, $link);
}

function notify_user_action(int $recipientId, array $actor, string $action, string $item, string $link): void
{
    notify_action([$recipientId], $actor, $action, $item, $link, true);
}

function church_record_manager_roles(): array
{
    return ['bishop', 'archdeacon', 'priest', 'assistant_clergy', 'parish_council', 'secretary', 'treasurer', 'ministry_leader'];
}

function activity_supervisor_roles(): array
{
    return ['bishop', 'archdeacon', 'priest', 'assistant_clergy', 'parish_council', 'secretary', 'treasurer'];
}

function module_definitions(): array
{
    $branchStaffRoles = church_record_manager_roles();
    $memberFields = static fn (): array => [
        'member_id' => ['label' => 'Member', 'type' => 'select', 'required' => true, 'options' => 'members'],
    ];

    return [
        'members' => [
            'title' => 'Members',
            'table' => 'members',
            'read' => [...$branchStaffRoles, 'member'],
            'write' => $branchStaffRoles,
            'own_member' => true,
            'fields' => [
                'first_name' => ['label' => 'First name', 'required' => true],
                'last_name' => ['label' => 'Last name', 'required' => true],
                'gender' => ['label' => 'Gender', 'type' => 'select', 'options' => ['Female', 'Male', 'Prefer not to say']],
                'date_of_birth' => ['label' => 'Date of birth', 'type' => 'date'],
                'phone' => ['label' => 'Phone'],
                'email' => ['label' => 'Email', 'type' => 'email'],
                'address' => ['label' => 'Address', 'type' => 'textarea'],
                'joined_on' => ['label' => 'Date joined', 'type' => 'date'],
                'status' => ['label' => 'Status', 'type' => 'select', 'options' => ['Active', 'Inactive', 'Transferred']],
            ],
        ],
        'clergy' => [
            'title' => 'Clergy & workers',
            'table' => 'clergy',
            'read' => $branchStaffRoles,
            'write' => $branchStaffRoles,
            'fields' => [
                'full_name' => ['label' => 'Full name', 'required' => true],
                'title' => ['label' => 'Position', 'type' => 'select', 'options' => ['Bishop', 'Archdeacon', 'Priest', 'Pastor', 'Assistant clergy', 'Deacon', 'Parish council', 'Secretary', 'Treasurer', 'Ministry leader', 'Church worker']],
                'phone' => ['label' => 'Phone'],
                'email' => ['label' => 'Email', 'type' => 'email'],
                'parish' => ['label' => 'Parish'],
                'responsibilities' => ['label' => 'Responsibilities', 'type' => 'textarea'],
                'status' => ['label' => 'Status', 'type' => 'select', 'options' => ['Active', 'On leave', 'Retired']],
            ],
        ],
        'baptisms' => [
            'title' => 'Baptism records',
            'table' => 'baptisms',
            'read' => [...$branchStaffRoles, 'member'],
            'write' => $branchStaffRoles,
            'member_scope' => 'member_id',
            'fields' => [...$memberFields(), 'event_date' => ['label' => 'Baptism date', 'type' => 'date', 'required' => true], 'officiant' => ['label' => 'Officiant'], 'godparents' => ['label' => 'Godparents'], 'church' => ['label' => 'Church'], 'notes' => ['label' => 'Notes', 'type' => 'textarea']],
        ],
        'confirmations' => [
            'title' => 'Confirmation records',
            'table' => 'confirmations',
            'read' => [...$branchStaffRoles, 'member'],
            'write' => $branchStaffRoles,
            'member_scope' => 'member_id',
            'fields' => [...$memberFields(), 'event_date' => ['label' => 'Confirmation date', 'type' => 'date', 'required' => true], 'officiant' => ['label' => 'Officiant'], 'sponsor' => ['label' => 'Sponsor'], 'church' => ['label' => 'Church'], 'notes' => ['label' => 'Notes', 'type' => 'textarea']],
        ],
        'marriages' => [
            'title' => 'Marriage records',
            'table' => 'marriages',
            'read' => [...$branchStaffRoles, 'member'],
            'write' => $branchStaffRoles,
            'member_scope' => 'spouse_one_id',
            'member_scope_or' => 'spouse_two_id',
            'fields' => [
                'spouse_one_id' => ['label' => 'First spouse (member)', 'type' => 'select', 'options' => 'members'],
                'spouse_one_name' => ['label' => 'First spouse name', 'required' => true],
                'spouse_two_id' => ['label' => 'Second spouse (member)', 'type' => 'select', 'options' => 'members'],
                'spouse_two_name' => ['label' => 'Second spouse name', 'required' => true],
                'event_date' => ['label' => 'Marriage date', 'type' => 'date', 'required' => true],
                'officiant' => ['label' => 'Officiant'],
                'church' => ['label' => 'Church'],
                'notes' => ['label' => 'Notes', 'type' => 'textarea'],
            ],
        ],
        'funerals' => [
            'title' => 'Funeral records',
            'table' => 'funerals',
            'read' => [...$branchStaffRoles, 'member'],
            'write' => $branchStaffRoles,
            'member_scope' => 'member_id',
            'fields' => [...$memberFields(), 'event_date' => ['label' => 'Funeral date', 'type' => 'date', 'required' => true], 'officiant' => ['label' => 'Officiant'], 'notes' => ['label' => 'Notes', 'type' => 'textarea']],
        ],
        'attendance' => [
            'title' => 'Attendance',
            'table' => 'attendance',
            'read' => [...$branchStaffRoles, 'member'],
            'write' => $branchStaffRoles,
            'member_scope' => 'member_id',
            'fields' => [...$memberFields(), 'service_date' => ['label' => 'Date', 'type' => 'date', 'required' => true], 'service_name' => ['label' => 'Service', 'required' => true], 'group_name' => ['label' => 'Fellowship / group'], 'status' => ['label' => 'Attendance', 'type' => 'select', 'options' => ['Present', 'Absent', 'Excused'], 'required' => true]],
        ],
        'offerings' => [
            'title' => 'Tithes & offerings',
            'table' => 'offerings',
            'read' => $branchStaffRoles,
            'write' => $branchStaffRoles,
            'fields' => ['received_on' => ['label' => 'Date received', 'type' => 'date', 'required' => true], 'category' => ['label' => 'Category', 'type' => 'select', 'options' => ['Tithe', 'Offering', 'Donation', 'Fundraising'], 'required' => true], 'amount' => ['label' => 'Amount', 'type' => 'number', 'required' => true], 'payment_method' => ['label' => 'Payment method', 'type' => 'select', 'options' => ['Cash', 'Mobile money', 'Bank transfer', 'Other']], 'reference' => ['label' => 'Reference'], 'notes' => ['label' => 'Notes', 'type' => 'textarea']],
        ],
        'expenses' => [
            'title' => 'Expenses & budgets',
            'table' => 'expenses',
            'read' => $branchStaffRoles,
            'write' => $branchStaffRoles,
            'fields' => ['spent_on' => ['label' => 'Date', 'type' => 'date', 'required' => true], 'category' => ['label' => 'Category', 'required' => true], 'amount' => ['label' => 'Amount', 'type' => 'number', 'required' => true], 'payee' => ['label' => 'Payee'], 'description' => ['label' => 'Description', 'type' => 'textarea']],
        ],
        'budgets' => [
            'title' => 'Budgets',
            'table' => 'budgets',
            'read' => $branchStaffRoles,
            'write' => $branchStaffRoles,
            'fields' => ['period_start' => ['label' => 'Period starts', 'type' => 'date', 'required' => true], 'period_end' => ['label' => 'Period ends', 'type' => 'date', 'required' => true], 'category' => ['label' => 'Category', 'required' => true], 'amount' => ['label' => 'Budget amount', 'type' => 'number', 'required' => true], 'notes' => ['label' => 'Notes', 'type' => 'textarea']],
        ],
        'events' => [
            'title' => 'Events',
            'table' => 'events',
            'read' => [...$branchStaffRoles, 'member'],
            'write' => $branchStaffRoles,
            'fields' => ['title' => ['label' => 'Event name', 'required' => true], 'event_type' => ['label' => 'Type', 'type' => 'select', 'options' => ['Service', 'Conference', 'Wedding', 'Baptism', 'Funeral', 'Fellowship', 'Other']], 'starts_at' => ['label' => 'Starts', 'type' => 'datetime-local', 'required' => true], 'ends_at' => ['label' => 'Ends', 'type' => 'datetime-local'], 'location' => ['label' => 'Location'], 'description' => ['label' => 'Description', 'type' => 'textarea']],
        ],
        'announcements' => [
            'title' => 'Announcements & reminders',
            'table' => 'announcements',
            'read' => [...$branchStaffRoles, 'member'],
            'write' => $branchStaffRoles,
            'fields' => ['title' => ['label' => 'Title', 'required' => true], 'message' => ['label' => 'Announcement', 'type' => 'textarea', 'required' => true], 'publish_at' => ['label' => 'Publish date', 'type' => 'datetime-local']],
        ],
    ];
}
