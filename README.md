# OurBill — ระบบหารเงินกลุ่มเพื่อน 💸

แอปบันทึกค่าใช้จ่ายกลุ่ม สำหรับเวลาเพื่อนสำรองจ่ายไปก่อน แล้วนานๆ ไปลืมว่าใครติดใครเท่าไหร่
ระบบช่วยจำและสรุปให้ว่า **ใครต้องโอนคืนให้ใคร เท่าไหร่** ด้วยจำนวนรายการน้อยที่สุด

## เทคโนโลยี
- **PHP** (ฝั่งหน้าเว็บ + เรียก REST API)
- **Supabase** (PostgreSQL) เป็นฐานข้อมูล
- **Tailwind CSS** (CDN) + ไอคอน **Lucide** — ธีม Emerald/Teal

## โครงสร้างไฟล์
| ไฟล์ | หน้าที่ |
|------|---------|
| `config.php` | ค่า Supabase + helper (เรียก API, คำนวณหาร, คำนวณเคลียร์หนี้, avatar) |
| `_layout.php` | เลย์เอาต์กลาง (navbar บน/ล่าง + footer) ของทุกหน้า |
| `split-editor.js` | ตัวคำนวณการหารบิลในฟอร์ม (หารเท่ากัน/กำหนดเอง) |
| `index.php` | หน้าหลัก — ดุลเงินแต่ละคน + วิธีเคลียร์หนี้ที่แนะนำ + รายจ่ายล่าสุด |
| `add-expense.php` | เพิ่มรายจ่าย (หารเท่ากัน หรือกำหนดยอดต่อคนเอง) |
| `expense.php` | รายละเอียดบิล + แก้ไข + ลบ |
| `settle.php` | เคลียร์หนี้ — แนะนำวิธีโอน + บันทึกว่าโอนแล้ว + ประวัติ |
| `friends.php` | เพื่อนของฉัน — ค้นหา/ส่งคำขอ/ตอบรับ (friend request สองทาง) |
| `friend_search.php` | endpoint ค้นหาผู้ใช้ (autocomplete) คืน JSON |
| `auth.php` | session + Google OAuth + แอดมิน (Supabase Auth) helpers |
| `auth_config.php` | ตั้งค่า Google Client ID/Secret + allowlist อีเมล (fallback) |
| `login.php` / `callback.php` / `logout.php` | เข้าสู่ระบบผู้ใช้ / รับ callback Google / ออกจากระบบ |
| `admin_login.php` / `admin.php` | เข้าสู่ระบบแอดมิน + แผงตั้งค่าระบบ |
| `schema.sql` | สคีมาฐานข้อมูล + ตาราง `settings` + RLS policy (รันใน Supabase SQL Editor) |

## ติดตั้ง
1. สร้างโปรเจกต์ Supabase แล้วรัน `schema.sql` **ทั้งไฟล์** ใน SQL Editor
   - สร้างตาราง + View + ตั้งค่า **RLS policy ให้ anon key เข้าถึงได้**
   - ถ้าไม่รันส่วน RLS แอปจะอ่านได้ค่าว่างและบันทึกไม่ได้ (error 42501)
2. ตั้งค่าความลับ Supabase (ไม่เก็บในโค้ด/ไม่ขึ้น git):
   - **เครื่อง local:** คัดลอก `config.local.example.php` → `config.local.php` แล้วใส่ค่าจริง
   - **บนเซิร์ฟเวอร์/Git:** ตั้งเป็น Environment Variable `SUPABASE_URL`, `SUPABASE_KEY` (ค่า ENV ถูกใช้ก่อนไฟล์ local เสมอ)
3. วางโฟลเดอร์ไว้ใน `htdocs` ของ XAMPP แล้วเปิด `http://localhost/ourbill/`

> **ความลับไม่ขึ้น git:** `config.local.php` ถูก `.gitignore` ไว้ · ส่วน Google OAuth (Client ID/Secret) เก็บในฐานข้อมูล (ตั้งผ่านแผงแอดมิน) ไม่ได้อยู่ในไฟล์

## Deploy ขึ้น Render (Docker)
มี `Dockerfile` + `render.yaml` ให้แล้ว (PHP 8.2 + Apache)

1. Render Dashboard → **New → Web Service** → เชื่อม GitHub repo นี้ (Render จะตรวจเจอ `Dockerfile` เอง) เลือก **Free plan**
2. ใส่ **Environment Variables**: `SUPABASE_URL`, `SUPABASE_KEY` (anon key)
3. Deploy เสร็จจะได้โดเมน `https://<ชื่อ>.onrender.com`
4. **Google Console** → Authorized redirect URIs เพิ่ม: `https://<ชื่อ>.onrender.com/callback.php`
   - ระบบ auto-detect redirect URI จากโดเมนปัจจุบันให้แล้ว (ดูค่าได้ที่แผงแอดมิน) — ไม่ต้องแก้ในโค้ด
5. เปิดใช้งานได้เลย — แอดมินล็อกอินที่ `/admin_login.php` เพื่ออนุมัติบัญชี

> Render free จะ sleep เมื่อไม่มีคนใช้ (เปิดครั้งแรกหลัง sleep จะช้า ~30 วิ) · session หายเมื่อ instance restart (ล็อกอินใหม่ได้)
> ต้องใช้ HTTPS เท่านั้นกับ Google OAuth — Render ให้ SSL ฟรีอยู่แล้ว

## ระบบล็อกอินด้วย Google
ทุกหน้าต้องล็อกอินก่อน (เรียก `require_login()`) เข้าได้เฉพาะอีเมลใน allowlist

ตั้งค่าใน `auth_config.php`:
1. สร้าง OAuth Client ID (Web application) ที่ [Google Cloud Console](https://console.cloud.google.com/) → APIs & Services → Credentials
2. **Authorized redirect URIs** ใส่ `http://localhost/ourbill/callback.php` (ต้องตรงกับ `GOOGLE_REDIRECT_URI` เป๊ะ ๆ)
3. วาง `GOOGLE_CLIENT_ID` และ `GOOGLE_CLIENT_SECRET`
4. ใส่อีเมล Google ของสมาชิกใน `$GOOGLE_ALLOWED_EMAILS` (ถ้าว่าง = เข้าไม่ได้เลย เพื่อความปลอดภัย)
5. ที่ OAuth consent screen เพิ่มอีเมลใน **Test users** (ถ้ายังไม่ publish แอป)

> หมายเหตุ: ผู้ใช้ทั่วไปยืนยันตัวตนผ่าน Google OAuth (ฝั่ง PHP) ส่วนข้อมูลใน DB เข้าผ่าน anon key

## แผงผู้ดูแลระบบ (Admin)
แอดมินล็อกอินด้วย **Supabase Auth (อีเมล/รหัสผ่าน)** ที่สร้างไว้ใน Supabase Dashboard → Authentication → Users

- เข้าที่ `admin_login.php` (มีลิงก์ "เข้าสู่ระบบผู้ดูแล" ที่หน้า login ผู้ใช้)
- ใครก็ตามที่ล็อกอิน Supabase Auth สำเร็จ = แอดมิน (เพราะมีแต่บัญชีแอดมินใน Auth)
- แผง `admin.php` ตั้งค่าได้จาก backend (เก็บในตาราง `settings`):
  - **ชื่อแอป / คำโปรย** — แสดงผลทั่วทั้งระบบ
  - **Google OAuth** — Client ID / Client Secret / Redirect URI (ไม่ต้องแก้ไฟล์)
  - **อนุมัติบัญชีผู้ใช้** — ดูรายชื่อคนที่ล็อกอินเข้ามา แล้วกด อนุมัติ / ยกเลิก / บล็อก / ลบ
- การเขียน `settings` และเปลี่ยนสถานะบัญชี ใช้ token แอดมิน (role `authenticated`) — RLS ให้ `anon` อ่านได้อย่างเดียว
- ลำดับการอ่านค่า Google: ตาราง `settings` ก่อน → ถ้าว่าง fallback ไปค่าคงที่ใน `auth_config.php`

### โมเดลการเข้าใช้งาน (อนุมัติบัญชี)
1. ทุกคนล็อกอิน Google ได้ → ครั้งแรกระบบสร้างบัญชีสถานะ **pending** อัตโนมัติ (ตาราง `app_accounts`)
2. ผู้ใช้เห็นข้อความ "รออนุมัติ" จนกว่าแอดมินจะกด **อนุมัติ**
3. RLS: `anon` เพิ่มบัญชีได้เฉพาะสถานะ `pending` เท่านั้น (กันสร้างบัญชี approved เอง), เปลี่ยนสถานะได้เฉพาะแอดมิน

> ต้อง **รัน `schema.sql` ใหม่อีกครั้ง** เพื่อสร้างตาราง `settings` และ `app_accounts`
> (ใช้ `IF NOT EXISTS`/`ON CONFLICT` รันซ้ำได้ปลอดภัย ไม่กระทบข้อมูลเดิม)

## ระบบเพื่อน (Friend request)
- พอล็อกอิน (อนุมัติแล้ว) ระบบสร้าง "สมาชิก" (`users`) ผูกกับบัญชีอัตโนมัติ (`users.account_id`)
- หน้า **เพื่อนของฉัน**: ค้นหาคนด้วยอีเมล/ชื่อ → ส่งคำขอ → อีกฝ่ายตอบรับ (ตาราง `friendships`)
- ตอน **เพิ่มรายจ่าย** เลือกได้เฉพาะ **ตัวเอง + เพื่อนที่ตอบรับแล้ว** (`selectable_members()`)
- หน้าหลักแสดงเฉพาะคนที่มีกิจกรรมจริง (ซ่อนสมาชิกที่เพิ่งล็อกอินแต่ยังไม่มีบิล)

## เงินเพื่อนที่ถือไว้ (Holdings)
- หน้า **เงินเพื่อน** (`holdings.php`) บันทึกว่าถือเงินของเพื่อนคนไหนไว้เท่าไหร่ (กันลืมคืน)
- เพิ่มได้ทั้ง "รับเงินมาถือ" (+) และ "คืนเงินไป" (−) · มีปุ่ม "คืนครบ" ต่อคน
- สรุปยอดรวม + แยกตามเพื่อน + แสดงเงินของเราที่ฝากไว้กับเพื่อน (มุมกลับ) + ประวัติ
- การ์ดสรุปบนหน้าหลักด้วย · เก็บในตาราง `holdings` (แยกจากระบบหารบิล)

## ผ่อนรายเดือน (Installments)
- หน้า **ผ่อนรายเดือน** (`installments.php`, แท็บในหน้า "เงินเพื่อน") สำหรับเพื่อนที่ผ่อนจ่ายให้เรา
- สร้างแผน: เพื่อน + ชื่อรายการ + ยอดต่อเดือน + จำนวนเดือน → คำนวณยอดรวม/คงเหลือ/แถบความคืบหน้า
- บันทึกจ่ายแต่ละงวด 2 แบบ: **จ่ายสด/โอน** หรือ **หักจากเงินที่จ่ายไว้ก่อน** (ดึงจากยอด holdings ของเพื่อนคนนั้น แล้วลด holdings ให้อัตโนมัติ)
- เก็บใน `installments` + `installment_payments`

## แนบรูปใบเสร็จ
- ตอนเพิ่ม/แก้รายจ่าย แนบรูปได้ (ไม่บังคับ, ≤ 5MB, JPG/PNG/WEBP/GIF)
- **บีบขนาดรูปอัตโนมัติฝั่ง browser** (ย่อด้านยาวสุด ≤ 1280px, JPEG ~70%) ก่อนอัป — ประหยัดพื้นที่/เน็ต (`image-compress.js`)
- เก็บไฟล์บน **Supabase Storage** bucket `receipts` (public) แล้วเก็บ URL ในคอลัมน์ `expenses.receipt_url`
- แสดงรูปในหน้ารายละเอียดบิล + thumbnail ในรายการล่าสุดหน้าหลัก · แก้ไขเปลี่ยน/ลบรูปได้
- ⚠️ ต้องรัน `schema.sql` ส่วน Storage เพื่อสร้าง bucket `receipts` + policy (anon อัปโหลด/อ่านได้)

## หลักการคำนวณ
- **ดุลสุทธิ** ของแต่ละคน = เงินที่สำรองจ่าย − ส่วนที่ต้องหาร + ที่จ่ายหนี้คืนแล้ว − ที่รับเงินคืนแล้ว
  - ค่าบวก = เป็นเจ้าหนี้ (รอรับคืน), ค่าลบ = เป็นลูกหนี้ (ต้องจ่าย)
- **เคลียร์หนี้** ใช้ greedy จับลูกหนี้ยอดมากสุดกับเจ้าหนี้ยอดมากสุด เพื่อให้โอนน้อยครั้งที่สุด
- **หารเศษไม่ลงตัว** ปัดลง 2 ตำแหน่ง แล้วโยนเศษที่เหลือให้คนแรก เพื่อให้ผลรวมตรงยอดบิลเป๊ะ
