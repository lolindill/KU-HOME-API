# 05: หลักฐานการเข้าพักตอน check-in — อัปโหลดผ่าน images เก็บ 1 ปี (REQ-033)

- **label:** `wayfinder:task`
- **type:** AFK
- **status:** open
- **blocked-by:** (ไม่มี — frontier)
- **assignee:** (ว่าง)
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

(ยังว่าง)
