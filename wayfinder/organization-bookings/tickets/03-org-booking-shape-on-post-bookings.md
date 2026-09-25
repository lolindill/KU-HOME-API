# Booking ให้องค์กรบน POST /bookings เดิม — field set และ consumer ของ user_id = null

- **label:** `wayfinder:grilling`
- **type:** HITL
- **status:** closed
- **blocked-by:** [01-organization-table-erp-and-replaceability](./01-organization-table-erp-and-replaceability.md)
- **assignee:** zcode (session 2026-09-25)

## Question

Requirement จาก owner (ล็อกแล้ว): org booking ใช้ **`POST /bookings` เดิม** — ไม่มี endpoint ใหม่ · เพิ่ม field `organize` (erp) + `customer_name` (string) + `customer_phone` / `customer_email` (nullable) · booking ผูกกับตาราง organizations **แทน** user table:

- **Validation shape:** org booking เกิดเมื่อไหร่ — ส่ง `organize` มา = เข้าโหมด org เลยไหม? `customer_name` บังคับเสมอไหม (ตาม requirement: phone/email เท่านั้นที่ nullable)? ห้ามส่ง `user` (จองแทน) กับ `organize` พร้อมกันใช่ไหม — mutually exclusive หรือมีลำดับชนะ?
- **`user_id = null` กระเพื่อมไปที่ไหนบ้าง** (schema nullable อยู่แล้ว — ปัญหาอยู่ที่ consumer):
  - ownership checks ทุกจุด ("เจ้าของหรือ admin") — null = เหลือแต่ admin
  - `Booking::getPrimaryGuestNameAttribute` fallback `user?->name` — org booking fallback ไป `customer_name` ไหม
  - **ราคา** — org ไม่มี user/role → เรท `daily` ปกติเสมอ ใช่ไหม (ไม่มีทางโดน daily_ku)
  - draft-dedup "1 draft ต่อ user" — ใช้กับ org booking ไหม (org จองซ้อนหลายบิลพร้อมกันได้ไหม)
- **customer snapshot เก็บที่ไหน:** column ใหม่บน `bookings` เลย หรือ pattern เดียวกับ guests JSON ของ booking_rooms? (requirement ชี้ contact ขององค์กร = 1 คนต่อ booking ไม่ใช่ต่อห้อง — น้ำหนักไปที่ bookings)
- **default guest name:** booking_rooms ที่ไม่ส่ง guests ใช้ชื่อผู้จองเป็น default — org booking ดึงจาก `customer_name` แทนได้เลยไหม

## Resolution (2026-09-25 — grilled กับ owner ครบ 4 คำถาม, ทุกข้อตามคำแนะนำ)

1. **Field `organize` บน request = erp code string** — admin ส่ง `organize` = รหัส erp → server lookup ในตาราง `organizations` แล้วเก็บเป็น FK `organization_id` (ตาม replaceability contract ของ ticket 01 — ไม่เก็บ erp string บน bookings) · lookup ไม่เจอ **หรือ** `is_active = false` → 422
2. **Mutually exclusive + customer_name บังคับกับ org — ไม่มีลำดับชนะ:**
   - ส่ง `user` (โหมด A จองแทน) กับ `organize` พร้อมกัน → 422 เสมอ
   - ส่ง `organize` → **ต้องส่ง `customer_name` มาด้วย** (phone/email nullable ตาม requirement) · `customer_name` โดยไม่มี `organize` ยังใช้ได้ = โหมด B เฮดเปล่าระบุคนเข้าพักตาม ticket 02
   - เฮดเปล่า (ไม่ส่งทั้ง `user`/`organize`) ผ่านเหมือนเดิมตาม ticket 02
   - ส่ง `organize` ได้เฉพาะ `role:admin` + `source='admin'` (ขยายกฎ marker ของ ticket 02 ครอบโหมด org)
3. **Snapshot = 3 columns ใหม่บน `bookings`:** `customer_name` (string) + `customer_phone` / `customer_email` (nullable) — ตาม requirement (contact 1 คนต่อ booking ไม่ใช่ต่อห้อง) และ ticket 01 ที่ชี้ snapshot บน booking · ไม่ใช้ JSON pattern แบบ guests เพราะต้อง filter/search ใน admin index ได้
4. **กฎผลพวง `user_id = null` (โหมด org สืบทอดกฎโหมด B ของ ticket 02 ข้อ 4 ครบทุกข้อ):** ไม่มี draft-dedup (org จองซ้อนหลายบิลได้) · ราคา `daily` ปกติเสมอ — ไม่มี role ให้ `getEffectiveDailyRate` ดู จึงไม่มีทางโดน daily_ku · room cap ไม่ใส่ (admin) · ownership เหลือแต่ admin ทุกจุด ("เจ้าของหรือ admin" รองรับ null อยู่แล้ว)
5. **ชื่อ default ใช้ customer_name ทั้ง 2 จุด:**
   - `Booking::getPrimaryGuestNameAttribute` — ยืด fallback chain เป็น `user?->name` → **`customer_name`** → `'Customer'`
   - booking_rooms ที่ไม่ส่ง guests — default primary guest ดึงจาก `customer_name` แทนชื่อผู้จองเมื่อเป็น org booking
