<?php
declare(strict_types=1);

require_once __DIR__ . '/config/app.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit(t('Method not allowed.'));
}

verify_csrf();
$language = (string) ($_POST['language'] ?? '');
if (!array_key_exists($language, available_languages())) {
    http_response_code(400);
    exit(t('Choose a supported language.'));
}

$_SESSION['language'] = $language;
$returnTo = (string) ($_POST['return_to'] ?? 'dashboard.php');
$path = parse_url($returnTo, PHP_URL_PATH);
$query = parse_url($returnTo, PHP_URL_QUERY);
if (!is_string($path) || $path === '' || $path[0] !== '/' || str_starts_with($path, '//') || str_contains($path, '\\')) {
    redirect(current_user() ? 'dashboard.php' : 'login.php');
}

redirect($path . ($query ? '?' . $query : ''));
