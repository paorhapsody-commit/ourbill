<?php
require_once 'auth.php';
require_login();
require_once 'config.php';
require_once '_layout.php';

$cu      = current_user();
$curName = $cu['name'] ?? '';
$curPic  = $cu['picture'] ?? '';
$email   = $cu['email'] ?? '';
$mid     = (int) ($cu['member_id'] ?? 0);

$status_msg = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name   = trim($_POST['name'] ?? '');
    $remove = ($_POST['remove_picture'] ?? '') === '1';

    list($picUrl, $upErr) = $remove ? [null, null] : handle_avatar_upload($mid, 'picture');

    if ($upErr) {
        $status_msg = $upErr;
    } else {
        // '' = ลบรูปที่อัป (กลับไปใช้รูป Google) | string = รูปใหม่ | null = ไม่แตะรูปเดิม
        $newPic = $remove ? '' : $picUrl;
        list($ok, $err) = save_user_profile($name, $newPic);
        if ($ok) {
            header('Location: profile.php?ok=1');
            exit;
        }
        $status_msg = $err ?: 'บันทึกไม่สำเร็จ ลองใหม่อีกครั้ง';
    }
    // โพสต์ไม่ผ่าน: แสดงค่าที่กรอกกลับไป
    $curName = $name !== '' ? $name : $curName;
}

// ตรวจว่าตอนนี้ใช้ "รูปที่อัปเอง" อยู่ไหม (เพื่อโชว์ปุ่มลบ) — เทียบกับรูปตั้งต้นจาก Google
$googlePic = user_google_picture();
$isCustom  = ($curPic !== '' && $curPic !== $googlePic);

layout_head('โปรไฟล์', '');
?>

<div class="max-w-md mx-auto">
    <h1 class="text-xl font-bold text-slate-700 flex items-center gap-2 mb-5">
        <i data-lucide="user-round-cog" class="w-6 h-6 text-emerald-500"></i> โปรไฟล์ของฉัน
    </h1>

    <?php if ($status_msg): ?>
        <div class="mb-5 p-3.5 bg-rose-50 border border-rose-200 text-rose-700 text-sm font-medium rounded-xl flex items-center gap-2">
            <i data-lucide="alert-triangle" class="w-4 h-4 shrink-0"></i> <?= htmlspecialchars($status_msg) ?>
        </div>
    <?php endif; ?>

    <form method="POST" enctype="multipart/form-data" class="bg-white rounded-2xl border border-slate-100 shadow-sm p-6 space-y-6">
        <!-- รูปโปรไฟล์ -->
        <div class="flex flex-col items-center gap-2">
            <input type="file" name="picture" id="picture" accept="image/*" class="hidden">
            <input type="hidden" name="remove_picture" id="removePicture" value="">
            <label for="picture" class="relative cursor-pointer group">
                <img id="avatarPreview" src="<?= htmlspecialchars($curPic) ?>"
                     class="<?= $curPic ? '' : 'hidden' ?> w-28 h-28 rounded-full object-cover ring-4 ring-emerald-100" alt="รูปโปรไฟล์">
                <div id="avatarFallback"
                     class="<?= $curPic ? 'hidden' : '' ?> grid place-items-center w-28 h-28 rounded-full ring-4 ring-emerald-100 <?= avatar_palette($mid)['bg'] ?> <?= avatar_palette($mid)['text'] ?> text-4xl font-bold">
                    <?= htmlspecialchars(avatar_initial($curName ?: '?')) ?>
                </div>
                <span class="absolute bottom-0 right-0 grid place-items-center w-9 h-9 rounded-full bg-emerald-500 text-white shadow-lg ring-2 ring-white group-hover:scale-105 transition">
                    <i data-lucide="camera" class="w-4 h-4"></i>
                </span>
            </label>
            <p class="text-xs text-slate-400">แตะรูปเพื่อเปลี่ยน (บีบขนาดให้อัตโนมัติ · ไม่เกิน 5MB)</p>
            <?php if ($isCustom): ?>
                <button type="button" id="removePicBtn"
                        class="text-xs font-semibold text-rose-500 hover:text-rose-600 flex items-center gap-1 mt-0.5">
                    <i data-lucide="trash-2" class="w-3.5 h-3.5"></i> ลบรูปที่อัป (กลับไปใช้รูปเดิม)
                </button>
            <?php endif; ?>
        </div>

        <!-- ชื่อที่จะแสดง -->
        <div>
            <label class="block text-sm font-semibold text-slate-600 mb-1.5">ชื่อที่จะแสดง</label>
            <div class="relative">
                <i data-lucide="user" class="w-4 h-4 absolute left-3.5 top-1/2 -translate-y-1/2 text-slate-300"></i>
                <input type="text" name="name" required maxlength="60" value="<?= htmlspecialchars($curName) ?>"
                       placeholder="ชื่อที่เพื่อน ๆ จะเห็น"
                       class="w-full pl-10 pr-4 py-2.5 border border-slate-200 rounded-xl focus:outline-none focus:ring-2 focus:ring-emerald-400 text-sm">
            </div>
            <p class="text-xs text-slate-400 mt-1.5">ชื่อนี้จะแสดงในบิล รายการเคลียร์หนี้ และกับเพื่อน ๆ</p>
        </div>

        <!-- อีเมล (แก้ไม่ได้) -->
        <div>
            <label class="block text-sm font-semibold text-slate-600 mb-1.5">อีเมล (ล็อกอิน Google)</label>
            <div class="flex items-center gap-2 px-3.5 py-2.5 bg-slate-50 border border-slate-200 rounded-xl text-sm text-slate-500">
                <i data-lucide="mail" class="w-4 h-4 text-slate-300"></i>
                <span class="truncate"><?= htmlspecialchars($email) ?></span>
                <i data-lucide="lock" class="w-3.5 h-3.5 text-slate-300 ml-auto"></i>
            </div>
        </div>

        <div class="flex gap-2 pt-1">
            <a href="index.php" class="flex-1 py-3 rounded-xl bg-slate-100 text-slate-600 font-semibold text-sm text-center hover:bg-slate-200 transition">ยกเลิก</a>
            <button type="submit"
                    class="flex-1 bg-gradient-to-br from-emerald-400 to-teal-500 hover:from-emerald-500 hover:to-teal-600 text-white font-bold py-3 rounded-xl shadow-lg shadow-emerald-200 transition flex items-center justify-center gap-2">
                <i data-lucide="check-circle" class="w-5 h-5"></i> บันทึก
            </button>
        </div>
    </form>
</div>

<script src="image-compress.js"></script>
<script>
(function () {
    const inp = document.getElementById('picture');
    if (!inp) return;
    attachImageCompressor(inp, { onPreview: function (url) {
        const img = document.getElementById('avatarPreview');
        const fb  = document.getElementById('avatarFallback');
        img.src = url; img.classList.remove('hidden');
        if (fb) fb.classList.add('hidden');
        // ถ้าเพิ่งเลือกรูปใหม่ ให้ยกเลิกสถานะ "ลบรูป" ที่อาจตั้งไว้
        document.getElementById('removePicture').value = '';
    }});

    // ปุ่มลบรูปที่อัปเอง -> ยืนยันแล้วส่งฟอร์มพร้อมธง remove_picture
    const rm = document.getElementById('removePicBtn');
    if (rm) rm.addEventListener('click', function () {
        const submitRemove = function () {
            document.getElementById('removePicture').value = '1';
            rm.closest('form').submit();
        };
        if (window.Swal) {
            Swal.fire({
                title: 'ลบรูปโปรไฟล์?',
                text: 'จะกลับไปใช้รูปเริ่มต้นจากบัญชี Google',
                icon: 'warning', showCancelButton: true,
                confirmButtonText: 'ลบรูป', cancelButtonText: 'ยกเลิก',
                confirmButtonColor: '#f43f5e', reverseButtons: true
            }).then(function (r) { if (r.isConfirmed) submitRemove(); });
        } else { submitRemove(); }
    });
})();
</script>

<?php layout_foot(); ?>
