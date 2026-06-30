<?php
require_once 'auth.php';
require_login();
require_once 'config.php';
require_once '_layout.php';

$id = intval($_GET['id'] ?? 0);
if ($id <= 0) { header('Location: index.php'); exit; }

$status_msg = '';

/* ----- จัดการ POST: ลบ / แก้ไข ----- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'delete') {
        sb_delete('expenses?id=eq.' . $id); // cascade ลบ splits อัตโนมัติ
        header('Location: index.php?deleted=1');
        exit;
    }

    if ($action === 'edit') {
        $title   = trim($_POST['title'] ?? '');
        $total   = round((float) ($_POST['total_amount'] ?? 0), 2);
        $paid_by = intval($_POST['paid_by'] ?? 0);
        $mode    = ($_POST['mode'] ?? 'equal') === 'custom' ? 'custom' : 'equal';
        $picked  = $_POST['split_with'] ?? [];
        $amounts = $_POST['amount'] ?? [];

        if ($title === '' || $total <= 0 || $paid_by <= 0) {
            $status_msg = 'กรุณากรอกข้อมูลให้ครบถ้วน';
        } else {
            list($splits, $err) = compute_splits($mode, $total, $picked, $amounts);
            // รูปใบเสร็จ: ลบ / เปลี่ยนรูปใหม่ / คงเดิม
            $exUpdate = ['title' => $title, 'total_amount' => $total, 'paid_by' => $paid_by];
            $upErr = null;
            if (!empty($_POST['remove_receipt'])) {
                $exUpdate['receipt_url'] = null;
            } elseif (!$err) {
                list($rurl, $upErr) = handle_receipt_upload('receipt');
                if ($rurl !== null) $exUpdate['receipt_url'] = $rurl;
            }

            if ($err || $upErr) {
                $status_msg = $err ?: $upErr;
            } else {
                sb_update('expenses?id=eq.' . $id, $exUpdate);
                sb_delete('expense_splits?expense_id=eq.' . $id);
                $payload = [];
                foreach ($splits as $uid => $amt) {
                    $payload[] = ['expense_id' => $id, 'user_id' => (int) $uid, 'amount' => $amt];
                }
                sb_insert('expense_splits', $payload);
                header('Location: expense.php?id=' . $id . '&saved=1');
                exit;
            }
        }
    }
}

/* ----- ดึงข้อมูล ----- */
$rows = sb_get('expenses?id=eq.' . $id . '&select=*,users(name)');
$exp  = $rows[0] ?? null;
if (!$exp) {
    layout_head('ไม่พบรายการ', '');
    echo '<div class="bg-white rounded-2xl p-10 text-center text-slate-400 border border-dashed border-slate-200">ไม่พบรายจ่ายนี้ <a href="index.php" class="text-emerald-600 font-semibold">กลับหน้าหลัก</a></div>';
    layout_foot();
    exit;
}

$splits   = sb_get('expense_splits?expense_id=eq.' . $id . '&select=*,users(name)&order=amount.desc') ?: [];
$splitMap = [];
foreach ($splits as $s) { $splitMap[(int) $s['user_id']] = (float) $s['amount']; }

// ตัวเลือกผู้ร่วมหาร = ตัวเอง + เพื่อน + คนที่อยู่ในบิลนี้อยู่แล้ว (กันตกหล่นตอนแก้ไข)
$accountId = (int) ($_SESSION['user']['account_id'] ?? 0);
$users     = $accountId ? selectable_members($accountId) : [];
$haveIds   = array_map('intval', array_column($users, 'id'));
foreach (array_keys($splitMap) as $uid) {
    if (!in_array((int) $uid, $haveIds, true)) {
        $ur = sb_get('users?id=eq.' . (int) $uid . '&limit=1');
        if (is_array($ur) && isset($ur[0]['id'])) { $users[] = $ur[0]; $haveIds[] = (int) $ur[0]['id']; }
    }
}

layout_head('รายละเอียดรายจ่าย', '');
?>

<div class="max-w-xl mx-auto">
    <a href="index.php" class="inline-flex items-center gap-1.5 text-sm font-semibold text-slate-500 hover:text-emerald-600 mb-4">
        <i data-lucide="arrow-left" class="w-4 h-4"></i> กลับหน้าหลัก
    </a>

    <?php if (isset($_GET['new'])): ?>
        <div class="mb-4 p-3.5 bg-emerald-50 border border-emerald-200 text-emerald-700 text-sm font-medium rounded-xl flex items-center gap-2">
            <i data-lucide="party-popper" class="w-4 h-4"></i> บันทึกรายจ่ายและหารยอดเรียบร้อยแล้ว
        </div>
    <?php elseif (isset($_GET['saved'])): ?>
        <div class="mb-4 p-3.5 bg-emerald-50 border border-emerald-200 text-emerald-700 text-sm font-medium rounded-xl flex items-center gap-2">
            <i data-lucide="check-circle" class="w-4 h-4"></i> แก้ไขรายการเรียบร้อยแล้ว
        </div>
    <?php endif; ?>
    <?php if ($status_msg): ?>
        <div class="mb-4 p-3.5 bg-rose-50 border border-rose-200 text-rose-700 text-sm font-medium rounded-xl flex items-center gap-2">
            <i data-lucide="alert-triangle" class="w-4 h-4"></i> <?= htmlspecialchars($status_msg) ?>
        </div>
    <?php endif; ?>

    <!-- การ์ดสรุป -->
    <div class="bg-white rounded-2xl border border-slate-100 shadow-sm overflow-hidden mb-5">
        <div class="bg-gradient-to-br from-emerald-400 to-teal-500 p-6 text-white">
            <p class="text-emerald-50 text-sm flex items-center gap-1.5"><i data-lucide="shopping-bag" class="w-4 h-4"></i> รายการ</p>
            <h1 class="text-2xl font-extrabold mt-0.5"><?= htmlspecialchars($exp['title']) ?></h1>
            <p class="text-4xl font-black mt-3"><?= baht($exp['total_amount']) ?> <span class="text-lg font-medium text-emerald-100">฿</span></p>
        </div>
        <div class="p-5 flex flex-wrap gap-x-6 gap-y-2 text-sm">
            <div class="flex items-center gap-2">
                <span class="text-slate-400">สำรองจ่ายโดย</span>
                <?= avatar($exp['paid_by'], $exp['users']['name'] ?? '?', 'w-6 h-6 text-xs') ?>
                <span class="font-semibold text-slate-700"><?= htmlspecialchars($exp['users']['name'] ?? 'ไม่ระบุ') ?></span>
            </div>
            <div class="flex items-center gap-2 text-slate-400">
                <i data-lucide="calendar" class="w-4 h-4"></i> <?= date('d/m/Y H:i', strtotime($exp['created_at'])) ?>
            </div>
        </div>
    </div>

    <!-- รูปใบเสร็จ -->
    <?php if (!empty($exp['receipt_url'])): ?>
        <h2 class="text-sm font-bold text-slate-500 mb-2 px-1 flex items-center gap-1.5"><i data-lucide="receipt-text" class="w-4 h-4"></i> ใบเสร็จ</h2>
        <a href="<?= htmlspecialchars($exp['receipt_url']) ?>" target="_blank" class="block mb-6">
            <img src="<?= htmlspecialchars($exp['receipt_url']) ?>" alt="ใบเสร็จ"
                 class="w-full max-h-96 object-contain rounded-2xl border border-slate-100 shadow-sm bg-white">
        </a>
    <?php endif; ?>

    <!-- รายการหาร -->
    <h2 class="text-sm font-bold text-slate-500 mb-2 px-1">หารกัน <?= count($splits) ?> คน</h2>
    <div class="bg-white rounded-2xl border border-slate-100 shadow-sm divide-y divide-slate-50 mb-6">
        <?php foreach ($splits as $s): ?>
            <div class="flex items-center gap-3 p-3.5">
                <?= avatar($s['user_id'], $s['users']['name'] ?? '?', 'w-9 h-9 text-sm') ?>
                <span class="font-medium text-slate-700 text-sm flex-1"><?= htmlspecialchars($s['users']['name'] ?? 'ไม่ระบุ') ?></span>
                <span class="font-bold text-slate-800"><?= baht($s['amount']) ?> ฿</span>
            </div>
        <?php endforeach; ?>
    </div>

    <!-- ปุ่มแก้ไข / ลบ -->
    <div class="flex gap-3">
        <button onclick="document.getElementById('editPanel').classList.toggle('hidden')"
                class="flex-1 bg-white border border-slate-200 hover:border-emerald-400 text-slate-700 font-semibold py-2.5 rounded-xl transition flex items-center justify-center gap-2">
            <i data-lucide="pencil" class="w-4 h-4"></i> แก้ไข
        </button>
        <form method="POST" onsubmit="return confirm('ลบรายการ &quot;<?= htmlspecialchars(addslashes($exp['title'])) ?>&quot; ออกถาวร?');" class="flex-1">
            <input type="hidden" name="action" value="delete">
            <button type="submit" class="w-full bg-rose-50 border border-rose-200 hover:bg-rose-100 text-rose-600 font-semibold py-2.5 rounded-xl transition flex items-center justify-center gap-2">
                <i data-lucide="trash-2" class="w-4 h-4"></i> ลบรายการ
            </button>
        </form>
    </div>

    <!-- แผงแก้ไข (ซ่อนไว้) -->
    <div id="editPanel" class="hidden mt-5">
        <form method="POST" enctype="multipart/form-data" class="bg-white rounded-2xl border border-slate-100 shadow-sm p-6 space-y-5" id="expenseForm">
            <input type="hidden" name="action" value="edit">
            <h3 class="font-bold text-slate-700 flex items-center gap-2"><i data-lucide="pencil" class="w-4 h-4 text-emerald-500"></i> แก้ไขรายการ</h3>

            <div>
                <label class="block text-sm font-semibold text-slate-600 mb-1.5">ค่าอะไร?</label>
                <input type="text" name="title" required value="<?= htmlspecialchars($exp['title']) ?>"
                       class="w-full px-4 py-2.5 border border-slate-200 rounded-xl focus:outline-none focus:ring-2 focus:ring-emerald-400 text-sm">
            </div>
            <div>
                <label class="block text-sm font-semibold text-slate-600 mb-1.5">ยอดเงินรวม (บาท)</label>
                <div class="relative">
                    <span class="absolute left-3.5 top-1/2 -translate-y-1/2 text-slate-400 font-bold">฿</span>
                    <input type="number" name="total_amount" id="total" step="0.01" min="0.01" required value="<?= htmlspecialchars($exp['total_amount']) ?>"
                           class="w-full pl-9 pr-4 py-2.5 border border-slate-200 rounded-xl focus:outline-none focus:ring-2 focus:ring-emerald-400 font-bold text-slate-800">
                </div>
            </div>
            <div>
                <label class="block text-sm font-semibold text-slate-600 mb-1.5">ใครสำรองจ่ายก่อน?</label>
                <select name="paid_by" class="w-full px-3.5 py-2.5 bg-white border border-slate-200 rounded-xl focus:outline-none focus:ring-2 focus:ring-emerald-400 text-sm text-slate-700">
                    <?php foreach ($users as $u): ?>
                        <option value="<?= $u['id'] ?>" <?= $u['id'] == $exp['paid_by'] ? 'selected' : '' ?>><?= htmlspecialchars($u['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label class="block text-sm font-semibold text-slate-600 mb-1.5">วิธีหาร</label>
                <div class="grid grid-cols-2 gap-2 p-1 bg-slate-100 rounded-xl">
                    <label class="cursor-pointer">
                        <input type="radio" name="mode" value="equal" class="peer sr-only">
                        <span class="flex items-center justify-center gap-1.5 py-2 rounded-lg text-sm font-semibold text-slate-500 peer-checked:bg-white peer-checked:text-emerald-600 peer-checked:shadow-sm transition">
                            <i data-lucide="equal" class="w-4 h-4"></i> หารเท่ากัน
                        </span>
                    </label>
                    <label class="cursor-pointer">
                        <input type="radio" name="mode" value="custom" class="peer sr-only" checked>
                        <span class="flex items-center justify-center gap-1.5 py-2 rounded-lg text-sm font-semibold text-slate-500 peer-checked:bg-white peer-checked:text-emerald-600 peer-checked:shadow-sm transition">
                            <i data-lucide="sliders-horizontal" class="w-4 h-4"></i> กำหนดเอง
                        </span>
                    </label>
                </div>
            </div>
            <div>
                <label class="block text-sm font-semibold text-slate-600 mb-1.5">ใครร่วมหารบ้าง?</label>
                <div class="border border-slate-200 rounded-xl divide-y divide-slate-100 overflow-hidden">
                    <?php foreach ($users as $u):
                        $in  = array_key_exists((int) $u['id'], $splitMap);
                        $amt = $in ? $splitMap[(int) $u['id']] : ''; ?>
                        <label class="flex items-center gap-3 p-3 hover:bg-slate-50 cursor-pointer person-row">
                            <input type="checkbox" name="split_with[]" value="<?= $u['id'] ?>" <?= $in ? 'checked' : '' ?>
                                   class="person-check w-4 h-4 text-emerald-500 border-slate-300 rounded focus:ring-emerald-400">
                            <?= avatar($u['id'], $u['name'], 'w-8 h-8 text-xs') ?>
                            <span class="text-sm font-medium text-slate-700 flex-1"><?= htmlspecialchars($u['name']) ?></span>
                            <div class="relative w-28">
                                <span class="absolute left-2.5 top-1/2 -translate-y-1/2 text-slate-400 text-sm">฿</span>
                                <input type="number" step="0.01" min="0" name="amount[<?= $u['id'] ?>]" value="<?= $amt !== '' ? baht($amt) : '' ?>"
                                       class="amount-input w-full pl-6 pr-2 py-1.5 text-right text-sm border border-slate-200 rounded-lg focus:outline-none focus:ring-2 focus:ring-emerald-400" data-uid="<?= $u['id'] ?>">
                            </div>
                        </label>
                    <?php endforeach; ?>
                </div>
                <div class="mt-2 flex items-center justify-between text-sm font-medium px-1">
                    <span class="text-slate-400">หารกัน <span id="cntPeople">0</span> คน</span>
                    <span id="sumLabel" class="text-emerald-600"></span>
                </div>
            </div>

            <!-- รูปใบเสร็จ -->
            <div>
                <label class="block text-sm font-semibold text-slate-600 mb-1.5">รูปใบเสร็จ</label>
                <input type="file" name="receipt" id="receiptEdit" accept="image/*" class="hidden">
                <label for="receiptEdit" class="flex flex-col items-center justify-center gap-2 p-4 border-2 border-dashed border-slate-200 rounded-xl text-slate-400 hover:border-emerald-400 hover:text-emerald-500 cursor-pointer transition">
                    <i data-lucide="camera" class="w-6 h-6"></i>
                    <span id="receiptHintEdit" class="text-sm"><?= !empty($exp['receipt_url']) ? 'แตะเพื่อเปลี่ยนรูป' : 'แตะเพื่อแนบรูป' ?></span>
                    <img id="receiptPreviewEdit" src="<?= htmlspecialchars($exp['receipt_url'] ?? '') ?>" class="<?= !empty($exp['receipt_url']) ? '' : 'hidden' ?> max-h-48 rounded-lg shadow-sm" alt="preview">
                </label>
                <?php if (!empty($exp['receipt_url'])): ?>
                    <label class="flex items-center gap-2 mt-2 text-sm text-slate-500 cursor-pointer">
                        <input type="checkbox" name="remove_receipt" value="1" class="w-4 h-4 text-rose-500 border-slate-300 rounded focus:ring-rose-400">
                        ลบรูปใบเสร็จออก
                    </label>
                <?php endif; ?>
            </div>

            <button type="submit" id="submitBtn"
                    class="w-full bg-gradient-to-br from-emerald-400 to-teal-500 hover:from-emerald-500 hover:to-teal-600 text-white font-bold py-3 rounded-xl shadow-lg shadow-emerald-200 transition flex items-center justify-center gap-2">
                <i data-lucide="save" class="w-5 h-5"></i> บันทึกการแก้ไข
            </button>
        </form>
    </div>
</div>

<script src="split-editor.js"></script>
<script src="image-compress.js"></script>
<script>
(function () {
    const inp = document.getElementById('receiptEdit');
    if (!inp) return;
    attachImageCompressor(inp, { onPreview: function (url) {
        const img = document.getElementById('receiptPreviewEdit');
        img.src = url; img.classList.remove('hidden');
        document.getElementById('receiptHintEdit').textContent = 'แตะเพื่อเปลี่ยนรูป (บีบขนาดให้อัตโนมัติ)';
    }});
})();
</script>
<?php layout_foot(); ?>
