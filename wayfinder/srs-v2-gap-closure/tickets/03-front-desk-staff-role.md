# 03: เปิด front-desk ให้ role staff — check-in/out ได้ 24 ชม. ไม่ต้องรอ admin (REQ-031)

- **label:** `wayfinder:task`
- **type:** AFK
- **status:** closed
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

- **Status:** Closed (2026-10-14)
- **Implemented Changes:**
  1. **Route Authorization (`routes/api.php`):**
     - แยกกลุ่ม endpoint ที่จำเป็นต่อการปฏิบัติงาน 24 ชม. ออกมาเป็นกลุ่ม `role:admin,staff`:
       - `PUT /bookings/{bookingId}/assign-rooms` (`BookingController::class, 'autoAssignRooms'`)
       - `POST /front-desk/{bookingId}/check-in` (`FrontDeskController::class, 'checkIn'`)
       - `POST /front-desk/{bookingId}/check-out` (`FrontDeskController::class, 'checkOut'`)
       - `POST /front-desk/{bookingId}/payment` (`FrontDeskController::class, 'recordPayment'`)
     - คงสิทธิ์ `role:admin` สำหรับ endpoints บริหาร:
       - `POST /front-desk/walk-in`
       - `POST /front-desk/{bookingId}/mark-no-show`
  2. **In-Controller Defense-in-Depth Checks:**
     - `FrontDeskController::checkIn`: รองรับ `['admin', 'staff']` และส่ง `$userRole` เข้าสู่ state transition
     - `FrontDeskController::checkOut`: ปรับ role check เป็น `in_array($user->role, ['admin', 'staff'])` และส่ง `$user->role` เข้าสู่ state transition
     - `FrontDeskController::recordPayment`: ปรับ role check เป็น `in_array($user->role, ['admin', 'staff'])` และส่ง `$user->role`
     - `BookingController::autoAssignRooms`: ปรับ in-controller check อนุญาตให้ `staff` ใช้งานได้ร่วมกับ owner/admin
  3. **State Transitions (`Booking.php` & `BookingRoom.php`):**
     - เพิ่ม `staff` ใน `Booking::validTransitions` สำหรับ `draft -> paid` และ `paid -> confirmed`
     - เพิ่ม `staff` ใน `BookingRoom::validTransitions` สำหรับ `draft -> confirmed`, `confirmed -> checked_in`, และ `checked_in -> checked_out`
  4. **Worktree Environment Bootstrap (`tests/bootstrap.php` & `phpunit.xml`):**
     - สร้าง worktree autoload wrapper เพื่อแก้ปัญหา namespace resolution ของ Composer junction ใน git worktree (ตาม precedent ของ worktree อื่นในโปรเจกต์)

- **Decisions Made (Operational Rationale):**
  - **Payment (`POST /front-desk/{bookingId}/payment`): อนุญาตให้ `staff`**
    - *เหตุผล:* REQ-031 ตั้งเป้าให้ฟร้อนท์/รปภ. บริการเช็คอิน-เช็คเอาต์ได้ 24 ชม. ในทางปฏิบัติ แขกจำนวนมากชำระเงินสดหรือโอนเงินหน้าเคาน์เตอร์ขณะเช็คอิน หากยอดค้างยังไม่ครบ `checkIn` จะปฏิเสธทันที และหากมีค่าใช้จ่ายเพิ่มเติมก่อนออกจากโรงแรม `checkOut` จะบล็อกหากมียอดค้างชำระ การให้สิทธิ์บันทึกการชำระเงินจึงเป็นฟังก์ชันจำเป็นยิ่งยวดเพื่อให้กระบวนการเช็คอิน/เอาต์สำเร็จได้จริง
  - **Auto-Assign Rooms (`PUT /bookings/{bookingId}/assign-rooms`): อนุญาตให้ `staff`**
    - *เหตุผล:* แขกที่มาถึงอาจยังไม่ได้ห้อง (กรณีจองแบบยังไม่ระบุห้อง หรือ cluster assignment ต้องรัน) หากฟร้อนท์ไม่สามารถ auto-assign ได้ จะไม่สามารถ check-in ห้องที่ยังไม่มี `room_id` ได้ ส่งผลให้ต้องปลุกหรือรอ Admin มาจัดสรรห้องให้
  - **Walk-in & Mark No-show: คงเป็น `admin` เท่านั้น**
    - *เหตุผล:* Walk-in booking มีผลต่อการสร้างบุ๊กกิ้งสดโดยไม่ผ่านระบบจองปกติ และการ Mark No-show มีผลทางกฎหมาย/การเงินในการริบเงินมัดจำ จึงสงวนไว้เป็นอำนาจของระดับผู้จัดการ/Admin

- **Verification & Test Results:**
  - เพิ่มชุดทดสอบใน `tests/Feature/FrontDeskTest.php` ครอบคลุม:
    - Staff ทำ check-in สำเร็จ (สถานะเปลี่ยนเป็น `checked_in`, ห้องเป็น `occupied`)
    - Staff ทำ check-out สำเร็จ + สร้างงานทำความสะอาด (`HousekeepingTask` สถานะ `pending`) อัตโนมัติ
    - Staff ทำ record payment สำเร็จ
    - Staff เรียก auto-assign rooms สำเร็จ
    - User/Guest ทั่วไปได้รับ `403 Forbidden` ในทุก operational endpoints
    - Staff ได้รับ `403 Forbidden` เมื่อพยายามเข้าถึง `walk-in` และ `mark-no-show`
  - `php artisan test --filter=FrontDeskTest`: 22 passed (92 assertions)
  - `php artisan test` (Full Suite): 657 passed (2643 assertions) 100% green

- **Documentation Handoff:**
  - จัดทำเอกสารส่งมอบสำหรับผู้จัดการนำไปรวมเข้า `docs/api_guide.md` ไว้ที่ `wayfinder/srs-v2-gap-closure/research/api-doc-handoff-03.md`

- **Text for Manager to add into `cline.md` (SRS Deviation Ledger):**
  > **REQ-031 (Front desk 24h operations):** Resolved on 2026-10-14 (Ticket 03). Opened front-desk operations (`check-in`, `check-out`, `payment`) and `assign-rooms` to `staff` role alongside `admin` via `routes/api.php`, in-controller re-checks (`FrontDeskController`, `BookingController`), and state transitions (`Booking`, `BookingRoom`). Administrative actions (`walk-in`, `mark-no-show`) remain strictly `admin` only.

