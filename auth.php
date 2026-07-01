<?php
/* =========================================================
 *  auth.php — จัดการ session + Google OAuth helpers
 *  เรียกไฟล์นี้ก่อนไฟล์อื่นในทุกหน้า แล้วค่อย require_login()
 * ========================================================= */
require_once __DIR__ . '/auth_config.php';
require_once __DIR__ . '/config.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
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

/**
 * จำนวนงวดที่ "ครบกำหนดแล้ว" ณ วันนี้ — ยึดวันเดียวกันของทุกเดือนจาก start_date
 * งวดแรกครบกำหนด ณ วันเริ่ม, งวดถัดไปทุกๆ 1 เดือน (วันเดียวกัน) จนครบ $months
 * ไม่มี start_date = คิดเต็มทุกงวด (เข้ากันได้กับข้อมูลเก่า)
 */
function installments_due_count($startDate, $months) {
    $months = (int) $months;
    if ($months <= 0) return 0;
    if (empty($startDate)) return $months;
    $start = date_create(substr($startDate, 0, 10));
    if (!$start) return $months;
    $now = date_create('today');
    if ($now < $start) return 0;
    $d = date_diff($start, $now);
    $elapsed = $d->y * 12 + $d->m;     // เดือนเต็มที่ผ่านมา (นับเมื่อถึงวันเดียวกันของเดือน)
    return min($elapsed + 1, $months); // +1 = งวดแรก ณ วันเริ่ม
}

/**
 * ยอดผ่อนที่ "ถึงกำหนดแล้วและยังค้างจ่าย" ต่อแผน ณ วันนี้
 * @return float = (งวดที่ครบกำหนด × ยอดต่อเดือน) − ที่จ่ายแล้ว (ไม่ติดลบ)
 */
function installment_due_outstanding($plan, $paid) {
    $due = installments_due_count($plan['start_date'] ?? null, (int) $plan['months']);
    return max(0, round($due * (float) $plan['monthly_amount'] - (float) $paid, 2));
}

/**
 * รายการผ่อนที่ "ถึงกำหนดงวดแล้วแต่ยังจ่ายไม่ครบ" — สำหรับแจ้งเตือนหน้าแรก
 * คืน list: [id, title, friend_id, friend_name, outstanding, due, months, friend_pays, due_date]
 */
function installments_due_alerts($myMember) {
    $me = (int) $myMember;
    if ($me <= 0) return [];
    $plans = sb_rows(sb_get('installments?or=(payee_id.eq.' . $me . ',payer_id.eq.' . $me
        . ')&select=id,title,monthly_amount,months,payer_id,payee_id,start_date,created_at'));
    if (!$plans) return [];
    $ids  = implode(',', array_map(fn($p) => (int) $p['id'], $plans));
    $paid = [];
    foreach (sb_rows(sb_get('installment_payments?installment_id=in.(' . $ids . ')&select=installment_id,amount')) as $pm) {
        $paid[(int) $pm['installment_id']] = ($paid[(int) $pm['installment_id']] ?? 0) + (float) $pm['amount'];
    }
    $out = [];
    foreach ($plans as $p) {
        $outstanding = installment_due_outstanding($p, $paid[(int) $p['id']] ?? 0);
        if ($outstanding < 0.009) continue;
        $due   = installments_due_count($p['start_date'] ?? null, (int) $p['months']);
        $payee = (int) $p['payee_id']; $payer = (int) $p['payer_id'];
        $friendId = $payee === $me ? $payer : $payee;
        if ($friendId === $me) continue;
        $base = !empty($p['start_date']) ? substr($p['start_date'], 0, 10) : substr($p['created_at'] ?? '', 0, 10);
        $out[] = [
            'id' => (int) $p['id'], 'title' => $p['title'], 'friend_id' => $friendId,
            'outstanding' => $outstanding, 'due' => $due, 'months' => (int) $p['months'],
            'friend_pays' => $payee === $me,   // true = เพื่อนผ่อนให้เรา (เรารอรับ)
            'due_date' => $base && $due > 0 ? date('Y-m-d', strtotime('+' . ($due - 1) . ' months', strtotime($base))) : null,
        ];
    }
    if (empty($out)) return [];
    // ชื่อเพื่อน
    $fids = array_values(array_unique(array_map(fn($r) => $r['friend_id'], $out)));
    $names = [];
    foreach (sb_rows(sb_get('users?id=in.(' . implode(',', $fids) . ')&select=id,name')) as $u) {
        $names[(int) $u['id']] = $u['name'];
    }
    foreach ($out as &$r) { $r['friend_name'] = $names[$r['friend_id']] ?? ('#' . $r['friend_id']); }
    return $out;
}

/**
 * รวบรวมทุกธุรกรรมที่เกี่ยวกับเรา จัดกลุ่มตามวัน (สำหรับปฏิทินหน้าแรก)
 * คืน list: [date(Y-m-d), icon, title, sub, amount, sign('+'/'-'/'')]
 */
function calendar_events($myMember) {
    $me = (int) $myMember;
    if ($me <= 0) return [];
    $ev = [];
    $add = function (&$ev, $ts, $icon, $title, $sub, $amount, $sign) {
        $d = substr((string) $ts, 0, 10);
        if (!$d) return;
        $ev[] = ['date' => $d, 'icon' => $icon, 'title' => $title, 'sub' => $sub,
                 'amount' => round((float) $amount, 2), 'sign' => $sign];
    };

    // บิลที่เราจ่ายก่อน
    foreach (sb_rows(sb_get('expenses?paid_by=eq.' . $me . '&select=id,title,total_amount,created_at')) as $e) {
        $add($ev, $e['created_at'] ?? '', 'receipt', $e['title'] ?? 'รายจ่าย', 'เราจ่ายก่อน', $e['total_amount'] ?? 0, '');
    }
    // บิลที่เพื่อนจ่ายก่อน + เรามีส่วนหาร
    foreach (sb_rows(sb_get('expense_splits?user_id=eq.' . $me . '&select=amount,expenses(id,title,created_at,paid_by)')) as $s) {
        $exp = $s['expenses'] ?? null;
        if (!$exp || (int) ($exp['paid_by'] ?? 0) === $me) continue;
        $add($ev, $exp['created_at'] ?? '', 'receipt', $exp['title'] ?? 'รายจ่าย', 'ส่วนแบ่งของเรา', $s['amount'] ?? 0, '');
    }
    // เคลียร์หนี้
    foreach (sb_rows(sb_get('settlements?or=(from_user.eq.' . $me . ',to_user.eq.' . $me . ')&select=from_user,to_user,amount,created_at,fromu:from_user(name),tou:to_user(name)')) as $st) {
        $iPaid = (int) $st['from_user'] === $me;
        $other = $iPaid ? ($st['tou']['name'] ?? '?') : ($st['fromu']['name'] ?? '?');
        $add($ev, $st['created_at'] ?? '', 'arrow-right-left', 'เคลียร์หนี้', ($iPaid ? 'จ่ายคืน ' : 'รับคืนจาก ') . $other, $st['amount'] ?? 0, $iPaid ? '-' : '+');
    }
    // เงินที่ถือไว้
    foreach (sb_rows(sb_get('holdings?or=(holder_id.eq.' . $me . ',owner_id.eq.' . $me . ')&select=holder_id,owner_id,amount,note,created_at,holder:holder_id(name),owner:owner_id(name)')) as $h) {
        $weHold = (int) $h['holder_id'] === $me;
        $other  = $weHold ? ($h['owner']['name'] ?? '?') : ($h['holder']['name'] ?? '?');
        $amt    = (float) $h['amount'];
        $sub    = ($weHold ? ($amt >= 0 ? 'รับเงิน ' : 'คืนเงินให้ ') : ($amt >= 0 ? 'ถือเงินเรา · ' : 'คืนเงินเรา · ')) . $other;
        $add($ev, $h['created_at'] ?? '', 'piggy-bank', 'เงินที่ถือไว้', $sub, abs($amt), '');
    }
    // จ่ายงวดผ่อน
    $plans = sb_rows(sb_get('installments?or=(payee_id.eq.' . $me . ',payer_id.eq.' . $me . ')&select=id,title'));
    if ($plans) {
        $titleOf = []; foreach ($plans as $p) $titleOf[(int) $p['id']] = $p['title'];
        $ids = implode(',', array_map(fn($p) => (int) $p['id'], $plans));
        foreach (sb_rows(sb_get('installment_payments?installment_id=in.(' . $ids . ')&select=installment_id,amount,paid_at')) as $pm) {
            $add($ev, $pm['paid_at'] ?? '', 'banknote', 'จ่ายงวด: ' . ($titleOf[(int) $pm['installment_id']] ?? '-'), '', $pm['amount'] ?? 0, '');
        }
    }
    return $ev;
}

/**
 * ยอดสุทธิรวมทุกฟังก์ชัน ระหว่างเรากับเพื่อนแต่ละคน (จากมุมของ $myMember)
 * รวม: หารบิล + เคลียร์หนี้ + เงินเพื่อนที่ถือไว้ + ผ่อนรายเดือน
 * คืน array: fid => [id, name, bill, settle, holding, installment, net]
 *   net > 0 = เพื่อนติดเรา (รอรับ) | net < 0 = เราติดเพื่อน (ต้องจ่าย)
 */
function unified_balances($myMember) {
    $myMember = (int) $myMember;
    if ($myMember <= 0) return [];
    $net = [];
    $touch = function (&$net, $fid) {
        $fid = (int) $fid;
        if (!isset($net[$fid])) $net[$fid] = ['bill' => 0, 'settle' => 0, 'holding' => 0, 'installment' => 0, 'inst_paid' => 0];
        return $fid;
    };

    // (A) บิลที่เราจ่ายก่อน -> เพื่อนติดเราตามส่วนหาร
    foreach (sb_rows(sb_get('expenses?paid_by=eq.' . $myMember . '&select=id,expense_splits(user_id,amount)')) as $e) {
        foreach (($e['expense_splits'] ?? []) as $s) {
            if ((int) $s['user_id'] === $myMember) continue;
            $f = $touch($net, $s['user_id']);
            $net[$f]['bill'] += (float) $s['amount'];
        }
    }
    // (B) ส่วนหารของเราในบิลที่เพื่อนจ่ายก่อน -> เราติดเพื่อน
    foreach (sb_rows(sb_get('expense_splits?user_id=eq.' . $myMember . '&select=amount,expenses(paid_by)')) as $s) {
        $p = (int) ($s['expenses']['paid_by'] ?? 0);
        if ($p <= 0 || $p === $myMember) continue;
        $f = $touch($net, $p);
        $net[$f]['bill'] -= (float) $s['amount'];
    }
    // เคลียร์หนี้ (settlements)
    foreach (sb_rows(sb_get('settlements?or=(from_user.eq.' . $myMember . ',to_user.eq.' . $myMember . ')&select=from_user,to_user,amount')) as $st) {
        $from = (int) $st['from_user']; $to = (int) $st['to_user']; $amt = (float) $st['amount'];
        if ($from === $myMember && $to !== $myMember) { $f = $touch($net, $to);   $net[$f]['settle'] += $amt; } // เราจ่ายคืนเพื่อน -> ลดที่เราติด
        if ($to === $myMember && $from !== $myMember) { $f = $touch($net, $from); $net[$f]['settle'] -= $amt; } // เพื่อนจ่ายคืนเรา -> ลดที่เพื่อนติด
    }
    // เงินเพื่อนที่ถือไว้ (holdings)
    foreach (sb_rows(sb_get('holdings?or=(holder_id.eq.' . $myMember . ',owner_id.eq.' . $myMember . ')&select=holder_id,owner_id,amount')) as $h) {
        $hd = (int) $h['holder_id']; $ow = (int) $h['owner_id']; $amt = (float) $h['amount'];
        if ($hd === $myMember && $ow !== $myMember) { $f = $touch($net, $ow); $net[$f]['holding'] -= $amt; } // เราถือเงินเพื่อน -> เราติดเพื่อน
        if ($ow === $myMember && $hd !== $myMember) { $f = $touch($net, $hd); $net[$f]['holding'] += $amt; } // เพื่อนถือเงินเรา -> เพื่อนติดเรา
    }
    // ผ่อนรายเดือน (installments) -> นับเฉพาะงวดที่ "ถึงกำหนดแล้ว" ตาม start_date
    $plans = sb_rows(sb_get('installments?or=(payee_id.eq.' . $myMember . ',payer_id.eq.' . $myMember . ')&select=id,payer_id,payee_id,monthly_amount,months,start_date'));
    if ($plans) {
        $ids  = implode(',', array_map(fn($p) => (int) $p['id'], $plans));
        $paid = [];
        foreach (sb_rows(sb_get('installment_payments?installment_id=in.(' . $ids . ')&select=installment_id,amount')) as $pm) {
            $paid[(int) $pm['installment_id']] = ($paid[(int) $pm['installment_id']] ?? 0) + (float) $pm['amount'];
        }
        foreach ($plans as $p) {
            $pd     = $paid[(int) $p['id']] ?? 0;
            $remain = installment_due_outstanding($p, $pd); // เฉพาะงวดที่ครบกำหนด
            $payer = (int) $p['payer_id']; $payee = (int) $p['payee_id'];
            if ($payee === $myMember && $payer !== $myMember) { $f = $touch($net, $payer); $net[$f]['installment'] += $remain; $net[$f]['inst_paid'] += $pd; } // เพื่อนผ่อนให้เรา (เก็บยอดที่เพื่อนจ่ายแล้ว)
            if ($payer === $myMember && $payee !== $myMember) { $f = $touch($net, $payee); $net[$f]['installment'] -= $remain; } // เราผ่อนให้เพื่อน
        }
    }

    if (empty($net)) return [];
    // ชื่อสมาชิก
    $names = [];
    foreach (sb_rows(sb_get('users?id=in.(' . implode(',', array_keys($net)) . ')&select=id,name')) as $u) {
        $names[(int) $u['id']] = $u['name'];
    }
    $out = [];
    foreach ($net as $fid => $b) {
        $out[$fid] = $b + [
            'id'   => $fid,
            'name' => $names[$fid] ?? ('#' . $fid),
            'net'  => round($b['bill'] + $b['settle'] + $b['holding'] + $b['installment'], 2),
        ];
    }
    uasort($out, fn($a, $z) => abs($z['net']) <=> abs($a['net']));
    return $out;
}

/**
 * ไทม์ไลน์รวมทุกธุรกรรมระหว่างเรากับเพื่อนคนเดียว (บิล + เคลียร์ + ถือเงิน + ผ่อน)
 * คืน list เรียงใหม่ล่าสุดก่อน: [ts, icon, title, sub, impact]
 *   impact > 0 = ทำให้ "เพื่อนติดเรา" มากขึ้น | impact < 0 = ทำให้ "เราติดเพื่อน" มากขึ้น
 */
function friend_timeline($myMember, $friendId) {
    $me = (int) $myMember; $fr = (int) $friendId;
    if ($me <= 0 || $fr <= 0 || $me === $fr) return [];
    $ev = [];

    // (1) บิลที่เราจ่ายก่อน + เพื่อนมีส่วนหาร -> เพื่อนติดเรา
    foreach (sb_rows(sb_get('expenses?paid_by=eq.' . $me . '&select=id,title,created_at,expense_splits(user_id,amount)')) as $e) {
        foreach (($e['expense_splits'] ?? []) as $s) {
            if ((int) $s['user_id'] !== $fr) continue;
            $ev[] = ['ts' => $e['created_at'] ?? '', 'icon' => 'receipt', 'title' => 'บิล: ' . ($e['title'] ?? '-'),
                     'sub' => 'เราจ่ายก่อน · ส่วนแบ่งของเพื่อน', 'impact' => (float) $s['amount']];
        }
    }
    // (2) บิลที่เพื่อนจ่ายก่อน + เรามีส่วนหาร -> เราติดเพื่อน
    foreach (sb_rows(sb_get('expense_splits?user_id=eq.' . $me . '&select=amount,expenses(id,title,created_at,paid_by)')) as $s) {
        if ((int) ($s['expenses']['paid_by'] ?? 0) !== $fr) continue;
        $ev[] = ['ts' => $s['expenses']['created_at'] ?? '', 'icon' => 'receipt', 'title' => 'บิล: ' . ($s['expenses']['title'] ?? '-'),
                 'sub' => 'เพื่อนจ่ายก่อน · ส่วนแบ่งของเรา', 'impact' => -(float) $s['amount']];
    }
    // (3) เคลียร์หนี้ (settlements)
    foreach (sb_rows(sb_get('settlements?select=from_user,to_user,amount,note,created_at'
            . '&or=(and(from_user.eq.' . $me . ',to_user.eq.' . $fr . '),and(from_user.eq.' . $fr . ',to_user.eq.' . $me . '))')) as $st) {
        $amt = (float) $st['amount']; $iPaid = (int) $st['from_user'] === $me;
        $ev[] = ['ts' => $st['created_at'] ?? '', 'icon' => 'arrow-right-left', 'title' => 'เคลียร์หนี้',
                 'sub' => $iPaid ? 'เราโอนคืนเพื่อน' : 'เพื่อนโอนคืนเรา', 'impact' => $iPaid ? $amt : -$amt];
    }
    // (4) เงินที่ถือไว้ (holdings)
    foreach (sb_rows(sb_get('holdings?select=holder_id,owner_id,amount,note,created_at'
            . '&or=(and(holder_id.eq.' . $me . ',owner_id.eq.' . $fr . '),and(holder_id.eq.' . $fr . ',owner_id.eq.' . $me . '))')) as $h) {
        $amt = (float) $h['amount']; $weHold = (int) $h['holder_id'] === $me;
        // เราถือเงินเพื่อน (amt>0) = เราติดเพื่อน (impact -) ; เพื่อนถือเงินเรา = เพื่อนติดเรา (impact +)
        $impact = $weHold ? -$amt : $amt;
        $sub = $weHold ? ($amt >= 0 ? 'รับเงินเพื่อนมาถือ' : 'คืนเงินให้เพื่อน')
                       : ($amt >= 0 ? 'เพื่อนถือเงินเรา' : 'เพื่อนคืนเงินเรา');
        $ev[] = ['ts' => $h['created_at'] ?? '', 'icon' => 'piggy-bank', 'title' => 'เงินที่ถือไว้', 'sub' => $sub, 'impact' => $impact];
    }
    // (5) ผ่อนรายเดือน: ครบกำหนดทีละงวด (ตาม start_date) + การจ่ายแต่ละงวด
    $plans = sb_rows(sb_get('installments?select=id,title,monthly_amount,months,payer_id,payee_id,start_date,created_at'
            . '&or=(and(payee_id.eq.' . $me . ',payer_id.eq.' . $fr . '),and(payer_id.eq.' . $me . ',payee_id.eq.' . $fr . '))'));
    if ($plans) {
        $titleOf = [];
        foreach ($plans as $p) {
            $titleOf[(int) $p['id']] = $p['title'];
            $friendPays = (int) $p['payee_id'] === $me;            // เพื่อนผ่อนให้เรา
            $monthly = (float) $p['monthly_amount'];
            // อีเวนต์ "ถึงกำหนด" ทีละงวด เฉพาะงวดที่ครบกำหนดแล้ว ณ วันนี้
            $due   = installments_due_count($p['start_date'] ?? null, (int) $p['months']);
            $base  = !empty($p['start_date']) ? substr($p['start_date'], 0, 10) : substr($p['created_at'] ?? '', 0, 10);
            for ($n = 0; $n < $due; $n++) {
                $ts = $base ? date('Y-m-d', strtotime("+$n months", strtotime($base))) : ($p['created_at'] ?? '');
                $ev[] = ['ts' => $ts, 'icon' => 'calendar-clock', 'title' => 'ผ่อนงวดที่ ' . ($n + 1) . '/' . (int) $p['months'] . ': ' . $p['title'],
                         'sub' => $friendPays ? 'ถึงกำหนดเพื่อนผ่อน' : 'ถึงกำหนดเราผ่อน', 'impact' => $friendPays ? $monthly : -$monthly];
            }
        }
        $ids = implode(',', array_map(fn($p) => (int) $p['id'], $plans));
        $payeeOf = [];
        foreach ($plans as $p) { $payeeOf[(int) $p['id']] = (int) $p['payee_id']; }
        foreach (sb_rows(sb_get('installment_payments?installment_id=in.(' . $ids . ')&select=id,installment_id,amount,source,paid_at')) as $pm) {
            $iid = (int) $pm['installment_id']; $amt = (float) $pm['amount'];
            $friendPays = ($payeeOf[$iid] ?? 0) === $me;           // เพื่อนเป็นคนผ่อน -> จ่ายลดที่เพื่อนติดเรา (impact -)
            $ev[] = ['ts' => $pm['paid_at'] ?? '', 'icon' => 'banknote', 'title' => 'จ่ายงวด: ' . ($titleOf[$iid] ?? '-'),
                     'sub' => $friendPays ? 'เพื่อนจ่ายงวด' : 'เราจ่ายงวดให้เพื่อน', 'impact' => $friendPays ? -$amt : $amt];
        }
    }

    usort($ev, fn($a, $z) => strcmp($z['ts'], $a['ts']));
    return $ev;
}

/**
 * เคลียร์หนี้แบบสรุปรวม: ลูกหนี้จ่ายคืน $payAmount
 * ปิดยอดทุก bucket (บิล + ผ่อนที่ถึงกำหนด + เงินที่ถือไว้เดิม) ให้เป็น 0
 * แล้วยุบ "ส่วนต่าง" (จ่ายเกิน/ขาด) เหลือก้อนเดียวเก็บไว้ที่เงินเพื่อน (holdings)
 * @param float $payAmount จำนวนที่ลูกหนี้จ่ายคืนจริง (null/<0 = จ่ายเต็มยอด)
 * คืน ['net','paid','residual'] หรือ null ถ้าไม่มีอะไรต้องทำ
 */
function reconcile_with_friend($myMember, $friendId, $payAmount = null) {
    $me = (int) $myMember; $fr = (int) $friendId;
    if ($me <= 0 || $fr <= 0 || $me === $fr) return null;

    $all = unified_balances($me);
    $f   = $all[$fr] ?? null;
    if (!$f) return null;

    $net = round((float) $f['net'], 2);              // + = เพื่อนติดเรา | − = เราติดเพื่อน
    $pay = ($payAmount === null || (float) $payAmount < 0) ? abs($net) : round((float) $payAmount, 2);
    if (abs($net) < 0.009 && $pay < 0.009) return null;

    $billB = round($f['bill'] + $f['settle'], 2);    // ส่วนบิลคงเหลือ (+ = เพื่อนติดเรา)
    $holdB = round($f['holding'], 2);                 // ส่วนเงินที่ถือไว้
    $instB = round($f['installment'], 2);             // ส่วนผ่อนคงเหลือ (เฉพาะที่ถึงกำหนด)
    $note  = 'เคลียร์ยอดรวม';

    // (1) ปิดยอดเงินที่ถือไว้เดิม -> holding bucket = 0
    if (abs($holdB) > 0.009) {
        if ($holdB > 0) sb_insert('holdings', ['holder_id' => $fr, 'owner_id' => $me, 'amount' => -$holdB, 'note' => $note]);
        else            sb_insert('holdings', ['holder_id' => $me, 'owner_id' => $fr, 'amount' => $holdB,  'note' => $note]);
    }

    // (2) ปิดงวดผ่อนที่ถึงกำหนด -> installment bucket = 0
    if (abs($instB) > 0.009) {
        $plans = sb_rows(sb_get('installments?select=id,monthly_amount,months,start_date'
            . '&or=(and(payee_id.eq.' . $me . ',payer_id.eq.' . $fr . '),and(payer_id.eq.' . $me . ',payee_id.eq.' . $fr . '))'));
        if ($plans) {
            $ids  = implode(',', array_map(fn($p) => (int) $p['id'], $plans));
            $paid = [];
            foreach (sb_rows(sb_get('installment_payments?installment_id=in.(' . $ids . ')&select=installment_id,amount')) as $pm) {
                $paid[(int) $pm['installment_id']] = ($paid[(int) $pm['installment_id']] ?? 0) + (float) $pm['amount'];
            }
            foreach ($plans as $p) {
                $remain = installment_due_outstanding($p, $paid[(int) $p['id']] ?? 0);
                if ($remain > 0.009) {
                    sb_insert('installment_payments', ['installment_id' => (int) $p['id'], 'amount' => $remain, 'source' => 'cash', 'note' => $note]);
                }
            }
        }
    }

    // (3) ปิดยอดบิล -> bill bucket = 0
    if (abs($billB) > 0.009) {
        if ($billB > 0) sb_insert('settlements', ['from_user' => $fr, 'to_user' => $me, 'amount' => $billB,  'note' => $note]);
        else            sb_insert('settlements', ['from_user' => $me, 'to_user' => $fr, 'amount' => -$billB, 'note' => $note]);
    }

    // ตอนนี้ net = 0 ทุก bucket — เก็บส่วนต่างหลังลูกหนี้จ่าย $pay เป็น "เงินเพื่อน"
    $residual = round($net - ($net >= 0 ? $pay : -$pay), 2); // + = เพื่อนยังติดเรา | − = เพื่อนจ่ายเกิน/เราติด
    if ($residual > 0.009) {
        // เพื่อนยังติดเรา -> เงินเราอยู่กับเพื่อน
        sb_insert('holdings', ['holder_id' => $fr, 'owner_id' => $me, 'amount' => $residual, 'note' => 'ส่วนต่างหลังเคลียร์']);
    } elseif ($residual < -0.009) {
        // เพื่อนจ่ายเกิน / เราติดเพื่อน -> เงินเพื่อนอยู่กับเรา
        sb_insert('holdings', ['holder_id' => $me, 'owner_id' => $fr, 'amount' => -$residual, 'note' => 'ส่วนต่างหลังเคลียร์']);
    }

    return ['net' => $net, 'paid' => $pay, 'residual' => $residual];
}

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
