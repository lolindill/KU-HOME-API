# ตาราง organizations — contract ของ erp, CRUD และกติกา replaceability

- **label:** `wayfinder:grilling`
- **type:** HITL
- **status:** closed
- **blocked-by:** —
- **assignee:** kevii (session 2026-09-25)

## Question

Requirement จาก owner: ตาราง `organizations` ใหม่ มี field `erp` (fk) + `name` และ "ถ้ารุ่นพี่หาทางดึง organization-data API ได้ ตารางนี้เปลี่ยน (replace) ได้" — ต้องเคลียร์ก่อนเขียน migration:

- **`erp` คืออะไรกันแน่:** รหัสองค์กรฝั่ง ERP ภายนอก (เช่น รหัสหน่วยงาน KU) เก็บเป็น string? unique ต้องไหม? คำว่า "fk" หมายถึง FK ไปที่ไหน — ในเครื่องเราไม่มีตาราง erp ให้อ้าง
- **กติกา replaceability (โจทย์หลัก):** booking ควรอ้างอิง organization ด้วย **FK `organizations.id`** หรือ **erp code (string) บน bookings เลย** — อะไรอยู่รอดจากการเทตารางทิ้งไปใช้ API แทน? (decision นี้ตัดสิน field `organize` บน bookings ของ ticket 03 ด้วย)
- **CRUD:** ต้องมี endpoint จัดการ organizations ไหม (ใต้ `role:admin` — index/show/store/update/toggle)? หรือยังไม่ต้องมี endpoint เลย จองโดยส่ง erp มาแล้ว validate กับตารางล้วน ๆ?
- **Soft delete / toggle active:** precedent ของระบบ — discounts ไม่มี DELETE ใช้ toggle เพื่อรักษา FK/audit trail; organizations ที่ถูกอ้างด้วย booking เก่าควรทำตัวยังไงเมื่อเลิกใช้?

## Resolution (2026-09-25 — grilled กับ owner ครบ 4 คำถาม)

1. **`erp` = รหัสองค์กรฝั่ง ERP ภายนอก เก็บ `string` + `unique` + `nullable`** — เป็น **logical FK** ชี้ไประบบภายนอก (เช่น รหัสหน่วยงาน KU) **ไม่มี DB FK constraint** เพราะในเครื่องเราไม่มีตาราง erp ให้อ้าง · nullable เผื่อองค์กรที่ยังไม่มีรหัสในระบบ ERP · unique เพื่อ dedupe ตอน sync/import ในอนาคต
2. **กติกา replaceability → booking อ้างอิงด้วย nullable FK `organization_id` (restrictOnDelete) + snapshot ครบ** — replaceability มาจาก 2 ชั้น: (1) snapshot `customer_name` / `customer_phone` / `customer_email` บน booking ทำให้ booking เก่าอ่านความหมายถูกเสมอโดยไม่ต้องงัดตาราง (2) `organizations.erp` เป็น key กลางที่ใช้ map ไปยัง organization-data API ตอนเปลี่ยน — วันถอดตาราง รัน migration วิ่ง map `organization_id → erp → API id` ครั้งเดียว · **decision นี้ตัดสิน field `organize` บน bookings ของ ticket 03 แล้ว** (ไม่เก็บ erp string บน bookings ตรง ๆ, ไม่เก็บซ้ำทั้ง id+erp)
3. **CRUD เต็มใต้ `role:admin`:** `GET /organizations` (index + search) · `GET /organizations/{id}` · `POST /organizations` · `PUT /organizations/{id}` · `PATCH /organizations/{id}/toggle` — **ไม่มี DELETE** ตาม precedent discounts (รักษา FK/audit trail) · ข้อมูลชุดแรก = admin สร้างผ่าน endpoint นี้เอง (ไม่มี importer/sync ใน scope — fog "ข้อมูลชุดแรกมาจากไหน" ปิดด้วยข้อนี้)
4. **เลิกใช้องค์กร = column `is_active` (default `true`, cast `PgBoolean`) + `PATCH .../toggle`** — ปิดแล้ว = สร้าง booking ใหม่ที่อ้างองค์กรนี้ **422** แต่ booking เก่าไม่กระทบ (อ่าน snapshot ของตัวเอง) · **ไม่มี SoftDeletes, ไม่มี hard delete** — เลือกแบบ toggle อิสระ (ไม่มีเงื่อนไขกัน booking ค้าง) เพราะ snapshot ทำให้ booking เก่าปลอดภัยอยู่แล้ว
