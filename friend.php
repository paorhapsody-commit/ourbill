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

<?php if (abs($net) > 0.009): $friendOwesMe = $net > 0; ?>
<form method="POST" action="settle.php" class="js-reconcile-form mb-3"
      data-name="<?= htmlspecialchars($friendName, ENT_QUOTES) ?>" data-net="<?= round($net, 2) ?>" data-fid="<?= $fid ?>">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="reconcile">
    <input type="hidden" name="friend_id" value="<?= $fid ?>">
    <button type="submit" class="w-full bg-gradient-to-br from-emerald-400 to-teal-500 hover:from-emerald-500 hover:to-teal-600 text-white font-bold text-sm py-3 rounded-xl shadow-md shadow-emerald-200 transition flex items-center justify-center gap-2">
        <i data-lucide="check-check" class="w-4 h-4"></i> เคลียร์ยอด<?= $friendOwesMe ? ' (เพื่อนจ่ายคืน)' : ' (เราจ่ายคืน)' ?> <?= baht(abs($net)) ?> ฿
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
<p class="text-xs text-slate-400 mb-3">รวมทุกฟังก์ชัน เรียงใหม่ล่าสุดก่อน</p>

<?php if (empty($timeline)): ?>
    <div class="bg-white rounded-2xl border border-dashed border-slate-200 p-10 text-center text-slate-400 text-sm">
        ยังไม่มีธุรกรรมกับเพื่อนคนนี้
    </div>
<?php else:
    /* ยอดสะสม: ไล่จากรายการเก่าสุดขึ้นมา เพื่อให้แต่ละแถวบอกได้ว่า "ณ ตอนนั้นยอดสุทธิเท่าไหร่"
       ช่วยไล่ที่มาของยอดรวมด้านบนได้ว่ามาจากไหน (ตัว timeline เรียงใหม่->เก่า จึงต้องวนกลับทาง) */
    $running = 0.0;
    $rows    = [];
    foreach (array_reverse($timeline) as $t) {
        $running += round((float) $t['impact'], 2);
        $t['running'] = round($running, 2);
        $rows[] = $t;
    }
    $rows = array_reverse($rows);           // กลับมาเรียงใหม่ล่าสุดก่อนเหมือนเดิม

    // จัดกลุ่มตามวัน — เดิมไหลติดกัน 100 แถวไม่มีตัวแบ่ง
    $byDay = [];
    foreach ($rows as $t) {
        $d = !empty($t['ts']) ? date('Y-m-d', ts_thai($t['ts'])) : '';
        $byDay[$d][] = $t;
    }
    $thMonth = ['', 'ม.ค.', 'ก.พ.', 'มี.ค.', 'เม.ย.', 'พ.ค.', 'มิ.ย.', 'ก.ค.', 'ส.ค.', 'ก.ย.', 'ต.ค.', 'พ.ย.', 'ธ.ค.'];
?>
    <!-- คำอธิบายเครื่องหมาย: +/− เป็นผลต่อ "ยอดหนี้" ไม่ใช่ทิศทางเงินสด -->
    <div class="bg-slate-50 border border-slate-100 rounded-xl p-3 mb-4 text-[11px] text-slate-500 space-y-1">
        <p>ตัวเลขคือ <b>ผลต่อยอดสุทธิ</b> ระหว่างเรากับเพื่อน ไม่ใช่ทิศทางเงินสด</p>
        <p><span class="text-emerald-600 font-bold">+</span> ทำให้เพื่อนติดเรามากขึ้น (หรือหนี้เราลดลง)
           · <span class="text-rose-500 font-bold">−</span> ทำให้เราติดเพื่อนมากขึ้น (หรือหนี้เพื่อนลดลง)</p>
        <p><span class="inline-block px-1.5 py-0.5 rounded bg-white border border-dashed border-slate-300 text-slate-400">เส้นประ</span>
           = งวดผ่อนที่ระบบตั้งยอดให้ตามรอบเดือน ไม่ใช่รายการที่มีคนกดบันทึก</p>
    </div>

    <div class="js-more-list space-y-3" data-show="6" data-unit="วัน">
        <?php foreach ($byDay as $day => $items):
            $dayTotal = 0; foreach ($items as $t) { $dayTotal += round((float) $t['impact'], 2); }
            $dayTotal = round($dayTotal, 2);
            $u = $day ? strtotime($day . ' 12:00:00') : 0; ?>
            <div>
                <!-- หัววัน + ผลรวมของวันนั้น -->
                <div class="flex items-baseline gap-2 px-1 mb-1.5">
                    <span class="text-xs font-bold text-slate-500">
                        <?= $u ? (int) date('j', $u) . ' ' . $thMonth[(int) date('n', $u)] . ' ' . (date('Y', $u) + 543) % 100 : 'ไม่ระบุวันที่' ?>
                    </span>
                    <span class="text-[11px] text-slate-300">·</span>
                    <span class="text-[11px] font-semibold <?= abs($dayTotal) < 0.009 ? 'text-slate-400' : ($dayTotal > 0 ? 'text-emerald-600' : 'text-rose-500') ?>">
                        รวมวันนี้ <?= abs($dayTotal) < 0.009 ? '0.00' : (($dayTotal > 0 ? '+' : '−') . baht(abs($dayTotal))) ?> ฿
                    </span>
                    <span class="text-[11px] text-slate-300 ml-auto"><?= count($items) ?> รายการ</span>
                </div>

                <div class="bg-white rounded-2xl border border-slate-100 shadow-sm overflow-hidden">
                    <?php foreach ($items as $t):
                        $imp  = round((float) $t['impact'], 2);
                        $pos  = $imp >= 0;
                        $auto = !empty($t['auto']);
                        // รายการที่เกิดจากการกด "เคลียร์ยอด" ครั้งเดียวจะแตกเป็นหลายแถว — ติดป้ายให้รู้ว่าชุดเดียวกัน
                        $fromReconcile = !empty($t['note']) && mb_strpos($t['note'], 'เคลียร์') !== false; ?>
                        <div class="flex items-center gap-3 px-4 py-3 border-b border-slate-50 last:border-0 <?= $auto ? 'bg-slate-50/60' : '' ?>">
                            <span class="grid place-items-center w-9 h-9 rounded-xl shrink-0 <?= $auto ? 'bg-white border border-dashed border-slate-300 text-slate-400' : ($pos ? 'bg-emerald-50 text-emerald-500' : 'bg-rose-50 text-rose-500') ?>">
                                <i data-lucide="<?= htmlspecialchars($t['icon']) ?>" class="w-4 h-4"></i>
                            </span>
                            <div class="min-w-0 flex-1">
                                <p class="text-sm font-semibold text-slate-700 truncate">
                                    <?= htmlspecialchars($t['title']) ?>
                                    <?php if ($fromReconcile): ?>
                                        <span class="ml-1 text-[10px] font-medium px-1.5 py-0.5 rounded bg-emerald-50 text-emerald-600 align-middle">จากการเคลียร์ยอด</span>
                                    <?php endif; ?>
                                </p>
                                <p class="text-xs text-slate-400 truncate">
                                    <?= htmlspecialchars($t['sub']) ?>
                                    <?php if (!empty($t['ts'])): ?> · <?= date('H:i', ts_thai($t['ts'])) ?><?php endif; ?>
                                </p>
                            </div>
                            <div class="text-right shrink-0">
                                <p class="font-bold text-sm <?= abs($imp) < 0.009 ? 'text-slate-400' : ($pos ? 'text-emerald-600' : 'text-rose-500') ?>">
                                    <?= $pos ? '+' : '−' ?><?= baht(abs($imp)) ?> ฿
                                </p>
                                <p class="text-[10px] text-slate-300 mt-0.5">
                                    ยอดสะสม <?= abs($t['running']) < 0.009 ? '0.00' : (($t['running'] > 0 ? '+' : '−') . baht(abs($t['running']))) ?>
                                </p>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<?php endif; // valid ?>
<?php reconcile_modal($validFriend && $myMember ? [$fid => $timeline] : []); ?>
<?php layout_foot(); ?>
