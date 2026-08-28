<?php
/* =========================================================
 *  ledger.php — ตรรกะเรื่องเงินทั้งหมด (แยกออกจาก auth.php)
 * ---------------------------------------------------------
 *  auth.php ควรมีแค่ session / OAuth / บัญชี / เพื่อน
 *  ส่วนยอดคงเหลือ ไทม์ไลน์ การแบ่งรอบ และการเคลียร์หนี้ อยู่ที่นี่
 *
 *  หัวใจคือ ledger_snapshot() ที่ดึงข้อมูล 6 ตารางครั้งเดียวต่อ request
 *  แล้วให้ทุกฟังก์ชันกรองในหน่วยความจำ (ดูคำอธิบายที่ตัวฟังก์ชัน)
 *
 *  โหลดผ่าน auth.php อยู่แล้ว — ไม่ต้อง require เองในหน้าเพจ
 * ========================================================= */
require_once __DIR__ . '/config.php';

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

/* =========================================================
 *  Ledger snapshot — ดึงข้อมูลการเงินของเราครั้งเดียวต่อ request
 * ---------------------------------------------------------
 *  เดิม unified_balances / calendar_events / installments_due_alerts / friend_timeline
 *  ต่างคนต่างยิง query ตารางชุดเดียวกัน หน้าแรกจึงยิงซ้ำเกือบ 3 เท่า (วัดได้ ~21 request)
 *  และ settle.php ที่วน friend_timeline ต่อเพื่อน 1 คนก็ยิงเพิ่มรอบละ 6
 *
 *  ที่นี่ดึงตารางละครั้งเดียว "เมื่อถูกใช้จริง" ด้วย select ที่เป็น superset ของทุกฟังก์ชัน
 *  แล้วให้ทุกฟังก์ชันกรองในหน่วยความจำแทน — ผลลัพธ์ต้องเท่าเดิมเป๊ะ
 *  (โหลดแบบ lazy เพราะบางหน้าใช้แค่ 2-3 ก้อน เช่นหน้าผ่อนไม่ต้องใช้บิลเลย)
 * ========================================================= */

/** ข้อมูลก้อนเดียว: paid|shares|settles|holds|plans|payments — ดึงครั้งแรกที่เรียก แล้วจำไว้ */
function ledger_part($myMember, $key) {
    static $cache = [];
    $me = (int) $myMember;
    if ($me <= 0) return [];
    if (isset($cache[$me][$key])) return $cache[$me][$key];

    switch ($key) {
        // บิลที่เราสำรองจ่าย (พ่วงส่วนหารของทุกคนในบิล)
        case 'paid':
            $rows = sb_rows(sb_get('expenses?paid_by=eq.' . $me
                . '&select=id,title,total_amount,receipt_url,created_at,spent_at,expense_splits(user_id,amount)'));
            break;
        // ส่วนหารของเราในบิลที่คนอื่นจ่าย
        case 'shares':
            $rows = sb_rows(sb_get('expense_splits?user_id=eq.' . $me
                . '&select=amount,expenses(id,title,total_amount,receipt_url,created_at,spent_at,paid_by)'));
            break;
        case 'settles':
            $rows = sb_rows(sb_get('settlements?or=(from_user.eq.' . $me . ',to_user.eq.' . $me . ')'
                . '&select=from_user,to_user,amount,note,created_at,fromu:from_user(name),tou:to_user(name)'));
            break;
        case 'holds':
            $rows = sb_rows(sb_get('holdings?or=(holder_id.eq.' . $me . ',owner_id.eq.' . $me . ')'
                . '&select=holder_id,owner_id,amount,note,created_at,holder:holder_id(name),owner:owner_id(name)'));
            break;
        case 'plans':
            $rows = sb_rows(sb_get('installments?or=(payee_id.eq.' . $me . ',payer_id.eq.' . $me . ')'
                . '&select=id,title,monthly_amount,months,payer_id,payee_id,start_date,created_at'));
            break;
        case 'payments':
            $ids  = implode(',', array_map(fn($p) => (int) $p['id'], ledger_part($me, 'plans')));
            $rows = $ids !== '' ? sb_rows(sb_get('installment_payments?installment_id=in.(' . $ids . ')'
                . '&select=id,installment_id,amount,source,paid_at,note')) : [];
            break;
        default:
            $rows = [];
    }
    return $cache[$me][$key] = $rows;
}

/** ข้อมูลครบทุกก้อน — ใช้กับหน้าที่ต้องใช้ทั้งหมดจริง ๆ (หน้าแรก, ไทม์ไลน์เพื่อน)
 *  @return array{paid:array,shares:array,settles:array,holds:array,plans:array,payments:array} */
function ledger_snapshot($myMember) {
    $out = [];
    foreach (['paid', 'shares', 'settles', 'holds', 'plans', 'payments'] as $k) {
        $out[$k] = ledger_part($myMember, $k);
    }
    return $out;
}

/** ยอดที่จ่ายไปแล้วของแต่ละแผนผ่อน [installment_id => ยอดรวม] */
function ledger_paid_by_plan($snap) {
    $paid = [];
    foreach ($snap['payments'] as $pm) {
        $iid = (int) $pm['installment_id'];
        $paid[$iid] = ($paid[$iid] ?? 0) + (float) $pm['amount'];
    }
    return $paid;
}

/** ชื่อสมาชิกตาม id — จำไว้ทั้ง request ไม่ยิงซ้ำ @return array<int,string> */
function member_names($ids) {
    static $known = [];
    $ids  = array_values(array_unique(array_filter(array_map('intval', (array) $ids))));
    $miss = array_values(array_diff($ids, array_keys($known)));
    if ($miss) {
        foreach (sb_rows(sb_get('users?id=in.(' . implode(',', $miss) . ')&select=id,name')) as $u) {
            $known[(int) $u['id']] = $u['name'];
        }
        foreach ($miss as $m) { if (!isset($known[$m])) $known[$m] = '#' . $m; }
    }
    $out = [];
    foreach ($ids as $i) $out[$i] = $known[$i];
    return $out;
}

/**
 * รายการผ่อนที่ "ถึงกำหนดงวดแล้วแต่ยังจ่ายไม่ครบ" — สำหรับแจ้งเตือนหน้าแรก
 * คืน list: [id, title, friend_id, friend_name, outstanding, due, months, friend_pays, due_date]
 */
function installments_due_alerts($myMember) {
    $me = (int) $myMember;
    if ($me <= 0) return [];
    $plans = ledger_part($me, 'plans');       // หน้านี้ไม่ต้องใช้บิล/เคลียร์หนี้/เงินที่ถือไว้
    if (!$plans) return [];
    $paid = ledger_paid_by_plan(['payments' => ledger_part($me, 'payments')]);
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
    $names = member_names(array_map(fn($r) => $r['friend_id'], $out));
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
        $d = thai_date($ts);
        if (!$d) return;
        $ev[] = ['date' => $d, 'icon' => $icon, 'title' => $title, 'sub' => $sub,
                 'amount' => round((float) $amount, 2), 'sign' => $sign];
    };

    $snap = ledger_snapshot($me);

    // บิลที่เราจ่ายก่อน
    foreach ($snap['paid'] as $e) {
        $add($ev, $e['spent_at'] ?? $e['created_at'] ?? '', 'receipt', $e['title'] ?? 'รายจ่าย', 'เราจ่ายก่อน', $e['total_amount'] ?? 0, '');
    }
    // บิลที่เพื่อนจ่ายก่อน + เรามีส่วนหาร
    foreach ($snap['shares'] as $s) {
        $exp = $s['expenses'] ?? null;
        if (!$exp || (int) ($exp['paid_by'] ?? 0) === $me) continue;
        $add($ev, $exp['spent_at'] ?? $exp['created_at'] ?? '', 'receipt', $exp['title'] ?? 'รายจ่าย', 'ส่วนแบ่งของเรา', $s['amount'] ?? 0, '');
    }
    // เคลียร์หนี้
    foreach ($snap['settles'] as $st) {
        $iPaid = (int) $st['from_user'] === $me;
        $other = $iPaid ? ($st['tou']['name'] ?? '?') : ($st['fromu']['name'] ?? '?');
        $add($ev, $st['created_at'] ?? '', 'arrow-right-left', 'เคลียร์หนี้', ($iPaid ? 'จ่ายคืน ' : 'รับคืนจาก ') . $other, $st['amount'] ?? 0, $iPaid ? '-' : '+');
    }
    // เงินที่ถือไว้
    foreach ($snap['holds'] as $h) {
        $weHold = (int) $h['holder_id'] === $me;
        $other  = $weHold ? ($h['owner']['name'] ?? '?') : ($h['holder']['name'] ?? '?');
        $amt    = (float) $h['amount'];
        $sub    = ($weHold ? ($amt >= 0 ? 'รับเงิน ' : 'คืนเงินให้ ') : ($amt >= 0 ? 'ถือเงินเรา · ' : 'คืนเงินเรา · ')) . $other;
        $add($ev, $h['created_at'] ?? '', 'piggy-bank', 'เงินที่ถือไว้', $sub, abs($amt), '');
    }
    // จ่ายงวดผ่อน
    $titleOf = []; foreach ($snap['plans'] as $p) $titleOf[(int) $p['id']] = $p['title'];
    foreach ($snap['payments'] as $pm) {
        $add($ev, $pm['paid_at'] ?? '', 'banknote', 'จ่ายงวด: ' . ($titleOf[(int) $pm['installment_id']] ?? '-'), '', $pm['amount'] ?? 0, '');
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

    $snap = ledger_snapshot($myMember);

    // (A) บิลที่เราจ่ายก่อน -> เพื่อนติดเราตามส่วนหาร
    foreach ($snap['paid'] as $e) {
        foreach (($e['expense_splits'] ?? []) as $s) {
            if ((int) $s['user_id'] === $myMember) continue;
            $f = $touch($net, $s['user_id']);
            $net[$f]['bill'] += (float) $s['amount'];
        }
    }
    // (B) ส่วนหารของเราในบิลที่เพื่อนจ่ายก่อน -> เราติดเพื่อน
    foreach ($snap['shares'] as $s) {
        $p = (int) ($s['expenses']['paid_by'] ?? 0);
        if ($p <= 0 || $p === $myMember) continue;
        $f = $touch($net, $p);
        $net[$f]['bill'] -= (float) $s['amount'];
    }
    // เคลียร์หนี้ (settlements)
    foreach ($snap['settles'] as $st) {
        $from = (int) $st['from_user']; $to = (int) $st['to_user']; $amt = (float) $st['amount'];
        if ($from === $myMember && $to !== $myMember) { $f = $touch($net, $to);   $net[$f]['settle'] += $amt; } // เราจ่ายคืนเพื่อน -> ลดที่เราติด
        if ($to === $myMember && $from !== $myMember) { $f = $touch($net, $from); $net[$f]['settle'] -= $amt; } // เพื่อนจ่ายคืนเรา -> ลดที่เพื่อนติด
    }
    // เงินเพื่อนที่ถือไว้ (holdings)
    foreach ($snap['holds'] as $h) {
        $hd = (int) $h['holder_id']; $ow = (int) $h['owner_id']; $amt = (float) $h['amount'];
        if ($hd === $myMember && $ow !== $myMember) { $f = $touch($net, $ow); $net[$f]['holding'] -= $amt; } // เราถือเงินเพื่อน -> เราติดเพื่อน
        if ($ow === $myMember && $hd !== $myMember) { $f = $touch($net, $hd); $net[$f]['holding'] += $amt; } // เพื่อนถือเงินเรา -> เพื่อนติดเรา
    }
    // ผ่อนรายเดือน (installments) -> นับเฉพาะงวดที่ "ถึงกำหนดแล้ว" ตาม start_date
    $plans = $snap['plans'];
    if ($plans) {
        $paid = ledger_paid_by_plan($snap);
        foreach ($plans as $p) {
            $pd     = $paid[(int) $p['id']] ?? 0;
            $remain = installment_due_outstanding($p, $pd); // เฉพาะงวดที่ครบกำหนด
            $payer = (int) $p['payer_id']; $payee = (int) $p['payee_id'];
            if ($payee === $myMember && $payer !== $myMember) { $f = $touch($net, $payer); $net[$f]['installment'] += $remain; $net[$f]['inst_paid'] += $pd; } // เพื่อนผ่อนให้เรา (เก็บยอดที่เพื่อนจ่ายแล้ว)
            if ($payer === $myMember && $payee !== $myMember) { $f = $touch($net, $payee); $net[$f]['installment'] -= $remain; } // เราผ่อนให้เพื่อน
        }
    }

    if (empty($net)) return [];
    $names = member_names(array_keys($net));
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
 * คืน list เรียงใหม่ล่าสุดก่อน: [ts, icon, title, sub, impact, auto, note]
 *   impact > 0 = ทำให้ "เพื่อนติดเรา" มากขึ้น | impact < 0 = ทำให้ "เราติดเพื่อน" มากขึ้น
 *   auto  = true คือรายการที่ระบบคำนวณให้เอง (งวดผ่อนที่ถึงกำหนด) ไม่ใช่ธุรกรรมที่มีคนกดบันทึก
 *   note  = โน้ตของรายการ ใช้ติดป้ายว่ามาจากการกด "เคลียร์ยอด"
 *
 * ทุก sub ต้องจบด้วย "ผลที่เกิด" (เพื่อนติดเราเพิ่ม / เราติดเพื่อนเพิ่ม / หนี้ใครลดลง)
 * เพราะเครื่องหมาย +/− เป็นผลต่อ "ยอดหนี้" ไม่ใช่ทิศทางเงินสด — ถ้าไม่บอกจะอ่านกลับด้าน
 */
function friend_timeline($myMember, $friendId) {
    $me = (int) $myMember; $fr = (int) $friendId;
    if ($me <= 0 || $fr <= 0 || $me === $fr) return [];
    $ev   = [];
    $snap = ledger_snapshot($me);   // ใช้ข้อมูลชุดเดียวกับหน้าอื่น แล้วกรองเอาเฉพาะคู่นี้

    // (1) บิลที่เราจ่ายก่อน + เพื่อนมีส่วนหาร -> เพื่อนติดเรา
    foreach ($snap['paid'] as $e) {
        foreach (($e['expense_splits'] ?? []) as $s) {
            if ((int) $s['user_id'] !== $fr) continue;
            $ev[] = ['ts' => $e['spent_at'] ?? $e['created_at'] ?? '', 'icon' => 'receipt', 'title' => 'บิล: ' . ($e['title'] ?? '-'),
                     'sub' => 'เราจ่ายก่อน · เพื่อนติดเราเพิ่ม', 'impact' => (float) $s['amount']];
        }
    }
    // (2) บิลที่เพื่อนจ่ายก่อน + เรามีส่วนหาร -> เราติดเพื่อน
    foreach ($snap['shares'] as $s) {
        if ((int) ($s['expenses']['paid_by'] ?? 0) !== $fr) continue;
        $ev[] = ['ts' => $s['expenses']['spent_at'] ?? $s['expenses']['created_at'] ?? '', 'icon' => 'receipt', 'title' => 'บิล: ' . ($s['expenses']['title'] ?? '-'),
                 'sub' => 'เพื่อนจ่ายก่อน · เราติดเพื่อนเพิ่ม', 'impact' => -(float) $s['amount']];
    }
    // (3) เคลียร์หนี้ (settlements) — เอาเฉพาะที่คู่กับเพื่อนคนนี้
    foreach ($snap['settles'] as $st) {
        $f = (int) $st['from_user']; $t = (int) $st['to_user'];
        if (!(($f === $me && $t === $fr) || ($f === $fr && $t === $me))) continue;
        $amt = (float) $st['amount']; $iPaid = $f === $me;
        $ev[] = ['ts' => $st['created_at'] ?? '', 'icon' => 'arrow-right-left',
                 'title' => $iPaid ? 'เราโอนคืนเพื่อน' : 'เพื่อนโอนคืนเรา',
                 'sub'   => $iPaid ? 'หนี้ที่เราติดเพื่อนลดลง' : 'หนี้ที่เพื่อนติดเราลดลง',
                 'impact' => $iPaid ? $amt : -$amt, 'note' => $st['note'] ?? ''];
    }
    // (4) เงินที่ถือไว้ (holdings) — เอาเฉพาะที่คู่กับเพื่อนคนนี้
    foreach ($snap['holds'] as $h) {
        $hd = (int) $h['holder_id']; $ow = (int) $h['owner_id'];
        if (!(($hd === $me && $ow === $fr) || ($hd === $fr && $ow === $me))) continue;
        $amt = (float) $h['amount']; $weHold = $hd === $me;
        // เราถือเงินเพื่อน (amt>0) = เราติดเพื่อน (impact -) ; เพื่อนถือเงินเรา = เพื่อนติดเรา (impact +)
        $impact = $weHold ? -$amt : $amt;
        // ชื่อรายการบอกไปเลยว่าเกิดอะไร (เดิมทุกแถวชื่อ "เงินที่ถือไว้" เหมือนกันหมด 4 ความหมาย)
        [$title, $sub] = $weHold
            ? ($amt >= 0 ? ['รับเงินเพื่อนมาถือ', 'เราติดเพื่อนเพิ่ม']   : ['คืนเงินให้เพื่อน', 'หนี้ที่เราติดเพื่อนลดลง'])
            : ($amt >= 0 ? ['เพื่อนถือเงินเราไว้', 'เพื่อนติดเราเพิ่ม'] : ['เพื่อนคืนเงินเรา', 'หนี้ที่เพื่อนติดเราลดลง']);
        $ev[] = ['ts' => $h['created_at'] ?? '', 'icon' => 'piggy-bank', 'title' => $title,
                 'sub' => $sub, 'impact' => $impact, 'note' => $h['note'] ?? ''];
    }
    // (5) ผ่อนรายเดือน: ครบกำหนดทีละงวด (ตาม start_date) + การจ่ายแต่ละงวด
    $plans = array_values(array_filter($snap['plans'], function ($p) use ($me, $fr) {
        $payer = (int) $p['payer_id']; $payee = (int) $p['payee_id'];
        return ($payee === $me && $payer === $fr) || ($payer === $me && $payee === $fr);
    }));
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
                // auto = ระบบตั้งยอดให้ตามรอบเดือน ไม่ใช่ธุรกรรมที่มีคนกดบันทึก
                $ev[] = ['ts' => $ts, 'icon' => 'calendar-clock', 'auto' => true,
                         'title' => 'ถึงกำหนดงวดที่ ' . ($n + 1) . '/' . (int) $p['months'] . ': ' . $p['title'],
                         'sub'   => $friendPays ? 'เพื่อนผ่อนให้เรา · เพื่อนติดเราเพิ่ม' : 'เราผ่อนให้เพื่อน · เราติดเพื่อนเพิ่ม',
                         'impact' => $friendPays ? $monthly : -$monthly];
            }
        }
        $payeeOf = [];
        foreach ($plans as $p) { $payeeOf[(int) $p['id']] = (int) $p['payee_id']; }
        foreach ($snap['payments'] as $pm) {
            $iid = (int) $pm['installment_id'];
            if (!isset($payeeOf[$iid])) continue;          // งวดของแผนที่ไม่เกี่ยวกับเพื่อนคนนี้
            $amt = (float) $pm['amount'];
            $friendPays = ($payeeOf[$iid] ?? 0) === $me;           // เพื่อนเป็นคนผ่อน -> จ่ายลดที่เพื่อนติดเรา (impact -)
            $ev[] = ['ts' => $pm['paid_at'] ?? '', 'icon' => 'banknote',
                     'title' => ($friendPays ? 'เพื่อนจ่ายงวด: ' : 'เราจ่ายงวด: ') . ($titleOf[$iid] ?? '-'),
                     'sub'   => $friendPays ? 'หนี้ที่เพื่อนติดเราลดลง' : 'หนี้ที่เราติดเพื่อนลดลง',
                     'impact' => $friendPays ? -$amt : $amt, 'note' => $pm['note'] ?? ''];
        }
    }

    usort($ev, fn($a, $z) => strcmp($z['ts'], $a['ts']));
    return $ev;
}

/** แถวนี้เกิดจากการกด "เคลียร์ยอด" ไหม (reconcile_with_friend ใส่ note ให้ทุกแถวที่มันสร้าง) */
function tl_is_reconcile($t) {
    return !empty($t['note']) && mb_strpos($t['note'], 'เคลียร์') !== false;
}
/** แถว "ส่วนต่างหลังเคลียร์" = ยอดที่ยกไปเป็นยอดตั้งต้นของรอบถัดไป */
function tl_is_carry($t) {
    return !empty($t['note']) && mb_strpos($t['note'], 'ส่วนต่าง') !== false;
}

/**
 * ตัดไทม์ไลน์เป็น "รอบ" — แต่ละรอบจบที่การกดเคลียร์ยอด 1 ครั้ง
 * ทำให้ยอดสะสมเริ่มนับใหม่ทุกรอบ แทนที่จะบวกยาวตั้งแต่ธุรกรรมแรกจนงง
 *
 * @return array เรียงรอบเก่า -> ใหม่ · รอบสุดท้ายคือรอบปัจจุบัน (closed_at = null)
 *   open        ยอดยกมาจากรอบก่อน
 *   items       รายการในรอบ เรียงเก่า -> ใหม่ (รวมแถวปิดยอด แต่ไม่รวมแถวส่วนต่าง)
 *   closed_at   เวลาที่ปิดรอบ (null = รอบปัจจุบัน)
 *   net_before  ยอดคงค้างก่อนกดเคลียร์
 *   pay         เงินที่จ่ายจริงตอนปิดรอบ
 *   payer       'friend' = เพื่อนจ่ายคืนเรา | 'me' = เราจ่ายคืนเพื่อน | null = ไม่มียอดต้องจ่าย
 *   carry       ส่วนต่างที่ยกไปรอบถัดไป
 *
 * ยอดที่จ่ายไม่ได้ถูกเก็บเป็นคอลัมน์ในฐานข้อมูล แต่ถอดกลับได้จากนิยามของ
 * reconcile_with_friend(): residual = net − sign(net) × pay  =>  pay = |net − residual|
 */
function tl_split_rounds($timeline) {
    $asc    = array_reverse($timeline);          // เก่า -> ใหม่
    $rounds = [];
    $blank  = ['open' => 0.0, 'items' => [], 'closed_at' => null,
               'net_before' => 0.0, 'pay' => 0.0, 'payer' => null, 'carry' => 0.0];
    $cur    = $blank;
    $run    = 0.0;                                // ยอดคงค้างสะสมภายในรอบ
    $i = 0; $n = count($asc);

    while ($i < $n) {
        if (!tl_is_reconcile($asc[$i])) {
            $run += round((float) $asc[$i]['impact'], 2);
            $cur['items'][] = $asc[$i];
            $i++;
            continue;
        }

        // เจอกลุ่มการเคลียร์ (หลายแถวเกิดพร้อมกัน) -> กินทั้งกลุ่มแล้วปิดรอบ
        $netBefore = round($run, 2);
        $carry     = 0.0;
        while ($i < $n && tl_is_reconcile($asc[$i])) {
            if (tl_is_carry($asc[$i])) {
                $carry += (float) $asc[$i]['impact'];        // ยกไปรอบหน้า
            } else {
                $run += round((float) $asc[$i]['impact'], 2); // แถวปิดยอดของรอบนี้
                $cur['items'][] = $asc[$i];
            }
            $i++;
        }
        $carry = round($carry, 2);

        $cur['closed_at']  = $asc[$i - 1]['ts'] ?? null;
        $cur['net_before'] = $netBefore;
        $cur['carry']      = $carry;
        $cur['pay']        = round(abs($netBefore - $carry), 2);
        $cur['payer']      = abs($netBefore) < 0.009 ? null : ($netBefore > 0 ? 'friend' : 'me');

        $rounds[] = $cur;
        $cur = $blank;
        $cur['open'] = $carry;
        $run = $carry;
    }
    $rounds[] = $cur;                             // รอบปัจจุบัน (ยังไม่ปิด)
    return $rounds;
}

/**
 * รายการที่ "ประกอบเป็นยอดคงค้างตอนนี้" = เฉพาะรอบปัจจุบัน + ยอดที่ยกมาจากรอบก่อน
 * ใช้กับ modal เคลียร์หนี้ — เดิมส่งไทม์ไลน์ทั้งหมดไป ทำให้โชว์รายการที่เคลียร์ไปแล้วปนมาด้วย
 * @return array เรียงใหม่ -> เก่า (รูปแบบเดียวกับ friend_timeline)
 */
function friend_open_items($timeline) {
    $rounds = tl_split_rounds($timeline);
    $cur    = end($rounds) ?: ['open' => 0.0, 'items' => []];
    $items  = array_reverse($cur['items']);       // ใหม่ -> เก่า

    if (abs((float) $cur['open']) > 0.009) {
        $items[] = ['ts' => '', 'icon' => 'corner-down-right', 'title' => 'ยกยอดมาจากรอบก่อน',
                    'sub' => 'คงค้างหลังเคลียร์ครั้งล่าสุด', 'impact' => (float) $cur['open']];
    }
    return $items;
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
