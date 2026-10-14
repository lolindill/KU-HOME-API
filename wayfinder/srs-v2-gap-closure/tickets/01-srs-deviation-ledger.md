# 01: SRS deviation ledger — จด REQ ที่ตัดสิน "ไม่ทำ" ให้ครบที่เดียว

- **label:** `wayfinder:task`
- **type:** AFK
- **status:** open
- **blocked-by:** (ไม่มี — frontier)
- **assignee:** agent-hub:antigravity/gemini-3.8-flash (manager dispatch 2026-10-14)
- **summary:** เขียน SRS v2 deviation ledger 5 ข้อ + interpretation 2 ข้อลง cline.md พร้อม pointer ใน AGENTS.md
- **phase:** 0

## Question

SRS v2 = acceptance contract (owner 2026-10-14) แต่มี REQ ที่ตัดสินใจแล้วว่า**ไม่ทำ** (deviation) หรือ**ถือว่าของเดิมครอบคลุม** (interpretation) — ปัจจุบันยังไม่มีที่ไหนจดรวม ทำให้ acceptance/UAT ย้อนหลังไม่ได้ จะจดที่ไหน รูปแบบไหน ให้ future agent และผู้ตรวจสอบเห็นตรงกัน?

**สิ่งที่ล็อกแล้ว (ห้ามเปลี่ยน — งานนี้คือจด ไม่ใช่ตัดสินใหม่):**

- Deviation 5 ข้อ:
  1. REQ-009/040/041 เมลยืนยันอัตโนมัติ 2 formats — frontend gen + admin ส่งเอง (freeze 06/10/26)
  2. REQ-020 quotation/ใบ confirm/Guest Folio — เหตุผลเดียวกัน ไม่มี document endpoint ฝั่ง backend
  3. REQ-049 หัวหน้าแม่บ้าน Approve/Reject — ชน state machine + ไม่มี role รอโครงสร้างทีม
  4. REQ-038 เปลี่ยนราคาอนาคต — ไม่ทำ + risk note (แก้ราคาทันทีกระทบ draft ราคาเก่า)
  5. REQ-010 QR จริง — ติด external gateway decision (จดเป็น "pending external" ไม่ใช่ปิดถาวร)
- Interpretation 2 ข้อ: REQ-045 (upgrade = เปลี่ยน room_type_id + ส่วนต่างเข้าค้างชำระ) · REQ-012 (draft + เลข confirm ตั้งแต่สร้าง)

**งาน:**

- เพิ่มหัวข้อใหม่ใน `cline.md` (เช่น "📋 SRS v2 deviation ledger 2026-10-14") — ต่อ REQ: ระบุ REQ id, สถานะ (deviation/interpretation/pending-external), เหตุผล, วันที่ตัดสิน, ทางกลับ (ถ้ามี)
- เพิ่ม pointer สั้นๆ ใน `AGENTS.md` (เช่น ใต้หัว Wayfinder maps หรือ Documents to read) ชี้มาที่ ledger ใน `cline.md` + map นี้
- ไม่แก้โค้ดใดๆ — commit เอกสารอย่างเดียว

**Done เมื่อ:** ledger ครบทั้ง 7 ข้อ (อ่านแล้วรู้ทันทีว่า REQ ไหน "งด" และเพราะอะไร) · suite ไม่ถูกแตะ · commit แล้ว

## Resolution

(ยังว่าง)
