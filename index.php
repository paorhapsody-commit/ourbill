<?php
require_once 'auth.php';
require_login();
require_once 'config.php';
require_once '_layout.php';

$balances = sb_get('user_balances?order=balance.desc') ?: [];
// แสดงเฉพาะคนที่มีกิจกรรมจริง (ไม่โชว์สมาชิกที่เพิ่งล็อกอินแต่ยังไม่มีบิล)
$balances = array_values(array_filter($balances, fn($b) =>
    (float) $b['paid_amount'] > 0 || (float) $b['owed_amount'] > 0
    || (float) $b['settled_paid'] > 0 || (float) $b['settled_received'] > 0));
$ledger   = sb_get('expenses?select=*,users(name)&order=created_at.desc&limit=8') ?: [];
$settle   = calculate_settlements($balances);

// สรุปยอดรวม
$total_volume = 0;
foreach ($balances as $b) { $total_volume += (float) $b['paid_amount']; }
$total_outstanding = 0;
foreach ($settle as $t) { $total_outstanding += $t['amount']; }

// เงินเพื่อนที่เราถือไว้
$myMember = (int) ($_SESSION['user']['member_id'] ?? 0);
$held = 0;
if ($myMember) {
    foreach (sb_rows(sb_get('holdings?holder_id=eq.' . $myMember . '&select=amount')) as $h) {
        $held += (float) $h['amount'];
    }
}

layout_head('หน้าหลัก', 'index.php');
?>

<!-- Summary stat cards -->
<div class="grid grid-cols-2 lg:grid-cols-5 gap-3 md:gap-4 mb-7">
    <div class="bg-white rounded-2xl p-4 border border-slate-100 shadow-sm">
        <div class="flex items-center gap-2 text-slate-400 text-xs font-medium mb-1.5">
            <i data-lucide="banknote" class="w-4 h-4"></i> ใช้จ่ายรวมทั้งกลุ่ม
        </div>
        <p class="text-2xl font-extrabold text-slate-800"><?= baht($total_volume) ?> <span class="text-sm font-medium text-slate-400">฿</span></p>
    </div>
    <div class="bg-white rounded-2xl p-4 border border-slate-100 shadow-sm">
        <div class="flex items-center gap-2 text-slate-400 text-xs font-medium mb-1.5">
            <i data-lucide="hourglass" class="w-4 h-4"></i> หนี้ที่ยังค้าง
        </div>
        <p class="text-2xl font-extrabold <?= $total_outstanding > 0 ? 'text-rose-500' : 'text-emerald-500' ?>"><?= baht($total_outstanding) ?> <span class="text-sm font-medium text-slate-400">฿</span></p>
    </div>
    <div class="bg-white rounded-2xl p-4 border border-slate-100 shadow-sm">
        <div class="flex items-center gap-2 text-slate-400 text-xs font-medium mb-1.5">
            <i data-lucide="users" class="w-4 h-4"></i> สมาชิกในกลุ่ม
        </div>
        <p class="text-2xl font-extrabold text-slate-800"><?= count($balances) ?> <span class="text-sm font-medium text-slate-400">คน</span></p>
    </div>
    <div class="bg-gradient-to-br from-emerald-400 to-teal-500 rounded-2xl p-4 shadow-lg shadow-emerald-200 text-white">
        <div class="flex items-center gap-2 text-emerald-50 text-xs font-medium mb-1.5">
            <i data-lucide="arrow-right-left" class="w-4 h-4"></i> รายการต้องโอน
        </div>
        <p class="text-2xl font-extrabold"><?= count($settle) ?> <span class="text-sm font-medium text-emerald-100">รายการ</span></p>
    </div>
    <a href="holdings.php" class="bg-white rounded-2xl p-4 border border-slate-100 shadow-sm hover:shadow-md transition col-span-2 lg:col-span-1">
        <div class="flex items-center gap-2 text-slate-400 text-xs font-medium mb-1.5">
            <i data-lucide="piggy-bank" class="w-4 h-4"></i> เงินเพื่อนที่ถือไว้
        </div>
        <p class="text-2xl font-extrabold text-slate-800"><?= baht($held) ?> <span class="text-sm font-medium text-slate-400">฿</span></p>
    </a>
</div>

<!-- Balances -->
<div class="flex items-center justify-between mb-4">
    <h2 class="text-lg font-bold text-slate-700 flex items-center gap-2">
        <i data-lucide="scale" class="w-5 h-5 text-emerald-500"></i> สถานะดุลเงินของแต่ละคน
    </h2>
    <a href="add-expense.php" class="md:hidden text-sm font-semibold text-emerald-600 flex items-center gap-1">
        <i data-lucide="plus" class="w-4 h-4"></i> เพิ่ม
    </a>
</div>

<div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4 mb-8">
    <?php if (empty($balances)): ?>
        <div class="col-span-full bg-white rounded-2xl p-8 text-center text-slate-400 border border-dashed border-slate-200">
            ยังไม่มีสมาชิก — <a href="friends.php" class="text-emerald-600 font-semibold">เพิ่มเพื่อนก่อน</a>
        </div>
    <?php endif; ?>

    <?php foreach ($balances as $u):
        $bal = (float) $u['balance']; ?>
        <div class="bg-white rounded-2xl p-5 border border-slate-100 shadow-sm hover:shadow-md hover:-translate-y-0.5 transition">
            <div class="flex items-center gap-3 mb-4">
                <?= avatar($u['id'], $u['name'], 'w-11 h-11 text-base') ?>
                <div>
                    <h3 class="font-bold text-slate-800 leading-tight"><?= htmlspecialchars($u['name']) ?></h3>
                    <p class="text-xs text-slate-400">สำรองจ่าย <?= baht($u['paid_amount']) ?> ฿</p>
                </div>
            </div>

            <?php if ($bal > 0.009): ?>
                <p class="text-xs text-slate-400 mb-0.5">ยอดสุทธิ · รอรับเงินคืน</p>
                <p class="text-2xl font-black text-emerald-600">+<?= baht($bal) ?> ฿</p>
                <span class="inline-flex items-center gap-1 text-xs bg-emerald-50 text-emerald-700 px-2.5 py-1 rounded-full font-medium mt-2.5">
                    <i data-lucide="trending-up" class="w-3.5 h-3.5"></i> เป็นเจ้าหนี้
                </span>
            <?php elseif ($bal < -0.009): ?>
                <p class="text-xs text-slate-400 mb-0.5">ยอดสุทธิ · ต้องจ่ายคืน</p>
                <p class="text-2xl font-black text-rose-500"><?= baht($bal) ?> ฿</p>
                <span class="inline-flex items-center gap-1 text-xs bg-rose-50 text-rose-600 px-2.5 py-1 rounded-full font-medium mt-2.5">
                    <i data-lucide="trending-down" class="w-3.5 h-3.5"></i> เป็นลูกหนี้
                </span>
            <?php else: ?>
                <p class="text-xs text-slate-400 mb-0.5">ยอดสุทธิ</p>
                <p class="text-2xl font-black text-slate-400">0.00 ฿</p>
                <span class="inline-flex items-center gap-1 text-xs bg-slate-100 text-slate-500 px-2.5 py-1 rounded-full font-medium mt-2.5">
                    <i data-lucide="check" class="w-3.5 h-3.5"></i> เคลียร์แล้ว
                </span>
            <?php endif; ?>
        </div>
    <?php endforeach; ?>
</div>

<!-- Settle-up preview -->
<?php if (!empty($settle)): ?>
<div class="bg-white rounded-2xl border border-slate-100 shadow-sm overflow-hidden mb-8">
    <div class="flex items-center justify-between p-5 bg-gradient-to-r from-emerald-50 to-teal-50 border-b border-slate-100">
        <h2 class="text-base font-bold text-slate-700 flex items-center gap-2">
            <i data-lucide="sparkles" class="w-5 h-5 text-emerald-500"></i> วิธีเคลียร์หนี้ที่แนะนำ
        </h2>
        <a href="settle.php" class="text-sm font-semibold text-emerald-600 hover:text-emerald-700 flex items-center gap-1">
            ดูทั้งหมด <i data-lucide="chevron-right" class="w-4 h-4"></i>
        </a>
    </div>
    <div class="divide-y divide-slate-50">
        <?php foreach (array_slice($settle, 0, 3) as $t): ?>
            <div class="flex items-center gap-3 p-4">
                <?= avatar($t['from_id'], $t['from'], 'w-9 h-9 text-sm') ?>
                <span class="font-semibold text-slate-700 text-sm"><?= htmlspecialchars($t['from']) ?></span>
                <i data-lucide="arrow-right" class="w-4 h-4 text-slate-300 shrink-0"></i>
                <?= avatar($t['to_id'], $t['to'], 'w-9 h-9 text-sm') ?>
                <span class="font-semibold text-slate-700 text-sm"><?= htmlspecialchars($t['to']) ?></span>
                <span class="ml-auto font-bold text-emerald-600"><?= baht($t['amount']) ?> ฿</span>
            </div>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>

<!-- Recent expenses -->
<div class="flex items-center justify-between mb-4">
    <h2 class="text-lg font-bold text-slate-700 flex items-center gap-2">
        <i data-lucide="receipt" class="w-5 h-5 text-emerald-500"></i> รายจ่ายล่าสุด
    </h2>
</div>

<div class="bg-white rounded-2xl border border-slate-100 shadow-sm overflow-hidden">
    <?php if (empty($ledger)): ?>
        <p class="p-8 text-center text-slate-400">ยังไม่มีการบันทึกค่าใช้จ่าย</p>
    <?php else: foreach ($ledger as $item): ?>
        <a href="expense.php?id=<?= $item['id'] ?>"
           class="flex items-center gap-3 p-4 border-b border-slate-50 last:border-0 hover:bg-emerald-50/40 transition">
            <?php if (!empty($item['receipt_url'])): ?>
                <img src="<?= htmlspecialchars($item['receipt_url']) ?>" alt="" class="w-10 h-10 rounded-xl object-cover shrink-0 border border-slate-100">
            <?php else: ?>
                <span class="grid place-items-center w-10 h-10 rounded-xl bg-emerald-50 text-emerald-500 shrink-0">
                    <i data-lucide="shopping-bag" class="w-5 h-5"></i>
                </span>
            <?php endif; ?>
            <div class="min-w-0">
                <p class="font-semibold text-slate-800 truncate"><?= htmlspecialchars($item['title']) ?></p>
                <p class="text-xs text-slate-400">
                    จ่ายก่อนโดย <?= htmlspecialchars($item['users']['name'] ?? 'ไม่ระบุ') ?> ·
                    <?= date('d/m/y H:i', strtotime($item['created_at'])) ?>
                </p>
            </div>
            <span class="ml-auto font-bold text-slate-800 shrink-0"><?= baht($item['total_amount']) ?> ฿</span>
            <i data-lucide="chevron-right" class="w-4 h-4 text-slate-300 shrink-0"></i>
        </a>
    <?php endforeach; endif; ?>
</div>

<?php layout_foot(); ?>
