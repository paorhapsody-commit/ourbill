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

/* Timeout การเรียก Supabase (วินาที) — override ได้ใน config.local.php ก่อน require ไฟล์นี้ */
if (!defined('SB_CONNECT_TIMEOUT')) define('SB_CONNECT_TIMEOUT', 8);
if (!defined('SB_TIMEOUT'))         define('SB_TIMEOUT', 25);

/**
 * เรียก Supabase REST API
 *  - มี timeout กันหน้าเว็บค้างเมื่อ Supabase ไม่ตอบ
 *  - retry อัตโนมัติ "เฉพาะ GET" เมื่อเจอความผิดพลาดชั่วคราว (เครือข่ายล่ม / HTTP 429 / 5xx)
 *    ห้าม retry POST/PATCH/DELETE เด็ดขาด เพราะอาจสร้างรายการเงินซ้ำ (settlements/holdings ฯลฯ)
 *  - log ทุกความผิดพลาดผ่าน error_log() เพื่อไม่ให้ยอดเงินผิดแบบเงียบ ๆ
 * @param string      $token  JWT ของผู้ใช้ (เช่น token แอดมิน) — ถ้าไม่ส่งจะใช้ anon key
 * @return array{status:int, body:mixed}
 */
function supabase_call($endpoint, $method = 'GET', $data = null, $extraHeaders = [], $token = null) {
    $url    = SUPABASE_URL . '/rest/v1/' . $endpoint;
    $method = strtoupper($method);

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

    $payload     = ($data !== null) ? json_encode($data) : null;
    $maxAttempts = ($method === 'GET') ? 3 : 1; // GET เท่านั้นที่ retry ได้อย่างปลอดภัย

    $status = 0; $body = null; $curlErr = '';
    for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_CONNECTTIMEOUT => SB_CONNECT_TIMEOUT,
            CURLOPT_TIMEOUT        => SB_TIMEOUT,
        ]);
        if ($payload !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);

        $response = curl_exec($ch);
        $status   = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr  = ($response === false) ? curl_error($ch) : '';
        curl_close($ch);

        $body = ($response === false) ? null : json_decode($response, true);

        // ชั่วคราว = เครือข่ายล่ม / ไม่มี status / โดน rate-limit / server error
        $transient = ($response === false) || $status === 0 || $status === 429 || $status >= 500;
        if (!$transient) break;

        if ($attempt < $maxAttempts) {
            error_log("[OurBill] Supabase $method $endpoint ล้มเหลว (HTTP $status"
                . ($curlErr ? ", curl: $curlErr" : '') . ") — retry $attempt/" . ($maxAttempts - 1));
            usleep(200000 * $attempt); // backoff 0.2s, 0.4s
        }
    }

    if ($status === 0 || $status >= 400) {
        $msg = (is_array($body) && isset($body['message'])) ? " msg: {$body['message']}" : '';
        error_log("[OurBill] Supabase $method $endpoint => HTTP $status" . ($curlErr ? " curl: $curlErr" : '') . $msg);
    }

    return ['status' => $status, 'body' => $body];
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

/**
 * กรองผลลัพธ์ให้เหลือเฉพาะ "แถวจริง"
 * PostgREST สำเร็จ -> คืน list (array ดัชนีต่อเนื่อง) ของแถว | error -> คืน object (associative)
 * จึงเช็คว่าเป็น list ไหม แทนการบังคับว่าต้องมีคอลัมน์ id (เดิมทำให้ select เฉพาะบางคอลัมน์โดนกรองทิ้ง)
 */
function sb_rows($res) {
    if (!is_array($res) || empty($res)) return [];
    if (array_keys($res) !== range(0, count($res) - 1)) {
        // ไม่ใช่ list = error object -> log ไว้ กันยอดเงินผิด/เป็นศูนย์แบบเงียบเมื่อ query พลาด
        if (isset($res['message']) || isset($res['code'])) {
            error_log('[OurBill] sb_rows ได้ error object แทนรายการแถว: ' . json_encode($res, JSON_UNESCAPED_UNICODE));
        }
        return [];
    }
    return array_values(array_filter($res, 'is_array'));
}

/** upsert (insert หรือ update ถ้า key ซ้ำ) — ใช้ token แอดมินเพื่อผ่าน RLS */
function sb_upsert($table, $rows, $on_conflict, $token = null) {
    return supabase_call($table . '?on_conflict=' . $on_conflict, 'POST', $rows,
        ['Prefer: resolution=merge-duplicates,return=representation'], $token);
}

/* =========================================================
 *  Supabase Storage (อัปโหลดรูป)
 * ========================================================= */

/** อัปโหลดไฟล์ขึ้น bucket */
function sb_upload_file($bucket, $path, $tmpFile, $mime) {
    $url = SUPABASE_URL . '/storage/v1/object/' . $bucket . '/' . rawurlencode($path);
    $ch  = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_HTTPHEADER     => [
            'apikey: ' . SUPABASE_KEY,
            'Authorization: Bearer ' . SUPABASE_KEY,
            'Content-Type: ' . $mime,
            'x-upsert: true',
        ],
        CURLOPT_POSTFIELDS     => file_get_contents($tmpFile),
        CURLOPT_CONNECTTIMEOUT => SB_CONNECT_TIMEOUT,
        CURLOPT_TIMEOUT        => 60, // อัปโหลดไฟล์ให้เวลามากกว่า REST ปกติ
    ]);
    $res     = curl_exec($ch);
    $status  = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr = ($res === false) ? curl_error($ch) : '';
    curl_close($ch);
    if ($status === 0 || $status >= 400) {
        error_log("[OurBill] Supabase upload $bucket/$path => HTTP $status" . ($curlErr ? " curl: $curlErr" : ''));
    }
    return ['status' => $status, 'body' => ($res === false ? null : json_decode($res, true))];
}

/** URL สาธารณะของไฟล์ในบัคเก็ต public */
function sb_public_url($bucket, $path) {
    return SUPABASE_URL . '/storage/v1/object/public/' . $bucket . '/' . rawurlencode($path);
}

/**
 * รับไฟล์รูปจากฟอร์ม -> อัปโหลด -> คืน [public_url, error]
 * ไม่มีไฟล์แนบ = [null, null] (ไม่ถือเป็น error)
 */
function handle_receipt_upload($field = 'receipt') {
    if (empty($_FILES[$field]) || ($_FILES[$field]['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return [null, null];
    }
    $f = $_FILES[$field];
    if ($f['error'] !== UPLOAD_ERR_OK)  return [null, 'อัปโหลดรูปไม่สำเร็จ (error ' . $f['error'] . ')'];
    if ($f['size'] > 5 * 1024 * 1024)   return [null, 'รูปต้องมีขนาดไม่เกิน 5MB'];

    $allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif'];
    $mime = function_exists('finfo_open')
        ? (new finfo(FILEINFO_MIME_TYPE))->file($f['tmp_name'])
        : ($f['type'] ?? '');
    if (!isset($allowed[$mime])) return [null, 'รองรับเฉพาะไฟล์รูป (JPG/PNG/WEBP/GIF)'];

    $name = 'rcpt_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $allowed[$mime];
    $res  = sb_upload_file('receipts', $name, $f['tmp_name'], $mime);
    if ($res['status'] < 200 || $res['status'] >= 300) {
        return [null, 'อัปโหลดรูปขึ้น Storage ไม่สำเร็จ (HTTP ' . $res['status'] . ') — ตรวจว่าได้สร้าง bucket "receipts" + policy แล้ว'];
    }
    return [sb_public_url('receipts', $name), null];
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
