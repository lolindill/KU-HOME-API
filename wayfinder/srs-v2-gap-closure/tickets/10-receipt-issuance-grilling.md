# 10: Grill โครงใบเสร็จ 3.8 — ชั้นบันทึก, split semantics, เงื่อนไขออกได้

- **label:** `wayfinder:grilling`
- **type:** HITL
- **status:** open
- **blocked-by:** (ไม่มี — frontier)
- **assignee:** (ว่าง)
- **summary:** Grill owner โครงตารางใบเสร็จใหม่ split ต่อผู้รับ เงื่อนไข/roles ก่อน implement ชั้นบันทึก
- **phase:** 4

## Question

Owner ล็อกแล้ว (2026-10-14): ใบเสร็จ SRS 3.8 = **backend ออกเลข + บันทึกแถวแบ่งยอดต่อผู้รับเท่านั้น** — PDF render ฝั่ง frontend ตัว `receipts` ❄️ เดิมคง frozen — ต้องตัดสินโครงชัดก่อนลงมือ:

1. **โครงเก็บ:** ตารางใหม่ (เช่น `receipt_issues` header + `receipt_issue_lines` ต่อผู้รับ) หรือปลด freeze `receipts` เดิม? — ชน freeze โดยตรง ต้องตัดสินชัด (ทิศทางแนะนำ: ตารางใหม่ เก็บ ❄️ ไว้เป็น legacy ตามเหตุผลเดิม)
2. **เลขใบเสร็จ:** reuse `receipt_sequences` ที่หลับอยู่ หรือ sequence ใหม่ (precedent atomic: `booking_sequences` + `lockForUpdate` — **ห้าม max()+1**)
3. **เงื่อนไขออกได้เมื่อไหร่:** ต่อ booking เท่านั้น (ตาม SRS) — ออกได้เมื่อ `paid_amount > 0`? หรือ `is_paid` เท่านั้น? · org deferred ที่จ่ายหลังเข้าพักออกได้ไหม · ยอดออกได้ต้อง ≤ `paid_amount` หรือยอดไหนก็ได้ตามที่ admin กรอก?
4. **Split semantics ตาม SRS:** 1 คน = เต็ม / 2 คน = หาร 2 / 3 คน = หาร 3 (สูงสุด 3) / >1 คนกรอกเองราคาต่อคนไม่เท่ากัน — validate ผลรวมแถวต้องเท่ากับยอดที่ตั้งใจออก (422 ถ้าไม่ครบ) · ปัดเศษหารไม่ลงตัวใครรับบาทที่เศษ? · reissue (ออกใหม่หลังออกไปแล้ว) ได้ไหม — เลขใหม่เสมอ?
5. **Roles:** ออกได้ใคร — admin+staff (เทียบ `recordPayment`)?
6. **ผูก ledger:** แต่ละ line อ้าง `payments` row ไหน (หรือแค่ booking-level พอ) — เพื่อ audit ย้อนได้ว่าเงินชุดนี้มาจากเหตุการณ์ใด

**บริบท:** `receipts` frozen ตาม `AGENTS.md`/`cline.md` · `payments` = single ledger 3 write points · `Receipt::generateUniqueReceiptNo()` ยังมีอยู่ (legacy)

**Done เมื่อ:** Resolution ตอบครบ 6 ข้อ + ร่าง schema ตัวเดียวชัดๆ — ticket 11 ห้ามเริ่มก่อน ticket นี้ปิด

## Resolution

(ยังว่าง — รอ grilling คู่กับ owner)
