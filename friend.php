<?php
require_once 'auth.php';
require_login();
require_once 'config.php';
require_once '_layout.php';

$me       = (int) ($_SESSION['user']['account_id'] ?? 0);
$myMember = (int) ($_SESSION['user']['member_id'] ?? 0);
$fid      = (int) ($_GET['id'] ?? 0);

// ตรวจว่า id เป็นสมาชิกที่เป็นเพื่อนกันจริง (ไม่ใช่ตัวเอง)
$members = [];
foreach (selectable_members($me) as $m) { $members[(int) $m['id']] = $m['name']; }
$validFriend = $fid > 0 && $fid !== $myMember && isset($members[$fid]);
$friendName  = $members[$fid] ?? ('#' . $fid);

// ยอดสุทธิรวมทุกฟังก์ชันของเพื่อนคนนี้
$bal = null;
if ($validFriend && $myMember > 0) {
    $all = unified_balances($myMember);
    $bal = $all[$fid] ?? ['bill' => 0, 'settle' => 0, 'holding' => 0, 'installment' => 0, 'net' => 0, 'name' => $friendName, 'id' => $fid];
}
$timeline = $validFriend && $myMember > 0 ? friend_timeline($myMember, $fid) : [];

layout_head($friendName, 'friends.php');
?>

<a href="index.php" class="inline-flex items-center gap-1.5 text-sm font-semibold text-slate-500 hover:text-emerald-600 mb-4">
    <i data-lucide="chevron-left" class="w-4 h-4"></i> กลับหน้าหลัก
</a>

<?php if (!$validFriend): ?>
    <div class="bg-white rounded-2xl border border-dashed border-slate-200 p-10 text-center text-slate-400">
        ไม่พบเพื่อนคนนี้ หรือยังไม่ได้เป็นเพื่อนกัน — <a href="friends.php" class="text-emerald-600 font-semibold">ไปหน้าเพื่อน</a>
    </div>
<?php elseif ($myMember === 0): ?>
    <div class="p-4 bg-amber-50 border border-amber-200 text-amber-800 text-sm rounded-xl flex items-center gap-2">
        <i data-lucide="alert-triangle" class="w-4 h-4"></i> เซสชันเก่า — <a href="logout.php" class="font-semibold underline">ออกแล้วล็อกอินใหม่</a>
    </div>
<?php else:
    $net = (float) $bal['net'];
    // bucket ที่จะโชว์ (เรียงตามฟังก์ชัน) — ค่าบวก = เพื่อนติดเรา
    $buckets = [
        ['label' => 'ค่าบิล',       'icon' => 'receipt',        'val' => $bal['bill'] + $bal['settle'], 'href' => 'settle.php'],
        ['label' => 'เงินที่ถือไว้', 'icon' => 'piggy-bank',      'val' => $bal['holding'],                'href' => 'holdings.php'],
        ['label' => 'ผ่อนรายเดือน',  'icon' => 'calendar-clock',  'val' => $bal['installment'],            'href' => 'installments.php'],
    ];
?>

<!-- การ์ดสรุปยอดสุทธิ -->
<div class="bg-white rounded-2xl border border-slate-100 shadow-sm p-5 mb-5">
    <div class="flex items-center gap-3.5">
        <?= avatar($fid, $friendName, 'w-14 h-14 text-xl') ?>
        <div class="min-w-0 flex-1">
            <h1 class="text-lg font-bold text-slate-800 truncate"><?= htmlspecialchars($friendName) ?></h1>
            <p class="text-xs text-slate-400">ยอดสุทธิรวมทุกอย่างระหว่างเรากับเพื่อน</p>
        </div>
        <div class="text-right shrink-0">
            <?php if ($net > 0.009): ?>
                <p class="text-2xl font-black text-emerald-600">+<?= baht($net) ?> ฿</p>
                <p class="text-[11px] text-emerald-600 font-medium">เพื่อนติดเรา</p>
            <?php elseif ($net < -0.009): ?>
                <p class="text-2xl font-black text-rose-500"><?= baht($net) ?> ฿</p>
                <p class="text-[11px] text-rose-500 font-medium">เราติดเพื่อน</p>
            <?php else: ?>
                <p class="text-2xl font-black text-slate-400">0.00 ฿</p>
                <p class="text-[11px] text-slate-400 font-medium">เคลียร์แล้ว</p>
            <?php endif; ?>
        </div>
    </div>

    <!-- แยกตามฟังก์ชัน -->
    <div class="grid grid-cols-3 gap-2 mt-5">
        <?php foreach ($buckets as $b): $v = round((float) $b['val'], 2); ?>
            <a href="<?= $b['href'] ?>" class="rounded-xl p-3 border border-slate-100 hover:border-emerald-200 hover:bg-emerald-50/40 transition text-center">
                <i data-lucide="<?= $b['icon'] ?>" class="w-4 h-4 mx-auto text-slate-400 mb-1"></i>
                <p class="text-[11px] text-slate-400 mb-0.5"><?= $b['label'] ?></p>
                <p class="font-bold text-sm <?= abs($v) < 0.009 ? 'text-slate-400' : ($v > 0 ? 'text-emerald-600' : 'text-rose-500') ?>">
                    <?= abs($v) < 0.009 ? '0.00' : (($v > 0 ? '+' : '−') . baht(abs($v))) ?>
                </p>
            </a>
        <?php endforeach; ?>
    </div>
</div>

<?php if (abs($net) > 0.009): ?>
<form method="POST" action="settle.php" class="mb-3"
      onsubmit="return confirm('เคลียร์ยอดสุทธิรวม <?= baht(abs($net)) ?> ฿ กับ <?= htmlspecialchars(addslashes($friendName)) ?> ?\nระบบจะปิดยอดทั้งบิล เงินที่ถือไว้ และผ่อน ให้เป็น 0');">
    <input type="hidden" name="action" value="settle_all">
    <input type="hidden" name="friend_id" value="<?= $fid ?>">
    <button type="submit" class="w-full bg-gradient-to-br from-emerald-400 to-teal-500 hover:from-emerald-500 hover:to-teal-600 text-white font-bold text-sm py-3 rounded-xl shadow-md shadow-emerald-200 transition flex items-center justify-center gap-2">
        <i data-lucide="check-check" class="w-4 h-4"></i> เคลียร์ยอดสุทธิทั้งหมด (<?= baht(abs($net)) ?> ฿)
    </button>
</form>
<?php endif; ?>

<!-- ปุ่มลัด -->
<div class="grid grid-cols-3 gap-2 mb-7">
    <a href="add-expense.php" class="flex flex-col items-center gap-1 py-3 rounded-xl bg-white border border-slate-100 shadow-sm text-slate-600 hover:text-emerald-600 hover:border-emerald-200 transition text-xs font-semibold">
        <i data-lucide="plus-circle" class="w-5 h-5"></i> เพิ่มบิล
    </a>
    <a href="settle.php" class="flex flex-col items-center gap-1 py-3 rounded-xl bg-white border border-slate-100 shadow-sm text-slate-600 hover:text-emerald-600 hover:border-emerald-200 transition text-xs font-semibold">
        <i data-lucide="arrow-right-left" class="w-5 h-5"></i> เคลียร์หนี้
    </a>
    <a href="holdings.php" class="flex flex-col items-center gap-1 py-3 rounded-xl bg-white border border-slate-100 shadow-sm text-slate-600 hover:text-emerald-600 hover:border-emerald-200 transition text-xs font-semibold">
        <i data-lucide="piggy-bank" class="w-5 h-5"></i> เงินที่ถือไว้
    </a>
</div>

<!-- ไทม์ไลน์รวมทุกธุรกรรม -->
<h2 class="text-lg font-bold text-slate-700 flex items-center gap-2 mb-1">
    <i data-lucide="history" class="w-5 h-5 text-emerald-500"></i> ประวัติทั้งหมดกับเพื่อนคนนี้
</h2>
<p class="text-xs text-slate-400 mb-4">รวมทุกฟังก์ชัน · เครื่องหมาย + = เพื่อนติดเราเพิ่ม / − = เราติดเพื่อนเพิ่ม</p>

<?php if (empty($timeline)): ?>
    <div class="bg-white rounded-2xl border border-dashed border-slate-200 p-10 text-center text-slate-400 text-sm">
        ยังไม่มีธุรกรรมกับเพื่อนคนนี้
    </div>
<?php else: ?>
    <div class="bg-white rounded-2xl border border-slate-100 shadow-sm overflow-hidden">
        <?php foreach ($timeline as $t):
            $imp = round((float) $t['impact'], 2);
            $pos = $imp >= 0; ?>
            <div class="flex items-center gap-3 p-4 border-b border-slate-50 last:border-0">
                <span class="grid place-items-center w-9 h-9 rounded-xl shrink-0 <?= $pos ? 'bg-emerald-50 text-emerald-500' : 'bg-rose-50 text-rose-500' ?>">
                    <i data-lucide="<?= htmlspecialchars($t['icon']) ?>" class="w-4 h-4"></i>
                </span>
                <div class="min-w-0 flex-1">
                    <p class="text-sm font-semibold text-slate-700 truncate"><?= htmlspecialchars($t['title']) ?></p>
                    <p class="text-xs text-slate-400">
                        <?= htmlspecialchars($t['sub']) ?>
                        <?php if (!empty($t['ts'])): ?> · <?= date('d/m/y H:i', strtotime($t['ts'])) ?><?php endif; ?>
                    </p>
                </div>
                <span class="font-bold text-sm shrink-0 <?= abs($imp) < 0.009 ? 'text-slate-400' : ($pos ? 'text-emerald-600' : 'text-rose-500') ?>">
                    <?= $pos ? '+' : '−' ?><?= baht(abs($imp)) ?> ฿
                </span>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<?php endif; // valid ?>
<?php layout_foot(); ?>
