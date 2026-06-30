<?php
/* =========================================================
 *  Shared layout — เรียก layout_head() ต้นไฟล์ และ layout_foot() ท้ายไฟล์
 * ========================================================= */

function nav_items() {
    return [
        ['href' => 'index.php',       'icon' => 'layout-dashboard', 'label' => 'หน้าหลัก'],
        ['href' => 'add-expense.php', 'icon' => 'plus-circle',      'label' => 'เพิ่มรายจ่าย'],
        ['href' => 'settle.php',      'icon' => 'arrow-right-left',  'label' => 'เคลียร์หนี้'],
        ['href' => 'holdings.php',    'icon' => 'piggy-bank',        'label' => 'เงินเพื่อน'],
        ['href' => 'friends.php',     'icon' => 'users',             'label' => 'เพื่อน'],
    ];
}

function layout_head($title, $active = '') {
    $items = nav_items();
?><!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($title) ?> · OurBill</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://unpkg.com/lucide@latest"></script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Sans+Thai:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'IBM Plex Sans Thai', sans-serif; }
        @keyframes pop { 0% { transform: scale(.96); opacity: 0 } 100% { transform: scale(1); opacity: 1 } }
        .animate-pop { animation: pop .25s cubic-bezier(.2,.8,.3,1) both; }
        ::-webkit-scrollbar { width: 8px; height: 8px; }
        ::-webkit-scrollbar-thumb { background: #99f6e4; border-radius: 99px; }
    </style>
</head>
<body class="min-h-screen bg-gradient-to-br from-emerald-50 via-teal-50 to-cyan-50 text-slate-800 pb-24 md:pb-8">

    <!-- Top bar -->
    <nav class="sticky top-0 z-40 bg-white/70 backdrop-blur-xl border-b border-white/60 shadow-sm">
        <div class="max-w-4xl mx-auto px-4 py-3 flex items-center justify-between">
            <a href="index.php" class="flex items-center gap-2.5 group">
                <span class="grid place-items-center w-10 h-10 rounded-2xl bg-gradient-to-br from-emerald-400 to-teal-500 text-white shadow-lg shadow-emerald-200 group-hover:scale-105 transition">
                    <i data-lucide="wallet-cards" class="w-5 h-5"></i>
                </span>
                <div class="leading-tight">
                    <p class="font-bold text-slate-800 text-lg tracking-tight"><?= htmlspecialchars(function_exists('setting') ? setting('app_name', 'OurBill') : 'OurBill') ?></p>
                    <p class="text-[11px] text-emerald-600 font-medium -mt-0.5"><?= htmlspecialchars(function_exists('setting') ? setting('app_tagline', 'หารกันให้ชัด ไม่มีลืม') : 'หารกันให้ชัด ไม่มีลืม') ?></p>
                </div>
            </a>

            <div class="flex items-center gap-2">
                <!-- Desktop nav -->
                <div class="hidden md:flex items-center gap-1 bg-white/60 rounded-2xl p-1 border border-white/80">
                    <?php foreach ($items as $it):
                        $on = $it['href'] === $active; ?>
                        <a href="<?= $it['href'] ?>"
                           class="flex items-center gap-1.5 px-3.5 py-2 rounded-xl text-sm font-semibold transition
                           <?= $on ? 'bg-gradient-to-br from-emerald-400 to-teal-500 text-white shadow-md shadow-emerald-200'
                                   : 'text-slate-500 hover:text-emerald-600 hover:bg-emerald-50' ?>">
                            <i data-lucide="<?= $it['icon'] ?>" class="w-4 h-4"></i><?= $it['label'] ?>
                        </a>
                    <?php endforeach; ?>
                </div>

                <!-- User chip + logout -->
                <?php if (function_exists('current_user') && ($cu = current_user())): ?>
                    <div class="flex items-center gap-1.5 bg-white/60 rounded-2xl p-1 pl-1.5 border border-white/80">
                        <?php if (!empty($cu['picture'])): ?>
                            <img src="<?= htmlspecialchars($cu['picture']) ?>" alt="" referrerpolicy="no-referrer"
                                 class="w-8 h-8 rounded-full object-cover ring-2 ring-emerald-200">
                        <?php else: ?>
                            <span class="grid place-items-center w-8 h-8 rounded-full bg-emerald-100 text-emerald-600">
                                <i data-lucide="user" class="w-4 h-4"></i>
                            </span>
                        <?php endif; ?>
                        <span class="hidden sm:block text-sm font-semibold text-slate-600 max-w-[8rem] truncate pr-1"><?= htmlspecialchars($cu['name']) ?></span>
                        <a href="logout.php" title="ออกจากระบบ"
                           class="grid place-items-center w-8 h-8 rounded-xl text-slate-400 hover:text-rose-500 hover:bg-rose-50 transition">
                            <i data-lucide="log-out" class="w-4 h-4"></i>
                        </a>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </nav>

    <!-- Mobile bottom tab bar -->
    <nav class="md:hidden fixed bottom-0 inset-x-0 z-40 bg-white/80 backdrop-blur-xl border-t border-white/60 shadow-[0_-4px_20px_rgba(0,0,0,0.05)]">
        <div class="grid grid-cols-5">
            <?php foreach ($items as $it):
                $on = $it['href'] === $active; ?>
                <a href="<?= $it['href'] ?>" class="flex flex-col items-center gap-0.5 py-2.5 text-[10px] font-medium transition
                   <?= $on ? 'text-emerald-600' : 'text-slate-400' ?>">
                    <span class="<?= $on ? 'bg-emerald-100 px-3 py-1 rounded-full' : 'px-3 py-1' ?>">
                        <i data-lucide="<?= $it['icon'] ?>" class="w-5 h-5"></i>
                    </span>
                    <?= $it['label'] ?>
                </a>
            <?php endforeach; ?>
        </div>
    </nav>

    <main class="max-w-4xl mx-auto px-4 py-6 md:py-8 animate-pop">
<?php
}

function layout_foot() {
?>
    </main>
    <footer class="max-w-4xl mx-auto px-4 pt-2 pb-6 text-center text-xs text-slate-400">
        OurBill · ระบบหารเงินกลุ่มเพื่อน · ทำด้วย PHP + Supabase
    </footer>
    <script>lucide.createIcons();</script>
</body>
</html>
<?php
}

/**
 * Modal ยืนยันการเคลียร์หนี้ — แสดงรายการรายวันที่ประกอบเป็นยอด + กรอกจำนวนใน modal + ส่วนต่าง
 * ใช้กับฟอร์ม class "js-reconcile-form" + data-name/net/fid และ hidden input[name=amount]
 * $itemsByFriend = [friendId => [ {ts,icon,title,sub,impact}, ... ]] (จาก friend_timeline)
 * เรียกก่อน layout_foot()
 */
function reconcile_modal($itemsByFriend = []) {
?>
<script>window.RECONCILE_ITEMS = <?= json_encode($itemsByFriend ?: new stdClass, JSON_UNESCAPED_UNICODE) ?>;</script>
<div id="reconcileModal" class="hidden fixed inset-0 z-50 flex items-end sm:items-center justify-center sm:p-4 bg-black/50 backdrop-blur-sm">
    <div class="bg-white rounded-t-2xl sm:rounded-2xl shadow-xl w-full max-w-md max-h-[92vh] flex flex-col">
        <div class="p-5 pb-3 border-b border-slate-100 shrink-0">
            <div class="flex items-center gap-2">
                <span class="grid place-items-center w-9 h-9 rounded-xl bg-emerald-100 text-emerald-600"><i data-lucide="check-check" class="w-4 h-4"></i></span>
                <h3 class="font-bold text-slate-800">เคลียร์กับ <span id="rcName"></span></h3>
            </div>
            <div class="text-sm mt-2" id="rcSummary"></div>
        </div>
        <div class="px-5 py-3 overflow-y-auto flex-1">
            <p class="text-xs font-semibold text-slate-400 mb-2">รายการที่ประกอบเป็นยอด (+ เพื่อนติดเรา / − เราติดเพื่อน)</p>
            <div id="rcItems" class="space-y-1.5"></div>
        </div>
        <div class="p-5 pt-3 border-t border-slate-100 shrink-0">
            <label class="block text-xs font-semibold text-slate-500 mb-1">จำนวนที่ลูกหนี้จ่ายคืน (฿)</label>
            <input id="rcAmount" type="number" step="0.01" min="0"
                   class="w-full px-3 py-2.5 border border-slate-200 rounded-xl text-sm font-bold focus:outline-none focus:ring-2 focus:ring-emerald-400 mb-2">
            <div class="flex justify-between items-start text-sm mb-3"><span class="text-slate-500">คงเหลือหลังเคลียร์</span><span id="rcResidual" class="text-right"></span></div>
            <div class="flex gap-2">
                <button id="rcCancel" type="button" class="flex-1 py-2.5 rounded-xl bg-slate-100 text-slate-600 font-semibold text-sm hover:bg-slate-200 transition">ยกเลิก</button>
                <button id="rcConfirm" type="button" class="flex-1 py-2.5 rounded-xl bg-gradient-to-br from-emerald-400 to-teal-500 text-white font-bold text-sm shadow-md shadow-emerald-200 transition">ยืนยันเคลียร์</button>
            </div>
        </div>
    </div>
</div>
<script>
(function () {
    const modal = document.getElementById('reconcileModal');
    if (!modal) return;
    document.body.appendChild(modal); // ย้ายออกจาก <main> (ที่มี transform) ให้ fixed เต็มจอ
    const ITEMS = window.RECONCILE_ITEMS || {};
    const ICON = { receipt:'receipt', 'arrow-right-left':'arrow-right-left', 'piggy-bank':'piggy-bank', banknote:'banknote', 'calendar-clock':'calendar-clock' };
    const $ = function (id) { return document.getElementById(id); };
    let pending = null, curNet = 0;
    function fmt(n){ return Number(n).toLocaleString('en-US',{minimumFractionDigits:2,maximumFractionDigits:2}); }
    function dateTH(ts){ if(!ts) return ''; const p=String(ts).slice(0,10).split('-'); return p.length===3 ? (parseInt(p[2])+'/'+p[1]+'/'+p[0].slice(2)) : ''; }

    function updateResidual(){
        const pay = parseFloat($('rcAmount').value) || 0;
        const residual = Math.round((curNet - (curNet >= 0 ? pay : -pay)) * 100) / 100;
        let r;
        if (Math.abs(residual) < 0.009) r = '<span class="text-slate-500 font-semibold">เคลียร์หมด ✓</span>';
        else if (residual > 0) r = '<span class="text-emerald-600 font-semibold">เพื่อนยังติดเรา ' + fmt(residual) + ' ฿</span><br><span class="text-[11px] text-slate-400">→ เก็บไว้ที่เงินเพื่อน</span>';
        else r = '<span class="text-rose-500 font-semibold">เราติดเพื่อน ' + fmt(-residual) + ' ฿</span><br><span class="text-[11px] text-slate-400">→ เก็บไว้ที่เงินเพื่อน</span>';
        $('rcResidual').innerHTML = r;
    }

    function open(form) {
        pending = form;
        curNet = parseFloat(form.dataset.net) || 0;
        $('rcName').textContent = form.dataset.name || '';
        $('rcSummary').innerHTML = curNet >= 0
            ? '<span class="text-emerald-600 font-bold">สรุป: เพื่อนติดเรา ' + fmt(curNet) + ' ฿</span>'
            : '<span class="text-rose-500 font-bold">สรุป: เราติดเพื่อน ' + fmt(-curNet) + ' ฿</span>';
        const items = ITEMS[form.dataset.fid] || [];
        $('rcItems').innerHTML = items.length ? items.map(function (it) {
            const pos = (it.impact || 0) >= 0;
            return '<div class="flex items-center gap-2.5">'
              + '<span class="grid place-items-center w-8 h-8 rounded-lg bg-slate-50 text-emerald-500 shrink-0"><i data-lucide="' + (ICON[it.icon] || 'circle') + '" class="w-4 h-4"></i></span>'
              + '<div class="min-w-0 flex-1"><p class="text-sm text-slate-700 truncate">' + (it.title || '') + '</p>'
              + '<p class="text-[11px] text-slate-400">' + (it.sub ? it.sub + ' · ' : '') + dateTH(it.ts) + '</p></div>'
              + '<span class="text-sm font-semibold shrink-0 ' + (pos ? 'text-emerald-600' : 'text-rose-500') + '">' + (pos ? '+' : '−') + fmt(Math.abs(it.impact || 0)) + '</span></div>';
        }).join('') : '<p class="text-slate-400 text-sm text-center py-2">— ไม่มีรายการย่อย —</p>';
        $('rcAmount').value = fmt(Math.abs(curNet));
        updateResidual();
        modal.classList.remove('hidden');
        if (window.lucide) lucide.createIcons();
    }

    document.querySelectorAll('.js-reconcile-form').forEach(function (f) {
        f.addEventListener('submit', function (e) { e.preventDefault(); open(f); });
    });
    $('rcAmount').addEventListener('input', updateResidual);
    $('rcCancel').addEventListener('click', function () { modal.classList.add('hidden'); });
    modal.addEventListener('click', function (e) { if (e.target === modal) modal.classList.add('hidden'); });
    $('rcConfirm').addEventListener('click', function () {
        if (!pending) return;
        let h = pending.querySelector('input[name=amount]');
        if (!h) { h = document.createElement('input'); h.type = 'hidden'; h.name = 'amount'; pending.appendChild(h); }
        h.value = (parseFloat($('rcAmount').value) || 0).toFixed(2);
        pending.submit();
    });
})();
</script>
<?php
}
