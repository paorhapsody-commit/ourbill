<?php
require_once 'auth.php';
require_login();
require_once 'config.php';
require_once '_layout.php';

$accountId  = (int) ($_SESSION['user']['account_id'] ?? 0);
$myMemberId = (int) ($_SESSION['user']['member_id'] ?? 0);
$users      = $accountId ? selectable_members($accountId) : [];
$status_msg = '';
$status_ok  = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $title   = trim($_POST['title'] ?? '');
    $total   = round((float) ($_POST['total_amount'] ?? 0), 2);
    $paid_by = intval($_POST['paid_by'] ?? 0);
    $mode    = ($_POST['mode'] ?? 'equal') === 'custom' ? 'custom' : 'equal';
    $picked  = $_POST['split_with'] ?? [];          // ids ที่ติ๊กร่วมหาร
    $amounts = $_POST['amount'] ?? [];               // amount[uid] (โหมด custom)

    $picked = array_values(array_filter(array_map('intval', $picked)));

    if ($title === '' || $total <= 0 || empty($picked) || $paid_by <= 0) {
        $status_msg = 'กรุณากรอกชื่อรายการ ยอดเงิน และเลือกผู้ร่วมหารอย่างน้อย 1 คน';
    } else {
        list($splits, $err)        = compute_splits($mode, $total, $picked, $amounts);
        list($receiptUrl, $upErr)  = $err ? [null, null] : handle_receipt_upload('receipt');
        if ($err || $upErr) {
            $status_msg = $err ?: $upErr;
        } else {
            $res = sb_insert('expenses', [
                'title' => $title, 'total_amount' => $total, 'paid_by' => $paid_by,
                'receipt_url' => $receiptUrl,
            ]);
            $expense_id = $res['body'][0]['id'] ?? null;

            if ($expense_id) {
                $payload = [];
                foreach ($splits as $uid => $amt) {
                    $payload[] = ['expense_id' => $expense_id, 'user_id' => (int) $uid, 'amount' => $amt];
                }
                sb_insert('expense_splits', $payload);
                header('Location: expense.php?id=' . $expense_id . '&new=1');
                exit;
            }
            $status_msg = 'เชื่อมต่อ Supabase เพื่อบันทึกไม่สำเร็จ ลองอีกครั้ง';
        }
    }
}

layout_head('เพิ่มรายจ่าย', 'add-expense.php');
?>

<div class="max-w-xl mx-auto">
    <h1 class="text-xl font-bold text-slate-700 flex items-center gap-2 mb-5">
        <i data-lucide="plus-circle" class="w-6 h-6 text-emerald-500"></i> บันทึกรายจ่ายใหม่
    </h1>

    <?php if ($status_msg): ?>
        <div class="mb-5 p-3.5 bg-rose-50 border border-rose-200 text-rose-700 text-sm font-medium rounded-xl flex items-center gap-2">
            <i data-lucide="alert-triangle" class="w-4 h-4 shrink-0"></i> <?= htmlspecialchars($status_msg) ?>
        </div>
    <?php endif; ?>

    <?php if (empty($users)): ?>
        <div class="bg-white rounded-2xl p-8 text-center text-slate-400 border border-dashed border-slate-200">
            เซสชันยังไม่พร้อม — <a href="logout.php" class="text-emerald-600 font-semibold">ออกแล้วล็อกอินใหม่</a>
        </div>
    <?php else: ?>
        <?php if (count($users) <= 1): ?>
            <div class="mb-4 p-3.5 bg-amber-50 border border-amber-200 text-amber-800 text-sm rounded-xl flex items-center gap-2">
                <i data-lucide="info" class="w-4 h-4 shrink-0"></i> ยังไม่มีเพื่อนให้หารด้วย — <a href="friends.php" class="font-semibold underline">เพิ่มเพื่อนก่อน</a>
            </div>
        <?php endif; ?>

    <form method="POST" enctype="multipart/form-data" class="bg-white rounded-2xl border border-slate-100 shadow-sm p-6 space-y-5" id="expenseForm">
        <!-- ชื่อรายการ -->
        <div>
            <label class="block text-sm font-semibold text-slate-600 mb-1.5">ค่าอะไร?</label>
            <div class="relative">
                <i data-lucide="shopping-bag" class="w-4 h-4 absolute left-3.5 top-1/2 -translate-y-1/2 text-slate-300"></i>
                <input type="text" name="title" required value="<?= htmlspecialchars($_POST['title'] ?? '') ?>"
                       placeholder="เช่น ค่าส้มตำปูปลาร้า, ค่าบอร์ดเกม"
                       class="w-full pl-10 pr-4 py-2.5 border border-slate-200 rounded-xl focus:outline-none focus:ring-2 focus:ring-emerald-400 text-sm">
            </div>
        </div>

        <!-- ยอดรวม -->
        <div>
            <label class="block text-sm font-semibold text-slate-600 mb-1.5">ยอดเงินรวม (บาท)</label>
            <div class="relative">
                <span class="absolute left-3.5 top-1/2 -translate-y-1/2 text-slate-400 font-bold">฿</span>
                <input type="number" name="total_amount" id="total" step="0.01" min="0.01" required
                       value="<?= htmlspecialchars($_POST['total_amount'] ?? '') ?>" placeholder="0.00"
                       class="w-full pl-9 pr-4 py-2.5 border border-slate-200 rounded-xl focus:outline-none focus:ring-2 focus:ring-emerald-400 font-bold text-slate-800">
            </div>
        </div>

        <!-- คนสำรองจ่าย -->
        <div>
            <label class="block text-sm font-semibold text-slate-600 mb-1.5">ใครสำรองจ่ายก่อน?</label>
            <select name="paid_by" class="w-full px-3.5 py-2.5 bg-white border border-slate-200 rounded-xl focus:outline-none focus:ring-2 focus:ring-emerald-400 text-sm text-slate-700">
                <?php foreach ($users as $u): ?>
                    <option value="<?= $u['id'] ?>" <?= $u['id'] == $myMemberId ? 'selected' : '' ?>><?= htmlspecialchars($u['name']) ?><?= $u['id'] == $myMemberId ? ' (ฉัน)' : '' ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <!-- โหมดการหาร -->
        <div>
            <label class="block text-sm font-semibold text-slate-600 mb-1.5">วิธีหาร</label>
            <div class="grid grid-cols-2 gap-2 p-1 bg-slate-100 rounded-xl" id="modeToggle">
                <label class="cursor-pointer">
                    <input type="radio" name="mode" value="equal" class="peer sr-only" checked>
                    <span class="flex items-center justify-center gap-1.5 py-2 rounded-lg text-sm font-semibold text-slate-500 peer-checked:bg-white peer-checked:text-emerald-600 peer-checked:shadow-sm transition">
                        <i data-lucide="equal" class="w-4 h-4"></i> หารเท่ากัน
                    </span>
                </label>
                <label class="cursor-pointer">
                    <input type="radio" name="mode" value="custom" class="peer sr-only">
                    <span class="flex items-center justify-center gap-1.5 py-2 rounded-lg text-sm font-semibold text-slate-500 peer-checked:bg-white peer-checked:text-emerald-600 peer-checked:shadow-sm transition">
                        <i data-lucide="sliders-horizontal" class="w-4 h-4"></i> กำหนดเอง
                    </span>
                </label>
            </div>
        </div>

        <!-- ผู้ร่วมหาร -->
        <div>
            <label class="block text-sm font-semibold text-slate-600 mb-1.5">ใครร่วมหารบ้าง?</label>
            <div class="border border-slate-200 rounded-xl divide-y divide-slate-100 overflow-hidden">
                <?php foreach ($users as $u): ?>
                    <label class="flex items-center gap-3 p-3 hover:bg-slate-50 cursor-pointer person-row">
                        <input type="checkbox" name="split_with[]" value="<?= $u['id'] ?>" checked
                               class="person-check w-4 h-4 text-emerald-500 border-slate-300 rounded focus:ring-emerald-400">
                        <?= avatar($u['id'], $u['name'], 'w-8 h-8 text-xs') ?>
                        <span class="text-sm font-medium text-slate-700 flex-1"><?= htmlspecialchars($u['name']) ?></span>
                        <div class="relative w-28">
                            <span class="absolute left-2.5 top-1/2 -translate-y-1/2 text-slate-400 text-sm">฿</span>
                            <input type="number" step="0.01" min="0" name="amount[<?= $u['id'] ?>]"
                                   class="amount-input w-full pl-6 pr-2 py-1.5 text-right text-sm border border-slate-200 rounded-lg bg-slate-50 focus:outline-none focus:ring-2 focus:ring-emerald-400"
                                   data-uid="<?= $u['id'] ?>" readonly>
                        </div>
                    </label>
                <?php endforeach; ?>
            </div>
            <!-- สรุปยอด -->
            <div id="splitSummary" class="mt-2 flex items-center justify-between text-sm font-medium px-1">
                <span class="text-slate-400">หารกัน <span id="cntPeople">0</span> คน</span>
                <span id="sumLabel" class="text-emerald-600"></span>
            </div>
        </div>

        <!-- แนบรูปใบเสร็จ -->
        <div>
            <label class="block text-sm font-semibold text-slate-600 mb-1.5">แนบรูปใบเสร็จ <span class="text-slate-400 font-normal">(ไม่บังคับ · ไม่เกิน 5MB)</span></label>
            <input type="file" name="receipt" id="receipt" accept="image/*" class="hidden">
            <label for="receipt" id="receiptDrop"
                   class="flex flex-col items-center justify-center gap-2 p-5 border-2 border-dashed border-slate-200 rounded-xl text-slate-400 hover:border-emerald-400 hover:text-emerald-500 cursor-pointer transition">
                <i data-lucide="camera" class="w-7 h-7"></i>
                <span id="receiptHint" class="text-sm">แตะเพื่อถ่าย/เลือกรูปใบเสร็จ</span>
                <img id="receiptPreview" class="hidden max-h-56 rounded-lg shadow-sm" alt="preview">
            </label>
        </div>

        <button type="submit" id="submitBtn"
                class="w-full bg-gradient-to-br from-emerald-400 to-teal-500 hover:from-emerald-500 hover:to-teal-600 text-white font-bold py-3 rounded-xl shadow-lg shadow-emerald-200 transition flex items-center justify-center gap-2">
            <i data-lucide="check-circle" class="w-5 h-5"></i> บันทึกและหารยอด
        </button>
    </form>
    <?php endif; ?>
</div>

<script src="split-editor.js"></script>
<script src="image-compress.js"></script>
<script>
(function () {
    const inp = document.getElementById('receipt');
    if (!inp) return;
    attachImageCompressor(inp, { onPreview: function (url) {
        const img = document.getElementById('receiptPreview');
        img.src = url; img.classList.remove('hidden');
        document.getElementById('receiptHint').textContent = 'แตะเพื่อเปลี่ยนรูป (บีบขนาดให้อัตโนมัติ)';
    }});
})();
</script>

<?php layout_foot(); ?>
