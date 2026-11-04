# วิธีส่งอีเมลออกจาก Laravel ผ่าน Gmail — transport options ทั้งหมด

- **label:** `wayfinder:research`
- **type:** AFK
- **status:** closed *(2026-09-11 — research subagent, verify กับ official Google pages)*
- **blocked-by:** —
- **assignee:** (ว่าง)

## Question

ระบบ (Laravel 13, API-only, queue driver `database` มี worker อยู่แล้ว) ต้องการส่งอีเมลออก (transactional) โดยผู้ใช้ระบุ "ผ่าน Gmail" — research ทางเลือกให้ครบพร้อมข้อจำกัดจริง:

- **ทางเลือก A — Gmail SMTP + App Password:** ข้อกำหนด (บัญชีต้องเปิด 2-Step Verification, สร้าง App Password 16 ตัว), config ใน Laravel (`MAIL_MAILER=smtp`, host/port `smtp.gmail.com:587` STARTTLS หรือ `465`), quota จริง (consumer ~500 mail/วัน · Google Workspace ~2,000 mail/วัน), ความเสี่ยง (App Password = password จริงใน `.env`, การ rotate)
- **ทางเลือก B — Gmail API (`google/apiclient`):** ส่งผ่าน API (`users.messages.send`) หรือ SMTP XOAUTH2 — ต้องมี OAuth refresh token (flow การขอ offline access ครั้งเดียว), quota/quota-units จริง, ต้นทุน dependency (package ใหญ่แค่ไหน), ขัด standing decision "0 package" ของ auth หรือไม่ (คนละ domain กับ auth — ตัดสินแยกได้)
- **ทางเลือก C — Google Workspace SMTP relay** (`aspmx.l.google.com`): ข้อกำหนด (ต้องมี Workspace domain + static IP ฝั่ง server), เหมาะกับการส่งจาก address `@ku.ac.th` หรือไม่
- **การส่งจาก address `@ku.ac.th` ผ่าน Gmail:** เรื่อง "Send mail as" alias (ต้อง verify + มี SMTP ของ domain นั้น), SPF/DKIM/DMARC ของ domain ปลายทางจะตีเมลที่ส่งในนาม `ku.ac.th` จาก Gmail เป็น spam ไหม — ข้อจำกัดที่ทำให้ "ส่งจาก gmail.com ตรงๆ" เป็นทางที่ realistic ที่สุดหรือเปล่า
- Config จริงใน Laravel 13: `.env` template ครบทุกทางเลือก, `config/mail.php` (Symfony mailer transports), การใช้ `Mail::queue` กับ queue driver `database` ที่มีอยู่, การจัดการ fail (retry/failed jobs)
- เปรียบเทียบสั้นๆ เพื่อ context (ไม่ใช่ขอเปลี่ยน scope): SES/Mailgun/Postmark free tier — เพื่อให้ owner เห็นว่า Gmail เหมาะกับ volume เท่าไรและเมื่อไรควรย้าย

## Resolution (2026-09-11)

ดูรายงานเต็ม: [../research/gmail-transport-options.md](../research/gmail-transport-options.md)

- **แนะนำ v1: Option A — Gmail SMTP + App Password** (`MAIL_MAILER=smtp` · `smtp.gmail.com:587` STARTTLS) — **0 new package** (`symfony/mailer` มากับ framework อยู่แล้ว) · ส่งผ่าน `Mail::queue` บน database queue ที่มีอยู่ + `afterCommit()` (booking flows ครอบ transaction) + `$tries=3` + `failed()` logging → lockout ไหลลง `failed_jobs` ไม่ยิงซ้ำ
- **ข้อกำหนด:** บัญชี Gmail ต้องเปิด 2-Step Verification แล้วสร้าง App Password 16 ตัว (เก็บใน `.env` ถือเป็น credential จริง — rotate ได้)
- **Limits จริง:** consumer ~500 เมล/วัน **และ** ~500 recipients/เมล (นับทั้งคู่) · เกิน = **lockout ชั่วคราว 1–24 ชม.** ไม่ใช่ ban ถาวร · Workspace 2,000/วัน · **Gmail API ไม่ได้เพิ่ม quota การส่งเลย** (option B ต่างกันที่ mechanics ไม่ใช่ limit)
- ❗ **Decisive constraint — ส่งจาก `@ku.ac.th` ผ่าน Gmail ปกติไม่ realistic:** โดน DMARC misalignment ตาม sender guidelines ของ Google เอง (spam/reject `5.7.26`) · "Send mail as" ต้องมี SMTP credentials ของ domain ปลายทางอยู่ดี · **และ Google จะ deprecate "Send as" third-party address เดือน ม.ค. 2027** → **v1 ใช้ gmail.com sender จริง** (เช่น `kuhome.noreply@gmail.com`) + `Reply-To` เป็น address KU
- ทางที่ถูกต้องระยะยาวคือ **Option C — Workspace SMTP relay ของ domain `ku.ac.th`** (10,000 เมล/วัน) แต่ต้องมีสิทธิ์ Workspace admin ซึ่งทีมไม่ได้ควบคุม — จดไว้เป็นเส้นทางอนาคต
- Service account ส่งแทน user Gmail ปกติ **ไม่ได้** (ต้อง Workspace domain-wide delegation, super-admin only)
- ตัดสิน use case/sender/volume ต่อใน [ticket 04](./04-email-use-cases-and-sender-identity.md) · ตั้งค่าบัญชีจริง → [ticket 07](./07-gmail-sender-account-setup.md)
