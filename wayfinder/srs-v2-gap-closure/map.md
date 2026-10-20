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
  - 🧊 **ราคา group/month: auto-apply ฝั่ง server** ใน `DiscountService::reprice` — ~~(≥30 คืน → เรท `month` · ≥5 ห้อง → `group` min_5 · ≥10 ห้อง → min_10 แทน `daily`)~~ *(แก้มติ 2026-10-20)* → **สิทธิ์ admin-only** + สูตร hybrid บล็อกเดือน `(a+b)×month + c×เรทรายวัน` ที่ x≥30 ต่อ booking_room (a=floor(x/30) · b=floor(y/**20**) · c=y%20 — config `block_min_nights` 21→20) · user จองเอง daily เส้นตรงเฉพาะ <30 คืน — **ห้อง x≥30 หรือบิล ≥5 ห้อง = เขตของ admin → 422 reject** (ku_member คง daily_ku) · `set_room_price` ชนะเด็ดขาด — ห้ามย้ายไปให้ frontend คิด · รายละเอียดที่ [ticket 08](./tickets/08-pricing-tier-grilling.md) Resolution
  - 🧊 **Guest cap 2 ชั้น (REQ-007/019):** ห้ามจำนวน `guests` เกิน `room_types.max_guests` ทุกจุดที่ส่ง + บังคับ ≥1 คนตอน submit สลิป confirm — **ห้าม**บังคับ guests required ตั้งแต่ create (ทับ flow draft real-time)
  - ~~🧊 **ใบเสร็จ 3.8 = backend ชั้นบันทึกเท่านั้น:** ออกเลข atomic + แถวแบ่งยอดต่อผู้รับ (1 เต็ม / 2 หาร 2 / 3 หาร 3 / >1 กรอกเอง) — PDF render ฝั่ง frontend · ตัว `receipts` ❄️ เดิมคง frozen — ออกแบบชั้น record ใหม่ที่ [ticket 10](./tickets/10-receipt-issuance-grilling.md)~~ *(❌ supersede 2026-10-14 — owner ยืนยันใบเสร็จจริงออกโดยฝ่ายอื่น**นอกระบบ** ไม่รวมเข้าระบบ · มติใหม่ = **deviation ไม่ทำ** ดู [ticket 10](./tickets/10-receipt-issuance-grilling.md) + ledger ข้อ 5 ใน `cline.md` · `receipts` ❄️ ยังคง frozen)*
  - 🧊 **REQ-048 รูปแม่บ้าน:** ใช้ `images` table (morph `HousekeepingTask`) ตาม AGENTS.md — ห้ามสร้างตารางรูปใหม่ · หมวด before/after (cap ห้องละ 5+5)/forgotten_item/damage + หมายเหตุ · retention 90 วัน
  - 🧊 **สต๊อก (REQ-051/052):** CRUD + `reorder_point`/`max_stock` + สถานะ derived ok/low/out (แถบสีให้ frontend render) + movement log เก็บ 1 ปี · roles: admin+staff เขียน, housekeeping อ่าน
  - 🧊 **charges ค่าปรับ/ค่าเสียหาย (REQ-042/044, ล็อก 2026-10-15):** บวกยอดจริง**ผ่านปลายน้ำ** — `total_amount` คงค่าห้องล้วน · `outstanding = total + charges_total − paid` · `is_paid = paid ≥ total + charges` · เก็บเงินหลังเข้าพักที่ front desk ผ่าน `recordPayment` เท่านั้น (staff คุมความถูกต้อง) · ไม่มีรูปหลักฐาน · ผูกห้องด้วย `booking_room_id` nullable · รายละเอียดที่ [ticket 12](./tickets/12-charges-toward-total-grilling.md) — **✅ implemented 2026-10-15 ที่ [ticket 13](./tickets/13-charges-toward-total-impl.md)**
- **Interpretation (implementation ปัจจุบันถือว่าครอบคลุม — จดลง deviation ledger เช่นกัน):** REQ-045 upgrade = เปลี่ยน `room_type_id` + ส่วนต่างเข้ายอดค้างชำระ (surcharge) · REQ-012 draft = draft + เลข confirm ออกตั้งแต่ตอนสร้าง
- **WIP นอก map:** สลิปไม่แจ้งยอด `amount` nullable (owner 08/10/26, 5 ไฟล์แก้ + tests เขียว) — commit แยกก่อนเริ่มงานใน map นี้
- **ลำดับงานแนะนำ (advise เท่านั้น — frontier คือของจริง):** Phase 0 = ticket 01 (deviation ledger) · Phase 1 quick wins = 02, 03, 04 · Phase 2 money = ~~08 → 09~~ *(08 ปิด 2026-10-20 — **ticket 09 ปลดล็อก รอ session implement** — ตัวเปิดที่เหลือตัวเดียวของแมป)* · Phase 3 โครงสร้างใหม่ = 05, 06, 07 · Phase 4 grilling ก่อนลงมือ = ~~10 → 11~~ *(10 ปิด deviation 2026-10-14 · 11 ปิด out-of-scope)* และ ~~12 → 13~~ *(12 ปิด 2026-10-15 · 13 ปิด 2026-10-15)*
- ธรรมเนียมเดิม: 1 ticket ต่อ 1 session — claim ด้วย `assignee:` ก่อนลงมือ · resolve แล้วเขียน `## Resolution` ลง ticket → `status: closed` → append 1 บรรทัดใน "Decisions so far" แล้ว commit tracker ไปกับ branch ที่ทำงานอยู่

## Decisions so far
- [01: SRS deviation ledger](./tickets/01-srs-deviation-ledger.md): ledger 7 ข้อ (deviation 5 + interpretation 2) ลง `cline.md` + pointer ใน AGENTS.md — REQ ที่ "งด" ต้องมีบรรทัดที่เดียว
- [02: Guest cap validation](./tickets/02-guest-cap-validation.md): cap `max_guests` ทุก write path + ≥1 คนตอน confirm — ห้ามบังคับ guests ตอน create draft (REQ-007/019 ✅ implemented)
- [03: Front-desk staff](./tickets/03-front-desk-staff-role.md): `role:admin,staff` บน check-in/out **+ payment + assign-rooms** (ฟร้อนต้องทำงานจบในตัว) · walk-in/no-show คง admin · staff เข้า validTransitions เส้นทางบริการ (REQ-031 ✅)
- [04: Auto-assign T-2](./tickets/04-auto-assign-t2.md): `DailyRoomMaintenance` assign เลขห้อง 2 วันก่อน check-in ผ่าน RoomAllocator · fail-safe ต่อ booking (REQ-023 ✅)
- [05: Check-in evidence](./tickets/05-checkin-evidence-upload.md): morph `BookingRoom` + column `images.metadata` · state guard confirmed/checked_in/checked_out · retention 365 วันต่อ `app:cleanup-images` (REQ-033 ✅)
- [06: Housekeeping photos](./tickets/06-housekeeping-photos.md): morph `HousekeepingTask` + column `images.category` · drop scaffold `housekeeping_photos` · cap 5+5 · notes ใช้ column เดิม · retention 90 วัน (REQ-048 ✅)
- [07: Stock system](./tickets/07-stock-crud-movement-log.md): CRUD `/stock` + derived ok/low/out + `stock_movements` (soft delete คง audit trail) + cleanup 365 วัน + `SuppliesReportData` เต็ม (REQ-051/052 ✅)
- [10: Grill โครงใบเสร็จ 3.8](./tickets/10-receipt-issuance-grilling.md) *(2026-10-14 — grilling)*: **deviation — ไม่ทำชั้นใบเสร็จในระบบ** — owner ยืนยันใบเสร็จจริงออกโดยฝ่ายอื่นนอกระบบ + gateway มีปัญหาจึงใช้ admin verify สลิปผ่าน `booking_confirmations` ต่อไป · `receipts` ❄️ คง frozen · `receipt_sequences` คงหลับ · มติเดิม "backend ชั้นบันทึก" ถูก supersede — ledger ข้อ 5 ใน `cline.md`
- [12: Grill charges บวกยอดรวมจริง](./tickets/12-charges-toward-total-grilling.md) *(2026-10-14–15 — grilling)*: charges บวกยอดจริงผ่านปลายน้ำ — `total_amount` คงค่าห้องล้วน + `outstanding = total + charges − paid` + `is_paid` ตามยอดรวม · ผูกห้องด้วย `booking_room_id` nullable · REQ-044 = กลไกเดียวกัน + `charge_type='addon'` · เก็บหลังเข้าพักที่ front desk ผ่าน `recordPayment` เท่านั้น · ไม่มีรูปหลักฐาน (หลักฐาน = payments — interpretation REQ-042 จดตอนปิด ticket 13) · สถานะยืดหยุ่น — ticket 13 ปลดล็อก
- [13: Implement charges บวกยอดรวมจริง](./tickets/13-charges-toward-total-impl.md) *(2026-10-15 — task)*: ✅ implemented ตาม ticket 12 ทุกข้อ — `charges_total` append + `syncIsPaid()` recompute (ครอบ create/update/delete + จุดเปรียบเทียบ verify/recordPayment/surcharge) + `GET /bookings/{id}/additional-charges` + snapshot เงิน + filter บริการเสริมในรายงาน · suite 737 เขียว · **REQ-042/044 ปิด** (interpretation อนุประโยค "หลักฐานการจ่ายเงินตามห้องพัก" = ledger ข้อ 9 ใน `cline.md`)
- [08: Grill กติกา auto-apply เรท group/month](./tickets/08-pricing-tier-grilling.md) *(2026-10-14–20 — grilling)*: **สิทธิ์ admin-only** — user: daily เส้นตรงเฉพาะ <30 คืน · ห้อง x≥30 (เขต monthly) หรือบิล ≥5 ห้อง (cap 4 มีอยู่แล้ว) → **422 reject** · ku_member คง daily_ku · เส้นทาง admin: ≥5/≥10 ห้อง → group min_5/min_10 · ต่อ booking_room ที่ x≥30 → สูตร hybrid `(a+b)×month + c×เรทรายวัน` (a=floor(x/30) · b=floor(y/20) · c=y%20) · config `block_min_nights` 21→20 · ส่วนลดตาม precedent — `set_room_price` ชนะเด็ดขาดข้าม hybrid · ปัด `RoundToTen` ที่ `booking_rooms.amount` · REQ-004/015/016 กติกาพร้อม implement — **ปลดล็อก ticket 09**

> dispatch ขนาน 2026-10-14 ผ่าน agent-hub (antigravity · gemini-3.8-flash) — 7 worktree แยก แล้ว merge กลับ `agust-11` โดยผู้จัดการ · suite 725 เขียว · เอกสาร API ผนวกท้าย `docs/api_guide.md` (หัวข้อ 🧩 SRS v2 Gap Closure)


## Not yet specified

- **REQ-010 QR จริง (PromptPay) + webhook HMAC** — ติด decision เกตเวย์ภายนอก (blocker #4 จากยุค webhook) — ปัจจุบัน mock gateway (`gateway.mockbank.com`) + webhook 410 เมื่อเกตเวย์ชัดจะ graduate เป็น ticket ใหม่ หรือปิดด้วย deviation line
- **NFR ประสิทธิภาพ 500 concurrent users** — ยังไม่มีหลักฐาน load test ใน repo (มี throttle + `lockForUpdate` รองรับเชิงโครงสร้างแล้ว) — ค่อยตัดสินว่าทำ load test script หรือจด deviation

## Out of scope

- **ชั้นบันทึกใบเสร็จในระบบ (SRS §3.8)** — [ticket 11](./tickets/11-receipt-issuance-impl.md) *(ปิด out-of-scope 2026-10-14)*: ใบเสร็จจริงออกโดยฝ่ายอื่นนอกระบบ KU HOME (มติ owner ใน [ticket 10](./tickets/10-receipt-issuance-grilling.md)) — ไม่สร้างตาราง/endpoint ออกเลข ไม่ปลด freeze `receipts` ❄️ · deviation จด ledger ข้อ 5 ใน `cline.md`
- **REQ-009/040/041 เมลยืนยันอัตโนมัติ 2 formats + REQ-020 quotation/ใบ confirm/Guest Folio** — deviation ตาม freeze 06/10/26: frontend senior gen เอกสารอยู่แล้ว + admin ส่งเองตามช่องทาง (Facebook/Line/หน้าฟ้อน) — งานเมลคง frozen ที่ `google-integration` (tickets 04/07/08) ปลดได้เมื่อ owner สั่งใหม่เท่านั้น
- **REQ-049 หัวหน้าแม่บ้าน Approve/Reject** — deviation ไว้ก่อน: ชน state machine `done = terminal/locked` + ไม่มี role หัวหน้าแม่บ้าน รอโครงสร้างทีมชัดเจน
- **REQ-038 เปลี่ยนราคาห้องล่วงหน้าในอนาคต (effective-dated rates)** — deviation ⚠️ risk จดไว้: แก้ราคาทันทีมีผลกับ draft ที่จองในราคาเก่า · จะกลับมาทำได้เมื่อมีหลักฐานความเสียหายจริง (ทางแก้จดไว้: `effective_from` บน `global_rates` + query เลือกแถวล่าสุดที่ ≤ วันเข้าพัก)
