<?php
require_once 'auth.php';

if (is_admin()) { header('Location: admin.php'); exit; }

$err = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    $pass  = $_POST['password'] ?? '';
    if ($email === '' || $pass === '') {
        $err = 'กรุณากรอกอีเมลและรหัสผ่าน';
    } else {
        $r = supabase_password_login($email, $pass);
        if ($r['status'] === 200 && !empty($r['body']['access_token'])) {
            session_regenerate_id(true);
            $_SESSION['admin'] = [
                'id'    => $r['body']['user']['id']    ?? '',
                'email' => $r['body']['user']['email'] ?? $email,
                'token' => $r['body']['access_token'],
            ];
            header('Location: admin.php');
            exit;
        }
        $err = $r['body']['error_description'] ?? $r['body']['msg'] ?? 'อีเมลหรือรหัสผ่านไม่ถูกต้อง';
    }
}
?><!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>เข้าสู่ระบบผู้ดูแล · OurBill</title>
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
<body class="min-h-screen bg-gradient-to-br from-slate-800 via-slate-900 to-emerald-950 flex items-center justify-center p-4 text-slate-100">

    <div class="w-full max-w-sm animate-pop">
        <div class="text-center mb-7">
            <span class="inline-grid place-items-center w-16 h-16 rounded-3xl bg-gradient-to-br from-emerald-400 to-teal-500 text-white shadow-xl shadow-emerald-900/50 mb-3">
                <i data-lucide="shield-check" class="w-8 h-8"></i>
            </span>
            <h1 class="text-2xl font-extrabold tracking-tight">แผงผู้ดูแลระบบ</h1>
            <p class="text-sm text-emerald-300/80 font-medium">OurBill Admin</p>
        </div>

        <div class="bg-white/10 backdrop-blur-xl rounded-3xl border border-white/10 shadow-2xl p-7">
            <?php if ($err): ?>
                <div class="mb-5 p-3 bg-rose-500/20 border border-rose-400/30 text-rose-100 text-sm rounded-xl flex items-start gap-2">
                    <i data-lucide="alert-triangle" class="w-4 h-4 mt-0.5 shrink-0"></i> <span><?= htmlspecialchars($err) ?></span>
                </div>
            <?php endif; ?>

            <form method="POST" class="space-y-4">
                <div>
                    <label class="block text-sm font-semibold text-slate-200 mb-1.5">อีเมลผู้ดูแล</label>
                    <div class="relative">
                        <i data-lucide="mail" class="w-4 h-4 absolute left-3.5 top-1/2 -translate-y-1/2 text-slate-400"></i>
                        <input type="email" name="email" required value="<?= htmlspecialchars($_POST['email'] ?? '') ?>"
                               class="w-full pl-10 pr-4 py-2.5 bg-white/5 border border-white/15 rounded-xl text-sm text-white placeholder-slate-400 focus:outline-none focus:ring-2 focus:ring-emerald-400">
                    </div>
                </div>
                <div>
                    <label class="block text-sm font-semibold text-slate-200 mb-1.5">รหัสผ่าน</label>
                    <div class="relative">
                        <i data-lucide="lock" class="w-4 h-4 absolute left-3.5 top-1/2 -translate-y-1/2 text-slate-400"></i>
                        <input type="password" name="password" required
                               class="w-full pl-10 pr-4 py-2.5 bg-white/5 border border-white/15 rounded-xl text-sm text-white focus:outline-none focus:ring-2 focus:ring-emerald-400">
                    </div>
                </div>
                <button type="submit"
                        class="w-full bg-gradient-to-br from-emerald-400 to-teal-500 hover:from-emerald-500 hover:to-teal-600 text-white font-bold py-3 rounded-xl shadow-lg shadow-emerald-900/40 transition flex items-center justify-center gap-2">
                    <i data-lucide="log-in" class="w-5 h-5"></i> เข้าสู่ระบบ
                </button>
            </form>
        </div>

        <p class="text-center text-xs text-slate-400 mt-6">
            <a href="login.php" class="hover:text-emerald-300 inline-flex items-center gap-1">
                <i data-lucide="arrow-left" class="w-3.5 h-3.5"></i> กลับหน้าผู้ใช้
            </a>
        </p>
    </div>

    <script>lucide.createIcons();</script>
</body>
</html>
