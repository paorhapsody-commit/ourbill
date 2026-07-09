<?php
require_once 'auth.php';

// ล็อกอินอยู่แล้ว ส่งเข้าหน้าหลัก
if (is_logged_in()) { header('Location: index.php'); exit; }

// เก็บ invite key ใน session เพื่อใช้ใน callback.php หลังล็อกอิน
if (isset($_GET['invite'])) {
    $invKey = preg_replace('/[^a-f0-9]/i', '', (string) ($_GET['invite'] ?? ''));
    if (strlen($invKey) === 32) {
        $_SESSION['invite_key'] = $invKey;
    }
}

$errors = [
    'cancel'  => 'คุณยกเลิกการเข้าสู่ระบบ',
    'state'   => 'เซสชันหมดอายุ กรุณาลองใหม่อีกครั้ง',
    'token'   => 'แลกข้อมูลกับ Google ไม่สำเร็จ ลองใหม่อีกครั้ง',
    'email'   => 'ไม่สามารถอ่านอีเมลจากบัญชี Google ได้',
    'blocked' => 'บัญชีนี้ถูกระงับการใช้งาน กรุณาติดต่อผู้ดูแลระบบ',
];
$err = $_GET['err'] ?? '';
$msg = $errors[$err] ?? '';
$isPending   = isset($_GET['pending']);
$pendingMail = $_GET['e'] ?? '';
$configured  = google_configured();
?><!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>เข้าสู่ระบบ · OurBill</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://unpkg.com/lucide@latest"></script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Sans+Thai:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'IBM Plex Sans Thai', sans-serif; }
        @keyframes pop { 0% { transform: scale(.96); opacity: 0 } 100% { transform: scale(1); opacity: 1 } }
        .animate-pop { animation: pop .3s cubic-bezier(.2,.8,.3,1) both; }
    </style>
</head>
<body class="min-h-screen bg-gradient-to-br from-emerald-50 via-teal-50 to-cyan-50 flex items-center justify-center p-4 text-slate-800">

    <div class="w-full max-w-sm animate-pop">
        <!-- โลโก้ -->
        <div class="text-center mb-7">
            <span class="inline-grid place-items-center w-16 h-16 rounded-3xl bg-gradient-to-br from-emerald-400 to-teal-500 text-white shadow-xl shadow-emerald-200 mb-3">
                <i data-lucide="wallet-cards" class="w-8 h-8"></i>
            </span>
            <h1 class="text-2xl font-extrabold tracking-tight"><?= htmlspecialchars(setting('app_name', 'OurBill')) ?></h1>
            <p class="text-sm text-emerald-600 font-medium"><?= htmlspecialchars(setting('app_tagline', 'หารกันให้ชัด ไม่มีลืม')) ?></p>
        </div>

        <div class="bg-white rounded-3xl border border-slate-100 shadow-sm p-7">
            <h2 class="font-bold text-slate-700 text-center mb-1">เข้าสู่ระบบ</h2>
            <p class="text-xs text-slate-400 text-center mb-6">ครั้งแรกที่เข้า ต้องรอผู้ดูแลอนุมัติก่อนใช้งาน</p>

            <?php if ($isPending): ?>
                <div class="mb-5 p-4 bg-amber-50 border border-amber-200 text-amber-800 text-sm rounded-xl flex items-start gap-2">
                    <i data-lucide="clock" class="w-4 h-4 mt-0.5 shrink-0"></i>
                    <span>
                        <b>บัญชีของคุณกำลังรอการอนุมัติ</b><br>
                        <?php if ($pendingMail): ?><span class="text-xs"><?= htmlspecialchars($pendingMail) ?></span><br><?php endif; ?>
                        <span class="text-xs">โปรดติดต่อผู้ดูแลให้อนุมัติ แล้วล็อกอินอีกครั้ง</span>
                    </span>
                </div>
            <?php endif; ?>
            <?php if ($msg): ?>
                <div class="mb-5 p-3 bg-rose-50 border border-rose-200 text-rose-700 text-sm rounded-xl flex items-start gap-2">
                    <i data-lucide="alert-triangle" class="w-4 h-4 mt-0.5 shrink-0"></i>
                    <span><?= htmlspecialchars($msg) ?></span>
                </div>
            <?php endif; ?>

            <?php if (!$configured): ?>
                <div class="p-4 bg-amber-50 border border-amber-200 text-amber-800 text-sm rounded-xl">
                    <p class="font-semibold flex items-center gap-1.5 mb-1"><i data-lucide="settings" class="w-4 h-4"></i> ยังไม่ได้ตั้งค่า Google</p>
                    <p class="text-xs leading-relaxed">ผู้ดูแลต้องตั้งค่า <b>Client ID / Secret / Redirect URI</b> ที่ <a href="admin_login.php" class="underline font-semibold">แผงผู้ดูแลระบบ</a> ก่อน</p>
                </div>
            <?php else: ?>
                <a href="<?= htmlspecialchars(google_login_url()) ?>"
                   class="w-full flex items-center justify-center gap-3 bg-white border border-slate-200 hover:border-emerald-400 hover:shadow-md text-slate-700 font-semibold py-3 rounded-xl transition">
                    <svg class="w-5 h-5" viewBox="0 0 48 48"><path fill="#FFC107" d="M43.611 20.083H42V20H24v8h11.303c-1.649 4.657-6.08 8-11.303 8c-6.627 0-12-5.373-12-12s5.373-12 12-12c3.059 0 5.842 1.154 7.961 3.039l5.657-5.657C34.046 6.053 29.268 4 24 4C12.955 4 4 12.955 4 24s8.955 20 20 20s20-8.955 20-20c0-1.341-.138-2.65-.389-3.917z"/><path fill="#FF3D00" d="m6.306 14.691l6.571 4.819C14.655 15.108 18.961 12 24 12c3.059 0 5.842 1.154 7.961 3.039l5.657-5.657C34.046 6.053 29.268 4 24 4C16.318 4 9.656 8.337 6.306 14.691z"/><path fill="#4CAF50" d="M24 44c5.166 0 9.86-1.977 13.409-5.192l-6.19-5.238A11.91 11.91 0 0 1 24 36c-5.202 0-9.619-3.317-11.283-7.946l-6.522 5.025C9.505 39.556 16.227 44 24 44z"/><path fill="#1976D2" d="M43.611 20.083H42V20H24v8h11.303a12.04 12.04 0 0 1-4.087 5.571l.003-.002l6.19 5.238C36.971 39.205 44 34 44 24c0-1.341-.138-2.65-.389-3.917z"/></svg>
                    เข้าสู่ระบบด้วย Google
                </a>
            <?php endif; ?>
        </div>

        <p class="text-center text-xs text-slate-400 mt-6">
            <a href="admin_login.php" class="hover:text-emerald-600 inline-flex items-center gap-1">
                <i data-lucide="shield-check" class="w-3.5 h-3.5"></i> เข้าสู่ระบบผู้ดูแล
            </a>
        </p>
    </div>

    <script>lucide.createIcons();</script>
</body>
</html>
