<?php
require_once 'auth.php';
require_login();
require_once 'config.php';
require_once '_layout.php';

$me  = (int) ($_SESSION['user']['account_id'] ?? 0);
$msg = '';

/** อ่านแถว friendship ตาม id */
function fs_row($id) {
    $r = sb_get('friendships?id=eq.' . intval($id) . '&limit=1');
    return (is_array($r) && isset($r[0]['id'])) ? $r[0] : null;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $me > 0) {
    $action = $_POST['action'] ?? '';

    if ($action === 'request') {
        $target = (int) ($_POST['target_account'] ?? 0);
        if ($target > 0 && $target !== $me) {
            // กันส่งซ้ำถ้ามีความสัมพันธ์อยู่แล้ว (ทั้งสองทิศ)
            $exist = sb_get('friendships?or=(and(requester.eq.' . $me . ',addressee.eq.' . $target . '),and(requester.eq.' . $target . ',addressee.eq.' . $me . '))&limit=1');
            if (!is_array($exist) || empty($exist)) {
                sb_insert('friendships', ['requester' => $me, 'addressee' => $target, 'status' => 'pending']);
            }
        }
        header('Location: friends.php?sent=1'); exit;

    } elseif ($action === 'accept') {
        $row = fs_row($_POST['fid'] ?? 0);
        if ($row && (int) $row['addressee'] === $me && $row['status'] === 'pending') {
            sb_update('friendships?id=eq.' . (int) $row['id'], ['status' => 'accepted']);
        }
        header('Location: friends.php?accepted=1'); exit;

    } elseif (in_array($action, ['decline', 'cancel', 'unfriend'], true)) {
        $row = fs_row($_POST['fid'] ?? 0);
        if ($row && ((int) $row['addressee'] === $me || (int) $row['requester'] === $me)) {
            sb_delete('friendships?id=eq.' . (int) $row['id']);
        }
        header('Location: friends.php?removed=1'); exit;
    }
}

// แยกประเภทความสัมพันธ์
$friends = []; $incoming = []; $outgoing = [];
foreach (friend_links($me) as $l) {
    $other = ((int) $l['requester'] === $me) ? $l['adr'] : $l['req'];
    $item  = ['fid' => $l['id'], 'acc' => $other];
    if ($l['status'] === 'accepted')          $friends[]  = $item;
    elseif ((int) $l['addressee'] === $me)    $incoming[] = $item;
    else                                       $outgoing[] = $item;
}

layout_head('เพื่อน', 'friends.php');

/** avatar จากรูป Google หรือชื่อย่อ */
function acc_avatar($acc, $size = 'w-11 h-11') {
    if (!empty($acc['picture'])) {
        return '<img src="' . htmlspecialchars($acc['picture']) . '" referrerpolicy="no-referrer" alt="" class="' . $size . ' rounded-full object-cover ring-2 ring-slate-100">';
    }
    return avatar($acc['id'], $acc['name'] ?: $acc['email'], $size . ' text-base');
}
?>

<h1 class="text-xl font-bold text-slate-700 flex items-center gap-2 mb-1">
    <i data-lucide="users" class="w-6 h-6 text-emerald-500"></i> เพื่อนของฉัน
</h1>
<p class="text-sm text-slate-400 mb-5">ค้นหาด้วยอีเมลหรือชื่อ → ส่งคำขอ → อีกฝ่ายตอบรับ ถึงจะเลือกมาหารบิลด้วยกันได้</p>

<?php if ($me === 0): ?>
    <div class="mb-5 p-4 bg-amber-50 border border-amber-200 text-amber-800 text-sm rounded-xl flex items-center gap-2">
        <i data-lucide="alert-triangle" class="w-4 h-4"></i>
        เซสชันเก่ายังไม่มีข้อมูลบัญชี — <a href="logout.php" class="font-semibold underline">ออกแล้วล็อกอินใหม่</a> หนึ่งครั้ง
    </div>
<?php else: ?>

<!-- ค้นหา -->
<div class="bg-white rounded-2xl border border-slate-100 shadow-sm p-4 mb-6 relative">
    <label class="block text-sm font-semibold text-slate-600 mb-1.5">เพิ่มเพื่อนใหม่</label>
    <div class="relative">
        <i data-lucide="search" class="w-4 h-4 absolute left-3.5 top-1/2 -translate-y-1/2 text-slate-300"></i>
        <input type="text" id="friendSearch" autocomplete="off" placeholder="พิมพ์อีเมลหรือชื่อเพื่อน..."
               class="w-full pl-10 pr-4 py-2.5 border border-slate-200 rounded-xl focus:outline-none focus:ring-2 focus:ring-emerald-400 text-sm">
    </div>
    <div id="searchResults" class="hidden absolute left-4 right-4 mt-1 bg-white border border-slate-200 rounded-xl shadow-lg z-30 overflow-hidden max-h-80 overflow-y-auto"></div>
</div>

<!-- คำขอที่เข้ามา -->
<?php if (!empty($incoming)): ?>
    <h2 class="text-sm font-bold text-slate-500 mb-2 px-1 flex items-center gap-1.5">
        <i data-lucide="inbox" class="w-4 h-4"></i> คำขอเป็นเพื่อน (<?= count($incoming) ?>)
    </h2>
    <div class="space-y-2 mb-6">
        <?php foreach ($incoming as $it): $a = $it['acc']; ?>
            <div class="bg-white rounded-2xl border border-emerald-100 shadow-sm p-3.5 flex items-center gap-3">
                <?= acc_avatar($a, 'w-10 h-10') ?>
                <div class="min-w-0 flex-1">
                    <p class="font-semibold text-slate-800 truncate"><?= htmlspecialchars($a['name'] ?: '—') ?></p>
                    <p class="text-xs text-slate-400 truncate"><?= htmlspecialchars($a['email']) ?></p>
                </div>
                <form method="POST" class="inline">
                    <input type="hidden" name="action" value="accept"><input type="hidden" name="fid" value="<?= $it['fid'] ?>">
                    <button class="text-xs font-semibold px-3 py-2 rounded-lg bg-emerald-500 hover:bg-emerald-600 text-white flex items-center gap-1"><i data-lucide="check" class="w-3.5 h-3.5"></i> ตอบรับ</button>
                </form>
                <form method="POST" class="inline">
                    <input type="hidden" name="action" value="decline"><input type="hidden" name="fid" value="<?= $it['fid'] ?>">
                    <button class="text-xs font-semibold px-3 py-2 rounded-lg bg-slate-100 hover:bg-rose-100 text-slate-500 hover:text-rose-600"><i data-lucide="x" class="w-3.5 h-3.5"></i></button>
                </form>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<!-- เพื่อนแล้ว -->
<h2 class="text-sm font-bold text-slate-500 mb-2 px-1 flex items-center gap-1.5">
    <i data-lucide="user-check" class="w-4 h-4"></i> เพื่อนของฉัน (<?= count($friends) ?>)
</h2>
<div class="space-y-2 mb-6">
    <?php if (empty($friends)): ?>
        <div class="bg-white rounded-2xl p-6 text-center text-slate-400 border border-dashed border-slate-200 text-sm">ยังไม่มีเพื่อน — ค้นหาด้านบนเพื่อส่งคำขอ</div>
    <?php endif; ?>
    <?php foreach ($friends as $it): $a = $it['acc']; ?>
        <div class="bg-white rounded-2xl border border-slate-100 shadow-sm p-3.5 flex items-center gap-3">
            <?= acc_avatar($a, 'w-10 h-10') ?>
            <div class="min-w-0 flex-1">
                <p class="font-semibold text-slate-800 truncate"><?= htmlspecialchars($a['name'] ?: '—') ?></p>
                <p class="text-xs text-slate-400 truncate"><?= htmlspecialchars($a['email']) ?></p>
            </div>
            <span class="text-xs bg-emerald-50 text-emerald-600 px-2.5 py-1 rounded-full font-medium flex items-center gap-1"><i data-lucide="heart-handshake" class="w-3.5 h-3.5"></i> เพื่อน</span>
            <form method="POST" onsubmit="return confirm('ยกเลิกเพื่อนกับ <?= htmlspecialchars(addslashes($a['name'] ?: $a['email'])) ?> ?');">
                <input type="hidden" name="action" value="unfriend"><input type="hidden" name="fid" value="<?= $it['fid'] ?>">
                <button class="p-2 text-slate-300 hover:text-rose-500 hover:bg-rose-50 rounded-lg transition"><i data-lucide="user-minus" class="w-4 h-4"></i></button>
            </form>
        </div>
    <?php endforeach; ?>
</div>

<!-- คำขอที่ส่งไป -->
<?php if (!empty($outgoing)): ?>
    <h2 class="text-sm font-bold text-slate-500 mb-2 px-1 flex items-center gap-1.5">
        <i data-lucide="send" class="w-4 h-4"></i> คำขอที่ส่งไป (<?= count($outgoing) ?>)
    </h2>
    <div class="space-y-2">
        <?php foreach ($outgoing as $it): $a = $it['acc']; ?>
            <div class="bg-white rounded-2xl border border-slate-100 shadow-sm p-3.5 flex items-center gap-3 opacity-80">
                <?= acc_avatar($a, 'w-9 h-9') ?>
                <div class="min-w-0 flex-1">
                    <p class="font-semibold text-slate-700 text-sm truncate"><?= htmlspecialchars($a['name'] ?: $a['email']) ?></p>
                    <p class="text-xs text-amber-500">รอตอบรับ...</p>
                </div>
                <form method="POST">
                    <input type="hidden" name="action" value="cancel"><input type="hidden" name="fid" value="<?= $it['fid'] ?>">
                    <button class="text-xs font-medium px-3 py-1.5 rounded-lg bg-slate-100 hover:bg-slate-200 text-slate-500">ยกเลิก</button>
                </form>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<script>
(function () {
    const input   = document.getElementById('friendSearch');
    const box     = document.getElementById('searchResults');
    let timer = null;

    const relLabel = {
        friends: '<span class="text-xs text-emerald-600 font-medium">เพื่อนแล้ว</span>',
        out:     '<span class="text-xs text-amber-500 font-medium">รอตอบรับ</span>',
        in:      '<span class="text-xs text-sky-500 font-medium">ส่งคำขอหาคุณ</span>',
    };

    function esc(s) { return (s || '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c])); }

    function render(items) {
        if (!items.length) {
            box.innerHTML = '<div class="p-4 text-sm text-slate-400 text-center">ไม่พบผู้ใช้ (เพื่อนต้องล็อกอินและได้รับอนุมัติก่อน)</div>';
            box.classList.remove('hidden'); return;
        }
        box.innerHTML = items.map(it => {
            const avatar = it.picture
                ? `<img src="${esc(it.picture)}" referrerpolicy="no-referrer" class="w-9 h-9 rounded-full object-cover">`
                : `<span class="grid place-items-center w-9 h-9 rounded-full bg-emerald-100 text-emerald-600 font-bold">${esc((it.name||it.email)[0]||'?').toUpperCase()}</span>`;
            const right = it.rel === 'none'
                ? `<button type="submit" class="text-xs font-semibold px-3 py-1.5 rounded-lg bg-emerald-500 hover:bg-emerald-600 text-white flex items-center gap-1"><i data-lucide="user-plus" class="w-3.5 h-3.5"></i> เพิ่ม</button>`
                : (relLabel[it.rel] || '');
            const formOpen = it.rel === 'none'
                ? `<form method="POST"><input type="hidden" name="action" value="request"><input type="hidden" name="target_account" value="${it.id}">`
                : `<div>`;
            const formClose = it.rel === 'none' ? `</form>` : `</div>`;
            return `<div class="flex items-center gap-3 p-3 border-b border-slate-50 last:border-0 hover:bg-slate-50">
                ${avatar}
                <div class="min-w-0 flex-1"><p class="font-semibold text-slate-800 text-sm truncate">${esc(it.name||'—')}</p><p class="text-xs text-slate-400 truncate">${esc(it.email)}</p></div>
                ${formOpen}${right}${formClose}
            </div>`;
        }).join('');
        box.classList.remove('hidden');
        if (window.lucide) lucide.createIcons();
    }

    input.addEventListener('input', function () {
        clearTimeout(timer);
        const q = this.value.trim();
        if (!q) { box.classList.add('hidden'); box.innerHTML=''; return; }
        timer = setTimeout(() => {
            fetch('friend_search.php?q=' + encodeURIComponent(q))
                .then(r => r.json()).then(render).catch(() => {});
        }, 250);
    });
    document.addEventListener('click', e => {
        if (!box.contains(e.target) && e.target !== input) box.classList.add('hidden');
    });
})();
</script>

<?php endif; // $me > 0 ?>
<?php layout_foot(); ?>
