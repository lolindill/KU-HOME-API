# 🗺️ Wayfinder Map — SRS v2 Gap Closure (ปิด REQ ค้างตาม acceptance)

- **label:** `wayfinder:map`
- **status:** active
- **tracker:** local-markdown — tickets อยู่ใน `tickets/` ของ directory นี้ (blocking ระบุใน field `blocked-by` ของแต่ละ ticket)
- **charted:** 2026-10-14

## Destination

SRS v2 (`srs_room_booking_v2.pdf` — REQ-001…052 + ใบเสร็จ 3.8) ถูกถือเป็น **acceptance contract** (owner decision 2026-10-14) — ทุก REQ ที่ตรวจ gap เมื่อ 2026-10-14 (suite 649 เขียว) แล้วยังไม่เขียว ต้องมีปลายทางชัดเจนอย่างใดอย่างหนึ่ง: **implementation ตาม decisions ที่ grill ล็อกกับ owner แล้ว** หรือ **documented deviation** ที่จดรวมที่เดียว — แผนงานจบเมื่อ tickets ทั้งหมดปิด, deviation ledger ลง `cline.md` (+ pointer ใน AGENTS.md) และ suite เขียว

## Notes

- **ที่มา:** gap audit 4 explore agents (2026-10-14) + grill session กับ owner ล็อก 7 จุด — standing decisions ด้านล่างนี้ห้าม re-litigate ไม่มีหลักฐานใหม่:
  - 🧊 **SRS = acceptance** — ห้ามปล่อย REQ ลอย: ไม่ทำ = ต้องมี deviation line ที่จดเหตุผล
  - 🧊 **ราคา group/month: auto-apply ฝั่ง server** ใน `DiscountService::reprice` (≥30 คืน → เรท `month` · ≥5 ห้อง → `group` min_5 · ≥10 ห้อง → min_10 แทน `daily`) — ห้ามย้ายไปให้ frontend คิด (edge ที่เหลือ grill ที่ [ticket 08](./tickets/08-pricing-tier-grilling.md))
  - 🧊 **Guest cap 2 ชั้น (REQ-007/019):** ห้ามจำนวน `guests` เกิน `room_types.max_guests` ทุกจุดที่ส่ง + บังคับ ≥1 คนตอน submit สลิป confirm — **ห้าม**บังคับ guests required ตั้งแต่ create (ทับ flow draft real-time)
  - 🧊 **ใบเสร็จ 3.8 = backend ชั้นบันทึกเท่านั้น:** ออกเลข atomic + แถวแบ่งยอดต่อผู้รับ (1 เต็ม / 2 หาร 2 / 3 หาร 3 / >1 กรอกเอง) — PDF render ฝั่ง frontend · ตัว `receipts` ❄️ เดิมคง frozen — ออกแบบชั้น record ใหม่ที่ [ticket 10](./tickets/10-receipt-issuance-grilling.md)
  - 🧊 **REQ-048 รูปแม่บ้าน:** ใช้ `images` table (morph `HousekeepingTask`) ตาม AGENTS.md — ห้ามสร้างตารางรูปใหม่ · หมวด before/after (cap ห้องละ 5+5)/forgotten_item/damage + หมายเหตุ · retention 90 วัน
  - 🧊 **สต๊อก (REQ-051/052):** CRUD + `reorder_point`/`max_stock` + สถานะ derived ok/low/out (แถบสีให้ frontend render) + movement log เก็บ 1 ปี · roles: admin+staff เขียน, housekeeping อ่าน
- **Interpretation (implementation ปัจจุบันถือว่าครอบคลุม — จดลง deviation ledger เช่นกัน):** REQ-045 upgrade = เปลี่ยน `room_type_id` + ส่วนต่างเข้ายอดค้างชำระ (surcharge) · REQ-012 draft = draft + เลข confirm ออกตั้งแต่ตอนสร้าง
- **WIP นอก map:** สลิปไม่แจ้งยอด `amount` nullable (owner 08/10/26, 5 ไฟล์แก้ + tests เขียว) — commit แยกก่อนเริ่มงานใน map นี้
- **ลำดับงานแนะนำ (advise เท่านั้น — frontier คือของจริง):** Phase 0 = ticket 01 (deviation ledger) · Phase 1 quick wins = 02, 03, 04 · Phase 2 money = 08 → 09 · Phase 3 โครงสร้างใหม่ = 05, 06, 07 · Phase 4 grilling ก่อนลงมือ = 10 → 11 และ 12 → 13
- ธรรมเนียมเดิม: 1 ticket ต่อ 1 session — claim ด้วย `assignee:` ก่อนลงมือ · resolve แล้วเขียน `## Resolution` ลง ticket → `status: closed` → append 1 บรรทัดใน "Decisions so far" แล้ว commit tracker ไปกับ branch ที่ทำงานอยู่

## Decisions so far

(map เพิ่ง charted — การตัดสินใจทั้ง 7 จุดเกิดก่อน charting จึงฝังเป็น standing decisions ใน Notes และเนื้อ ticket โดยตรง · บรรทัดถัดไปจะเกิดจาก ticket ที่ปิดจริง)

## Not yet specified

- **REQ-010 QR จริง (PromptPay) + webhook HMAC** — ติด decision เกตเวย์ภายนอก (blocker #4 จากยุค webhook) — ปัจจุบัน mock gateway (`gateway.mockbank.com`) + webhook 410 เมื่อเกตเวย์ชัดจะ graduate เป็น ticket ใหม่ หรือปิดด้วย deviation line
- **NFR ประสิทธิภาพ 500 concurrent users** — ยังไม่มีหลักฐาน load test ใน repo (มี throttle + `lockForUpdate` รองรับเชิงโครงสร้างแล้ว) — ค่อยตัดสินว่าทำ load test script หรือจด deviation

## Out of scope

- **REQ-009/040/041 เมลยืนยันอัตโนมัติ 2 formats + REQ-020 quotation/ใบ confirm/Guest Folio** — deviation ตาม freeze 06/10/26: frontend senior gen เอกสารอยู่แล้ว + admin ส่งเองตามช่องทาง (Facebook/Line/หน้าฟ้อน) — งานเมลคง frozen ที่ `google-integration` (tickets 04/07/08) ปลดได้เมื่อ owner สั่งใหม่เท่านั้น
- **REQ-049 หัวหน้าแม่บ้าน Approve/Reject** — deviation ไว้ก่อน: ชน state machine `done = terminal/locked` + ไม่มี role หัวหน้าแม่บ้าน รอโครงสร้างทีมชัดเจน
- **REQ-038 เปลี่ยนราคาห้องล่วงหน้าในอนาคต (effective-dated rates)** — deviation ⚠️ risk จดไว้: แก้ราคาทันทีมีผลกับ draft ที่จองในราคาเก่า · จะกลับมาทำได้เมื่อมีหลักฐานความเสียหายจริง (ทางแก้จดไว้: `effective_from` บน `global_rates` + query เลือกแถวล่าสุดที่ ≤ วันเข้าพัก)
