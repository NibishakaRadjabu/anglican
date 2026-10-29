<?php
declare(strict_types=1);

require_once __DIR__ . '/config/app.php';
$user = require_login();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit;
}
verify_csrf();
$id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT) ?: 0;
if ($id < 1) {
    http_response_code(400);
    exit(t('Choose a valid notification.'));
}
$statement = db()->prepare('UPDATE notifications SET read_at = NOW() WHERE id = ? AND user_id = ? AND read_at IS NULL');
$statement->execute([$id, (int) $user['id']]);
header('Content-Type: application/json; charset=utf-8');
echo json_encode(['updated' => $statement->rowCount() > 0], JSON_THROW_ON_ERROR);
