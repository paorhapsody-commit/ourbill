<?php
require_once 'auth.php';

// ผู้ใช้กดยกเลิกที่หน้า Google
if (isset($_GET['error'])) {
    header('Location: login.php?err=cancel');
    exit;
}

$code  = $_GET['code']  ?? '';
$state = $_GET['state'] ?? '';

// ตรวจ state กัน CSRF
if ($code === '' || $state === '' || !hash_equals($_SESSION['oauth_state'] ?? '', $state)) {
    header('Location: login.php?err=state');
    exit;
}
unset($_SESSION['oauth_state']);

// แลก code เป็น token
$token = google_exchange_code($code);
if (empty($token['access_token'])) {
    header('Location: login.php?err=token');
    exit;
}

// ดึงโปรไฟล์
$info = google_fetch_userinfo($token['access_token']);
$email = $info['email'] ?? '';

if ($email === '' || empty($info['email_verified'])) {
    header('Location: login.php?err=email');
    exit;
}

// โมเดลอนุมัติ: ทุกคนล็อกอินได้ แต่ต้องได้รับการอนุมัติจากแอดมินก่อนใช้งาน
$status = account_resolve_status($info);
if ($status === 'blocked') {
    header('Location: login.php?err=blocked');
    exit;
}
if ($status !== 'approved') {
    header('Location: login.php?pending=1&e=' . urlencode($email));
    exit;
}

// อนุมัติแล้ว — สร้าง/หาแถวสมาชิก แล้วเก็บ session
$account = account_find($email);
$member  = $account ? ensure_member($account) : null;

session_regenerate_id(true);
$_SESSION['user'] = [
    'sub'        => $info['sub']     ?? '',
    'email'      => $email,
    'name'       => $info['name']    ?? $email,
    'picture'    => $info['picture'] ?? '',
    'account_id' => $account['id']   ?? null,
    'member_id'  => $member['id']    ?? null,
    'theme'      => $account['theme'] ?? '',
];

header('Location: index.php');
exit;
