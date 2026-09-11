# ตั้งค่า Gmail sender account จริง (App Password + .env + ยิงเมลทดสอบ)

- **label:** `wayfinder:task`
- **type:** HITL
- **status:** open
- **blocked-by:** 04-email-use-cases-and-sender-identity
- **assignee:** (ว่าง)

## Question

งานมือหลัง ticket 04 ตัดสิน transport (คาดหมาย = Option A: Gmail SMTP + App Password ตาม research 03):

- เตรียมบัญชี Gmail sender ที่ตกลงใน ticket 04 (เช่น `kuhome.noreply@gmail.com`) — เปิด 2-Step Verification → สร้าง **App Password 16 ตัว**
- ลง `.env`: `MAIL_MAILER=smtp`, `MAIL_HOST=smtp.gmail.com`, `MAIL_PORT=587`, `MAIL_USERNAME=<address>`, `MAIL_PASSWORD=<app password>`, `MAIL_FROM_ADDRESS/NAME` + restart `queue:listen` (config cache)
- ยิงเมลจริง 1 ฉบับผ่าน `php artisan tinker` (`Mail::raw`) ยืนยัน inbox ถึงจริง
- จดลง Resolution: ที่อยู่บัญชี, วิธี rotate App Password, ข้อจำกัด 500 ใบ/วันที่ต้องจำ — ผูกกับ use case ที่ ticket 04 ตัดสิน
