<?php
declare(strict_types=1);

require_once __DIR__ . '/config/app.php';
$viewer = require_login();
$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT) ?: 0;
if ($id < 1) {
    http_response_code(404);
    exit;
}

if ($id !== (int) $viewer['id']) {
    if ($viewer['role'] !== 'bishop') {
        http_response_code(404);
        exit;
    }
    if (!empty($viewer['branch_id'])) {
        $scope = db()->prepare('SELECT COALESCE(u.branch_id, m.branch_id) FROM users u LEFT JOIN members m ON m.id = u.member_id WHERE u.id = ?');
        $scope->execute([$id]);
        if ((int) $scope->fetchColumn() !== (int) $viewer['branch_id']) {
            http_response_code(404);
            exit;
        }
    }
}

$statement = db()->prepare('SELECT image_mime, image_data FROM user_profile_pictures WHERE user_id = ?');
$statement->execute([$id]);
$picture = $statement->fetch();
if (!$picture) {
    http_response_code(404);
    exit;
}

header('Content-Type: ' . $picture['image_mime']);
header('Content-Length: ' . strlen($picture['image_data']));
header('Cache-Control: private, max-age=300');
header("Content-Security-Policy: default-src 'none'; sandbox");
echo $picture['image_data'];
