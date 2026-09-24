# ตาราง organizations — contract ของ erp, CRUD และกติกา replaceability

- **label:** `wayfinder:grilling`
- **type:** HITL
- **status:** open
- **blocked-by:** —
- **assignee:** (ว่าง)

## Question

Requirement จาก owner: ตาราง `organizations` ใหม่ มี field `erp` (fk) + `name` และ "ถ้ารุ่นพี่หาทางดึง organization-data API ได้ ตารางนี้เปลี่ยน (replace) ได้" — ต้องเคลียร์ก่อนเขียน migration:

- **`erp` คืออะไรกันแน่:** รหัสองค์กรฝั่ง ERP ภายนอก (เช่น รหัสหน่วยงาน KU) เก็บเป็น string? unique ต้องไหม? คำว่า "fk" หมายถึง FK ไปที่ไหน — ในเครื่องเราไม่มีตาราง erp ให้อ้าง
- **กติกา replaceability (โจทย์หลัก):** booking ควรอ้างอิง organization ด้วย **FK `organizations.id`** หรือ **erp code (string) บน bookings เลย** — อะไรอยู่รอดจากการเทตารางทิ้งไปใช้ API แทน? (decision นี้ตัดสิน field `organize` บน bookings ของ ticket 03 ด้วย)
- **CRUD:** ต้องมี endpoint จัดการ organizations ไหม (ใต้ `role:admin` — index/show/store/update/toggle)? หรือยังไม่ต้องมี endpoint เลย จองโดยส่ง erp มาแล้ว validate กับตารางล้วน ๆ?
- **Soft delete / toggle active:** precedent ของระบบ — discounts ไม่มี DELETE ใช้ toggle เพื่อรักษา FK/audit trail; organizations ที่ถูกอ้างด้วย booking เก่าควรทำตัวยังไงเมื่อเลิกใช้?
