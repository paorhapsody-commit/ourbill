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
    ];
}

function layout_head($title, $active = '') {
    $items = nav_items();
    $savedTheme = function_exists('current_user_theme') ? current_user_theme() : '';
?><!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($title) ?> · OurBill</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        /* ---------- ธีมสี: สี emerald/teal/cyan ทั้งแอปชี้ไปที่ CSS variables --c-*
           เปลี่ยนธีม = สลับค่าตัวแปรเดียว มีผลทุกหน้า (rose ที่แทน "หนี้/อันตราย" ไม่ถูกแตะ) */
        window.OB_THEMES = {
            green:   { label: 'เขียว', 50:'#ecfdf5',100:'#d1fae5',200:'#a7f3d0',300:'#6ee7b7',400:'#34d399',500:'#10b981',600:'#059669',700:'#047857' },
            sky:     { label: 'ฟ้า',   50:'#f0f9ff',100:'#e0f2fe',200:'#bae6fd',300:'#7dd3fc',400:'#38bdf8',500:'#0ea5e9',600:'#0284c7',700:'#0369a1' },
            violet:  { label: 'ม่วง',  50:'#f5f3ff',100:'#ede9fe',200:'#ddd6fe',300:'#c4b5fd',400:'#a78bfa',500:'#8b5cf6',600:'#7c3aed',700:'#6d28d9' },
            amber:   { label: 'ส้ม',   50:'#fffbeb',100:'#fef3c7',200:'#fde68a',300:'#fcd34d',400:'#fbbf24',500:'#f59e0b',600:'#d97706',700:'#b45309' },
            fuchsia: { label: 'ชมพู',  50:'#fdf4ff',100:'#fae8ff',200:'#f5d0fe',300:'#f0abfc',400:'#e879f9',500:'#d946ef',600:'#c026d3',700:'#a21caf' }
        };
        window.OB_applyTheme = function (name) {
            var r = window.OB_THEMES[name] || window.OB_THEMES.green;
            var root = document.documentElement;
            ['50','100','200','300','400','500','600','700'].forEach(function (k) { root.style.setProperty('--c-' + k, r[k]); });
        };
        // ธีมที่บันทึกไว้ในบัญชี (server เป็นตัวตัดสิน) — ว่าง = ยังไม่ตั้ง ค่อย fallback ไป localStorage
        window.OB_SAVED_THEME = <?= json_encode($savedTheme, JSON_UNESCAPED_UNICODE) ?>;
        (function () {
            var t = window.OB_SAVED_THEME || localStorage.getItem('ob-theme') || 'green';
            window.OB_applyTheme(t);          // ใช้ทันที กันสีกระพริบก่อนวาดหน้า
            localStorage.setItem('ob-theme', t); // sync cache ให้ตรงกับบัญชี
        })();
        // ให้ Tailwind สร้างคลาส emerald/teal/cyan โดยอ้างอิงตัวแปร
        var _ramp = {}; ['50','100','200','300','400','500','600','700'].forEach(function (k) { _ramp[k] = 'var(--c-' + k + ')'; });
        tailwind.config = { theme: { extend: { colors: { emerald: _ramp, teal: _ramp, cyan: _ramp } } } };
    </script>
    <script src="https://unpkg.com/lucide@latest"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Sans+Thai:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        /* ค่าเริ่มต้น (ธีมเขียว) เผื่อกรณี JS ไม่ทำงาน */
        :root { --c-50:#ecfdf5; --c-100:#d1fae5; --c-200:#a7f3d0; --c-300:#6ee7b7; --c-400:#34d399; --c-500:#10b981; --c-600:#059669; --c-700:#047857; }
        body { font-family: 'IBM Plex Sans Thai', sans-serif; }
        @keyframes pop { 0% { transform: scale(.96); opacity: 0 } 100% { transform: scale(1); opacity: 1 } }
        .animate-pop { animation: pop .25s cubic-bezier(.2,.8,.3,1) both; }
        ::-webkit-scrollbar { width: 8px; height: 8px; }
        ::-webkit-scrollbar-thumb { background: var(--c-200); border-radius: 99px; }
    </style>
</head>
<body class="min-h-screen bg-gradient-to-br from-emerald-50 via-teal-50 to-cyan-50 text-slate-800 pb-24 md:pb-8">

    <!-- Top bar -->
    <nav class="sticky top-0 z-40 bg-white/70 backdrop-blur-xl border-b border-white/60 shadow-sm">
        <div class="max-w-6xl mx-auto px-4 py-3 flex items-center gap-2">
            <!-- left: โลโก้ -->
            <div class="flex-1 flex justify-start min-w-0">
            <a href="index.php" class="flex items-center gap-2.5 group">
                <span class="grid place-items-center w-10 h-10 rounded-2xl bg-gradient-to-br from-emerald-400 to-teal-500 text-white shadow-lg shadow-emerald-200 group-hover:scale-105 transition">
                    <i data-lucide="wallet-cards" class="w-5 h-5"></i>
                </span>
                <div class="leading-tight">
                    <p class="font-bold text-slate-800 text-lg tracking-tight"><?= htmlspecialchars(function_exists('setting') ? setting('app_name', 'OurBill') : 'OurBill') ?></p>
                    <p class="text-[11px] text-emerald-600 font-medium -mt-0.5"><?= htmlspecialchars(function_exists('setting') ? setting('app_tagline', 'หารกันให้ชัด ไม่มีลืม') : 'หารกันให้ชัด ไม่มีลืม') ?></p>
                </div>
            </a>
            </div>

            <!-- center: เมนูหลัก (กึ่งกลาง) -->
            <div class="hidden md:flex items-center gap-1 bg-white/60 rounded-2xl p-1 border border-white/80">
                    <?php foreach ($items as $it):
                        $on = $it['href'] === $active; ?>
                        <a href="<?= $it['href'] ?>"
                           class="flex items-center gap-1.5 px-3.5 py-2 rounded-xl text-sm font-semibold whitespace-nowrap transition
                           <?= $on ? 'bg-gradient-to-br from-emerald-400 to-teal-500 text-white shadow-md shadow-emerald-200'
                                   : 'text-slate-500 hover:text-emerald-600 hover:bg-emerald-50' ?>">
                            <i data-lucide="<?= $it['icon'] ?>" class="w-4 h-4"></i><?= $it['label'] ?>
                        </a>
                    <?php endforeach; ?>
                </div>

                <!-- right: โปรไฟล์ -->
                <div class="flex-1 flex justify-end min-w-0">
                <!-- User dropdown (โปรไฟล์ + ธีมสี + ออกจากระบบ) -->
                <?php if (function_exists('current_user') && ($cu = current_user())):
                    $avatar = !empty($cu['picture'])
                        ? '<img src="' . htmlspecialchars($cu['picture']) . '" alt="" referrerpolicy="no-referrer" class="w-8 h-8 rounded-full object-cover ring-2 ring-emerald-200">'
                        : '<span class="grid place-items-center w-8 h-8 rounded-full bg-emerald-100 text-emerald-600"><i data-lucide="user" class="w-4 h-4"></i></span>';
                ?>
                    <div class="relative" id="userMenu">
                        <button type="button" id="userMenuBtn" aria-haspopup="true" aria-expanded="false"
                                class="flex items-center gap-1.5 bg-white/60 rounded-2xl p-1 pl-1.5 border border-white/80 hover:bg-white transition">
                            <?= $avatar ?>
                            <span class="hidden sm:block text-sm font-semibold text-slate-600 max-w-[8rem] truncate"><?= htmlspecialchars($cu['name']) ?></span>
                            <i data-lucide="chevron-down" class="w-4 h-4 text-slate-400 mr-0.5 transition-transform" id="userMenuCaret"></i>
                        </button>

                        <div id="userMenuPanel"
                             class="hidden absolute right-0 mt-2 w-64 max-w-[calc(100vw-2rem)] bg-white rounded-2xl shadow-xl border border-slate-100 p-2 z-50 origin-top-right">
                            <!-- โปรไฟล์ -->
                            <div class="flex items-center gap-3 px-2 py-2">
                                <?= $avatar ?>
                                <div class="min-w-0">
                                    <p class="text-sm font-bold text-slate-800 truncate"><?= htmlspecialchars($cu['name']) ?></p>
                                    <?php if (!empty($cu['email'])): ?>
                                        <p class="text-[11px] text-slate-400 truncate"><?= htmlspecialchars($cu['email']) ?></p>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <div class="my-1.5 border-t border-slate-100"></div>

                            <!-- เพื่อน -->
                            <a href="friends.php"
                               class="w-full flex items-center gap-2.5 px-2.5 py-2 rounded-xl text-sm font-semibold transition
                               <?= $active === 'friends.php' ? 'bg-emerald-50 text-emerald-600' : 'text-slate-600 hover:bg-slate-50' ?>">
                                <i data-lucide="users" class="w-4 h-4"></i> เพื่อน
                            </a>

                            <div class="my-1.5 border-t border-slate-100"></div>

                            <!-- เปลี่ยนสีธีม -->
                            <div class="px-2 py-1">
                                <p class="text-[11px] font-semibold text-slate-400 mb-1.5 flex items-center gap-1">
                                    <i data-lucide="palette" class="w-3.5 h-3.5"></i> สีธีม
                                </p>
                                <div class="flex items-center gap-2" id="themeSwatches"></div>
                            </div>

                            <div class="my-1.5 border-t border-slate-100"></div>

                            <!-- ออกจากระบบ -->
                            <button type="button" id="logoutBtn"
                                    class="w-full flex items-center gap-2.5 px-2.5 py-2 rounded-xl text-sm font-semibold text-rose-500 hover:bg-rose-50 transition">
                                <i data-lucide="log-out" class="w-4 h-4"></i> ออกจากระบบ
                            </button>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </nav>

    <!-- Mobile bottom tab bar -->
    <nav class="md:hidden fixed bottom-0 inset-x-0 z-40 bg-white/80 backdrop-blur-xl border-t border-white/60 shadow-[0_-4px_20px_rgba(0,0,0,0.05)]">
        <div class="grid grid-cols-4">
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
    <script>
    // "ดูเพิ่ม" — ซ่อนรายการเกิน data-show แล้วมีปุ่มเผยทีละชุด (ใช้กับ .js-more-list)
    document.querySelectorAll('.js-more-list').forEach(function (box) {
        var step = parseInt(box.dataset.show) || 10;
        var items = Array.prototype.filter.call(box.children, function (c) { return c.nodeType === 1; });
        if (items.length <= step) return;
        var shown = step;
        function apply() { items.forEach(function (el, i) { el.style.display = i < shown ? '' : 'none'; }); }
        var btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'js-more-btn w-full mt-2 py-2 rounded-xl bg-slate-100 hover:bg-slate-200 text-slate-500 text-sm font-semibold transition';
        function label() { btn.textContent = 'ดูเพิ่ม (' + (items.length - shown) + ')'; }
        btn.addEventListener('click', function () {
            shown = Math.min(items.length, shown + step);
            apply();
            if (shown >= items.length) btn.remove(); else label();
        });
        apply(); label();
        box.parentNode.insertBefore(btn, box.nextSibling);
    });
    </script>
    <script>
    // ---------- User dropdown + เปลี่ยนสีธีม + ยืนยันออกจากระบบ ----------
    (function () {
        var menu = document.getElementById('userMenu');
        if (!menu) return;
        var btn   = document.getElementById('userMenuBtn');
        var panel = document.getElementById('userMenuPanel');
        var caret = document.getElementById('userMenuCaret');

        function openMenu()  { panel.classList.remove('hidden'); btn.setAttribute('aria-expanded', 'true');  if (caret) caret.style.transform = 'rotate(180deg)'; }
        function closeMenu() { panel.classList.add('hidden');    btn.setAttribute('aria-expanded', 'false'); if (caret) caret.style.transform = ''; }

        btn.addEventListener('click', function (e) {
            e.stopPropagation();
            panel.classList.contains('hidden') ? openMenu() : closeMenu();
        });
        document.addEventListener('click', function (e) { if (!menu.contains(e.target)) closeMenu(); });
        document.addEventListener('keydown', function (e) { if (e.key === 'Escape') closeMenu(); });

        // ปุ่มเลือกสีธีม
        var wrap = document.getElementById('themeSwatches');
        var current = window.OB_SAVED_THEME || localStorage.getItem('ob-theme') || 'green';
        function persistTheme(name) {
            localStorage.setItem('ob-theme', name); // cache ทันที
            // บันทึกลงบัญชี (server) เพื่อให้ตามผู้ใช้ไปทุกอุปกรณ์ — fire-and-forget
            fetch('set-theme.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'theme=' + encodeURIComponent(name)
            }).catch(function () {});
        }
        function renderSwatches() {
            wrap.innerHTML = '';
            Object.keys(window.OB_THEMES).forEach(function (name) {
                var t = window.OB_THEMES[name];
                var b = document.createElement('button');
                b.type = 'button';
                b.title = t.label;
                b.style.backgroundColor = t['500'];
                b.className = 'w-7 h-7 rounded-full transition hover:scale-110 ring-offset-2 ' +
                              (name === current ? 'ring-2 ring-slate-400' : 'ring-1 ring-black/5');
                b.addEventListener('click', function () {
                    current = name;
                    window.OB_applyTheme(name);
                    persistTheme(name);
                    renderSwatches();
                });
                wrap.appendChild(b);
            });
        }
        renderSwatches();

        // ออกจากระบบ + ยืนยันด้วย SweetAlert2
        var logoutBtn = document.getElementById('logoutBtn');
        if (logoutBtn) logoutBtn.addEventListener('click', function () {
            closeMenu();
            var accent = getComputedStyle(document.documentElement).getPropertyValue('--c-600').trim() || '#059669';
            Swal.fire({
                title: 'ออกจากระบบ?',
                text: 'คุณต้องการออกจากระบบใช่หรือไม่',
                icon: 'question',
                showCancelButton: true,
                confirmButtonText: 'ออกจากระบบ',
                cancelButtonText: 'ยกเลิก',
                confirmButtonColor: '#f43f5e',
                cancelButtonColor: accent,
                reverseButtons: true
            }).then(function (res) { if (res.isConfirmed) window.location.href = 'logout.php'; });
        });
    })();
    </script>
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
