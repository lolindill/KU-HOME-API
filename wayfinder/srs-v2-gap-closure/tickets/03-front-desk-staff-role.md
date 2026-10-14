# 03: เปิด front-desk ให้ role staff — check-in/out ได้ 24 ชม. ไม่ต้องรอ admin (REQ-031)

- **label:** `wayfinder:task`
- **type:** AFK
- **status:** open
- **blocked-by:** (ไม่มี — frontier)
- **assignee:** agent-hub:antigravity/gemini-3.8-flash (manager dispatch 2026-10-14)
- **summary:** เปิด route group front-desk + in-controller re-check ให้ staff ทำ check-in/out คู่ admin
- **phase:** 1

## Question

ทั้ง route group front-desk ล็อก `role:admin` เท่านั้น (`routes/api.php` :161, :213-219) และ `checkOut` re-check admin ใน controller (`FrontDeskController.php` :280-286) — SRS REQ-031 ต้องการ check-in ได้ตลอด 24 ชม. "ผ่านฟร้อนหรือเจ้าหน้าที่รักษาความปลอดภัย" ซึ่งไม่ใช่บัญชี admin

**Decision ที่ล็อกแล้ว (owner 2026-10-14):** เปิดให้ role `staff` ใช้ front-desk ได้ — ไม่สร้าง role ใหม่ (ฟร้อน/รปภ. ใช้บัญชี staff)

**จุดแตะ:**

- `routes/api.php`: front-desk group `role:admin` → `role:admin,staff` (เฉพาะ check-in / check-out / และสิ่งที่จำเป็นต่อการรับ-ส่งตัว — ตัดสินให้ครบใน ticket นี้ว่า `payment` (เก็บเงินสด) กับ `assign-rooms` ให้ staff ด้วยหรือไม่ โดยยึด spirit REQ-031 "ฟร้อนทำงานได้จริง" และจดลง Resolution)
- In-controller re-check ตาม defense-in-depth (`FrontDeskController` จุดที่ re-check admin) → เติม staff
- อย่าแตะ: users admin, reports (`role:admin,staff` อยู่แล้ว), housekeeping dashboard
- Tests: staff check-in ผ่าน · staff check-out ผ่าน + task แม่บ้านเกิด · user/guest ยัง 403 · regression admin เท่าเดิม
- `php artisan test` เขียว + อัปเดต `docs/api_guide.md` + deviation ledger ปิดรอย REQ-031 (ถ้า ticket 01 ปิดไปแล้ว)

## Resolution

(ยังว่าง)
