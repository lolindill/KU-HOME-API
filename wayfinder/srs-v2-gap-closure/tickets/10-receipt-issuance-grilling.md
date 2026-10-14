# 10: Grill โครงใบเสร็จ 3.8 — ชั้นบันทึก, split semantics, เงื่อนไขออกได้

- **label:** `wayfinder:grilling`
- **type:** HITL
- **status:** closed *(2026-10-14 — grilling กับ owner จบใน 1 รอบ: มติ deviation ไม่ทำในระบบ)*
- **blocked-by:** (ไม่มี — frontier)
- **assignee:** zcode session (grilling กับ owner 2026-10-14)
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

**มติ owner 2026-10-14 (grilling คู่กับ owner ใน session นี้): DEVIATION — ไม่ทำชั้นใบเสร็จในระบบ**

Owner ให้ข้อมูลใหม่ที่เปลี่ยนทิศของ ticket:

1. **ใบเสร็จจริงถูกออกโดยส่วนงานอื่น นอกระบบ KU HOME** และ**ไม่ถูกรวมเข้าระบบนี้** — ระบบจึงไม่มีชั้นออกเลข/บันทึกแถวแบ่งยอดต่อผู้รับจาก SRS §3.8 อีกต่อไป
2. **Payment gateway มีปัญหา** → ช่องทางการชำระจริงยังใช้การ **verify สลิปโดย admin ผ่าน `booking_confirmations`** (flow ที่มีอยู่และใช้งานได้จริง) ต่อไป

คำตอบต่อ 6 คำถามเดิม (ทุกข้อจบด้วย deviation — ไม่มี schema ใหม่):

1. **โครงเก็บ** — ไม่สร้าง `receipt_issues`/`receipt_issue_lines` และ**ไม่ปลด freeze** `receipts` ❄️ (คง frozen ตามเดิม)
2. **เลขใบเสร็จ** — ระบบไม่ออกเลข · `receipt_sequences` + `Receipt::generateUniqueReceiptNo()` คงหลับต่อไป (ไม่ reuse)
3. **เงื่อนไขออก** — N/A (ออกนอกระบบ)
4. **Split semantics** — N/A (ผู้ออกใบภายนอกจัดการเอง)
5. **Roles** — N/A
6. **ผูก ledger** — N/A · `payments` ledger คงเดิม 3 จุดเขียน ไม่ถูกอ้างจากใบเสร็จในระบบ

**ผลตามมา:**

- มติเดิมใน map Notes "🧊 ใบเสร็จ 3.8 = backend ชั้นบันทึกเท่านั้น (ออกเลข atomic + split lines)" **ถูก supersede ด้วยมตินี้** — แก้ Notes ของ map แล้ว
- Deviation ลง ledger ข้อ 5 ใน `cline.md` (📋 SRS v2 deviation ledger) แล้ว
- [ticket 11 (implement)](./11-receipt-issuance-impl.md) **ปิดเป็น out-of-scope** — ไม่มีอะไรต้อง implement
- **ทางกลับถ้ามี:** เมื่อ owner สั่งใหม่ ให้เปิด ticket ใหม่ ออกแบบจาก reference ของ ticket นี้ (โครง `receipt_issues` header + lines · reuse `receipt_sequences` format `REC-YYYYMM-XXXXX` · roles admin+staff เทียบ `recordPayment` · line อ้าง `payment_id` nullable · หารไม่ลงตัวให้ frontend คิดยอดส่งมา backend validate ผลรวม) — **ห้ามปลด freeze `receipts` เดิม**
