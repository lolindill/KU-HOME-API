---
label: wayfinder:task
type: AFK
title: "Param gate helper + availability summary endpoint — tracer bullet"
status: open
assignee: antigravity (2026-09-16)
blocked-by: []
---

# 01: Param gate helper + availability summary endpoint (tracer bullet)

**What to build:** เส้นทางแนวตั้งเส้นแรกจนจบ (tracer bullet) สำหรับฟีเจอร์ `include_reserved`:
1. สร้าง **shared gate resolution helper** (เช่น `app/Support/IncludeReservedGate.php` หรือ trait) ตรวจสอบเงื่อนไขว่า flag มีผลหรือไม่:
   - ทำงานเฉพาะเมื่อผู้ใช้ล็อกอินมี role เป็น `admin` (`$request->user('sanctum')?->role === 'admin'`) และส่ง boolean param `include_reserved=true` (ผ่าน query string หรือ request body)
   - หากเป็น non-admin หรือ anonymous (ผู้ใช้ทั่วไปหรือไม่ได้ล็อกอิน) ส่ง flag มา → ให้ **เมินเฉยเงียบ ๆ (silently ignored)** ห้ามคืน 403
   - ออกแบบให้เป็น single source of truth เพื่อให้ tickets 02–05 นำไป reuse ได้ทันที ไม่เขียน logic ซ้ำซ้อน
2. ใช้งานบน **summary availability endpoint** (`GET /api/v1/availability` ใน `RoomController::availability`):
   - **Admin ส่ง flag:**
     - Pool count รวมห้อง `reserved_closed` (สูตร: `available_rooms` = sellable + reserved − booked แทนที่ค่าเดิม)
     - คืน field โปร่งใสเพิ่มเติม: `sellable_rooms` (จำนวนห้องขายปกติที่ไม่รวม reserved) และ `reserved_rooms` (จำนวนห้องสำรอง)
     - บันทึกใน `search_criteria` ว่า `'include_reserved' => true`
     - King counters (`king_total_rooms`) เดินตามกฎ extended pool เช่นเดียวกัน (รวมห้อง king ที่เป็น `reserved_closed` เมื่อส่งทั้ง `bed_type=king_size` และ `include_reserved=true`)
   - **Non-admin / Anonymous ส่ง flag:** เมินเฉยเงียบ ๆ คืน payload ปกติเหมือนไม่ส่ง flag
   - **ไม่ส่ง flag:** Response ต้อง **byte-identical** กับพฤติกรรมปัจจุบัน 100% (backward-compatible)
   - **กฎเหล็กสัมบูรณ์ (Maintenance is absolute):** ห้องสถานะ `maintenance` ต้อง**ไม่**ถูกนับเข้า pool ใด ๆ ทั้งสิ้น ไม่ว่าจะส่ง flag หรือไม่ก็ตาม

**Files / Surfaces touched:**
- `app/Support/IncludeReservedGate.php` (หรือตำแหน่ง support helper กลางที่เหมาะสม)
- `app/Http/Controllers/Api/V1/RoomController.php` (method `availability`)
- Tests: `tests/Feature/RoomAvailabilityIncludeReservedTest.php`

**Blocked by:** None (frontier — can start immediately)

**Status:** ready-for-agent

- [ ] สร้าง shared gate resolution helper ตัวเดียวใน support namespace เช็ค sanctum role `admin` + boolean `include_reserved`
- [ ] Non-admin และ anonymous ส่ง `include_reserved=true` เข้า `GET /api/v1/availability` → คืน payload ปกติเหมือนไม่ส่ง flag ทุกไบต์ (ห้ามคืน 403)
- [ ] Admin ส่ง `include_reserved=true` → `available_rooms` ถูกแทนที่ด้วย extended pool + มีฟิลด์ `sellable_rooms` และ `reserved_rooms` + `search_criteria.include_reserved = true`
- [ ] King counters (`king_total_rooms`) ปรับตาม extended pool เมื่อส่งคู่กับ `bed_type=king_size`
- [ ] ไม่ส่ง flag → response ต้อง byte-identical กับระบบเดิม (regression test)
- [ ] ห้องสถานะ `maintenance` ไม่ถูกนับใน pool ทุกกรณี
- [ ] Single HTTP feature seam tests ครอบคลุม contract ทั้งหมด
- [ ] `php artisan test` เขียวทั้ง suite + `vendor/bin/pint --dirty`

**Spec Reference:**
- [spec.md § Solution](../spec.md#solution)
- [spec.md § Implementation Decisions](../spec.md#implementation-decisions) (Param contract, Surfaces touched #1 & #2, Response shape under the flag, Maintenance is absolute)
- [spec.md § Testing Decisions](../spec.md#testing-decisions)
