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
