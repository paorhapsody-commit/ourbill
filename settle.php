<?php
require_once 'auth.php';
require_login();
require_once 'config.php';
require_once '_layout.php';

$me       = (int) ($_SESSION['user']['account_id'] ?? 0);
$myMember = (int) ($_SESSION['user']['member_id'] ?? 0);

// บันทึกการโอนเงินคืน (settlement — มีผลกับยอดบิล)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'settle') {
    $from = intval($_POST['from_user'] ?? 0);
    $to   = intval($_POST['to_user'] ?? 0);
    $amt  = round((float) ($_POST['amount'] ?? 0), 2);
    if ($from > 0 && $to > 0 && $from !== $to && $amt > 0) {
        sb_insert('settlements', [
            'from_user' => $from, 'to_user' => $to, 'amount' => $amt,
            'note' => trim($_POST['note'] ?? '') ?: null,
        ]);
    }
    header('Location: settle.php?done=1');
    exit;
}

$friends = unified_balances($myMember);
$members = array_values(selectable_members($me)); // me + friends (สำหรับฟอร์มกำหนดเอง)
$history = sb_get('settlements?select=*,from:from_user(name),to:to_user(name)&order=created_at.desc&limit=15') ?: [];

// คัดเฉพาะเพื่อนที่ยังมียอด "ค่าบิล" ค้าง (settlement เคลียร์เฉพาะส่วนบิล)
$pending = [];
foreach ($friends as $f) {
    $billNet = round($f['bill'] + $f['settle'], 2);
    if (abs($billNet) > 0.009) { $f['billNet'] = $billNet; $pending[] = $f; }
}

layout_head('เคลียร์หนี้', 'settle.php');
?>

<!-- แท็บย่อย -->
<div class="flex gap-2 mb-5 flex-wrap">
    <a href="settle.php" class="px-4 py-2 rounded-xl text-sm font-semibold bg-gradient-to-br from-emerald-400 to-teal-500 text-white shadow-md shadow-emerald-200">ค่าบิล</a>
    <a href="holdings.php" class="px-4 py-2 rounded-xl text-sm font-semibold bg-white border border-slate-200 text-slate-500 hover:text-emerald-600">เงินที่ถือไว้</a>
    <a href="installments.php" class="px-4 py-2 rounded-xl text-sm font-semibold bg-white border border-slate-200 text-slate-500 hover:text-emerald-600">ผ่อนรายเดือน</a>
</div>

<h1 class="text-xl font-bold text-slate-700 flex items-center gap-2 mb-1">
    <i data-lucide="arrow-right-left" class="w-6 h-6 text-emerald-500"></i> เคลียร์หนี้ค่าบิล
</h1>
<p class="text-sm text-slate-400 mb-6">ยอดค่าบิลระหว่างเรากับเพื่อน · กดเคลียร์เมื่อจ่ายเงินจริงแล้ว (เงินที่ถือไว้/ผ่อนรายเดือน เคลียร์ที่แท็บของมันเอง)</p>

<?php if (isset($_GET['done'])): ?>
    <div class="mb-5 p-3.5 bg-emerald-50 border border-emerald-200 text-emerald-700 text-sm font-medium rounded-xl flex items-center gap-2">
        <i data-lucide="check-circle" class="w-4 h-4"></i> บันทึกการโอนเรียบร้อย ยอดอัปเดตแล้ว
    </div>
<?php endif; ?>

<!-- รายการค่าบิลที่ต้องเคลียร์ -->
<?php if ($myMember === 0): ?>
    <div class="mb-6 p-4 bg-amber-50 border border-amber-200 text-amber-800 text-sm rounded-xl flex items-center gap-2">
        <i data-lucide="alert-triangle" class="w-4 h-4"></i> เซสชันเก่า — <a href="logout.php" class="font-semibold underline">ออกแล้วล็อกอินใหม่</a>
    </div>
<?php elseif (empty($pending)): ?>
    <div class="bg-white rounded-2xl border border-slate-100 shadow-sm p-10 text-center mb-8">
        <span class="inline-grid place-items-center w-16 h-16 rounded-full bg-emerald-100 text-emerald-500 mb-3">
            <i data-lucide="party-popper" class="w-8 h-8"></i>
        </span>
        <p class="font-bold text-slate-700 text-lg">ค่าบิลเคลียร์หมดแล้ว! 🎉</p>
        <p class="text-sm text-slate-400 mt-1">ไม่มีหนี้ค่าบิลค้างกับเพื่อน</p>
    </div>
<?php else: ?>
    <div class="space-y-3 mb-8">
        <?php foreach ($pending as $f):
            $billNet = $f['billNet'];
            $friendOwesMe = $billNet > 0;
            // ทิศทางการโอนเพื่อเคลียร์ส่วนบิล
            $fromId = $friendOwesMe ? $f['id'] : $myMember;
            $toId   = $friendOwesMe ? $myMember : $f['id']; ?>
            <div class="bg-white rounded-2xl border border-slate-100 shadow-sm p-4 flex flex-col sm:flex-row sm:items-center gap-3">
                <a href="friend.php?id=<?= $f['id'] ?>" class="flex items-center gap-3 flex-1 min-w-0 group">
                    <?= avatar($f['id'], $f['name'], 'w-11 h-11 text-base') ?>
                    <div class="min-w-0">
                        <p class="font-bold text-slate-800 truncate group-hover:text-emerald-600 transition"><?= htmlspecialchars($f['name']) ?></p>
                        <p class="text-xs <?= $friendOwesMe ? 'text-emerald-600' : 'text-rose-500' ?>">
                            <?= $friendOwesMe ? 'ติดค่าบิลเรา' : 'เราติดค่าบิล' ?> <?= baht(abs($billNet)) ?> ฿
                        </p>
                        <?php if (abs($f['net']) > 0.009 && abs($f['net'] - $billNet) > 0.009): ?>
                            <p class="text-[11px] text-slate-400">ยอดรวมทุกอย่าง: <?= $f['net'] >= 0 ? '+' : '' ?><?= baht($f['net']) ?> ฿</p>
                        <?php endif; ?>
                    </div>
                </a>
                <form method="POST" class="sm:w-auto"
                      onsubmit="return confirm('บันทึกว่าเคลียร์ค่าบิล <?= baht(abs($billNet)) ?> ฿ กับ <?= htmlspecialchars(addslashes($f['name'])) ?> ?');">
                    <input type="hidden" name="action" value="settle">
                    <input type="hidden" name="from_user" value="<?= $fromId ?>">
                    <input type="hidden" name="to_user" value="<?= $toId ?>">
                    <input type="hidden" name="amount" value="<?= abs($billNet) ?>">
                    <input type="hidden" name="note" value="เคลียร์ค่าบิล">
                    <button type="submit"
                            class="w-full sm:w-auto bg-gradient-to-br from-emerald-400 to-teal-500 hover:from-emerald-500 hover:to-teal-600 text-white font-semibold text-sm px-5 py-2.5 rounded-xl shadow-md shadow-emerald-200 transition flex items-center justify-center gap-2">
                        <i data-lucide="check" class="w-4 h-4"></i> เคลียร์แล้ว
                    </button>
                </form>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<!-- บันทึกการโอนแบบกำหนดเอง -->
<?php if (!empty($members)): ?>
<details class="bg-white rounded-2xl border border-slate-100 shadow-sm mb-8 group">
    <summary class="flex items-center gap-2 p-4 cursor-pointer font-semibold text-slate-600 text-sm select-none">
        <i data-lucide="plus-circle" class="w-4 h-4 text-emerald-500"></i> บันทึกการโอนแบบกำหนดเอง
        <i data-lucide="chevron-down" class="w-4 h-4 ml-auto text-slate-400 group-open:rotate-180 transition"></i>
    </summary>
    <form method="POST" class="p-4 pt-0 grid grid-cols-1 sm:grid-cols-4 gap-3 items-end">
        <input type="hidden" name="action" value="settle">
        <div>
            <label class="block text-xs font-semibold text-slate-500 mb-1">คนจ่าย</label>
            <select name="from_user" class="w-full px-3 py-2 border border-slate-200 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-emerald-400">
                <?php foreach ($members as $u): ?><option value="<?= $u['id'] ?>" <?= $u['id'] == $myMember ? 'selected' : '' ?>><?= htmlspecialchars($u['name']) ?></option><?php endforeach; ?>
            </select>
        </div>
        <div>
            <label class="block text-xs font-semibold text-slate-500 mb-1">คนรับ</label>
            <select name="to_user" class="w-full px-3 py-2 border border-slate-200 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-emerald-400">
                <?php foreach ($members as $u): ?><option value="<?= $u['id'] ?>"><?= htmlspecialchars($u['name']) ?></option><?php endforeach; ?>
            </select>
        </div>
        <div>
            <label class="block text-xs font-semibold text-slate-500 mb-1">จำนวน (฿)</label>
            <input type="number" name="amount" step="0.01" min="0.01" required placeholder="0.00"
                   class="w-full px-3 py-2 border border-slate-200 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-emerald-400">
        </div>
        <button type="submit" class="bg-emerald-500 hover:bg-emerald-600 text-white font-semibold text-sm py-2 rounded-lg transition">บันทึก</button>
    </form>
</details>
<?php endif; ?>

<!-- ประวัติการเคลียร์ -->
<h2 class="text-lg font-bold text-slate-700 flex items-center gap-2 mb-4">
    <i data-lucide="history" class="w-5 h-5 text-emerald-500"></i> ประวัติการโอนคืน
</h2>
<div class="bg-white rounded-2xl border border-slate-100 shadow-sm overflow-hidden">
    <?php if (empty($history)): ?>
        <p class="p-8 text-center text-slate-400">ยังไม่มีประวัติการเคลียร์หนี้</p>
    <?php else: foreach ($history as $h): ?>
        <div class="flex items-center gap-3 p-4 border-b border-slate-50 last:border-0">
            <span class="grid place-items-center w-9 h-9 rounded-xl bg-emerald-50 text-emerald-500 shrink-0">
                <i data-lucide="banknote-arrow-up" class="w-4 h-4"></i>
            </span>
            <div class="text-sm min-w-0">
                <p class="text-slate-700">
                    <b><?= htmlspecialchars($h['from']['name'] ?? '?') ?></b>
                    <i data-lucide="arrow-right" class="inline w-3.5 h-3.5 text-slate-300"></i>
                    <b><?= htmlspecialchars($h['to']['name'] ?? '?') ?></b>
                </p>
                <p class="text-xs text-slate-400"><?= date('d/m/y H:i', strtotime($h['created_at'])) ?><?= $h['note'] ? ' · ' . htmlspecialchars($h['note']) : '' ?></p>
            </div>
            <span class="ml-auto font-bold text-emerald-600 shrink-0"><?= baht($h['amount']) ?> ฿</span>
        </div>
    <?php endforeach; endif; ?>
</div>

<?php layout_foot(); ?>
