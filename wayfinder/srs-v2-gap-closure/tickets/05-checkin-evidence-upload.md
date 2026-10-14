# 05: หลักฐานการเข้าพักตอน check-in — อัปโหลดผ่าน images เก็บ 1 ปี (REQ-033)

- **label:** `wayfinder:task`
- **type:** AFK
- **status:** closed
- **blocked-by:** (ไม่มี — frontier)
- **assignee:** agent-hub:antigravity/gemini-3.8-flash (manager dispatch 2026-10-14)
- **summary:** flow อัปโหลดบัตร ปชช./registration/PDPA ตอน check-in ผ่าน images morph เก็บ 365 วัน
- **phase:** 3

## Question

SRS REQ-033: ต้องอัปโหลดไฟล์หลักฐานได้ (บัตร ปชช., ใบลงทะเบียนการเข้าพัก Registration, guest register digital/กระดาษ, PDPA ฯลฯ) **ระยะเวลาเก็บ 1 ปี** — ระบบรูป (`images` table + private disk + signed URL 15 นาที) production-ready แล้วแต่**ไม่มี flow อัปโหลดหลักฐานตอน check-in โดยเฉพาะ** จะออกแบบยังไง?

**Decision ที่ล็อกแล้ว (owner 2026-10-14):** ใช้ระบบ `images` เดิม (morph + private disk + signed URL) เก็บ 365 วัน — ห้ามสร้างตารางรูปใหม่

**จุดตัดสินใจใน ticket นี้ (เสนอแล้วตัดสินตาม house pattern):**

- morph กับ model ไหน — เสนอ `BookingRoom` (หลักฐานผูกกับห้องที่เข้าพัก ไม่ใช่ booking รวม)
- route: ใต้ front-desk (เช่น `POST /front-desk/booking-rooms/{id}/evidence` + `GET .../evidence` คืน signed URL) — role ตามผล ticket 03
- ประเภทหลักฐานเป็น metadata (id_card / registration / guest_register / pdpa / other) ใส่ `images` metadata ที่มีอยู่ อย่าแก้ schema images ถ้าไม่จำเป็น
- retention 1 ปี: ต่อ `app:cleanup-images` หรือ command ใหม่ — ระวังอย่าชนกติกา "สลิป verified ห้ามลบ" (หลักฐาน check-in ไม่มีข้อยกเว้น verified — ตัดตามอายุ 365 วันตรงๆ ตาม SRS)

**ข้อควรระวังตาม AGENTS.md:** ลบ `Image` row = ไฟล์ถูกลบอัตโนมัติ (model hook) — อย่าลบไฟล์มือเอง · ไม่มี generic upload endpoint รูปเกิดจาก flow ของเจ้าของเสมอ

**Done เมื่อ:** อัปโหลด/ดูรูปผ่าน signed URL ได้ · cleanup ตัดเกิน 1 ปี · tests ครบ · suite เขียว + `docs/api_guide.md`

## Resolution

- **สิ่งที่ทำ:**
  1. **Migration เพิ่ม JSON metadata:** สร้าง `database/migrations/2026_10_14_110000_add_metadata_to_images_table.php` เพิ่ม column `metadata` (JSON nullable) ในตาราง `images` (จำเป็นเนื่องจาก schema เดิมไม่มี metadata column และเตรียมพร้อมสำหรับ REQ-048 รูปแม่บ้าน) โดยไม่ migrate ฐานข้อมูลจริงตามกติกา
  2. **Models:**
     - `App\Models\Image`: เพิ่ม `metadata` ใน `$fillable` และ `$casts` (`'metadata' => 'array'`)
     - `App\Models\BookingRoom`: เพิ่ม relationship `evidences(): MorphMany` ชี้ไปยัง `Image` model ผ่าน `imageable`
  3. **Form Request & Controller:**
     - สร้าง `App\Http\Requests\UploadCheckinEvidenceRequest` ตรวจสอบ `file` (required, file, mimes: jpeg,png,jpg,webp,pdf, max: 10MB), `type` (required, in: id_card, registration, guest_register, pdpa, other), `note` (optional, string, max: 1000)
     - เพิ่ม `uploadEvidence` และ `getEvidences` ใน `App\Http\Controllers\Api\V1\FrontDeskController`
       - ปลอดภัยด้วย private disk `'local'` (`storage/app/private/checkin_evidences/`)
       - รองรับ signed URL สดใหม่ 15 นาที ผ่าน accessor `$image->url` (route `images.file` exempt `RequireJsonAccept`)
       - Rollback safety: best-effort delete ไฟล์บน disk ทันทีหาก DB transaction ล้มเหลว
  4. **Routes:**
     - `POST /api/v1/front-desk/booking-rooms/{id}/evidence`
     - `GET /api/v1/front-desk/booking-rooms/{id}/evidence`
     - ลงทะเบียนใต้กลุ่ม `middleware('role:admin,staff')->prefix('front-desk')`
  5. **Retention & Cleanup Command (`app:cleanup-images`):**
     - ต่อเติมใน `App\Console\Commands\CleanupImages`:
       - Sweep orphan files ในโฟลเดอร์ `checkin_evidences` บน disk
       - Sweep orphan rows ของ `BookingRoom` ที่ถูกลบไปแล้ว
       - Retention 365 วัน สำหรับหลักฐาน check-in (`imageable_type = BookingRoom::class`) ตัดตามอายุตรง ๆ ไม่มีข้อยกเว้น (ลบผ่าน `$image->delete()` ซึ่ง model hook จะลบไฟล์บน disk ให้อัตโนมัติ)
  6. **Documentation Handoff:**
     - สรุปเอกสาร API สำหรับ merge เข้า `docs/api_guide.md` ไว้ที่ `wayfinder/srs-v2-gap-closure/research/api-doc-handoff-05.md`

- **Key Decisions:**
  - **ต่อเติมใน `app:cleanup-images`:** ไม่สร้าง command ใหม่ เพราะคำสั่งนี้มีหน้าที่ sweep images ทั้งระบบอยู่แล้ว และถูก schedule รันทุกคืน 02:30 การรวมศูนย์ทำให้ไม่ต้อง register cron ซ้ำซ้อนและลด overhead
  - **State Guard อนุญาต `['confirmed', 'checked_in', 'checked_out']`:** ในชีวิตจริงฟรอนต์อาจสแกนบัตรก่อนกดเช็คอิน (`confirmed`), สแกนระหว่างเข้าพัก (`checked_in`), หรืออัปโหลดเอกสารตกค้างหลังเช็คเอาท์ (`checked_out`); ไม่อนุญาตเฉพาะ `draft` (ยังไม่ยืนยันการจอง) และ `no_show` (ไม่มาพักจริง) โดยส่ง HTTP 422
  - **RBAC:** สิทธิ์ `admin` และ `staff` สอดคล้องกับ ticket 03 พร้อมทำ in-controller defense-in-depth re-check

- **ผลการทดสอบ:**
  - `tests/Feature/CheckinEvidenceTest.php`: 15 passed (67 assertions) ผ่านครบทุกเคส (upload, signed url, RBAC, state guard, validation, retention 365d, sweep orphan)
  - Full Test Suite: `664 passed (2672 assertions)` เขียว 100% ทั้งโปรเจกต์ (ระยะเวลา ~16 วินาที)
