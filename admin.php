<?php
require_once 'auth.php';
require_admin();

// ออกจากระบบแอดมิน
if (isset($_GET['logout'])) {
    unset($_SESSION['admin']);
    header('Location: admin_login.php?bye=1');
    exit;
}

$error = '';

/** จบงานเขียน: เช็ค token หมดอายุ แล้ว redirect */
function finish_write($res) {
    if (($res['status'] ?? 0) === 401) {
        unset($_SESSION['admin']);
        header('Location: admin_login.php?err=expired');
        exit;
    }
    if (($res['status'] ?? 0) >= 200 && $res['status'] < 300) {
        header('Location: admin.php?saved=1');
        exit;
    }
    return 'ทำรายการไม่สำเร็จ (HTTP ' . ($res['status'] ?? '?') . ') ' . ($res['body']['message'] ?? '');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'save_settings') {
        $rows = [
            ['key' => 'app_name',            'value' => trim($_POST['app_name'] ?? '')],
            ['key' => 'app_tagline',         'value' => trim($_POST['app_tagline'] ?? '')],
            ['key' => 'google_client_id',    'value' => trim($_POST['google_client_id'] ?? '')],
            ['key' => 'google_redirect_uri', 'value' => trim($_POST['google_redirect_uri'] ?? '')],
        ];
        // อัปเดต secret เฉพาะเมื่อกรอกใหม่ (เว้นว่าง = คงค่าเดิม)
        $secret = trim($_POST['google_client_secret'] ?? '');
        if ($secret !== '') $rows[] = ['key' => 'google_client_secret', 'value' => $secret];

        $error = finish_write(sb_upsert('settings', $rows, 'key', admin_token()));

    } elseif ($action === 'account') {
        $id  = intval($_POST['account_id'] ?? 0);
        $new = $_POST['new_status'] ?? '';
        if ($id > 0) {
            if ($new === 'delete') {
                $error = finish_write(supabase_call('app_accounts?id=eq.' . $id, 'DELETE', null, [], admin_token()));
            } elseif (in_array($new, ['approved', 'blocked', 'pending'], true)) {
                $error = finish_write(supabase_call('app_accounts?id=eq.' . $id, 'PATCH', ['status' => $new], [], admin_token()));
            }
        }
    }
}

$s        = app_settings(true);
$admin    = current_admin();
$accounts = sb_get('app_accounts?select=*&order=created_at.desc') ?: [];

// เรียงให้ pending ขึ้นก่อน
$rank = ['pending' => 0, 'approved' => 1, 'blocked' => 2];
usort($accounts, fn($a, $b) => ($rank[$a['status']] ?? 9) <=> ($rank[$b['status']] ?? 9));

$cntPending  = count(array_filter($accounts, fn($a) => $a['status'] === 'pending'));
$cntApproved = count(array_filter($accounts, fn($a) => $a['status'] === 'approved'));

$badge = [
    'pending'  => ['ป้าย' => 'รออนุมัติ', 'cls' => 'bg-amber-100 text-amber-700',  'icon' => 'clock'],
    'approved' => ['ป้าย' => 'อนุมัติแล้ว', 'cls' => 'bg-emerald-100 text-emerald-700', 'icon' => 'check'],
    'blocked'  => ['ป้าย' => 'ถูกบล็อก',  'cls' => 'bg-rose-100 text-rose-700',     'icon' => 'ban'],
];

function acct_btn($id, $status, $label, $cls, $icon, $confirm = '') {
    $onsubmit = $confirm ? ' onsubmit="return confirm(\'' . htmlspecialchars($confirm, ENT_QUOTES) . '\')"' : '';
    echo '<form method="POST" class="inline"' . $onsubmit . '>'
        . '<input type="hidden" name="action" value="account">'
        . '<input type="hidden" name="account_id" value="' . $id . '">'
        . '<input type="hidden" name="new_status" value="' . $status . '">'
        . '<button type="submit" class="inline-flex items-center gap-1 text-xs font-semibold px-2.5 py-1.5 rounded-lg transition ' . $cls . '">'
        . '<i data-lucide="' . $icon . '" class="w-3.5 h-3.5"></i>' . $label . '</button></form>';
}
?><!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ตั้งค่าระบบ · OurBill Admin</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://unpkg.com/lucide@latest"></script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Sans+Thai:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'IBM Plex Sans Thai', sans-serif; }
        @keyframes pop { 0% { transform: scale(.98); opacity: 0 } 100% { transform: scale(1); opacity: 1 } }
        .animate-pop { animation: pop .25s cubic-bezier(.2,.8,.3,1) both; }
    </style>
</head>
<body class="min-h-screen bg-slate-100 text-slate-800">

    <nav class="bg-slate-900 text-white">
        <div class="max-w-3xl mx-auto px-4 py-3 flex items-center justify-between">
            <div class="flex items-center gap-2.5">
                <span class="grid place-items-center w-10 h-10 rounded-2xl bg-gradient-to-br from-emerald-400 to-teal-500 shadow-lg">
                    <i data-lucide="shield-check" class="w-5 h-5"></i>
                </span>
                <div class="leading-tight">
                    <p class="font-bold text-lg">แผงผู้ดูแลระบบ</p>
                    <p class="text-[11px] text-emerald-300/80 -mt-0.5"><?= htmlspecialchars($admin['email']) ?></p>
                </div>
            </div>
            <div class="flex items-center gap-2">
                <a href="index.php" class="text-sm font-medium text-slate-300 hover:text-white px-3 py-2 rounded-lg hover:bg-white/10 transition flex items-center gap-1.5">
                    <i data-lucide="external-link" class="w-4 h-4"></i> <span class="hidden sm:inline">เปิดแอป</span>
                </a>
                <a href="admin.php?logout=1" class="text-sm font-medium text-rose-300 hover:text-rose-100 px-3 py-2 rounded-lg hover:bg-rose-500/20 transition flex items-center gap-1.5">
                    <i data-lucide="log-out" class="w-4 h-4"></i> <span class="hidden sm:inline">ออกจากระบบ</span>
                </a>
            </div>
        </div>
    </nav>

    <main class="max-w-3xl mx-auto px-4 py-7 animate-pop space-y-6">

        <?php if (isset($_GET['saved'])): ?>
            <div class="p-3.5 bg-emerald-50 border border-emerald-200 text-emerald-700 text-sm font-medium rounded-xl flex items-center gap-2">
                <i data-lucide="check-circle" class="w-4 h-4"></i> บันทึกเรียบร้อยแล้ว
            </div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="p-3.5 bg-rose-50 border border-rose-200 text-rose-700 text-sm font-medium rounded-xl flex items-center gap-2">
                <i data-lucide="alert-triangle" class="w-4 h-4"></i> <?= htmlspecialchars($error) ?>
            </div>
        <?php endif; ?>

        <!-- สรุป -->
        <div class="grid grid-cols-3 gap-3">
            <div class="bg-white rounded-2xl p-4 border border-slate-200 shadow-sm">
                <p class="text-xs text-slate-400 mb-1">รออนุมัติ</p>
                <p class="text-2xl font-extrabold text-amber-600"><?= $cntPending ?></p>
            </div>
            <div class="bg-white rounded-2xl p-4 border border-slate-200 shadow-sm">
                <p class="text-xs text-slate-400 mb-1">อนุมัติแล้ว</p>
                <p class="text-2xl font-extrabold text-emerald-600"><?= $cntApproved ?></p>
            </div>
            <div class="bg-white rounded-2xl p-4 border border-slate-200 shadow-sm">
                <p class="text-xs text-slate-400 mb-1">Google OAuth</p>
                <p class="text-sm font-bold mt-1.5 <?= google_configured() ? 'text-emerald-600' : 'text-rose-500' ?>">
                    <?= google_configured() ? 'ตั้งค่าแล้ว' : 'ยังไม่ตั้ง' ?>
                </p>
            </div>
        </div>

        <!-- บัญชีผู้ใช้ (อนุมัติ) -->
        <div class="bg-white rounded-2xl border border-slate-200 shadow-sm overflow-hidden">
            <div class="p-5 border-b border-slate-100">
                <h2 class="font-bold text-slate-700 flex items-center gap-2"><i data-lucide="user-check" class="w-5 h-5 text-emerald-500"></i> บัญชีผู้ใช้</h2>
                <p class="text-xs text-slate-400 mt-0.5">ทุกคนล็อกอิน Google ได้ แต่ต้องได้รับอนุมัติจึงจะใช้งานแอปได้</p>
            </div>

            <?php if (empty($accounts)): ?>
                <p class="p-8 text-center text-slate-400 text-sm">ยังไม่มีใครล็อกอินเข้ามา</p>
            <?php else: foreach ($accounts as $a):
                $b = $badge[$a['status']] ?? $badge['pending']; ?>
                <div class="flex flex-wrap items-center gap-3 p-4 border-b border-slate-50 last:border-0">
                    <?php if (!empty($a['picture'])): ?>
                        <img src="<?= htmlspecialchars($a['picture']) ?>" referrerpolicy="no-referrer" alt="" class="w-10 h-10 rounded-full object-cover ring-2 ring-slate-100">
                    <?php else: ?>
                        <span class="grid place-items-center w-10 h-10 rounded-full bg-slate-100 text-slate-400"><i data-lucide="user" class="w-5 h-5"></i></span>
                    <?php endif; ?>
                    <div class="min-w-0 flex-1">
                        <p class="font-semibold text-slate-800 truncate"><?= htmlspecialchars($a['name'] ?: '—') ?></p>
                        <p class="text-xs text-slate-400 truncate"><?= htmlspecialchars($a['email']) ?></p>
                    </div>
                    <span class="inline-flex items-center gap-1 text-xs font-medium px-2.5 py-1 rounded-full <?= $b['cls'] ?>">
                        <i data-lucide="<?= $b['icon'] ?>" class="w-3.5 h-3.5"></i> <?= $b['ป้าย'] ?>
                    </span>
                    <div class="flex items-center gap-1.5 w-full sm:w-auto">
                        <?php
                        if ($a['status'] === 'pending') {
                            acct_btn($a['id'], 'approved', 'อนุมัติ', 'bg-emerald-500 hover:bg-emerald-600 text-white', 'check');
                            acct_btn($a['id'], 'blocked',  'บล็อก',  'bg-slate-100 hover:bg-rose-100 text-slate-500 hover:text-rose-600', 'ban');
                        } elseif ($a['status'] === 'approved') {
                            acct_btn($a['id'], 'pending', 'ยกเลิกอนุมัติ', 'bg-slate-100 hover:bg-amber-100 text-slate-500 hover:text-amber-700', 'undo-2');
                            acct_btn($a['id'], 'blocked', 'บล็อก', 'bg-slate-100 hover:bg-rose-100 text-slate-500 hover:text-rose-600', 'ban');
                        } else { // blocked
                            acct_btn($a['id'], 'approved', 'ปลดบล็อก', 'bg-emerald-500 hover:bg-emerald-600 text-white', 'check');
                        }
                        acct_btn($a['id'], 'delete', '', 'bg-slate-100 hover:bg-rose-100 text-slate-400 hover:text-rose-600', 'trash-2',
                                 'ลบบัญชี ' . $a['email'] . ' ?');
                        ?>
                    </div>
                </div>
            <?php endforeach; endif; ?>
        </div>

        <form method="POST" class="space-y-6">
            <input type="hidden" name="action" value="save_settings">

            <!-- ทั่วไป -->
            <div class="bg-white rounded-2xl border border-slate-200 shadow-sm p-6 space-y-4">
                <h2 class="font-bold text-slate-700 flex items-center gap-2"><i data-lucide="settings" class="w-5 h-5 text-emerald-500"></i> ทั่วไป</h2>
                <div>
                    <label class="block text-sm font-semibold text-slate-600 mb-1.5">ชื่อแอป</label>
                    <input type="text" name="app_name" value="<?= htmlspecialchars($s['app_name'] ?? 'OurBill') ?>"
                           class="w-full px-4 py-2.5 border border-slate-200 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-emerald-400">
                </div>
                <div>
                    <label class="block text-sm font-semibold text-slate-600 mb-1.5">คำโปรย (tagline)</label>
                    <input type="text" name="app_tagline" value="<?= htmlspecialchars($s['app_tagline'] ?? '') ?>"
                           class="w-full px-4 py-2.5 border border-slate-200 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-emerald-400">
                </div>
            </div>

            <!-- Google OAuth -->
            <div class="bg-white rounded-2xl border border-slate-200 shadow-sm p-6 space-y-4">
                <h2 class="font-bold text-slate-700 flex items-center gap-2"><i data-lucide="key-round" class="w-5 h-5 text-emerald-500"></i> Google OAuth</h2>
                <p class="text-xs text-slate-400 -mt-2">ตั้งค่าที่ <a href="https://console.cloud.google.com/" target="_blank" class="text-emerald-600 underline">Google Cloud Console</a> → Credentials → OAuth client ID (Web)</p>
                <div>
                    <label class="block text-sm font-semibold text-slate-600 mb-1.5">Client ID</label>
                    <input type="text" name="google_client_id" value="<?= htmlspecialchars($s['google_client_id'] ?? '') ?>" placeholder="xxxxx.apps.googleusercontent.com"
                           class="w-full px-4 py-2.5 border border-slate-200 rounded-xl text-sm font-mono focus:outline-none focus:ring-2 focus:ring-emerald-400">
                </div>
                <div>
                    <label class="block text-sm font-semibold text-slate-600 mb-1.5">Client Secret</label>
                    <input type="password" name="google_client_secret" autocomplete="new-password"
                           placeholder="<?= !empty($s['google_client_secret']) ? '•••••• (มีค่าเดิมอยู่ — เว้นว่างเพื่อคงไว้)' : 'GOCSPX-...' ?>"
                           class="w-full px-4 py-2.5 border border-slate-200 rounded-xl text-sm font-mono focus:outline-none focus:ring-2 focus:ring-emerald-400">
                </div>
                <div>
                    <label class="block text-sm font-semibold text-slate-600 mb-1.5">Redirect URI</label>
                    <input type="text" name="google_redirect_uri" value="<?= htmlspecialchars($s['google_redirect_uri'] ?? '') ?>" placeholder="<?= htmlspecialchars(current_callback_url()) ?>"
                           class="w-full px-4 py-2.5 border border-slate-200 rounded-xl text-sm font-mono focus:outline-none focus:ring-2 focus:ring-emerald-400">
                    <div class="mt-2 p-3 bg-slate-50 border border-slate-200 rounded-lg text-xs">
                        <p class="text-slate-500 mb-1">URL ของระบบนี้ตอนนี้ (เอาไปใส่ใน Google Console → Authorized redirect URIs):</p>
                        <code class="block bg-white border border-slate-200 rounded px-2 py-1 text-emerald-700 break-all"><?= htmlspecialchars(current_callback_url()) ?></code>
                        <p class="text-slate-400 mt-1.5">ปล่อยช่องบนว่างไว้ก็ได้ — ระบบจะใช้ URL นี้อัตโนมัติ</p>
                    </div>
                </div>
            </div>

            <button type="submit"
                    class="w-full sm:w-auto bg-gradient-to-br from-emerald-400 to-teal-500 hover:from-emerald-500 hover:to-teal-600 text-white font-bold py-3 px-8 rounded-xl shadow-lg shadow-emerald-200 transition flex items-center justify-center gap-2">
                <i data-lucide="save" class="w-5 h-5"></i> บันทึกการตั้งค่า
            </button>
        </form>
    </main>

    <script>lucide.createIcons();</script>
</body>
</html>
