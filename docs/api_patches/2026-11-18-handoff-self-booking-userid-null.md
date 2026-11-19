# 🤝 Handoff — Bug: self-booking `user_id` = null ในหน้า report (🛡️ Identity-detach guard)

> **วันที่:** 2026-11-18 · **จดโดย:** session ฝั่ง backend (`C:\dev\hotel`, branch `dev`)
> **สถานะ:** ✅ fix ขึ้น `dev` + `main` แล้ว · ✅ ยืนยันบน domain จริงแล้ว · 🟡 ค้าง: remediation บิลเก่า + audit payload ฝั่ง FE

---

## 1) ปัญหา & root cause (สั้น)

- FE report บิล **self-booking** (`POST /bookings` ด้วย token user ปกติ) มี `user_id = null`
- `POST /bookings` ตั้ง `user_id` จาก token **ถูกต้องเสมอ** — ตัวร้ายคือ **`PUT /bookings/{id}`** (`BookingManagementController::updateBookingPayment`, admin edit)
- Contract เดิม (organization-bookings ticket 07): ส่ง `user`/`organize` = null ชัด ๆ = detach identity → backend เช็คด้วย `$request->has()` ซึ่ง **คืน `true` แม้ค่าเป็น `null`/`""`** → FE generic form แถม identity keys เป็น null ตอน save ฟอร์มแก้ → **ล้าง `user_id` + `organization_id` + `customer_*` ทิ้งเฉย ๆ**

## 2) สิ่งที่ทำไปแล้ว (เสร็จสมบูรณ์)

- **Fix (owner decision):** detach ต้องส่ง **`detach_identity: true`** เท่านั้น · `user`/`organize` เป็น `null`/`""` ไม่มี flag = **no-op** · flag คู่กับ `user`/`organize`/`customer_*` ค่าใหม่ = **422** · flag อยู่ใน identity group (state guard `complete`/`no_show` ยังครอบ)
- **Commits:** `dev` @ `3751fc6` → `main` @ `28c4786` (sync ผ่าน `scripts/sync-main.sh` ตามธรรมเนียม — conflict modify/delete แก้ด้วย `git rm` ไฟล์ excluded แล้ว re-run)
- **ไฟล์:** `app/Http/Controllers/Api/V1/BookingManagementController.php` (+25) · `app/Http/Requests/UpdateBookingPaymentRequest.php` (+5) · `tests/Feature/BookingAttachCustomerTest.php` (15 tests) · `docs/api_patches/2026-11-18-identity-detach-guard.md` · `cline.md`
- **ตรวจ:** suite เต็ม **992 passed (4,268 assertions)** · `pint --dirty` PASS

## 3) ✅ ยืนยันบน domain จริงแล้ว (2026-11-18)

- Script: `test_scripts/test_detach_guard_remote.php` *(โฟลเดอร์ `test_scripts/` untracked — มีเฉพาะเครื่อง dev)* · run: `php test_scripts/test_detach_guard_remote.php`
- ผล **ผ่านครบ 4 เช็ค** บน `https://ku-home.ku.ac.th/backend/api/v1`:
  1. `PUT {"user":null,"organize":null,"customer_*":null}` (payload แบบ FE generic form) → `user_id` **รอด** (no-op ทำงานจริง)
  2. `PUT {"detach_identity": true}` → `user_id` เป็น null (ถอดตาม flag)
  3. flag คู่ `user` ค่าใหม่ → **422**
  4. `PUT {"user": "<uuid>"}` → `user_id` กลับมาครบ (re-attach ใช้ได้จริง)
- เก็บกวาดบิลทดสอบเรียบร้อย (โดน 429 → พัก 65 วิ → retry ผ่าน ตามธรรมเนียม)
- **สรุป:** server รันโค้ด fix แล้ว — **ไม่ต้อง** restart php-fpm / ล้าง opcache เพิ่ม

## 4) 🟡 งานค้าง — session ถัดไปรับช่วงทำอะไร

### 4.1 [Prod remediation] บิลเก่าที่โดนล้าง **ไม่ฟื้นเอง** — ต้อง re-attach ด้วยมือ
- **วิธีหาบิลที่โดนกระทบ** (แยกจากเฮดเปล่าโหมด B by design): `user_id IS NULL AND organization_id IS NULL AND customer_name IS NULL` — บิลโหมด B จะมี `customer_name` snapshot ติดเสมอ
- **แก้:** `PUT /api/v1/bookings/{id}` body `{"user": "<user-uuid>"}` (ยืนยันบน domain จริงแล้ว — สถานะบิลอื่น ๆ ไม่กระทบ)
- **trace เจ้าของตัวจริง:** ดู `status_change_logs` (causer_id / role) ของบิลนั้น — ตัวที่ submit slip / transition ล่าสุดเป็น user ตัวนั้นคือเจ้าของที่ควร attach กลับ (identity edit ไม่เข้า status_change_logs เพราะไม่ transition สถานะ)

### 4.2 [FE audit] payload ของ `PUT /bookings/{id}` (ฟอร์มแก้บิล admin)
- `POST /bookings` ตรวจ payload จากโค้ด FE แล้ว **ปลอดภัยทั้ง 2 โหมด** (user: `source=online`, identity จาก token · admin: `user` หรือ `organize` + `customer_*`) — **ห้ามสรุปว่า PUT ปลอดภัยตามไปด้วย ยังไม่ได้ดู**
- FE ควรเลิกแถม `user`/`organize`/`customer_*` เป็น `null` ใน PUT (backend ทำ no-op ให้แล้ว แต่ตาม contract ใหม่ควรส่งเฉพาะ field ที่ตั้งใจแก้) · ถ้าตั้งใจถอด identity → ส่ง `detach_identity: true`
- **หมายเหตุ:** repo FE (`ku-home`) **ไม่อยู่บนเครื่องนี้** (ค้น `C:\dev` แล้วไม่เจอ) — ฝั่งรับช่วงต้องเปิด repo FE เอง · ref จาก FE team: `src/entities/booking/api/bookings.ts` (createBooking · POST), `src/features/admin/AdminBooking/lib/adminBookingApi.ts` (createAdminBooking · POST) → **จุดที่ต้องตามต่อ = ฟังก์ชัน save/update ที่ยิง `PUT /bookings/{id}`**

### 4.3 [FE comms] แจ้ง patch note ให้ทีม FE
- `docs/api_patches/2026-11-18-identity-detach-guard.md` — TL;DR 4 ข้อ (ห้ามแถม null · detach ใช้ flag · flag+ค่า = 422 · บิลเก่า attach คืนด้วย `{"user": uuid}`) + ตารางพฤติกรรม + ตัวอย่าง request

## 5) Refs

- `cline.md` หัวข้อ **"🛡️ Identity-detach guard (2026-11-18)"**
- Patch note FE: `docs/api_patches/2026-11-18-identity-detach-guard.md`
- Tests: `tests/Feature/BookingAttachCustomerTest.php` (15 cases) · remote: `test_scripts/test_detach_guard_remote.php`
