# 01: SRS deviation ledger — จด REQ ที่ตัดสิน "ไม่ทำ" ให้ครบที่เดียว

- **label:** `wayfinder:task`
- **type:** AFK
- **status:** closed
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

- ได้บันทึก **SRS v2 deviation ledger (2026-10-14)** ครบถ้วนทั้ง 7 ข้อลงใน [`cline.md`](file:///c:/dev/hotel/cline.md#📋-srs-v2-deviation-ledger-2026-10-14) โดยระบุ REQ ID, สถานะ, วันที่ตัดสินใจ, เหตุผลโดยละเอียด และทางกลับ (พร้อม Risk note สำหรับ REQ-038):
  1. **REQ-009 / REQ-040 / REQ-041** (สถานะ: `deviation` · วันที่: 2026-10-06): อีเมลยืนยันอัตโนมัติ 2 รูปแบบ (Customer & Admin) — frontend senior gen เอกสารอยู่แล้ว + admin ส่งเองตามช่องทางปฏิบัติจริง (LINE/FB/เคาน์เตอร์); ระบบเมล backend ถูก freeze ไว้ที่ `google-integration` (tickets 04/07/08)
  2. **REQ-020** (สถานะ: `deviation` · วันที่: 2026-10-06): การออกเอกสาร Quotation / ใบยืนยัน / Guest Folio — frontend render เองโดยตรง ไม่มี document generation endpoint ฝั่ง backend API
  3. **REQ-049** (สถานะ: `deviation` · วันที่: 2026-10-14): หัวหน้าแม่บ้าน Approve / Reject งานทำความสะอาด — ขัดแย้งกับ state machine ของ `HousekeepingTask` ที่สถานะ `done` เป็น terminal/locked และยังไม่มี role หัวหน้าแม่บ้านในระบบ รอโครงสร้างทีม
  4. **REQ-038** (สถานะ: `deviation` · วันที่: 2026-10-14): เปลี่ยนราคาห้องพักล่วงหน้าในอนาคต (effective-dated rates) — ปัจจุบันใช้ราคา flat rate ปัจจุบันบน `room_types.rates` / `global_rates` · มี Risk note: การแก้ราคาทันทีจะกระทบ draft ที่จองไว้ด้วยราคาเดิมตอนคำนวณยอดใหม่
  5. **REQ-010** (สถานะ: `pending-external` · วันที่: 2026-10-14): การชำระเงิน QR PromptPay จริง + Webhook HMAC — สถานะรอข้อสรุปและการเชื่อมต่อ Payment Gateway ภายนอก (ไม่ใช่ปิดถาวร) ปัจจุบันใช้ mock gateway + สลิปโอนเงิน `BookingConfirmation`
  6. **REQ-045** (สถานะ: `interpretation` · วันที่: 2026-10-14): Room Upgrade — ระบบรองรับผ่านการแก้ `room_type_id` บน `BookingRoom` และส่งส่วนต่างค่าห้องเข้าสู่ยอดค้างชำระอัตโนมัติผ่าน `DiscountService::reprice()` ถือว่าครอบคลุมข้อกำหนดแล้ว
  7. **REQ-012** (สถานะ: `interpretation` · วันที่: 2026-10-14): Draft Booking + เลข confirm ตั้งแต่สร้าง — ระบบสร้าง draft booking แบบ real-time พร้อมออกรหัส confirmation จาก atomic sequence table ทันทีเพื่อกันห้อง (holding slot) 15 นาที ถือว่าครอบคลุมข้อกำหนด
  *(หมายเหตุ: งดเว้น REQ-031, REQ-051, REQ-052 ตามคำสั่ง เนื่องจาก ticket อื่นกำลังดำเนินการ)*
- ได้เพิ่ม Pointer ใน [`AGENTS.md`](file:///c:/dev/hotel/AGENTS.md) 2 ตำแหน่ง:
  - ในหัวข้อ `## 🗺️ Wayfinder maps (planning tracker)` ที่รายการ `srs-v2-gap-closure`
  - ในหัวข้อ `## Documents to read before sensitive edits` ชี้มายัง ledger ใน `cline.md` และ wayfinder map `wayfinder/srs-v2-gap-closure/map.md`
- เอกสารเท่านั้น ไม่มีการแตะต้องโค้ด, tests, routes, config หรือ database; suite รันผ่านครบ 649 passed (2605 assertions)
