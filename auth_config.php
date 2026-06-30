<?php
/* =========================================================
 *  Google OAuth — ตั้งค่าตรงนี้
 * ---------------------------------------------------------
 *  วิธีขอ Client ID / Secret:
 *  1. ไปที่ https://console.cloud.google.com/ > APIs & Services > Credentials
 *  2. Create Credentials > OAuth client ID > Web application
 *  3. Authorized redirect URIs ใส่ให้ตรงกับ GOOGLE_REDIRECT_URI ด้านล่างเป๊ะ ๆ
 *  4. คัดลอก Client ID และ Client Secret มาวางแทนค่าด้านล่าง
 *  5. ที่ OAuth consent screen เพิ่มอีเมลตัวเองใน Test users (ถ้ายังไม่ publish)
 * ========================================================= */

define('GOOGLE_CLIENT_ID',     'YOUR_CLIENT_ID.apps.googleusercontent.com');
define('GOOGLE_CLIENT_SECRET', 'YOUR_CLIENT_SECRET');
define('GOOGLE_REDIRECT_URI',  'http://localhost/ourbill/callback.php');

/* หมายเหตุ: ค่าด้านบนเป็นเพียง "ค่า fallback" เท่านั้น
 * แนะนำให้ตั้ง Client ID / Secret / Redirect URI จากแผงแอดมิน (admin.php) ซึ่งเก็บใน DB
 *
 * การอนุญาตผู้ใช้ใช้ระบบ "อนุมัติบัญชี" จากแผงแอดมินแล้ว (ไม่ใช้ allowlist อีเมลอีกต่อไป)
 * ทุกคนล็อกอิน Google ได้ -> ขึ้นสถานะรออนุมัติ -> แอดมินกดอนุมัติจึงใช้งานได้ */
