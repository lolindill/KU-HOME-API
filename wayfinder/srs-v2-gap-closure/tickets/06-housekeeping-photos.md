# 06: รูปแม่บ้านก่อน/หลัง + ของลืม + ความเสียหาย เก็บ 3 เดือน (REQ-048)

- **label:** `wayfinder:task`
- **type:** AFK
- **status:** open
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

(ยังว่าง)
