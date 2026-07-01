<?php
/* บันทึกธีมสีของผู้ใช้ (เรียกจาก dropdown ใน _layout.php ผ่าน fetch POST) */
require_once 'auth.php';
header('Content-Type: application/json; charset=utf-8');

if (!is_logged_in()) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'unauthenticated']);
    exit;
}

$theme = $_POST['theme'] ?? '';
if (!save_user_theme($theme)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'invalid theme']);
    exit;
}

echo json_encode(['ok' => true, 'theme' => $theme]);
