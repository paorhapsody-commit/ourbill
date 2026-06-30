<?php
require_once 'auth.php';
require_login();
require_once 'config.php';
require_once '_layout.php';

$myMember = (int) ($_SESSION['user']['member_id'] ?? 0);
$friends  = unified_balances($myMember);   // ยอดสุทธิรวมทุกฟังก์ชัน ต่อเพื่อน
$ledger   = sb_get('expenses?select=*,users(name)&order=created_at.desc&limit=50') ?: [];
$dueAlerts = installments_due_alerts($myMember);  // ผ่อนที่ถึงกำหนดงวดแล้ว

// รายการสำหรับปฏิทิน จัดกลุ่มตามวัน
$calByDate = [];
foreach (calendar_events($myMember) as $e) { $calByDate[$e['date']][] = $e; }

// สรุปจากมุมของเรา
$owedToMe = 0; $iOwe = 0; $installRecv = 0; $held = 0;
foreach ($friends as $f) {
    if ($f['net'] > 0.009)      $owedToMe += $f['net'];
    elseif ($f['net'] < -0.009) $iOwe     += -$f['net'];
    if ($f['installment'] > 0)  $installRecv += $f['installment'];
}
if ($myMember) {
    foreach (sb_rows(sb_get('holdings?holder_id=eq.' . $myMember . '&select=amount')) as $h) {
        $held += (float) $h['amount'];
    }
}

layout_head('หน้าหลัก', 'index.php');
?>

<?php if (!empty($dueAlerts)): ?>
<!-- แจ้งเตือนผ่อนที่ถึงกำหนดงวด -->
<div class="bg-amber-50 border border-amber-200 rounded-2xl p-4 mb-6">
    <p class="font-bold text-amber-800 text-sm flex items-center gap-2 mb-2.5">
        <i data-lucide="bell-ring" class="w-4 h-4"></i> ผ่อนถึงกำหนดงวดแล้ว (<?= count($dueAlerts) ?>)
    </p>
    <div class="space-y-2">
        <?php foreach ($dueAlerts as $a): ?>
            <a href="friend.php?id=<?= $a['friend_id'] ?>" class="flex items-center gap-3 bg-white rounded-xl p-3 border border-amber-100 hover:border-amber-300 transition">
                <span class="grid place-items-center w-9 h-9 rounded-xl bg-amber-100 text-amber-600 shrink-0">
                    <i data-lucide="calendar-clock" class="w-4 h-4"></i>
                </span>
                <div class="min-w-0 flex-1">
                    <p class="text-sm font-semibold text-slate-700 truncate"><?= htmlspecialchars($a['title']) ?></p>
                    <p class="text-xs text-slate-400">
                        <?= $a['friend_pays'] ? 'รอรับจาก' : 'ต้องจ่ายให้' ?> <?= htmlspecialchars($a['friend_name']) ?>
                        · ครบกำหนด <?= $a['due'] ?>/<?= $a['months'] ?> งวด
                        <?php if (!empty($a['due_date'])): ?> · งวดล่าสุด <?= date('d/m/y', strtotime($a['due_date'])) ?><?php endif; ?>
                    </p>
                </div>
                <span class="font-bold text-sm shrink-0 <?= $a['friend_pays'] ? 'text-emerald-600' : 'text-rose-500' ?>"><?= baht($a['outstanding']) ?> ฿</span>
            </a>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>

<!-- Summary stat cards (มุมของเรา) -->
<div class="grid grid-cols-2 lg:grid-cols-4 gap-3 md:gap-4 mb-7">
    <div class="bg-white rounded-2xl p-4 border border-slate-100 shadow-sm">
        <div class="flex items-center gap-2 text-slate-400 text-xs font-medium mb-1.5">
            <i data-lucide="trending-up" class="w-4 h-4"></i> เพื่อนติดเรา
        </div>
        <p class="text-2xl font-extrabold text-emerald-600"><?= baht($owedToMe) ?> <span class="text-sm font-medium text-slate-400">฿</span></p>
    </div>
    <div class="bg-white rounded-2xl p-4 border border-slate-100 shadow-sm">
        <div class="flex items-center gap-2 text-slate-400 text-xs font-medium mb-1.5">
            <i data-lucide="trending-down" class="w-4 h-4"></i> เราติดเพื่อน
        </div>
        <p class="text-2xl font-extrabold text-rose-500"><?= baht($iOwe) ?> <span class="text-sm font-medium text-slate-400">฿</span></p>
    </div>
    <a href="holdings.php" class="bg-white rounded-2xl p-4 border border-slate-100 shadow-sm hover:shadow-md transition">
        <div class="flex items-center gap-2 text-slate-400 text-xs font-medium mb-1.5">
            <i data-lucide="piggy-bank" class="w-4 h-4"></i> เงินเพื่อนที่ถือไว้
        </div>
        <p class="text-2xl font-extrabold text-slate-800"><?= baht($held) ?> <span class="text-sm font-medium text-slate-400">฿</span></p>
    </a>
    <a href="installments.php" class="bg-gradient-to-br from-emerald-400 to-teal-500 rounded-2xl p-4 shadow-lg shadow-emerald-200 text-white hover:from-emerald-500 hover:to-teal-600 transition">
        <div class="flex items-center gap-2 text-emerald-50 text-xs font-medium mb-1.5">
            <i data-lucide="calendar-clock" class="w-4 h-4"></i> ผ่อนค้างรับ
        </div>
        <p class="text-2xl font-extrabold"><?= baht($installRecv) ?> <span class="text-sm font-medium text-emerald-100">฿</span></p>
    </a>
</div>

<!-- ปฏิทินรายการ -->
<div class="bg-white rounded-2xl border border-slate-100 shadow-sm p-4 md:p-5 mb-7">
    <div class="flex items-center justify-between mb-3">
        <button type="button" id="calPrev" class="p-2 rounded-lg text-slate-400 hover:bg-slate-100 hover:text-emerald-600 transition"><i data-lucide="chevron-left" class="w-5 h-5"></i></button>
        <h2 id="calTitle" class="text-base font-bold text-slate-700 flex items-center gap-2"><i data-lucide="calendar" class="w-5 h-5 text-emerald-500"></i> <span></span></h2>
        <button type="button" id="calNext" class="p-2 rounded-lg text-slate-400 hover:bg-slate-100 hover:text-emerald-600 transition"><i data-lucide="chevron-right" class="w-5 h-5"></i></button>
    </div>
    <div class="grid grid-cols-7 gap-1 text-center text-[11px] font-semibold text-slate-400 mb-1">
        <span>อา</span><span>จ</span><span>อ</span><span>พ</span><span>พฤ</span><span>ศ</span><span>ส</span>
    </div>
    <div id="calGrid" class="grid grid-cols-7 gap-1"></div>
    <div id="calDay" class="mt-4"></div>
</div>

<script>
(function () {
    const EVENTS = <?= json_encode($calByDate, JSON_UNESCAPED_UNICODE) ?> || {};
    const MONTHS = ['มกราคม','กุมภาพันธ์','มีนาคม','เมษายน','พฤษภาคม','มิถุนายน','กรกฎาคม','สิงหาคม','กันยายน','ตุลาคม','พฤศจิกายน','ธันวาคม'];
    const ICON = { receipt:'receipt', 'arrow-right-left':'arrow-right-left', 'piggy-bank':'piggy-bank', banknote:'banknote' };
    const grid = document.getElementById('calGrid');
    const dayBox = document.getElementById('calDay');
    const title = document.querySelector('#calTitle span');
    const today = '<?= date('Y-m-d') ?>';
    let view = new Date('<?= date('Y-m-01') ?>T00:00:00');
    let selected = today;

    function fmt(n){ return Number(n).toLocaleString('en-US',{minimumFractionDigits:2,maximumFractionDigits:2}); }
    function pad(n){ return (n<10?'0':'')+n; }
    function key(y,m,d){ return y+'-'+pad(m+1)+'-'+pad(d); }

    function renderDay(dstr){
        selected = dstr;
        const list = EVENTS[dstr] || [];
        const [y,m,d] = dstr.split('-');
        let html = '<p class="text-sm font-bold text-slate-600 mb-2">'+parseInt(d)+' '+MONTHS[parseInt(m)-1]+' '+y+'</p>';
        if (!list.length) {
            html += '<p class="text-sm text-slate-400 text-center py-4">ไม่มีรายการวันนี้</p>';
        } else {
            html += '<div class="space-y-1.5">' + list.map(function(e){
                const color = e.sign==='+' ? 'text-emerald-600' : (e.sign==='-' ? 'text-rose-500' : 'text-slate-700');
                const amt = e.sign ? (e.sign+fmt(e.amount)) : fmt(e.amount);
                return '<div class="flex items-center gap-2.5 p-2 rounded-xl bg-slate-50">'
                  + '<span class="grid place-items-center w-8 h-8 rounded-lg bg-white text-emerald-500 shrink-0"><i data-lucide="'+(ICON[e.icon]||'circle')+'" class="w-4 h-4"></i></span>'
                  + '<div class="min-w-0 flex-1"><p class="text-sm font-semibold text-slate-700 truncate">'+e.title+'</p>'
                  + (e.sub ? '<p class="text-xs text-slate-400 truncate">'+e.sub+'</p>' : '') + '</div>'
                  + '<span class="text-sm font-bold '+color+' shrink-0">'+amt+' ฿</span></div>';
            }).join('') + '</div>';
        }
        dayBox.innerHTML = html;
        if (window.lucide) lucide.createIcons();
    }

    function render(){
        const y = view.getFullYear(), m = view.getMonth();
        title.textContent = MONTHS[m] + ' ' + y;
        const first = new Date(y, m, 1).getDay();
        const days = new Date(y, m+1, 0).getDate();
        let cells = '';
        for (let i=0;i<first;i++) cells += '<span></span>';
        for (let d=1; d<=days; d++){
            const ds = key(y,m,d);
            const has = !!EVENTS[ds];
            const isToday = ds===today, isSel = ds===selected;
            cells += '<button type="button" data-d="'+ds+'" class="relative aspect-square flex flex-col items-center justify-center rounded-lg text-sm transition '
                + (isSel ? 'bg-gradient-to-br from-emerald-400 to-teal-500 text-white font-bold shadow' : (isToday ? 'bg-emerald-50 text-emerald-700 font-semibold' : 'text-slate-600 hover:bg-slate-100'))
                + '">'+d
                + (has ? '<span class="absolute bottom-1 w-1.5 h-1.5 rounded-full '+(isSel?'bg-white':'bg-emerald-400')+'"></span>' : '')
                + '</button>';
        }
        grid.innerHTML = cells;
        grid.querySelectorAll('button[data-d]').forEach(function(b){
            b.addEventListener('click', function(){ renderDay(b.dataset.d); render(); });
        });
    }

    document.getElementById('calPrev').addEventListener('click', function(){ view.setMonth(view.getMonth()-1); render(); });
    document.getElementById('calNext').addEventListener('click', function(){ view.setMonth(view.getMonth()+1); render(); });
    render();
    renderDay(today);
})();
</script>

<!-- ยอดสุทธิรวมกับเพื่อน -->
<div class="flex items-center justify-between mb-1">
    <h2 class="text-lg font-bold text-slate-700 flex items-center gap-2">
        <i data-lucide="scale" class="w-5 h-5 text-emerald-500"></i> ยอดสุทธิกับเพื่อน
    </h2>
    <a href="settle.php" class="text-sm font-semibold text-emerald-600 hover:text-emerald-700 flex items-center gap-1">
        เคลียร์หนี้ <i data-lucide="chevron-right" class="w-4 h-4"></i>
    </a>
</div>
<p class="text-xs text-slate-400 mb-4">รวมทุกอย่าง: หารบิล + เคลียร์หนี้ + เงินที่ถือไว้ + ผ่อนรายเดือน</p>

<div class="space-y-2 mb-8">
    <?php if (empty($friends)): ?>
        <div class="bg-white rounded-2xl p-8 text-center text-slate-400 border border-dashed border-slate-200">
            ยังไม่มียอดกับเพื่อน — <a href="add-expense.php" class="text-emerald-600 font-semibold">เพิ่มรายจ่าย</a> หรือ <a href="friends.php" class="text-emerald-600 font-semibold">เพิ่มเพื่อน</a>
        </div>
    <?php endif; ?>

    <?php foreach ($friends as $f):
        $net  = (float) $f['net'];
        $bill = $f['bill'] + $f['settle'];
        $chips = [
            ['label' => 'บิล',     'val' => $bill],
            ['label' => 'ถือเงิน', 'val' => $f['holding']],
            ['label' => 'ผ่อน',    'val' => $f['installment']],
        ]; ?>
        <a href="friend.php?id=<?= $f['id'] ?>" class="bg-white rounded-2xl border border-slate-100 shadow-sm p-4 flex items-center gap-3 hover:border-emerald-200 hover:shadow-md transition">
            <?= avatar($f['id'], $f['name'], 'w-11 h-11 text-base') ?>
            <div class="min-w-0 flex-1">
                <p class="font-bold text-slate-800 truncate"><?= htmlspecialchars($f['name']) ?></p>
                <div class="flex flex-wrap gap-1 mt-1">
                    <?php foreach ($chips as $c): if (abs($c['val']) < 0.009) continue; ?>
                        <span class="text-[11px] px-1.5 py-0.5 rounded <?= $c['val'] >= 0 ? 'bg-emerald-50 text-emerald-600' : 'bg-rose-50 text-rose-500' ?>">
                            <?= $c['label'] ?> <?= $c['val'] >= 0 ? '+' : '−' ?><?= baht(abs($c['val'])) ?>
                        </span>
                    <?php endforeach; ?>
                </div>
                <?php if (($f['inst_paid'] ?? 0) > 0.009): ?>
                    <p class="text-[11px] text-slate-400 mt-1 flex items-center gap-1">
                        <i data-lucide="check" class="w-3 h-3 text-emerald-500"></i> หักผ่อนที่เพื่อนจ่ายแล้ว <?= baht($f['inst_paid']) ?> ฿
                    </p>
                <?php endif; ?>
            </div>
            <div class="text-right shrink-0">
                <?php if ($net > 0.009): ?>
                    <p class="text-xl font-black text-emerald-600">+<?= baht($net) ?> ฿</p>
                    <p class="text-[11px] text-emerald-600 font-medium">เพื่อนติดเรา</p>
                <?php elseif ($net < -0.009): ?>
                    <p class="text-xl font-black text-rose-500"><?= baht($net) ?> ฿</p>
                    <p class="text-[11px] text-rose-500 font-medium">เราติดเพื่อน</p>
                <?php else: ?>
                    <p class="text-xl font-black text-slate-400">0.00 ฿</p>
                    <p class="text-[11px] text-slate-400 font-medium">เคลียร์แล้ว</p>
                <?php endif; ?>
            </div>
            <i data-lucide="chevron-right" class="w-4 h-4 text-slate-300 shrink-0"></i>
        </a>
    <?php endforeach; ?>
</div>

<!-- Recent expenses -->
<div class="flex items-center justify-between mb-4">
    <h2 class="text-lg font-bold text-slate-700 flex items-center gap-2">
        <i data-lucide="receipt" class="w-5 h-5 text-emerald-500"></i> รายจ่ายล่าสุด
    </h2>
</div>

<div class="bg-white rounded-2xl border border-slate-100 shadow-sm overflow-hidden js-more-list" data-show="8">
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
