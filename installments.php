<?php
require_once 'auth.php';
require_login();
require_once 'config.php';
require_once '_layout.php';

$me       = (int) ($_SESSION['user']['account_id'] ?? 0);
$myMember = (int) ($_SESSION['user']['member_id'] ?? 0);
$status   = '';

/** เงินที่เพื่อนจ่ายไว้ก่อน (held) แยกตามเพื่อน — ใช้หักผ่อนได้ */
function held_by_owner($myMember) {
    $map = [];
    foreach (sb_rows(sb_get('holdings?holder_id=eq.' . $myMember . '&select=owner_id,amount')) as $h) {
        $map[(int) $h['owner_id']] = ($map[(int) $h['owner_id']] ?? 0) + (float) $h['amount'];
    }
    return $map;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $myMember > 0) {
    $action = $_POST['action'] ?? '';

    if ($action === 'create') {
        $payer   = (int) ($_POST['payer_id'] ?? 0);
        $title   = trim($_POST['title'] ?? '');
        $monthly = round((float) ($_POST['monthly_amount'] ?? 0), 2);
        $months  = (int) ($_POST['months'] ?? 0);
        $start   = trim($_POST['start_date'] ?? '');
        if ($payer > 0 && $payer !== $myMember && $title !== '' && $monthly > 0 && $months > 0) {
            sb_insert('installments', [
                'payer_id' => $payer, 'payee_id' => $myMember,
                'title' => $title, 'monthly_amount' => $monthly, 'months' => $months,
                'start_date' => $start !== '' ? $start : null,
            ]);
            header('Location: installments.php?saved=1'); exit;
        }
        $status = 'กรอกข้อมูลให้ครบ (เพื่อน, ชื่อรายการ, ยอดต่อเดือน, จำนวนเดือน)';

    } elseif ($action === 'pay') {
        $iid    = (int) ($_POST['installment_id'] ?? 0);
        $amt    = round((float) ($_POST['amount'] ?? 0), 2);
        $source = ($_POST['source'] ?? 'cash') === 'prepaid' ? 'prepaid' : 'cash';
        // ดึงแผนเพื่อตรวจเจ้าของ + payer
        $plan = sb_rows(sb_get('installments?id=eq.' . $iid . '&select=*'));
        $plan = $plan[0] ?? null;
        if ($plan && (int) $plan['payee_id'] === $myMember && $amt > 0) {
            if ($source === 'prepaid') {
                $held = held_by_owner($myMember)[(int) $plan['payer_id']] ?? 0;
                if ($amt > $held + 0.001) {
                    $status = 'เงินที่จ่ายไว้ก่อนมีไม่พอ (มี ' . baht($held) . ' ฿)';
                }
            }
            if ($status === '') {
                sb_insert('installment_payments', [
                    'installment_id' => $iid, 'amount' => $amt, 'source' => $source,
                    'note' => trim($_POST['note'] ?? '') ?: null,
                ]);
                // ถ้าหักจากเงินที่จ่ายไว้ก่อน -> ลดยอด held ของเพื่อนคนนั้นด้วย
                if ($source === 'prepaid') {
                    sb_insert('holdings', [
                        'holder_id' => $myMember, 'owner_id' => (int) $plan['payer_id'],
                        'amount' => -$amt, 'note' => 'หักผ่อน: ' . $plan['title'],
                    ]);
                }
                header('Location: installments.php?paid=1'); exit;
            }
        } elseif ($status === '') {
            $status = 'ไม่สามารถบันทึกการจ่ายได้';
        }

    } elseif ($action === 'delete') {
        $iid = (int) ($_POST['installment_id'] ?? 0);
        $plan = sb_rows(sb_get('installments?id=eq.' . $iid . '&select=payee_id'));
        if (($plan[0]['payee_id'] ?? 0) == $myMember) sb_delete('installments?id=eq.' . $iid);
        header('Location: installments.php?removed=1'); exit;
    }
}

// แผนผ่อนของเรา + การจ่าย
$plans = $myMember ? sb_rows(sb_get('installments?payee_id=eq.' . $myMember . '&select=*,payer:payer_id(name)&order=created_at.desc')) : [];
$paidById = [];
if ($plans) {
    $ids = implode(',', array_map(fn($p) => (int) $p['id'], $plans));
    foreach (sb_rows(sb_get('installment_payments?installment_id=in.(' . $ids . ')&select=*&order=paid_at.desc')) as $pm) {
        $paidById[(int) $pm['installment_id']][] = $pm;
    }
}
$held = $myMember ? held_by_owner($myMember) : [];
$friendMembers = array_filter(selectable_members($me), fn($m) => (int) $m['id'] !== $myMember);

layout_head('ผ่อนรายเดือน', 'holdings.php');
?>

<!-- แท็บย่อย -->
<div class="flex gap-2 mb-5">
    <a href="holdings.php" class="px-4 py-2 rounded-xl text-sm font-semibold bg-white border border-slate-200 text-slate-500 hover:text-emerald-600">เงินที่ถือไว้</a>
    <a href="installments.php" class="px-4 py-2 rounded-xl text-sm font-semibold bg-gradient-to-br from-emerald-400 to-teal-500 text-white shadow-md shadow-emerald-200">ผ่อนรายเดือน</a>
</div>

<h1 class="text-xl font-bold text-slate-700 flex items-center gap-2 mb-1">
    <i data-lucide="calendar-clock" class="w-6 h-6 text-emerald-500"></i> เงินผ่อนรายเดือน
</h1>
<p class="text-sm text-slate-400 mb-5">เพื่อนที่ผ่อนจ่ายให้เรา — บันทึกยอดต่อเดือน จำนวนเดือน และหักจากเงินที่จ่ายไว้ก่อนได้</p>

<?php if ($myMember === 0): ?>
    <div class="mb-5 p-4 bg-amber-50 border border-amber-200 text-amber-800 text-sm rounded-xl flex items-center gap-2">
        <i data-lucide="alert-triangle" class="w-4 h-4"></i> เซสชันเก่า — <a href="logout.php" class="font-semibold underline">ออกแล้วล็อกอินใหม่</a>
    </div>
<?php else: ?>

<?php if (isset($_GET['saved']) || isset($_GET['paid']) || isset($_GET['removed'])): ?>
    <div class="mb-5 p-3.5 bg-emerald-50 border border-emerald-200 text-emerald-700 text-sm font-medium rounded-xl flex items-center gap-2">
        <i data-lucide="check-circle" class="w-4 h-4"></i> บันทึกเรียบร้อยแล้ว
    </div>
<?php endif; ?>
<?php if ($status): ?>
    <div class="mb-5 p-3.5 bg-rose-50 border border-rose-200 text-rose-700 text-sm font-medium rounded-xl flex items-center gap-2">
        <i data-lucide="alert-triangle" class="w-4 h-4"></i> <?= htmlspecialchars($status) ?>
    </div>
<?php endif; ?>

<!-- สร้างแผนผ่อนใหม่ -->
<details class="bg-white rounded-2xl border border-slate-100 shadow-sm mb-6 group" <?= empty($plans) ? 'open' : '' ?>>
    <summary class="flex items-center gap-2 p-4 cursor-pointer font-semibold text-slate-600 text-sm select-none">
        <i data-lucide="plus-circle" class="w-4 h-4 text-emerald-500"></i> สร้างแผนผ่อนใหม่
        <i data-lucide="chevron-down" class="w-4 h-4 ml-auto text-slate-400 group-open:rotate-180 transition"></i>
    </summary>
    <?php if (empty($friendMembers)): ?>
        <p class="px-4 pb-4 text-sm text-slate-400">ยังไม่มีเพื่อน — <a href="friends.php" class="text-emerald-600 font-semibold">เพิ่มเพื่อนก่อน</a></p>
    <?php else: ?>
    <form method="POST" class="p-4 pt-0 space-y-3">
        <input type="hidden" name="action" value="create">
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
            <div>
                <label class="block text-xs font-semibold text-slate-500 mb-1">เพื่อนที่ผ่อนจ่าย</label>
                <select name="payer_id" class="w-full px-3 py-2.5 bg-white border border-slate-200 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-emerald-400">
                    <?php foreach ($friendMembers as $m): ?><option value="<?= $m['id'] ?>"><?= htmlspecialchars($m['name']) ?></option><?php endforeach; ?>
                </select>
            </div>
            <div>
                <label class="block text-xs font-semibold text-slate-500 mb-1">ชื่อรายการ</label>
                <input type="text" name="title" required placeholder="เช่น ยืมเงิน, ค่าโทรศัพท์"
                       class="w-full px-3 py-2.5 border border-slate-200 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-emerald-400">
            </div>
        </div>
        <div class="grid grid-cols-2 sm:grid-cols-3 gap-3">
            <div>
                <label class="block text-xs font-semibold text-slate-500 mb-1">ต่อเดือน (฿)</label>
                <input type="number" name="monthly_amount" step="0.01" min="0.01" required placeholder="0.00"
                       class="w-full px-3 py-2.5 border border-slate-200 rounded-xl text-sm font-bold focus:outline-none focus:ring-2 focus:ring-emerald-400">
            </div>
            <div>
                <label class="block text-xs font-semibold text-slate-500 mb-1">จำนวนเดือน</label>
                <input type="number" name="months" min="1" step="1" required placeholder="เช่น 6"
                       class="w-full px-3 py-2.5 border border-slate-200 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-emerald-400">
            </div>
            <div>
                <label class="block text-xs font-semibold text-slate-500 mb-1">เริ่ม (ไม่บังคับ)</label>
                <input type="date" name="start_date"
                       class="w-full px-3 py-2.5 border border-slate-200 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-emerald-400">
            </div>
        </div>
        <button type="submit" class="bg-gradient-to-br from-emerald-400 to-teal-500 hover:from-emerald-500 hover:to-teal-600 text-white font-bold text-sm py-2.5 px-6 rounded-xl shadow-md shadow-emerald-200 transition flex items-center gap-2">
            <i data-lucide="check" class="w-4 h-4"></i> สร้างแผน
        </button>
    </form>
    <?php endif; ?>
</details>

<!-- รายการแผนผ่อน -->
<?php if (empty($plans)): ?>
    <div class="bg-white rounded-2xl p-8 text-center text-slate-400 border border-dashed border-slate-200 text-sm">ยังไม่มีแผนผ่อน</div>
<?php else: foreach ($plans as $p):
    $total   = (float) $p['monthly_amount'] * (int) $p['months'];
    $pays    = $paidById[(int) $p['id']] ?? [];
    $paid    = 0; foreach ($pays as $pm) $paid += (float) $pm['amount'];
    $remain  = max(0, round($total - $paid, 2));
    $pct     = $total > 0 ? min(100, round($paid / $total * 100)) : 0;
    $monthsPaid = (float) $p['monthly_amount'] > 0 ? floor($paid / (float) $p['monthly_amount']) : 0;
    $avail   = $held[(int) $p['payer_id']] ?? 0;
    $done    = $remain < 0.01;
?>
    <div class="bg-white rounded-2xl border border-slate-100 shadow-sm p-5 mb-4">
        <div class="flex items-center gap-3 mb-3">
            <?= avatar($p['payer_id'], $p['payer']['name'] ?? '?', 'w-10 h-10 text-sm') ?>
            <div class="flex-1 min-w-0">
                <p class="font-bold text-slate-800 truncate"><?= htmlspecialchars($p['title']) ?></p>
                <p class="text-xs text-slate-400"><?= htmlspecialchars($p['payer']['name'] ?? '?') ?> · เดือนละ <?= baht($p['monthly_amount']) ?> × <?= (int) $p['months'] ?> เดือน</p>
            </div>
            <?php if ($done): ?><span class="text-xs bg-emerald-50 text-emerald-600 px-2.5 py-1 rounded-full font-medium flex items-center gap-1"><i data-lucide="check" class="w-3.5 h-3.5"></i> ครบแล้ว</span><?php endif; ?>
            <form method="POST" onsubmit="return confirm('ลบแผนผ่อน &quot;<?= htmlspecialchars(addslashes($p['title'])) ?>&quot; ?');">
                <input type="hidden" name="action" value="delete"><input type="hidden" name="installment_id" value="<?= $p['id'] ?>">
                <button class="p-2 text-slate-300 hover:text-rose-500 hover:bg-rose-50 rounded-lg transition"><i data-lucide="trash-2" class="w-4 h-4"></i></button>
            </form>
        </div>

        <!-- progress -->
        <div class="flex justify-between text-xs text-slate-400 mb-1">
            <span>จ่ายแล้ว <?= baht($paid) ?> / <?= baht($total) ?> ฿ (<?= (int) $monthsPaid ?>/<?= (int) $p['months'] ?> เดือน)</span>
            <span class="font-semibold <?= $done ? 'text-emerald-600' : 'text-rose-500' ?>">เหลือ <?= baht($remain) ?> ฿</span>
        </div>
        <div class="h-2 bg-slate-100 rounded-full overflow-hidden mb-4">
            <div class="h-full bg-gradient-to-r from-emerald-400 to-teal-500" style="width: <?= $pct ?>%"></div>
        </div>

        <?php if (!$done): ?>
        <!-- ฟอร์มจ่ายงวด -->
        <form method="POST" class="bg-slate-50 rounded-xl p-3 grid grid-cols-1 sm:grid-cols-[1fr_auto] gap-2 items-end">
            <input type="hidden" name="action" value="pay"><input type="hidden" name="installment_id" value="<?= $p['id'] ?>">
            <div class="grid grid-cols-2 gap-2">
                <div>
                    <label class="block text-[11px] font-semibold text-slate-500 mb-1">จำนวน (฿)</label>
                    <input type="number" name="amount" step="0.01" min="0.01" value="<?= baht(min($p['monthly_amount'], $remain)) ?>"
                           class="w-full px-3 py-2 border border-slate-200 rounded-lg text-sm font-bold focus:outline-none focus:ring-2 focus:ring-emerald-400">
                </div>
                <div>
                    <label class="block text-[11px] font-semibold text-slate-500 mb-1">วิธีจ่าย</label>
                    <select name="source" class="w-full px-2 py-2 border border-slate-200 rounded-lg text-sm bg-white focus:outline-none focus:ring-2 focus:ring-emerald-400">
                        <option value="cash">จ่ายสด/โอน</option>
                        <option value="prepaid">หักเงินจ่ายไว้ก่อน (<?= baht($avail) ?>)</option>
                    </select>
                </div>
            </div>
            <button type="submit" class="bg-emerald-500 hover:bg-emerald-600 text-white font-semibold text-sm py-2 px-5 rounded-lg transition whitespace-nowrap">บันทึกจ่าย</button>
        </form>
        <?php if ($avail > 0.009): ?><p class="text-[11px] text-slate-400 mt-1.5 px-1">เงินที่ <?= htmlspecialchars($p['payer']['name'] ?? 'เพื่อน') ?> จ่ายไว้ก่อน: <?= baht($avail) ?> ฿ (หักผ่อนได้)</p><?php endif; ?>
        <?php endif; ?>

        <!-- ประวัติการจ่าย -->
        <?php if ($pays): ?>
        <div class="mt-3 space-y-1">
            <?php foreach (array_slice($pays, 0, 4) as $pm): ?>
                <div class="flex items-center gap-2 text-xs text-slate-500">
                    <i data-lucide="<?= $pm['source'] === 'prepaid' ? 'wallet' : 'banknote' ?>" class="w-3.5 h-3.5 text-emerald-500"></i>
                    <span><?= date('d/m/y', strtotime($pm['paid_at'])) ?></span>
                    <span class="text-slate-400"><?= $pm['source'] === 'prepaid' ? 'หักเงินจ่ายไว้ก่อน' : 'จ่ายสด/โอน' ?></span>
                    <span class="ml-auto font-semibold text-slate-700"><?= baht($pm['amount']) ?> ฿</span>
                </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>
<?php endforeach; endif; ?>

<?php endif; // myMember ?>
<?php layout_foot(); ?>
