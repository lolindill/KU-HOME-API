# 🎟️ Discount System v2.1 — Implementation Plan & Handoff Spec

> **KU HOME API** — Hotel Management REST API (Laravel 13, PHP 8.3+, Sanctum)

---

## 1) Design Decisions (D1–D13)

| # | หัวข้อ | Decision |
|---|---|---|
| D1 | ฐานคำนวณส่วนลด | **เฉพาะค่าห้อง** (daily rate × nights) — addon (breakfast/extra_bed/early/late) **ไม่โดนลด** |
| D2 | Types | `percent` (value 1–100) · `fixed` (satang ก้อนต่อ booking_room) · `set_room_price` (satang = ราคา/คืนใหม่) |
| D3 | Targeting | `room_type_ids` json nullable — `null` = ใช้ได้ทุกประเภทห้อง |
| D4 | Windows | `usable_from/until` (datetime, เทียบกับ "now" ตอน apply/validate) + `stay_from/until` (date, ต่อห้อง: ต้อง `[check_in, check_out] ⊆ [stay_from, stay_until]`) — ทั้งคู่ nullable = ไม่จำกัด |
| D5 | Quota unit | **1 booking_room (eligible) = 1 slot** — นับทั้ง `held` และ `used` ใน global pool และ per-user |
| D6 | Limits | `max_uses` (global) และ `max_uses_per_user` ตั้งได้อิสระ · `NULL = ♾️ unlimited` · **all-or-nothing**: K ห้อง eligible ต้องว่าง global ≥ K **และ** per-user ≥ K พร้อมกัน ไม่พอ = 422 ทั้ง booking · per-user นับสะสม**ข้าม booking** |
| D7 | Lifecycle redemption | `apply` บน draft → **HELD** · booking เข้า `pending`/`verify_error`/resubmit → **ยัง HELD** · **transition เข้า `paid` หรือ `confirmed` → USED ถาวร** (= เงินเข้าจริงเท่านั้น: verify ผ่าน หรือ draft→paid เงินสด) |
| D8 | Release | FK cascade อัตโนมัติ: DELETE draft booking (manual + `CleanupExpiredDrafts` 02:00) · DELETE booking_room · swap/ลบโค้ด (delete redemptions ของ booking นั้นแล้ว re-apply) |
| D9 | Snapshot policy | **Live recompute** — redemption เป็น "ตั๋วคิวโควต้า" เท่านั้น · effect เงิน freeze ที่ `booking_rooms.room_amount/discount_amount` + `bookings.discount_code` · draft reprice ใช้**คำนิยามโค้ด + rate ล่าสุดเสมอ** · freeze point = ออกจาก draft (ส่งสลิป) · **ไม่มี DELETE /discounts/{id}** + FK restrict → โค้ดที่มี redemption หายไม่ได้ |
| D10 | Known behavior | booking `verify_error` ที่ user ทิ้งไปเลย = HELD slot ค้าง (เหมือนห้องที่ booking ค้าง — ระบบไม่มี cancel state) |
| D11 | Oversell protection | choke point เดียว (`DiscountService`) + lock (`lockForUpdate()`) + count + insert + post-insert assert ใน tx เดียว + UNIQUE(booking_room_id) |
| D12 | Money | satang integer, floor (`intdiv`), clamp · `total_amount` = Σ(room_amount − discount_amount + addon ทั้ง 4) |
| D13 | Out of scope | walk-in/front-desk discount · `min_nights` · role-targeted code · release-on-reject / admin manual slot-return |

---

## 2) Database Schema

- **`discounts` table**: UUID PK, `code` (string 50 unique uppercase), `type` (`percent`|`fixed`|`set_room_price`), `value` (int), `room_type_ids` (json nullable), `usable_from/until` (datetime nullable), `stay_from/until` (date nullable), `max_uses` (int nullable), `max_uses_per_user` (int nullable), `is_active` (PgBoolean default true).
- **`discount_redemptions` table**: UUID PK, `discount_id` (FK restrict), `booking_room_id` (FK cascadeOnDelete), `user_id` (FK), `status` (`held`|`used` default `held`), indexes `['discount_id', 'status']`, `['discount_id', 'user_id']`.
- **`bookings` table**: `discount_code` (string 50 nullable).
- **`booking_rooms` table**: `room_amount` (int default 0), `discount_amount` (int default 0).

---

## 3) Lifecycle State Machine

```
apply โค้ดบน draft ──────► HELD ──(transition เข้า paid/confirmed: verify ผ่าน / เงินสด)──► USED 🔒ถาวร
   draft→pending (ส่งสลิป)     │      ▲  ยัง HELD ตลอด pending / verify_error / resubmit
   pending→verify_error        │      └─ hook ใน Booking::transitionStatus() (จุดเดียว)
   (user แก้ตัวต่อได้)          │
                              ├─ DELETE draft booking (manual หรือ CleanupExpiredDrafts 02:00) ─┐
                              ├─ DELETE booking_room (cascade)                                 ├─► คืน slot อัตโนมัติ 🔄
                              └─ swap/ลบโค้ด (delete + re-apply / removeFromDraft) ────────────┘
```

---

## 4) Endpoints Summary

- `POST /api/v1/discounts/validate` — Validate & preview quota (`throttle:10,1`, auth required)
- `PUT /api/v1/bookings/{bookingId}/discount-code` — Apply/change discount code on draft (`throttle:5,1`, owner/admin)
- `DELETE /api/v1/bookings/{bookingId}/discount-code` — Remove discount code on draft (`throttle:5,1`, owner/admin)
- `GET /api/v1/discounts` — List discounts (`role:admin`, optional `?is_active=`)
- `POST /api/v1/discounts` — Create discount (`role:admin`, `201 Created`)
- `PUT /api/v1/discounts/{id}` — Update discount (`role:admin`)
- `PATCH /api/v1/discounts/{id}/toggle` — Toggle `is_active` (`role:admin`)
