# Implement: ตาราง organizations + admin จองแทน user

- **label:** `wayfinder:task`
- **type:** AFK
- **status:** open
- **blocked-by:** [01-organization-table-erp-and-replaceability](./01-organization-table-erp-and-replaceability.md), [02-admin-booking-for-user-target-identity](./02-admin-booking-for-user-target-identity.md)
- **assignee:** (ว่าง)

## Question

ลงมือเขียนโค้ดตาม resolution ของ ticket 01 + 02 — หนึ่ง session จบ:

- migration + model `Organization` (UUID PK, ผูก `PgBoolean` ถ้ามี boolean column) ตาม contract ที่ ticket 01 ล็อก
- endpoint จัดการ organizations ตามที่ ticket 01 ตัดสิน (ใต้ `role:admin` + `CheckRole` + re-check in-controller ตาม layer rules)
- write path "จองแทน user" ใน `createBooking`/`StoreBookingRequest` + column `created_by` (ถ้า ticket 02 ตัดสินให้เพิ่ม) + จุดแก้บังคับทั้งหมดที่ ticket 02 ไล่ไว้ (draft-dedup, room cap, ราคา daily_ku, ownership)
- tests ครอบทุกเคสที่ตัดสินไว้ + จด design decision ลง `cline.md` ก่อนเขียนตาม protocol · ปิด ticket เมื่อ `php artisan test` เขียว
