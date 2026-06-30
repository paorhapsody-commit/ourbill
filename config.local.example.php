<?php
/* =========================================================
 *  ตัวอย่าง config.local.php — คัดลอกไฟล์นี้เป็น config.local.php
 *  แล้วใส่ค่าจริง (ไฟล์ config.local.php จะไม่ถูก commit ขึ้น git)
 *
 *  หรือบนเซิร์ฟเวอร์ ไม่ต้องมีไฟล์นี้ก็ได้ — ตั้งเป็น Environment Variable แทน:
 *    SUPABASE_URL=https://xxxx.supabase.co
 *    SUPABASE_KEY=eyJhbGci... (anon key)
 * ========================================================= */

define('LOCAL_SUPABASE_URL', 'https://YOUR-PROJECT.supabase.co');
define('LOCAL_SUPABASE_KEY', 'YOUR_SUPABASE_ANON_KEY');
