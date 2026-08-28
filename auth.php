<?php
/* =========================================================
 *  auth.php — จัดการ session + Google OAuth helpers
 *  เรียกไฟล์นี้ก่อนไฟล์อื่นในทุกหน้า แล้วค่อย require_login()
 * ========================================================= */
require_once __DIR__ . '/auth_config.php';
require_once __DIR__ . '/config.php';

if (session_status() === PHP_SESSION_NONE) {
    /* cookie ของ session: กัน JS อ่าน (httponly) · กันส่งข้ามเว็บ (samesite)
     * · บังคับ https เมื่ออยู่หลัง SSL — Render ตัด TLS ที่ proxy จึงต้องดู X-Forwarded-Proto ด้วย */
    $https = (!empty($_SERVER['HTTPS']) && strtolower($_SERVER['HTTPS']) !== 'off')
          || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    session_set_cookie_params([
        'httponly' => true,
        'samesite' => 'Lax',
        'secure'   => $https,
    ]);
    session_start();
}

/* =========================================================
 *  CSRF — ทุกฟอร์ม POST ต้องแนบ csrf_field() และทุก handler ต้องเรียก csrf_check()
 * ========================================================= */

/** token ประจำ session (สร้างครั้งเดียวแล้วใช้ซ้ำทั้ง session) */
function csrf_token() {
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32));
    return $_SESSION['csrf'];
}

/** hidden input สำหรับแปะในฟอร์ม POST */
function csrf_field() {
    return '<input type="hidden" name="_csrf" value="' . htmlspecialchars(csrf_token(), ENT_QUOTES) . '">';
}

/** ตรวจ token ก่อนแตะข้อมูล — ไม่ผ่าน = ตัดจบ 403 (fail closed) */
function csrf_check() {
    $sent = $_POST['_csrf'] ?? '';
    if (!is_string($sent) || $sent === '' || !hash_equals(csrf_token(), $sent)) {
        http_response_code(403);
        exit('คำขอไม่ถูกต้อง (CSRF) — กรุณารีเฟรชหน้าแล้วลองใหม่');
    }
}

/* ---------- ผู้ใช้ทั่วไป (Google) ---------- */
function current_user()  { return $_SESSION['user'] ?? null; }
function is_logged_in()  { return isset($_SESSION['user']); }

/** บังคับให้ต้องล็อกอินก่อนเข้าหน้านี้ */
function require_login() {
    if (!is_logged_in()) {
        header('Location: login.php');
        exit;
    }
}

/* ---------- แอดมิน (Supabase Auth) ---------- */
function current_admin() { return $_SESSION['admin'] ?? null; }
function is_admin()      { return isset($_SESSION['admin']); }
function admin_token()   { return $_SESSION['admin']['token'] ?? null; }

/** บังคับให้ต้องเป็นแอดมินก่อนเข้าหน้านี้ */
function require_admin() {
    if (!is_admin()) {
        header('Location: admin_login.php');
        exit;
    }
}

/** ล็อกอินแอดมินด้วยอีเมล/รหัสผ่านผ่าน Supabase Auth (password grant) */
function supabase_password_login($email, $password) {
    $ch = curl_init(SUPABASE_URL . '/auth/v1/token?grant_type=password');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_HTTPHEADER     => [
            'apikey: ' . SUPABASE_KEY,
            'Content-Type: application/json',
        ],
        CURLOPT_POSTFIELDS     => json_encode(['email' => $email, 'password' => $password]),
        CURLOPT_CONNECTTIMEOUT => SB_CONNECT_TIMEOUT,
        CURLOPT_TIMEOUT        => SB_TIMEOUT,
    ]);
    $res    = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ['status' => (int) $status, 'body' => json_decode($res, true)];
}

/** สร้าง URL ของ callback.php จากคำขอปัจจุบัน (รองรับทุกโดเมน/พาธ อัตโนมัติ) */
function current_callback_url() {
    $proto = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || ((int) ($_SERVER['SERVER_PORT'] ?? 0) === 443)
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $dir  = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');
    return $proto . '://' . $host . $dir . '/callback.php';
}

/** อ่านค่า Google OAuth — settings ก่อน แล้ว fallback ไฟล์ config */
function google_cfg($key) {
    static $map = [
        'client_id'     => ['s' => 'google_client_id',     'c' => 'GOOGLE_CLIENT_ID'],
        'client_secret' => ['s' => 'google_client_secret', 'c' => 'GOOGLE_CLIENT_SECRET'],
        'redirect_uri'  => ['s' => 'google_redirect_uri',  'c' => 'GOOGLE_REDIRECT_URI'],
    ];
    if (!isset($map[$key])) return '';

    $val = trim((string) setting($map[$key]['s'], ''));
    if ($val === '' && defined($map[$key]['c'])) $val = trim((string) constant($map[$key]['c']));

    // redirect_uri: เดาจาก URL ปัจจุบันให้อัตโนมัติ ถ้ายังไม่ตั้ง หรือเป็นค่า localhost ค้างแต่จริง ๆ รันบนโดเมนอื่น
    if ($key === 'redirect_uri' && PHP_SAPI !== 'cli') {
        $host    = $_SERVER['HTTP_HOST'] ?? '';
        $isLocal = strpos($host, 'localhost') !== false || strpos($host, '127.0.0.1') !== false;
        $valIsLocal = strpos($val, 'localhost') !== false || strpos($val, '127.0.0.1') !== false;
        if ($val === '' || ($valIsLocal && !$isLocal)) {
            return current_callback_url();
        }
    }
    return $val;
}

/** ตรวจว่าตั้งค่า Google ครบหรือยัง */
function google_configured() {
    $id = google_cfg('client_id');
    return $id !== '' && strpos($id, 'YOUR_CLIENT_ID') === false;
}

/** สร้าง URL สำหรับพาผู้ใช้ไปหน้ายินยอมของ Google */
function google_login_url() {
    $_SESSION['oauth_state'] = bin2hex(random_bytes(16));
    return 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query([
        'client_id'     => google_cfg('client_id'),
        'redirect_uri'  => google_cfg('redirect_uri'),
        'response_type' => 'code',
        'scope'         => 'openid email profile',
        'state'         => $_SESSION['oauth_state'],
        'access_type'   => 'online',
        'prompt'        => 'select_account',
    ]);
}

/* ---------- บัญชีผู้ใช้ (โมเดลอนุมัติ) ---------- */

/** หาบัญชีจากอีเมล */
function account_find($email) {
    $rows = sb_get('app_accounts?email=eq.' . urlencode(strtolower(trim($email))) . '&limit=1');
    return (is_array($rows) && isset($rows[0]['id'])) ? $rows[0] : null;
}

/** สร้างบัญชีสถานะ pending (ครั้งแรกที่ล็อกอิน) */
function account_create_pending($info) {
    return sb_insert('app_accounts', [
        'sub'     => $info['sub']     ?? '',
        'email'   => strtolower(trim($info['email'])),
        'name'    => $info['name']    ?? '',
        'picture' => $info['picture'] ?? '',
        'status'  => 'pending',
    ]);
}

/**
 * รับโปรไฟล์ Google -> คืนสถานะบัญชี (pending|approved|blocked)
 * ถ้ายังไม่มีบัญชี จะสร้าง pending ให้อัตโนมัติ
 */
function account_resolve_status($info) {
    $acct = account_find($info['email']);
    if (!$acct) {
        account_create_pending($info);
        $acct = account_find($info['email']);
    }
    return $acct['status'] ?? 'pending';
}

/* ---------- สมาชิกในบิล (ผูกกับบัญชี) ---------- */

/** หา/สร้างแถวสมาชิก (users) ที่ผูกกับบัญชีนี้ */
function ensure_member($account) {
    $rows = sb_get('users?account_id=eq.' . (int) $account['id'] . '&limit=1');
    if (is_array($rows) && isset($rows[0]['id'])) return $rows[0];
    $res = sb_insert('users', [
        'name'       => $account['name'] ?: $account['email'],
        'account_id' => (int) $account['id'],
    ]);
    return $res['body'][0] ?? null;
}

/* ---------- ธีมสี (บันทึกต่อสมาชิกใน users.theme)
   หมายเหตุ: เก็บที่ users ไม่ใช่ app_accounts เพราะ RLS บล็อก anon UPDATE บน app_accounts
   (กันผู้ใช้แก้ status อนุมัติตัวเอง) แต่ users อนุญาต anon UPDATE เหมือน expenses ฯลฯ ---------- */

/** รายชื่อธีมที่อนุญาต — ต้องตรงกับ window.OB_THEMES ใน _layout.php */
function ob_valid_themes() { return ['green', 'sky', 'violet', 'amber', 'fuchsia']; }

/** ธีมปัจจุบันของผู้ใช้ (จาก session) — คืน '' ถ้ายังไม่ตั้ง */
function current_user_theme() {
    $t = current_user()['theme'] ?? '';
    return in_array($t, ob_valid_themes(), true) ? $t : '';
}

/** บันทึกธีมของผู้ใช้ลง users + อัปเดต session; คืน true ถ้าธีมถูกต้อง */
function save_user_theme($theme) {
    if (!in_array($theme, ob_valid_themes(), true)) return false;
    $mid = (int) (current_user()['member_id'] ?? 0);
    if ($mid > 0) sb_update('users?id=eq.' . $mid, ['theme' => $theme]);
    $_SESSION['user']['theme'] = $theme;
    return true;
}

/** รูปโปรไฟล์ตั้งต้นจาก Google (เก็บใน app_accounts.picture) — ใช้ตอนลบรูปที่อัปเอง */
function user_google_picture() {
    $g = current_user()['google_picture'] ?? '';
    if ($g !== '') return $g;
    $acc = (int) (current_user()['account_id'] ?? 0);
    if ($acc <= 0) return '';
    $rows = sb_rows(sb_get('app_accounts?id=eq.' . $acc . '&select=picture&limit=1'));
    return $rows[0]['picture'] ?? '';
}

/**
 * บันทึกโปรไฟล์ (ชื่อที่แสดง + รูป) ลง users + อัปเดต session
 * @param string      $name       ชื่อที่จะแสดง (ใช้ทั่วแอปในบิล/เพื่อน)
 * @param string|null $pictureUrl null = ไม่เปลี่ยนรูปเดิม | '' = ลบรูปที่อัปเอง (กลับไปใช้รูป Google) | URL = ตั้งรูปใหม่
 * @return array{0:bool,1:string|null} [success, error]
 */
function save_user_profile($name, $pictureUrl = null) {
    $name = trim((string) $name);
    if ($name === '')            return [false, 'กรุณากรอกชื่อที่จะแสดง'];
    if (mb_strlen($name) > 60)   return [false, 'ชื่อยาวเกินไป (ไม่เกิน 60 ตัวอักษร)'];

    $mid    = (int) (current_user()['member_id'] ?? 0);
    $revert = ($pictureUrl === '');           // ลบรูปที่อัปเอง
    $patch  = ['name' => $name];
    if ($revert)                    $patch['picture'] = null;
    elseif ($pictureUrl !== null)   $patch['picture'] = $pictureUrl;

    if ($mid > 0) {
        $res = sb_update('users?id=eq.' . $mid, $patch);
        if (($res['status'] ?? 0) < 200 || ($res['status'] ?? 0) >= 300) {
            return [false, 'บันทึกไม่สำเร็จ ลองใหม่อีกครั้ง'];
        }
        if ($revert) sb_delete_file('receipts', avatar_object_name($mid)); // ลบไฟล์ทิ้ง ไม่ให้เปลืองพื้นที่
    }

    $_SESSION['user']['name'] = $name;
    if ($revert)                  $_SESSION['user']['picture'] = user_google_picture();
    elseif ($pictureUrl !== null) $_SESSION['user']['picture'] = $pictureUrl;
    return [true, null];
}

/* ---------- ระบบเพื่อน ---------- */

/** ดึงความสัมพันธ์เพื่อนทั้งหมดที่เกี่ยวกับบัญชีนี้ (พร้อมข้อมูลทั้งสองฝั่ง) */
function friend_links($accountId) {
    $accountId = (int) $accountId;
    $rows = sb_get('friendships?select=*,req:requester(id,name,email,picture),adr:addressee(id,name,email,picture)'
        . '&or=(requester.eq.' . $accountId . ',addressee.eq.' . $accountId . ')&order=created_at.desc');
    return sb_rows($rows);
}

/** id ของบัญชีที่เป็นเพื่อนกัน (accepted) แล้ว */
function friends_accepted_ids($accountId) {
    $accountId = (int) $accountId;
    $ids = [];
    foreach (friend_links($accountId) as $l) {
        if (($l['status'] ?? '') === 'accepted') {
            $ids[] = ($l['requester'] == $accountId) ? (int) $l['addressee'] : (int) $l['requester'];
        }
    }
    return $ids;
}

/** รายชื่อสมาชิก (users) ที่เลือกหารบิลได้ = ตัวเอง + เพื่อนที่ตอบรับแล้ว */
function selectable_members($accountId) {
    $ids = array_merge([(int) $accountId], friends_accepted_ids($accountId));
    $ids = array_values(array_unique(array_filter($ids)));
    if (empty($ids)) return [];
    $rows = sb_get('users?account_id=in.(' . implode(',', $ids) . ')&order=name.asc');
    return sb_rows($rows);
}

/** id ของ users ที่เลือกหารบิลได้ (ใช้ตรวจค่าที่ส่งมาจากฟอร์ม ไม่ให้ยิง id มั่ว) */
function selectable_member_ids($accountId) {
    return array_map('intval', array_column(selectable_members($accountId), 'id'));
}

/* =========================================================
 *  ระบบลิงก์เชิญ (invite_keys)
 * ========================================================= */

/** สร้าง invite key ใหม่ สำหรับบัญชีที่อนุมัติแล้ว — คืน row หรือ null */
function invite_create($accountId) {
    $key = bin2hex(random_bytes(16)); // 32 hex chars, entropy 128-bit
    $res = sb_insert('invite_keys', [
        'key'        => $key,
        'created_by' => (int) $accountId,
    ]);
    return $res['body'][0] ?? null;
}

/**
 * ยืนยันและใช้ invite key (single-use, atomic)
 * คืน true ถ้า key ถูกต้อง + ยังไม่เคยใช้ + บันทึกสำเร็จ
 */
function invite_verify_and_use($key, $newAccountId) {
    $key = preg_replace('/[^a-f0-9]/i', '', (string) $key);
    if (strlen($key) !== 32) return false;
    $rows = sb_rows(sb_get('invite_keys?key=eq.' . urlencode($key) . '&used_at=is.null&select=id&limit=1'));
    if (empty($rows)) return false;
    $inviteId = (int) $rows[0]['id'];
    // PATCH เฉพาะเมื่อ used_at ยังเป็น null → กัน race condition
    $res  = sb_update(
        'invite_keys?id=eq.' . $inviteId . '&used_at=is.null',
        ['used_by' => (int) $newAccountId, 'used_at' => date('Y-m-d\TH:i:sP')]
    );
    $body = $res['body'] ?? null;
    return is_array($body) && !empty($body);
}

/** ดึงรายการ invite keys ที่สร้างโดยบัญชีนี้ (ล่าสุดก่อน) */
function invite_list($accountId) {
    return sb_rows(sb_get(
        'invite_keys?created_by=eq.' . (int) $accountId
        . '&select=id,key,created_at,used_at,used_acc:used_by(name,email)'
        . '&order=created_at.desc&limit=20'
    ));
}

/* ตรรกะเรื่องเงิน (ยอดคงเหลือ / ไทม์ไลน์ / เคลียร์หนี้) แยกไปอยู่ ledger.php แล้ว
 * โหลดต่อจากที่นี่ เพื่อให้หน้าเพจที่ require auth.php อยู่แล้วใช้ได้เหมือนเดิม */
require_once __DIR__ . '/ledger.php';


/** แลก authorization code เป็น access token */
function google_exchange_code($code) {
    $ch = curl_init('https://oauth2.googleapis.com/token');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query([
            'code'          => $code,
            'client_id'     => google_cfg('client_id'),
            'client_secret' => google_cfg('client_secret'),
            'redirect_uri'  => google_cfg('redirect_uri'),
            'grant_type'    => 'authorization_code',
        ]),
        CURLOPT_CONNECTTIMEOUT => SB_CONNECT_TIMEOUT,
        CURLOPT_TIMEOUT        => SB_TIMEOUT,
    ]);
    $res = curl_exec($ch);
    curl_close($ch);
    return json_decode($res, true);
}

/** ดึงข้อมูลโปรไฟล์ผู้ใช้จาก access token */
function google_fetch_userinfo($access_token) {
    $ch = curl_init('https://www.googleapis.com/oauth2/v3/userinfo');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $access_token],
        CURLOPT_CONNECTTIMEOUT => SB_CONNECT_TIMEOUT,
        CURLOPT_TIMEOUT        => SB_TIMEOUT,
    ]);
    $res = curl_exec($ch);
    curl_close($ch);
    return json_decode($res, true);
}
