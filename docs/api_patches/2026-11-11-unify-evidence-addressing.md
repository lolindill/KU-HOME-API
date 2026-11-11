# 📢 API Patch — 2026-11-11 · Unify Evidence Addressing (breaking URL-level change)

> **Branch:** `feature/unify-evidence-addressing` (ticket 04 — `api-route-hygiene`)
> **ผู้กระทบ:** ทุก client ที่เรียก upload evidence หรือ get evidences (ปัจจุบัน zero caller ฝั่ง frontend)
> **อ่านคู่:** [`api_guide.md`](../api_guide.md) (contract ฉบับอัปเดต) · [`cline.md`](../../cline.md)

---

## TL;DR

1. **ย้าย addressing ของ Check-in Evidence ให้เป็น nested prefix `/front-desk/{bookingId}/rooms/{bookingRoomId}/evidence`** ตรงกับพี่น้องในกลุ่ม Front Desk (check-in, check-out, change-room, split-upgrade)
2. **ถอดเส้นทางเดิม `/front-desk/booking-rooms/{id}/evidence` ออกทั้งหมด** (ไม่ทิ้ง tombstone — ยิงมาได้ `404 Not Found`)
3. **Scope Guard ใหม่:** หาก `bookingRoomId` ไม่ได้อยู่ภายใต้ `bookingId` ที่ระบุ จะตอบกลับ `404 Not Found` (ไม่ใช่ 403 — ป้องกัน enumeration และ data tampering ตาม precedent ownership)
4. **พฤติกรรมเดิมคงเป๊ะทุกด้าน:** Retention 365 วัน, RBAC `admin,staff`, signed URL 15 นาที, `RequireJsonAccept` (JSON API ปกติ ไม่ exempt)

---

## 🔴 Breaking changes

### 1. เส้นทาง URL เปลี่ยนเป็น Nested Addressing (Sibling Parity)

| รายการ | เดิม | ใหม่ |
|---|---|---|
| **POST Upload Evidence** | `/api/v1/front-desk/booking-rooms/{id}/evidence` | `/api/v1/front-desk/{bookingId}/rooms/{bookingRoomId}/evidence` |
| **GET List Evidences** | `/api/v1/front-desk/booking-rooms/{id}/evidence` | `/api/v1/front-desk/{bookingId}/rooms/{bookingRoomId}/evidence` |
| **Status เส้นเดิม** | รองรับ | ❌ **ถูกถอดออกทันที (404 Not Found)** |
| **URL Parameters** | `{id}` (`booking_rooms.id`) | `{bookingId}` (`bookings.id`) + `{bookingRoomId}` (`booking_rooms.id`) |
| **Router UUID Guard** | `where('id', '[0-9a-f\-]{36}')` | `whereUuid(['bookingId', 'bookingRoomId'])` |

### 2. Guard: BookingRoom Scope Check (404 Not Found)

- เมื่อ client ส่ง `bookingId` และ `bookingRoomId` มา ระบบจะ resolve ผ่าน `BookingRoom::where('id', $bookingRoomId)->where('booking_id', $bookingId)->firstOrFail()`
- หากห้องพักไม่ได้เป็นของบิลดังกล่าว จะตอบกลับ **`404 Not Found`** ทันที:
```json
{
  "status": "error",
  "message": "ไม่พบข้อมูลห้องพักที่ระบุในระบบค่ะนายท่าน"
}
```
- **เหตุผลทางความปลอดภัย:** ใช้ HTTP `404` แทนที่จะเป็น `403` เพื่อป้องกันการ probing / enumeration ว่าห้องพัก UUID นั้นมีอยู่ในระบบจริงหรือไม่ (ตาม precedent ownership ของระบบ KU HOME)

---

## 🔍 หลักฐานการตรวจฝั่ง Frontend & Limitations

- **หลักฐานการตรวจสอบ:** ยืนยันจาก **Reverse index snapshot 2026-11-06 (api_pages README)** ไม่ปรากฏ caller ของ `POST/GET /front-desk/booking-rooms/{id}/evidence` เลยแม้แต่จุดเดียว (**zero caller**)
- **ข้อสรุป:** **ไม่ต้องประสาน frontend ล่วงหน้าก่อน merge** เนื่องจากยังไม่มีการเชื่อมต่อใช้งานเส้นทางนี้จาก SPA
- **ข้อจำกัด (Limitation):** Frontend repo (`ku-home`) ไม่ได้อยู่บนโฮสต์/เครื่อง dev นี้ ทำให้ไม่สามารถ `grep` codebase สด ณ วันที่ 2026-11-11 ได้โดยตรง การตัดสินใจอิงตาม snapshot เอกสารทางการล่าสุดอายุ 5 วัน

---

## 🛡️ ยืนยันพฤติกรรมที่ไม่เปลี่ยนแปลง (Unchanged Behaviors)

1. **RBAC & Authorization:** อนุญาตเฉพาะผู้ใช้ที่มี role `admin` หรือ `staff` เท่านั้น (User ทั่วไปหรือ Housekeeping ได้ `403 Forbidden`)
2. **State Machine Guard:** อนุญาตให้อัปโหลดหลักฐานได้เฉพาะห้องพักที่มีสถานะ `confirmed`, `checked_in`, `checked_out` เท่านั้น (หากห้องเป็น `draft` หรือ `no_show` จะตอบกลับ `422 Unprocessable Entity`)
3. **Data Validation:** กฎและเงื่อนไขไฟล์ (`file` ขนาดไม่เกิน 10MB, mime type: `jpeg, png, jpg, webp, pdf`) และ `type` (`id_card, registration, guest_register, pdpa, other`) คงเดิม 100%
4. **Storage & Private Disk:** ไฟล์ถูกจัดเก็บบน private disk (`storage/app/private/checkin_evidences`) และผูก polymorphic เข้ากับ `images` table ผ่าน morph relation `evidences()`
5. **Signed URL & File Access:** `GET` evidences ส่งคืน signed URL อายุ 15 นาที เพื่อเปิดไฟล์ผ่าน route `images.file` โดยต้องส่ง Sanctum Token ของเจ้าของ booking หรือ admin เท่านั้น
6. **Retention Policy (365 วัน):** Command `app:cleanup-images` (รันอัตโนมัติ 02:30 ทุกวัน) ยังคง sweeping ลบหลักฐานอายุเกิน 365 วัน และล้าง orphan files/rows ตาม REQ-033
7. **Accept Header:** ไม่มีการ exempt ออกจาก `RequireJsonAccept` middleware เนื่องจาก evidence endpoint ให้ผลลัพธ์เป็น JSON ปกติ (แตกต่างจาก `images/{id}/file` ที่ต้อง serve raw binary)

---

## Error Matrix

| Case | Endpoint | HTTP Status | Response Payload (ย่อ) |
|---|---|---|---|
| เรียกเส้นเก่า (`/booking-rooms/{id}/evidence`) | POST / GET | 404 Not Found | Standard 404 Route Not Found |
| `{bookingId}` หรือ `{bookingRoomId}` ไม่ใช่ UUID | POST / GET | 404 Not Found | Router 404 (ติด `whereUuid`) |
| ไม่พบ `bookingId` หรือ `bookingRoomId` ในระบบ | POST / GET | 404 Not Found | `{"status":"error","message":"ไม่พบข้อมูลห้องพักที่ระบุในระบบค่ะนายท่าน"}` |
| `bookingRoomId` ไม่ได้อยู่ภายใต้ `bookingId` (ข้ามบิล) | POST / GET | 404 Not Found | `{"status":"error","message":"ไม่พบข้อมูลห้องพักที่ระบุในระบบค่ะนายท่าน"}` |
| ไม่ได้ส่ง Sanctum Bearer Token | POST / GET | 401 Unauthorized | `{"message":"Unauthenticated."}` |
| Role ไม่ใช่ `admin` หรือ `staff` (เช่น `user`, `housekeeping`) | POST / GET | 403 Forbidden | `{"status":"error","message":"ไม่มีสิทธิ์... (admin หรือ staff เท่านั้น)"}` |
| สถานะห้องเป็น `draft` หรือ `no_show` | POST | 422 Unprocessable Entity | `{"status":"error","message":"ไม่สามารถอัปโหลดหลักฐานได้... (อนุญาตเฉพาะ confirmed, checked_in, checked_out เท่านั้น)"}` |
| Validation ล้มเหลว (ไม่มีไฟล์ / ขนาดเกิน / type ผิด) | POST | 422 Unprocessable Entity | FormRequest validation errors |

---

## สำหรับฝั่ง Backend & Tests

- **Controller Signature:**
  - `CheckinEvidenceController::uploadEvidence(UploadCheckinEvidenceRequest $request, string $bookingId, string $bookingRoomId)`
  - `CheckinEvidenceController::getEvidences(Request $request, string $bookingId, string $bookingRoomId)`
- **Suite Status:**
  - `CheckinEvidenceTest`: อัปเดต 14 call sites ให้ใช้ nested route + เพิ่ม 4 เคสใหม่ (Scope mismatch 404 สำหรับ POST/GET, Router non-uuid 404, Legacy flat route 404) รวม 19 passed (75 assertions)
