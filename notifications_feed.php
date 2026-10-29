<?php
declare(strict_types=1);

require_once __DIR__ . '/config/app.php';
$user = require_login();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

$statement = db()->prepare('SELECT id, notification_type, data_json, title, message, link, created_at FROM notifications WHERE user_id = ? AND read_at IS NULL ORDER BY id DESC LIMIT 20');
$statement->execute([(int) $user['id']]);
$notifications = $statement->fetchAll();
foreach ($notifications as &$notification) {
    $data = json_decode((string) ($notification['data_json'] ?? ''), true);
    $data = is_array($data) ? $data : [];
    if ($notification['notification_type'] === 'new_parish_request') {
        $notification['title'] = t('New parish request');
        $notification['message'] = strtr(t('{{parish}} was submitted by {{name}}.'), [
            '{{parish}}' => (string) ($data['parish'] ?? ''),
            '{{name}}' => (string) ($data['name'] ?? ''),
        ]);
    } elseif ($notification['notification_type'] === 'new_resignation_letter') {
        $notification['title'] = t('New resignation letter');
        $notification['message'] = strtr(t('{{name}} submitted a resignation letter.'), [
            '{{name}}' => (string) ($data['name'] ?? ''),
        ]);
    } elseif ($notification['notification_type'] === 'action') {
        $notification['title'] = t('Website activity');
        $notification['message'] = strtr(t('{{actor}}: {{action}} — {{item}}.'), [
            '{{actor}}' => (string) ($data['actor'] ?? ''),
            '{{action}}' => t((string) ($data['action'] ?? 'Updated')),
            '{{item}}' => t((string) ($data['item'] ?? 'Website')),
        ]);
    } else {
        $notification['title'] = t((string) $notification['title']);
    }
    unset($notification['notification_type'], $notification['data_json']);
}
unset($notification);
$count = db()->prepare('SELECT COUNT(*) FROM notifications WHERE user_id = ? AND read_at IS NULL');
$count->execute([(int) $user['id']]);
echo json_encode(['unread_count' => (int) $count->fetchColumn(), 'notifications' => $notifications], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
