<?php
require_once 'auth.php';
require_login();
require_once 'config.php';
require_once '_layout.php';

$me       = (int) ($_SESSION['user']['account_id'] ?? 0);
$myMember = (int) ($_SESSION['user']['member_id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $myMember > 0) {
    csrf_check();
    if (($_POST['action'] ?? '') === 'add') {
        $owner = (int) ($_POST['owner_id'] ?? 0);
        $amt   = round((float) ($_POST['amount'] ?? 0), 2);
        $dir   = ($_POST['direction'] ?? 'in') === 'out' ? 'out' : 'in';
        // เจ้าของเงินต้องเป็นเพื่อนที่ตอบรับแล้วเท่านั้น (เดิมยิง member id อะไรก็ได้)
        if ($owner > 0 && $owner !== $myMember && $amt > 0
            && in_array($owner, selectable_member_ids($me), true)) {
            sb_insert('holdings', [
                'holder_id' => $myMember,
                'owner_id'  => $owner,
                'amount'    => $dir === 'out' ? -$amt : $amt,
                'note'      => trim($_POST['note'] ?? '') ?: null,
            ]);
        }
    }
    header('Location: holdings.php?saved=1');
    exit;
}

// ดึงรายการที่เกี่ยวกับเรา (เราถือ หรือเป็นเงินเรา)
$rows = $myMember ? sb_rows(sb_get(
    'holdings?select=*,holder:holder_id(name),owner:owner_id(name)'
    . '&or=(holder_id.eq.' . $myMember . ',owner_id.eq.' . $myMember . ')&order=created_at.desc'
)) : [];

/* ยอดคงเหลือของ "ก้อนนั้น" หลังจากแต่ละรายการ + วันที่ยอดเปลี่ยนครั้งล่าสุด
 * ตอบคำถาม "ยอดนี้เป็นเท่านี้มาตั้งแต่เมื่อไหร่" ซึ่งเดิมดูจากหน้าเว็บไม่ได้เลย
 * ต้องไล่จากเก่าไปใหม่ แล้วค่อยกลับมาเรียงใหม่ล่าสุดก่อนตอนแสดงผล */
$grossWe = 0; $grossFr = 0;          // ยอดดิบ: เราถือของเพื่อน / เพื่อนถือของเรา
$sinceWe = null; $sinceFr = null;    // เวลาที่ยอดนั้นเปลี่ยนครั้งล่าสุด
foreach (array_reverse($rows) as $h) {
    if ((int) $h['holder_id'] === $myMember) { $grossWe += (float) $h['amount']; $sinceWe = $h['created_at']; }
    else                                     { $grossFr += (float) $h['amount']; $sinceFr = $h['created_at']; }
    $h['bucket_total'] = round((int) $h['holder_id'] === $myMember ? $grossWe : $grossFr, 2);
    $hist[] = $h;
}
$hist = array_reverse($hist ?? []);

// ยอดสุทธิต่อเพื่อน = เงินที่ถือไว้ + บิล/รายจ่าย + เคลียร์หนี้ (ไม่รวมผ่อนรายเดือน — มีหน้าแยก)
//  custody > 0 = สุทธิแล้วเงินเพื่อนอยู่กับเรา (เราติดเพื่อน) | < 0 = เงินเราอยู่กับเพื่อน (เพื่อนติดเรา)
$weHold = [];  // เงินเพื่อนที่อยู่กับเรา (สุทธิ)
$frHold = [];  // เงินเราที่อยู่กับเพื่อน (สุทธิ, เก็บเป็นค่าบวก)
foreach (unified_balances($myMember) as $fid => $b) {
    $custody = round(-($b['bill'] + $b['settle'] + $b['holding']), 2); // หักลบบิลที่เพื่อนออกให้ด้วย
    if ($custody > 0.009)      $weHold[$fid] = ['name' => $b['name'], 'net' => $custody];
    elseif ($custody < -0.009) $frHold[$fid] = ['name' => $b['name'], 'net' => -$custody];
}
$totalHeld = array_sum(array_column($weHold, 'net'));
$totalMine = array_sum(array_column($frHold, 'net'));

// เพื่อนสำหรับ dropdown (ไม่รวมตัวเอง)
$friendMembers = array_filter(selectable_members($me), fn($m) => (int) $m['id'] !== $myMember);

layout_head('เงินเพื่อน', 'holdings.php');
?>

<!-- แท็บย่อย -->
<div class="flex gap-2 mb-5">
    <a href="holdings.php" class="px-4 py-2 rounded-xl text-sm font-semibold bg-gradient-to-br from-emerald-400 to-teal-500 text-white shadow-md shadow-emerald-200">เงินที่ถือไว้</a>
    <a href="installments.php" class="px-4 py-2 rounded-xl text-sm font-semibold bg-white border border-slate-200 text-slate-500 hover:text-emerald-600">ผ่อนรายเดือน</a>
</div>

<h1 class="text-xl font-bold text-slate-700 flex items-center gap-2 mb-1">
    <i data-lucide="piggy-bank" class="w-6 h-6 text-emerald-500"></i> เงินเพื่อนที่ถือไว้
</h1>
<p class="text-sm text-slate-400 mb-5">บันทึกว่ามีเงินของเพื่อนคนไหนอยู่กับเราเท่าไหร่ จะได้ไม่ลืมคืน · การ์ดแสดง<b>ยอดสุทธิ</b> หักลบกับบิล/รายจ่ายที่เพื่อนออกให้แล้ว (ไม่รวมผ่อนรายเดือน)</p>

<?php if ($myMember === 0): ?>
    <div class="mb-5 p-4 bg-amber-50 border border-amber-200 text-amber-800 text-sm rounded-xl flex items-center gap-2">
        <i data-lucide="alert-triangle" class="w-4 h-4"></i>
        เซสชันเก่ายังไม่มีข้อมูลบัญชี — <a href="logout.php" class="font-semibold underline">ออกแล้วล็อกอินใหม่</a> หนึ่งครั้ง
    </div>
<?php else: ?>

<!-- 2 การ์ด: เงินเพื่อนที่อยู่กับเรา / เงินเราที่อยู่กับเพื่อน (หักลบสุทธิแล้ว) -->
<?php
$shownHeld = array_filter($weHold, fn($v) => abs($v['net']) > 0.009);
$shownMine = array_filter($frHold, fn($v) => abs($v['net']) > 0.009);
?>
<div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-6">
    <!-- เงินเพื่อนที่อยู่กับเรา -->
    <div class="bg-white rounded-2xl border border-slate-100 shadow-sm overflow-hidden flex flex-col">
        <div class="p-4 bg-gradient-to-br from-emerald-400 to-teal-500 text-white">
            <p class="text-emerald-50 text-xs flex items-center gap-1.5"><i data-lucide="piggy-bank" class="w-4 h-4"></i> เงินเพื่อนที่อยู่กับเรา</p>
            <p class="text-3xl font-black mt-0.5"><?= baht($totalHeld) ?> <span class="text-base font-medium text-emerald-100">฿</span></p>
        </div>
        <div class="divide-y divide-slate-50 flex-1">
            <?php if (empty($shownHeld)): ?>
                <p class="p-6 text-center text-slate-400 text-sm">ไม่มีเงินเพื่อนที่ถือไว้</p>
            <?php else: foreach ($shownHeld as $oid => $v): ?>
                <a href="friend.php?id=<?= $oid ?>" class="flex items-center gap-3 p-3.5 hover:bg-emerald-50/40 transition group">
                    <?= avatar($oid, $v['name'], 'w-9 h-9 text-sm') ?>
                    <span class="flex-1 min-w-0 font-semibold text-slate-700 text-sm truncate group-hover:text-emerald-600"><?= htmlspecialchars($v['name']) ?></span>
                    <span class="font-black text-sm text-emerald-600"><?= baht($v['net']) ?> ฿</span>
                    <i data-lucide="chevron-right" class="w-4 h-4 text-slate-300 shrink-0"></i>
                </a>
            <?php endforeach; endif; ?>
        </div>
    </div>
    <!-- เงินเราที่อยู่กับเพื่อน -->
    <div class="bg-white rounded-2xl border border-slate-100 shadow-sm overflow-hidden flex flex-col">
        <div class="p-4 bg-gradient-to-br from-sky-400 to-cyan-500 text-white">
            <p class="text-sky-50 text-xs flex items-center gap-1.5"><i data-lucide="wallet" class="w-4 h-4"></i> เงินเราที่อยู่กับเพื่อน</p>
            <p class="text-3xl font-black mt-0.5"><?= baht($totalMine) ?> <span class="text-base font-medium text-sky-100">฿</span></p>
        </div>
        <div class="divide-y divide-slate-50 flex-1">
            <?php if (empty($shownMine)): ?>
                <p class="p-6 text-center text-slate-400 text-sm">ไม่มีเงินของเราที่ฝากเพื่อน</p>
            <?php else: foreach ($shownMine as $hid => $v): ?>
                <a href="friend.php?id=<?= $hid ?>" class="flex items-center gap-3 p-3.5 hover:bg-sky-50/40 transition group">
                    <?= avatar($hid, $v['name'], 'w-9 h-9 text-sm') ?>
                    <span class="flex-1 min-w-0 font-semibold text-slate-700 text-sm truncate group-hover:text-sky-600"><?= htmlspecialchars($v['name']) ?></span>
                    <span class="font-black text-sm text-sky-600"><?= baht($v['net']) ?> ฿</span>
                    <i data-lucide="chevron-right" class="w-4 h-4 text-slate-300 shrink-0"></i>
                </a>
            <?php endforeach; endif; ?>
        </div>
    </div>
</div>

<?php if ($grossWe > 0.009 || $grossFr > 0.009): ?>
<!-- ยอดดิบสองทาง + ยอดนี้เป็นแบบนี้มาตั้งแต่เมื่อไหร่ (การ์ดข้างบนเป็นยอดสุทธิ คนละตัวกัน) -->
<div class="bg-slate-50 border border-slate-100 rounded-2xl p-3 mb-6 grid grid-cols-1 sm:grid-cols-2 gap-2">
    <div class="bg-white rounded-xl px-3 py-2.5 border border-slate-100">
        <p class="text-[11px] text-slate-400">เราถือเงินเพื่อนไว้ (ยอดดิบ ยังไม่หักบิล)</p>
        <p class="font-bold text-slate-700"><?= baht($grossWe) ?> ฿</p>
        <p class="text-[10px] text-slate-400 mt-0.5">
            <?= $sinceWe ? 'ยอดนี้ตั้งแต่ ' . thai_short_date($sinceWe) . ' ' . date('H:i', ts_thai($sinceWe)) : 'ยังไม่มีรายการ' ?>
        </p>
    </div>
    <div class="bg-white rounded-xl px-3 py-2.5 border border-slate-100">
        <p class="text-[11px] text-slate-400">เพื่อนถือเงินเราไว้ (ยอดดิบ ยังไม่หักบิล)</p>
        <p class="font-bold text-slate-700"><?= baht($grossFr) ?> ฿</p>
        <p class="text-[10px] text-slate-400 mt-0.5">
            <?= $sinceFr ? 'ยอดนี้ตั้งแต่ ' . thai_short_date($sinceFr) . ' ' . date('H:i', ts_thai($sinceFr)) : 'ยังไม่มีรายการ' ?>
        </p>
    </div>
    <p class="sm:col-span-2 text-[11px] text-slate-400 px-1">
        ยอดดิบสองก้อนนี้ยังไม่หักลบกัน · หักลบแล้วได้ <b class="<?= amount_tone($grossFr - $grossWe) ?>"><?= signed_baht($grossFr - $grossWe) ?> ฿</b>
        ซึ่งเป็นตัวที่ใช้คิดยอดสุทธิ · ไล่ที่มาได้จาก<b>ประวัติล่าสุด</b>ด้านล่าง (คอลัมน์ขวาบอกยอดของก้อนนั้นหลังแต่ละรายการ)
    </p>
</div>
<?php endif; ?>

<!-- ฟอร์มเพิ่ม -->
<form method="POST" class="bg-white rounded-2xl border border-slate-100 shadow-sm p-5 mb-6 space-y-4">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="add">
    <h2 class="font-bold text-slate-700 flex items-center gap-2 text-sm"><i data-lucide="plus-circle" class="w-4 h-4 text-emerald-500"></i> บันทึกเงิน</h2>

    <?php if (empty($friendMembers)): ?>
        <p class="text-sm text-slate-400">ยังไม่มีเพื่อน — <a href="friends.php" class="text-emerald-600 font-semibold">เพิ่มเพื่อนก่อน</a></p>
    <?php else: ?>
    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
        <div>
            <label class="block text-xs font-semibold text-slate-500 mb-1">เพื่อน</label>
            <select name="owner_id" class="w-full px-3 py-2.5 bg-white border border-slate-200 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-emerald-400">
                <?php foreach ($friendMembers as $m): ?>
                    <option value="<?= $m['id'] ?>"><?= htmlspecialchars($m['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label class="block text-xs font-semibold text-slate-500 mb-1">จำนวน (฿)</label>
            <input type="number" name="amount" step="0.01" min="0.01" required placeholder="0.00"
                   class="w-full px-3 py-2.5 border border-slate-200 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-emerald-400 font-bold">
        </div>
    </div>
    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
        <div>
            <label class="block text-xs font-semibold text-slate-500 mb-1">ประเภท</label>
            <div class="grid grid-cols-2 gap-2 p-1 bg-slate-100 rounded-xl">
                <label class="cursor-pointer">
                    <input type="radio" name="direction" value="in" class="peer sr-only" checked>
                    <span class="flex items-center justify-center gap-1 py-2 rounded-lg text-sm font-semibold text-slate-500 peer-checked:bg-white peer-checked:text-emerald-600 peer-checked:shadow-sm transition">
                        <i data-lucide="arrow-down-left" class="w-4 h-4"></i> รับเงินมาถือ
                    </span>
                </label>
                <label class="cursor-pointer">
                    <input type="radio" name="direction" value="out" class="peer sr-only">
                    <span class="flex items-center justify-center gap-1 py-2 rounded-lg text-sm font-semibold text-slate-500 peer-checked:bg-white peer-checked:text-rose-500 peer-checked:shadow-sm transition">
                        <i data-lucide="arrow-up-right" class="w-4 h-4"></i> คืนเงินไป
                    </span>
                </label>
            </div>
        </div>
        <div>
            <label class="block text-xs font-semibold text-slate-500 mb-1">บันทึกช่วยจำ (ไม่บังคับ)</label>
            <input type="text" name="note" placeholder="เช่น ฝากซื้อของ"
                   class="w-full px-3 py-2.5 border border-slate-200 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-emerald-400">
        </div>
    </div>
    <button type="submit" class="w-full sm:w-auto bg-gradient-to-br from-emerald-400 to-teal-500 hover:from-emerald-500 hover:to-teal-600 text-white font-bold text-sm py-2.5 px-6 rounded-xl shadow-md shadow-emerald-200 transition flex items-center justify-center gap-2">
        <i data-lucide="check" class="w-4 h-4"></i> บันทึก
    </button>
    <?php endif; ?>
</form>

<!-- ประวัติ -->
<h2 class="text-sm font-bold text-slate-500 mb-2 px-1 flex items-center gap-1.5"><i data-lucide="history" class="w-4 h-4"></i> ประวัติล่าสุด</h2>
<p class="text-[11px] text-slate-400 mb-2 px-1">
    ตัวเลขคือ<b>ผลต่อยอดสุทธิ</b>ระหว่างเรากับเพื่อน ไม่ใช่ทิศทางเงินสด —
    <span class="text-emerald-600 font-bold">+</span> เพื่อนติดเราเพิ่ม/หนี้เราลดลง ·
    <span class="text-rose-500 font-bold">−</span> เราติดเพื่อนเพิ่ม/หนี้เพื่อนลดลง
</p>
<div class="bg-white rounded-2xl border border-slate-100 shadow-sm overflow-hidden js-more-list" data-show="10">
    <?php if (empty($hist)): ?>
        <p class="p-8 text-center text-slate-400 text-sm">ยังไม่มีประวัติ</p>
    <?php else: foreach ($hist as $h):
        $amt       = (float) $h['amount'];
        $iAmHolder = (int) $h['holder_id'] === $myMember;
        $friend    = $iAmHolder ? ($h['owner']['name'] ?? '?') : ($h['holder']['name'] ?? '?');

        /* ผลต่อยอดสุทธิ (ทิศเดียวกับ friend.php / หน้าแรก):
         * เราถือเงินเพื่อน = เราติดเพื่อน (−) · เพื่อนถือเงินเรา = เพื่อนติดเรา (+)
         * เดิมใช้เครื่องหมายดิบของ amount ซึ่งเป็นมุมของ "คนถือ" ทำให้แถวที่ผลตรงข้ามกัน
         * กลับเป็นสีเดียวกัน (รับเงินเพื่อนมาถือ กับ เพื่อนถือเงินเรา เขียวทั้งคู่) */
        $impact = $iAmHolder ? -$amt : $amt;

        [$title, $effect] = $iAmHolder
            ? ($amt >= 0 ? ['รับเงินของ ' . $friend . ' มาถือ', 'เราติดเพื่อนเพิ่ม']
                         : ['คืนเงินให้ ' . $friend,           'หนี้ที่เราติดเพื่อนลดลง'])
            : ($amt >= 0 ? [$friend . ' ถือเงินเราไว้',        'เพื่อนติดเราเพิ่ม']
                         : [$friend . ' คืนเงินเรา',           'หนี้ที่เพื่อนติดเราลดลง']);

        $bucket        = $iAmHolder ? 'เงินเพื่อนอยู่กับเรา' : 'เงินเราอยู่กับเพื่อน';
        $fromReconcile = !empty($h['note']) && mb_strpos($h['note'], 'เคลียร์') !== false;
    ?>
        <div class="flex items-center gap-3 p-4 border-b border-slate-50 last:border-0">
            <span class="grid place-items-center w-9 h-9 rounded-xl shrink-0 <?= $impact >= 0 ? 'bg-emerald-50 text-emerald-500' : 'bg-rose-50 text-rose-500' ?>">
                <i data-lucide="<?= $iAmHolder ? 'piggy-bank' : 'hand-coins' ?>" class="w-4 h-4"></i>
            </span>
            <div class="text-sm min-w-0 flex-1">
                <p class="text-slate-700 truncate">
                    <?= htmlspecialchars($title) ?>
                    <?php if ($fromReconcile): ?>
                        <span class="ml-1 text-[10px] font-medium px-1.5 py-0.5 rounded bg-emerald-50 text-emerald-600 align-middle">จากการเคลียร์ยอด</span>
                    <?php endif; ?>
                </p>
                <p class="text-xs text-slate-400 truncate">
                    <?= $effect ?> · <?= date('d/m/y H:i', strtotime($h['created_at'])) ?>
                    <?php if (!empty($h['note']) && !$fromReconcile): ?> · <?= htmlspecialchars($h['note']) ?><?php endif; ?>
                </p>
            </div>
            <div class="text-right shrink-0">
                <p class="font-bold <?= amount_tone($impact) ?>"><?= signed_baht($impact) ?> ฿</p>
                <!-- ยอดของก้อนนั้นหลังรายการนี้ — ไล่ดูได้ว่ายอดปัจจุบันเป็นแบบนี้มาตั้งแต่แถวไหน -->
                <p class="text-[10px] text-slate-300 mt-0.5"><?= $bucket ?> → <?= baht($h['bucket_total']) ?></p>
            </div>
        </div>
    <?php endforeach; endif; ?>
</div>

<?php endif; // myMember ?>
<?php layout_foot(); ?>
