# 06: รูปแม่บ้านก่อน/หลัง + ของลืม + ความเสียหาย เก็บ 3 เดือน (REQ-048)

- **label:** `wayfinder:task`
- **type:** AFK
- **status:** closed
- **blocked-by:** (ไม่มี — frontier)
- **assignee:** agent-hub:antigravity/gemini-3.8-flash (manager dispatch 2026-10-14)
- **summary:** อัปโหลดรูปงานแม่บ้าน 4 หมวด cap ก่อน/หลัง 5+5 ผ่าน images morph เก็บ 90 วัน
- **phase:** 3

## Question

SRS REQ-048: อัปโหลดรูปทำความสะอาด — ก่อน/หลัง อย่างละ **5 ภาพ**, ของที่ลืม, ความเสียหาย, หมายเหตุ (ข้อความ) — **เก็บ 3 เดือน** ปัจจุบัน `housekeeping_photos` เป็นแค่ scaffold (`task_id` + `photo_path`) ไม่มี route อัปโหลดเลย และไม่มี retention

**Decision ที่ล็อกแล้ว (owner 2026-10-14 + AGENTS.md):**

- ใช้ **`images` table (morph `HousekeepingTask`)** — AGENTS.md กำหนดไว้ชัด "อย่าสร้างตารางรูปใหม่" → ตาราง `housekeeping_photos` ถือเป็น dead scaffold (พิจารณาทิ้ง/ลบ migration ที่ไม่เคยใช้ — จดใน Resolution)
- หมวด: `before` / `after` (cap 5+5 ต่อ task) / `forgotten_item` / `damage` · หมายเหตุเป็นข้อความบน task (มี `notes` อยู่แล้วหรือ field เฉพาะ cleanup note — ตรวจ schema ก่อนตัดสิน)
- retention **90 วัน** — ต่อ `app:cleanup-images` หรือ command ใหม่ก็ได้ ให้จดเหตุผล

**จุดแตะ:**

- Routes ใต้ dashboard tasks (เช่น `POST /dashboard/tasks/{id}/photos`, `GET .../photos`) — housekeeping อัปโหลดงานตัวเองได้, admin ดูได้หมด (จดสิทธิ์ชัด)
- Cap 5+5: server-side validate ก่อนบันทึก (เกิน → 422)
- ⚠️ อย่าชนกับ REQ-042 ค่าเสียหาย: รูป `damage` = หลักฐานอย่างเดียว ไม่มีผลทางบัญชี (ยอดค่าเสียหายเป็นของ ticket 12)
- Tests: cap ครบ 5 → ภาพที่ 6 โดน 422 · retention 90 วัน · signed URL อายุ 15 นาที · housekeeping อัปโหลดงานตัวเอง / งานคนอื่น 403
- `php artisan test` เขียว + `docs/api_guide.md`

## Resolution

- **สิ่งที่ทำ:**
  1. สร้าง migration `2026_10_14_100000_drop_housekeeping_photos_and_add_category_to_images.php` ทำการ drop ตาราง dead scaffold `housekeeping_photos` และเพิ่ม column `category` (nullable indexed string) ให้กับตาราง `images`
  2. ลบ model `app/Models/HousekeepingPhoto.php` และปรับ `HousekeepingTask::photos()` ให้เป็น `MorphMany` เชื่อมโยงกับ `App\Models\Image` (พร้อม alias `images()`) และเพิ่ม `booted()` deleting hook เพื่อ cascade ลบรูปภาพและไฟล์จริงบน disk เมื่อ task ถูกลบ
  3. เพิ่ม `category` ใน `$fillable` ของ `App\Models\Image`
  4. เพิ่ม Routes ใน `routes/api.php` ใต้กลุ่ม `role:admin,housekeeping` และ prefix `dashboard`:
     - `POST /api/v1/dashboard/tasks/{id}/photos` (`DashboardController@uploadPhoto`)
     - `GET /api/v1/dashboard/tasks/{id}/photos` (`DashboardController@listPhotos`)
  5. พัฒนา `DashboardController@uploadPhoto` และ `DashboardController@listPhotos`:
     - รองรับ 4 หมวด: `before`, `after`, `forgotten_item`, `damage`
     - Server-side validate cap: `before` และ `after` สูงสุดอย่างละ 5 ภาพต่อ task หากเกินปฏิเสธด้วย HTTP `422 Unprocessable Entity`
     - หมวด `forgotten_item` และ `damage` ไม่จำกัด cap 5
     - ⚠️ รูปภาพหมวด `damage` เป็นหลักฐานภาพถ่ายเท่านั้น ไม่มีผลทางบัญชี ไม่มีการสร้างรายการชำระเงินหรือบันทึกค่าเสียหาย
     - บันทึกหมายเหตุงานลงฟิลด์ `notes` ของ task เมื่อมีการส่งฟิลด์ `notes` มาพร้อมกับการอัปโหลด
     - ส่งออก temporary signed URL อายุ 15 นาที (`images.file`) สำหรับเปิดดูรูปภาพ
     - สิทธิ์ (Authorization): แม่บ้านอัปโหลดและดูได้เฉพาะ task ที่ได้รับมอบหมาย (`assigned_to === auth.id`) หากเป็นงานของแม่บ้านคนอื่นจะได้ `403 Forbidden`; แอดมินมีสิทธิ์อัปโหลดและดูได้ทุก task; บทบาทอื่นโดน middleware ปฏิเสธ 403
  6. ปรับปรุง `app:cleanup-images` (`CleanupImages.php`):
     - เพิ่มการ sweep ไฟล์กำพร้าในโฟลเดอร์ `housekeeping` บน private disk
     - เพิ่มการ sweep แถวรูปภาพกำพร้าที่ผูกกับ `HousekeepingTask` ที่ถูกลบไปแล้ว
     - เพิ่ม Retention policy ลบรูปภาพของ `HousekeepingTask` ที่เก่าเกิน 90 วัน (`HOUSEKEEPING_PHOTO_RETENTION_DAYS` default 90)
  7. เขียนเอกสาร API ส่งมอบไว้ที่ `wayfinder/srs-v2-gap-closure/research/api-doc-handoff-06.md`

- **การตัดสินใจ (Decisions):**
  1. *Scaffold `housekeeping_photos`:* ตัดสินใจ drop ตาราง `housekeeping_photos` ผ่าน migration ใหม่ และลบ model `HousekeepingPhoto.php` เนื่องจากเป็น dead code เดิมที่ไม่มีการใช้งานจริง และเพื่อให้เป็นไปตามกฎของ `AGENTS.md` ที่กำหนดให้ใช้ตาราง `images` (morph `HousekeepingTask`) เท่านั้น
  2. *หมายเหตุ (Notes):* ตรวจสอบ schema พบว่าตาราง `housekeeping_tasks` มีคอลัมน์ `notes` (text nullable) และอยู่ใน `$fillable` อยู่แล้ว จึงตัดสินใจใช้คอลัมน์ `notes` เดิมที่มีอยู่ในการบันทึกหมายเหตุงานทำความสะอาด ไม่ต้องเพิ่มคอลัมน์ใหม่
  3. *Retention 90 วัน:* ตัดสินใจขยายการทำงานของคำสั่ง `app:cleanup-images` เดิม แทนการสร้าง command ใหม่ เนื่องจากคำสั่งนี้ถูกตั้งเวลาใน `routes/console.php` ให้รันทุกวันเวลา 02:30 น. อยู่แล้ว เป็นจุดรวมศูนย์นโยบายการจัดเก็บรูปภาพบน local disk และลดภาระในการตั้ง cron schedule ซ้ำซ้อน

- **ผลการทดสอบ (Test Results):**
  - สร้าง Feature test suite `tests/Feature/HousekeepingPhotoTest.php` ครอบคลุม 18 กรณีทดสอบ (71 assertions) ผ่าน 100%
  - รันชุดทดสอบทั้งระบบ (`php artisan test`): **ผ่าน 100% ทั้งหมด 667 tests (2,676 assertions)** โดยไม่มีข้อผิดพลาดใด ๆ
