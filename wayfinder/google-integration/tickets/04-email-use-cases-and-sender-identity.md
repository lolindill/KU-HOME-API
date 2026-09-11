# ระบบต้องส่งอีเมลอะไรบ้าง + ส่งจาก identity ไหน

- **label:** `wayfinder:grilling`
- **type:** HITL
- **status:** open
- **blocked-by:** 03-gmail-transport-options
- **assignee:** (ว่าง)

## Question

คุยกับ owner ให้ตัดสินใจเป็นข้อๆ (ใช้ข้อมูล quota/constraint จาก ticket 03 ประกอบ):

- **Use case ที่ต้องส่งจริงมีอะไรบ้างใน v1** — เช่น ยืนยันสลิป (verify/reject), แจ้งเตือน check-in, ยืนยันการจอง, password reset (อยู่นอก v1 ตาม ku-sso ticket 09 — กลับมาได้ไหมถ้ามีเมลแล้ว) — แต่ละอย่าง trigger จาก state machine ไหน
- **Volume โดยประมาณ** (จอง/วัน ในระดับที่ Gmail consumer 500 ใบ/วัน พอไหม) — ถ้าเกิน จะ cap หรือเปลี่ยน transport
- **Sender identity:** ส่งจาก `noreply@...` อะไร — gmail.com ส่วนตัว/ของโครงการ (ง่ายแต่หน้าตาไม่เป็นทางการ), `@ku.ac.th` ผ่าน Gmail alias (ผูกกับข้อจำกัด SPF/DKIM ใน research 03), หรือ Workspace
- **Reply-To และภาษา/รูปแบบเมล** (ไทย+emoji ตามธรรมเนียมโค้ด หรือเปล่า) — และใครเป็นเจ้าของ template ฝั่ง Laravel (Mailable class + markdown template ฝั่ง API — repo นี้ API-only, เมลเรนเดอร์ฝั่ง server ได้)
- ผลตัดสินควร lock: transport (A/B/C), sender address, รายการเมล v1 + จุด trigger ใน state machine
