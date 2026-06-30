<?php
/* =========================================================
 *  OurBill / FairShare — Config & Supabase helpers
 * ========================================================= */

/* ค่าลับ Supabase: ใช้ Environment Variable ก่อน (สำหรับ deploy)
 * ถ้าไม่มี ENV ค่อย fallback ไป config.local.php (สำหรับเครื่อง local — ไม่ขึ้น git) */
if (is_file(__DIR__ . '/config.local.php')) {
    require __DIR__ . '/config.local.php';
}
define('SUPABASE_URL', getenv('SUPABASE_URL') ?: (defined('LOCAL_SUPABASE_URL') ? LOCAL_SUPABASE_URL : ''));
define('SUPABASE_KEY', getenv('SUPABASE_KEY') ?: (defined('LOCAL_SUPABASE_KEY') ? LOCAL_SUPABASE_KEY : ''));

/**
 * เรียก Supabase REST API
 * @param string      $token  JWT ของผู้ใช้ (เช่น token แอดมิน) — ถ้าไม่ส่งจะใช้ anon key
 * @return array{status:int, body:mixed}
 */
function supabase_call($endpoint, $method = 'GET', $data = null, $extraHeaders = [], $token = null) {
    $url = SUPABASE_URL . '/rest/v1/' . $endpoint;
    $ch  = curl_init($url);

    // ถ้า caller ส่ง Prefer มาเองแล้ว ไม่ต้องใส่ default ซ้ำ
    $hasPrefer = false;
    foreach ($extraHeaders as $h) { if (stripos($h, 'Prefer:') === 0) { $hasPrefer = true; break; } }

    $headers = [
        'apikey: ' . SUPABASE_KEY,
        'Authorization: Bearer ' . ($token ?: SUPABASE_KEY),
        'Content-Type: application/json',
    ];
    if (!$hasPrefer) $headers[] = 'Prefer: return=representation';
    $headers = array_merge($headers, $extraHeaders);

    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    if ($data !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    }

    $response = curl_exec($ch);
    $status   = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return ['status' => (int) $status, 'body' => json_decode($response, true)];
}

/** เวอร์ชันสั้น คืนเฉพาะ body (เข้ากันได้กับโค้ดเดิม) */
function supabase_request($endpoint, $method = 'GET', $data = null) {
    return supabase_call($endpoint, $method, $data)['body'];
}

/* ---------- shortcut helpers ---------- */
function sb_get($endpoint)            { return supabase_request($endpoint, 'GET'); }
function sb_insert($table, $data)     { return supabase_call($table, 'POST', $data); }
function sb_update($endpoint, $data)  { return supabase_call($endpoint, 'PATCH', $data); }
function sb_delete($endpoint)         { return supabase_call($endpoint, 'DELETE'); }

/** กรองผลลัพธ์ให้เหลือเฉพาะ "แถวจริง" (กันกรณี body เป็น error object เช่นตารางยังไม่ถูกสร้าง) */
function sb_rows($res) {
    if (!is_array($res)) return [];
    return array_values(array_filter($res, fn($r) => is_array($r) && isset($r['id'])));
}

/** upsert (insert หรือ update ถ้า key ซ้ำ) — ใช้ token แอดมินเพื่อผ่าน RLS */
function sb_upsert($table, $rows, $on_conflict, $token = null) {
    return supabase_call($table . '?on_conflict=' . $on_conflict, 'POST', $rows,
        ['Prefer: resolution=merge-duplicates,return=representation'], $token);
}

/* =========================================================
 *  Settings (อ่านค่าตั้งค่าจากตาราง settings)
 * ========================================================= */
function app_settings($refresh = false) {
    static $cache = null;
    if ($cache === null || $refresh) {
        $cache = [];
        $rows = sb_get('settings?select=key,value');
        if (is_array($rows)) {
            foreach ($rows as $r) {
                // ป้องกันกรณี body เป็น error object ไม่ใช่ list ของแถว
                if (is_array($r) && isset($r['key'])) { $cache[$r['key']] = $r['value']; }
            }
        }
    }
    return $cache;
}

function setting($key, $default = '') {
    $s = app_settings();
    return (array_key_exists($key, $s) && $s[$key] !== null && $s[$key] !== '') ? $s[$key] : $default;
}

/* =========================================================
 *  Settle-up: คำนวณว่าใครควรโอนให้ใครเท่าไหร่ (น้อยรายการที่สุด)
 *  ใช้ greedy จับคู่ลูกหนี้ยอดมากสุดกับเจ้าหนี้ยอดมากสุด
 * ========================================================= */
function calculate_settlements($balances) {
    $creditors = []; // คนที่รอรับเงินคืน (balance > 0)
    $debtors   = []; // คนที่ต้องจ่ายคืน  (balance < 0)

    foreach ($balances as $b) {
        $bal = round((float) $b['balance'], 2);
        if ($bal > 0.009) {
            $creditors[] = ['id' => $b['id'], 'name' => $b['name'], 'amt' => $bal];
        } elseif ($bal < -0.009) {
            $debtors[]   = ['id' => $b['id'], 'name' => $b['name'], 'amt' => -$bal];
        }
    }

    usort($creditors, fn($a, $z) => $z['amt'] <=> $a['amt']);
    usort($debtors,   fn($a, $z) => $z['amt'] <=> $a['amt']);

    $tx = [];
    $i = 0; $j = 0;
    while ($i < count($debtors) && $j < count($creditors)) {
        $pay = min($debtors[$i]['amt'], $creditors[$j]['amt']);
        $tx[] = [
            'from_id' => $debtors[$i]['id'],   'from' => $debtors[$i]['name'],
            'to_id'   => $creditors[$j]['id'], 'to'   => $creditors[$j]['name'],
            'amount'  => round($pay, 2),
        ];
        $debtors[$i]['amt']   -= $pay;
        $creditors[$j]['amt'] -= $pay;
        if ($debtors[$i]['amt']   < 0.009) $i++;
        if ($creditors[$j]['amt'] < 0.009) $j++;
    }
    return $tx;
}

/**
 * คำนวณยอดต่อคนจาก input ของฟอร์ม (ใช้ร่วมกันทั้งหน้าเพิ่มและแก้ไข)
 * @return array{0: array<int,float>|null, 1: string|null}  [splits, error]
 */
function compute_splits($mode, $total, $picked, $amounts) {
    $total  = round((float) $total, 2);
    $picked = array_values(array_filter(array_map('intval', (array) $picked)));
    if (empty($picked)) return [null, 'เลือกผู้ร่วมหารอย่างน้อย 1 คน'];

    if ($mode === 'custom') {
        $splits = []; $sum = 0;
        foreach ($picked as $uid) {
            $a = round((float) ($amounts[$uid] ?? 0), 2);
            $splits[$uid] = $a; $sum += $a;
        }
        if (abs($sum - $total) > 0.01) {
            return [null, 'ยอดที่กำหนดเองรวมกันได้ ' . baht($sum) . ' ฿ ไม่เท่ากับยอดบิล ' . baht($total) . ' ฿'];
        }
        return [$splits, null];
    }

    // หารเท่ากัน: ปัดลง 2 ตำแหน่ง แล้วโยนเศษให้คนแรก
    $n    = count($picked);
    $each = floor($total / $n * 100) / 100;
    $rem  = round($total - $each * $n, 2);
    $splits = [];
    foreach ($picked as $idx => $uid) {
        $splits[$uid] = $each + ($idx === 0 ? $rem : 0);
    }
    return [$splits, null];
}

/* =========================================================
 *  UI helpers
 * ========================================================= */

/** จานสีพาสเทลสำหรับ avatar (วนตาม id) */
function avatar_palette($id) {
    $palette = [
        ['bg' => 'bg-emerald-100', 'text' => 'text-emerald-700'],
        ['bg' => 'bg-teal-100',    'text' => 'text-teal-700'],
        ['bg' => 'bg-cyan-100',    'text' => 'text-cyan-700'],
        ['bg' => 'bg-sky-100',     'text' => 'text-sky-700'],
        ['bg' => 'bg-amber-100',   'text' => 'text-amber-700'],
        ['bg' => 'bg-rose-100',    'text' => 'text-rose-700'],
        ['bg' => 'bg-violet-100',  'text' => 'text-violet-700'],
        ['bg' => 'bg-lime-100',    'text' => 'text-lime-700'],
    ];
    return $palette[((int) $id) % count($palette)];
}

/** ตัวอักษรย่อสำหรับ avatar (รองรับชื่อไทยที่ขึ้นต้นด้วย "คุณ") */
function avatar_initial($name) {
    $name = trim(preg_replace('/^คุณ/u', '', $name));
    return mb_strtoupper(mb_substr($name, 0, 1, 'UTF-8'), 'UTF-8');
}

/** render avatar วงกลม */
function avatar($id, $name, $size = 'w-10 h-10 text-sm') {
    $c = avatar_palette($id);
    return '<span class="inline-flex items-center justify-center rounded-full font-bold '
        . $size . ' ' . $c['bg'] . ' ' . $c['text'] . '">'
        . htmlspecialchars(avatar_initial($name)) . '</span>';
}

function baht($n) { return number_format((float) $n, 2); }
