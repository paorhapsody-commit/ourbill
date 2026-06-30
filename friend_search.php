<?php
/* friend_search.php — ค้นหาผู้ใช้สำหรับเพิ่มเพื่อน (คืน JSON) */
require_once 'auth.php';
require_login();
header('Content-Type: application/json; charset=utf-8');

$me = (int) ($_SESSION['user']['account_id'] ?? 0);
$q  = trim($_GET['q'] ?? '');
if ($q === '' || $me === 0) { echo json_encode([]); exit; }

// ค้นเฉพาะบัญชีที่อนุมัติแล้ว ตามอีเมลหรือชื่อ
$p   = '*' . rawurlencode($q) . '*';
$rows = sb_rows(sb_get("app_accounts?status=eq.approved&or=(email.ilike.$p,name.ilike.$p)&limit=8"));

// สถานะความสัมพันธ์กับเราแต่ละคน
$rel = [];
foreach (friend_links($me) as $l) {
    $other = ($l['requester'] == $me) ? (int) $l['addressee'] : (int) $l['requester'];
    if (($l['status'] ?? '') === 'accepted')      $rel[$other] = 'friends';
    elseif ((int) $l['requester'] === $me)         $rel[$other] = 'out';   // เราส่งคำขอไป
    else                                           $rel[$other] = 'in';    // เขาส่งคำขอมา
}

$out = [];
foreach ($rows as $r) {
    if ((int) $r['id'] === $me) continue; // ไม่แสดงตัวเอง
    $out[] = [
        'id'      => (int) $r['id'],
        'name'    => $r['name'],
        'email'   => $r['email'],
        'picture' => $r['picture'],
        'rel'     => $rel[(int) $r['id']] ?? 'none',
    ];
}
echo json_encode($out);
