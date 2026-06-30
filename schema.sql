-- =========================================================
--  OurBill / FairShare — Supabase (PostgreSQL) Schema
--  รันสคริปต์นี้ใน Supabase SQL Editor ครั้งเดียว
-- =========================================================

-- 1. ตารางเก็บรายชื่อเพื่อนในกลุ่ม
CREATE TABLE IF NOT EXISTS users (
    id SERIAL PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    created_at TIMESTAMP DEFAULT NOW()
);

-- 2. ตารางเก็บยอดใช้จ่ายหลัก (ใครเป็นคนสำรองจ่ายไปก่อน)
CREATE TABLE IF NOT EXISTS expenses (
    id SERIAL PRIMARY KEY,
    title VARCHAR(255) NOT NULL,
    total_amount DECIMAL(10, 2) NOT NULL,
    paid_by INT REFERENCES users(id) ON DELETE CASCADE,
    created_at TIMESTAMP DEFAULT NOW()
);

-- 3. ตารางบันทึกการกระจายหนี้ (แต่ละบิล ใครต้องช่วยรับผิดชอบเท่าไหร่)
--    เก็บ amount ต่อคน รองรับทั้งหารเท่ากันและหารไม่เท่ากัน
CREATE TABLE IF NOT EXISTS expense_splits (
    id SERIAL PRIMARY KEY,
    expense_id INT REFERENCES expenses(id) ON DELETE CASCADE,
    user_id INT REFERENCES users(id) ON DELETE CASCADE,
    amount DECIMAL(10, 2) NOT NULL
);

-- 4. ตารางบันทึกการเคลียร์หนี้ (ใครโอนเงินคืนให้ใครเท่าไหร่)
CREATE TABLE IF NOT EXISTS settlements (
    id SERIAL PRIMARY KEY,
    from_user INT REFERENCES users(id) ON DELETE CASCADE, -- คนจ่ายคืน
    to_user   INT REFERENCES users(id) ON DELETE CASCADE, -- คนรับเงินคืน
    amount DECIMAL(10, 2) NOT NULL,
    note VARCHAR(255),
    created_at TIMESTAMP DEFAULT NOW()
);

-- 5. View สรุปยอดเงินสุทธิของแต่ละคน (Net Balances)
--    balance = (สำรองจ่ายไป) - (ส่วนที่ต้องหาร) + (จ่ายหนี้คืนแล้ว) - (รับเงินคืนแล้ว)
--    balance > 0  => เป็นเจ้าหนี้ (รอรับเงินคืน)
--    balance < 0  => เป็นลูกหนี้ (ต้องจ่ายคืน)
CREATE OR REPLACE VIEW user_balances AS
WITH total_paid AS (
    SELECT paid_by AS user_id, COALESCE(SUM(total_amount), 0) AS total_spent
    FROM expenses GROUP BY paid_by
),
total_owed AS (
    SELECT user_id, COALESCE(SUM(amount), 0) AS total_must_pay
    FROM expense_splits GROUP BY user_id
),
settle_paid AS (
    SELECT from_user AS user_id, COALESCE(SUM(amount), 0) AS paid_back
    FROM settlements GROUP BY from_user
),
settle_recv AS (
    SELECT to_user AS user_id, COALESCE(SUM(amount), 0) AS got_back
    FROM settlements GROUP BY to_user
)
SELECT
    u.id,
    u.name,
    COALESCE(p.total_spent, 0)    AS paid_amount,
    COALESCE(o.total_must_pay, 0) AS owed_amount,
    COALESCE(sp.paid_back, 0)     AS settled_paid,
    COALESCE(sr.got_back, 0)      AS settled_received,
    (
        COALESCE(p.total_spent, 0)
        - COALESCE(o.total_must_pay, 0)
        + COALESCE(sp.paid_back, 0)
        - COALESCE(sr.got_back, 0)
    ) AS balance
FROM users u
LEFT JOIN total_paid  p  ON u.id = p.user_id
LEFT JOIN total_owed  o  ON u.id = o.user_id
LEFT JOIN settle_paid sp ON u.id = sp.user_id
LEFT JOIN settle_recv sr ON u.id = sr.user_id
ORDER BY u.id;

-- =========================================================
--  6. Row-Level Security (RLS)
--  Supabase เปิด RLS ให้ทุกตารางโดยค่าเริ่มต้น ถ้าไม่ใส่ policy
--  จะอ่านได้ค่าว่าง และเขียนไม่ได้ (error 42501)
--  แอปนี้เป็นกลุ่มเพื่อนปิด ใช้ anon key ร่วมกัน จึงเปิดสิทธิ์เต็มให้ anon
--  *** ถ้าต้องการความปลอดภัยมากขึ้น ให้เปลี่ยนเป็นล็อกอินผ่าน Supabase Auth ***
-- =========================================================
ALTER TABLE users          ENABLE ROW LEVEL SECURITY;
ALTER TABLE expenses       ENABLE ROW LEVEL SECURITY;
ALTER TABLE expense_splits ENABLE ROW LEVEL SECURITY;
ALTER TABLE settlements    ENABLE ROW LEVEL SECURITY;

DROP POLICY IF EXISTS allow_all ON users;
DROP POLICY IF EXISTS allow_all ON expenses;
DROP POLICY IF EXISTS allow_all ON expense_splits;
DROP POLICY IF EXISTS allow_all ON settlements;

CREATE POLICY allow_all ON users          FOR ALL TO anon, authenticated USING (true) WITH CHECK (true);
CREATE POLICY allow_all ON expenses        FOR ALL TO anon, authenticated USING (true) WITH CHECK (true);
CREATE POLICY allow_all ON expense_splits  FOR ALL TO anon, authenticated USING (true) WITH CHECK (true);
CREATE POLICY allow_all ON settlements     FOR ALL TO anon, authenticated USING (true) WITH CHECK (true);

-- =========================================================
--  7. ตารางตั้งค่าระบบ (key/value) — แก้ได้จากแผงแอดมิน
--     อ่าน: anon (แอปต้องอ่านค่าตั้งค่า) | เขียน: authenticated เท่านั้น (แอดมิน)
-- =========================================================
CREATE TABLE IF NOT EXISTS settings (
    key        TEXT PRIMARY KEY,
    value      TEXT,
    updated_at TIMESTAMP DEFAULT NOW()
);

ALTER TABLE settings ENABLE ROW LEVEL SECURITY;
DROP POLICY IF EXISTS settings_read  ON settings;
DROP POLICY IF EXISTS settings_write ON settings;
CREATE POLICY settings_read  ON settings FOR SELECT TO anon, authenticated USING (true);
CREATE POLICY settings_write ON settings FOR ALL    TO authenticated USING (true) WITH CHECK (true);

-- ค่าตั้งต้น
INSERT INTO settings (key, value) VALUES
    ('app_name',             'OurBill'),
    ('app_tagline',          'หารกันให้ชัด ไม่มีลืม'),
    ('google_client_id',     ''),
    ('google_client_secret', ''),
    ('google_redirect_uri',  '')
ON CONFLICT (key) DO NOTHING;

-- =========================================================
--  8. บัญชีผู้ใช้ที่ล็อกอิน Google (โมเดล "รออนุมัติ")
--     ทุกคนล็อกอินได้ -> สร้างแถว pending -> แอดมินอนุมัติจึงใช้งานได้
--     anon: อ่าน + เพิ่มได้เฉพาะสถานะ pending | authenticated(แอดมิน): ทำได้ทุกอย่าง
-- =========================================================
CREATE TABLE IF NOT EXISTS app_accounts (
    id         SERIAL PRIMARY KEY,
    sub        TEXT,
    email      TEXT UNIQUE NOT NULL,
    name       TEXT,
    picture    TEXT,
    status     TEXT NOT NULL DEFAULT 'pending',  -- pending | approved | blocked
    created_at TIMESTAMP DEFAULT NOW()
);

ALTER TABLE app_accounts ENABLE ROW LEVEL SECURITY;
DROP POLICY IF EXISTS acct_read   ON app_accounts;
DROP POLICY IF EXISTS acct_insert ON app_accounts;
DROP POLICY IF EXISTS acct_admin  ON app_accounts;
CREATE POLICY acct_read   ON app_accounts FOR SELECT TO anon, authenticated USING (true);
-- anon เพิ่มได้เฉพาะสถานะ pending (กันสร้างบัญชี approved เอง)
CREATE POLICY acct_insert ON app_accounts FOR INSERT TO anon, authenticated WITH CHECK (status = 'pending');
-- เปลี่ยนสถานะ/ลบ ได้เฉพาะแอดมิน (role authenticated)
CREATE POLICY acct_admin  ON app_accounts FOR ALL    TO authenticated USING (true) WITH CHECK (true);

-- =========================================================
--  9. ผูกสมาชิก (users) กับบัญชีล็อกอิน + ระบบเพื่อน (friend request)
-- =========================================================
-- สมาชิกในบิลแต่ละคน ผูกกับบัญชีล็อกอิน (สร้างอัตโนมัติตอนล็อกอินครั้งแรก)
ALTER TABLE users ADD COLUMN IF NOT EXISTS account_id INT REFERENCES app_accounts(id) ON DELETE CASCADE;

-- ความเป็นเพื่อน (ต้องตอบรับสองทาง)
CREATE TABLE IF NOT EXISTS friendships (
    id         SERIAL PRIMARY KEY,
    requester  INT REFERENCES app_accounts(id) ON DELETE CASCADE, -- คนส่งคำขอ
    addressee  INT REFERENCES app_accounts(id) ON DELETE CASCADE, -- คนรับคำขอ
    status     TEXT NOT NULL DEFAULT 'pending',                   -- pending | accepted
    created_at TIMESTAMP DEFAULT NOW(),
    UNIQUE (requester, addressee)
);

ALTER TABLE friendships ENABLE ROW LEVEL SECURITY;
DROP POLICY IF EXISTS allow_all ON friendships;
CREATE POLICY allow_all ON friendships FOR ALL TO anon, authenticated USING (true) WITH CHECK (true);

-- =========================================================
--  10. แนบรูปใบเสร็จในรายจ่าย (Supabase Storage)
-- =========================================================
ALTER TABLE expenses ADD COLUMN IF NOT EXISTS receipt_url TEXT;

-- สร้าง bucket "receipts" แบบ public (เปิดดูรูปผ่าน URL ได้)
INSERT INTO storage.buckets (id, name, public)
VALUES ('receipts', 'receipts', true)
ON CONFLICT (id) DO NOTHING;

-- อนุญาต anon อ่าน/อัปโหลดไฟล์ในบัคเก็ตนี้
DROP POLICY IF EXISTS receipts_read   ON storage.objects;
DROP POLICY IF EXISTS receipts_insert ON storage.objects;
CREATE POLICY receipts_read   ON storage.objects FOR SELECT TO anon, authenticated USING (bucket_id = 'receipts');
CREATE POLICY receipts_insert ON storage.objects FOR INSERT TO anon, authenticated WITH CHECK (bucket_id = 'receipts');
