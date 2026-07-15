# 📋 Plan: ย้าย `check_in`/`check_out` + State Machine 2 ชั้น (Final)

> **วันที่สร้าง:** 2026-06-24
> **อัปเดตล่าสุด:** 2026-06-24 (post-scrutinize + user decisions)
> **สถานะ:** รอ implement
> **ขอบเขต:** Fresh start — ไม่ต้องเก็บ/ย้ายข้อมูลเดิม (migrate:fresh)

---

## 🎯 เป้าหมาย

ย้าย `check_in`, `check_out` และสถานะการเข้าพักจาก `bookings` ไปยัง `booking_rooms`
เพื่อให้แต่ละห้องในการจองเดียวกันมีวันเข้า-ออกและสถานะแยกกันได้

---

## 📐 สถาปัตยกรรม State Machine 2 ชั้น

| ชั้น | Entity | State Flow | หน้าที่ |
|------|--------|------------|---------|
| **การเงิน/การจอง** | `Booking` | `draft → paid → confirmed → completed` + `confirmed → no_show` | ยืนยันการจอง + การเงิน |
| **ห้องพัก** | `BookingRoom` | `draft → confirmed → checked_in → checked_out` | สถานะห้องเชิงกายภาพ |

### ทำไมถึงแบ่ง 2 ชั้น?

1. **Payment/Receipt ไม่กระทบ** — `$booking->status` + `$booking->total_amount` + `$booking->is_paid` ยังทำงานเหมือนเดิม
2. **Check-in/Check-out ทีละห้องได้** — เพราะ status แยกที่ `booking_rooms`
3. **DailyRoomMaintenance** ใช้ `booking.status = 'confirmed'` + `booking_room.check_in = today`
4. **Booking auto-complete** — เมื่อทุก BookingRoom checkout แล้ว Booking จะ `confirmed → completed` อัตโนมัติ

### 🚫 สิ่งที่ถูกลบออกจาก flow เดิม
- ❌ `cancelled` (Booking) — ไม่มีการยกเลิกทั้งใบแล้ว
- ❌ `deleted` (Booking state) — draft ที่หมดอายุจะถูก hard delete record โดยตรง (status ค้าง `draft`)
- ❌ `checked_in`/`checked_out` (Booking) — ย้ายไป BookingRoom แล้ว

### ✅ สถานะใหม่
- ✨ `completed` (Booking) — terminal state เมื่อลูกค้า checkout ครบทุกห้อง
- ✨ `no_show` (Booking) — `confirmed → no_show` (admin only) กรณีลูกค้า confirm แล้วไม่มาเข้าพัก

---

## 🎬 Sample Flows

### Flow A: Online Booking (จอง 3 ห้องล่วงหน้า)
```
[1] User จอง        → Booking: draft | BookingRoom ×3: draft
[2] Webhook ชำระ    → Booking: draft → paid | BookingRoom ×3: ยัง draft
[3] Admin confirm   → Booking: paid → confirmed | SYNC BookingRoom ×3: draft → confirmed
[4] Check-in #1     → BookingRoom#1: confirmed → checked_in
[5] Check-in #2,#3  → BookingRoom#2,#3: confirmed → checked_in
[6] Check-out #1    → BookingRoom#1: checked_in → checked_out
[7] Check-out #3    → BookingRoom#3: checked_in → checked_out → AUTO Booking: confirmed → completed
```

### Flow B: Walk-in (1 ห้อง จ่ายเงินสดทันที)
```
→ Booking: draft → paid → confirmed (admin ทำครั้งเดียว)
→ BookingRoom: draft → confirmed → checked_in
→ Payment บันทึก (cash)
```

### Flow C: No-show
```
→ Admin เลือก booking ที่ confirmed แต่ลูกค้าไม่มา
→ Booking: confirmed → no_show (admin only)
```

### Flow D: Draft หมดอายุ
```
→ CleanupExpiredDrafts ตรวจ payment_deadline
→ หมดอายุ → hard delete record (status ยังเป็น draft ไม่ต้อง transition state)
```

---

## 🔧 Sync Mechanism (ฝังใน state machine)

กลไก sync ระหว่าง Booking ↔ BookingRoom ฝังอยู่ใน `transitionStatus()` ของแต่ละ model เพื่อ:
- ✅ Single source of truth (เปลี่ยนได้ทางเดียว)
- ✅ Side effect มองเห็นได้ใน model
- ✅ ลืมเรียกไม่ได้
- ✅ ทำงานใน DB transaction (atomic)

```php
// Booking::transitionStatus() — เมื่อ → confirmed
DB::transaction(function () use ($newStatus, $userRole) {
    // ... existing validation + state change ...
    $this->save();

    // ✨ SYNC: paid → confirmed → sync BookingRoom draft → confirmed
    if ($newStatus === 'confirmed') {
        $this->bookingRooms()->where('status', 'draft')->update(['status' => 'confirmed']);
    }
});

// BookingRoom::transitionStatus() — เมื่อ → checked_out
DB::transaction(function () use ($newStatus, $userRole) {
    // ... existing validation + state change ...
    $this->save();

    // ✨ AUTO: checked_in → checked_out แล้วเช็คทุกห้อง
    if ($newStatus === 'checked_out') {
        $allCheckedOut = !$this->booking->bookingRooms()
            ->where('status', '!=', 'checked_out')
            ->exists();

        if ($allCheckedOut && $this->booking->status === 'confirmed') {
            $this->booking->transitionStatus('completed', $userRole);
        }
    }
});
```

---

## 🔍 Findings จาก Scrutinize (แก้แล้วทั้งหมด)

### 🔴 BLOCKER #1: Availability check อ้างถึง `booking.check_in`/`booking.check_out` — ✅ แก้แล้ว
- **ไฟล์:** `BookingController.php:153-158`, `BookingRoom.php:87-88`
- **แก้:** กรองด้วย `booking_rooms.check_in`/`check_out`/`status` โดยตรง

### 🔴 BLOCKER #2: การคำนวณ `$nights` ใช้วันที่ระดับ booking — ✅ แก้แล้ว
- **ไฟล์:** `BookingController.php:139-141, 194-207`
- **แก้:** คำนวณ `$nights` **ใน loop ของแต่ละห้อง**

### 🟡 MAJOR #3: `applyDateFilter` และ `DailyRoomMaintenance` — ✅ แก้แล้ว
- **แก้:** `whereHas('bookingRooms', fn($q) => $q->whereDate('check_in', ...))`

### 🟡 MAJOR #4: `RoomController::availability` — ✅ แก้แล้ว
- **แก้:** query ผ่าน `bookingRooms` แทน `booking`

### 🟡 MAJOR #5: Backfill ข้อมูลเดิม — ไม่ต้องทำ (fresh start)

---

## 🛠️ แผนการเปลี่ยนแปลง

### Phase 1: Migration + Models

- [ ] **1.1 Migration ใหม่:** `move_dates_to_booking_rooms.php`
  - Drop `check_in`, `check_out` จาก `bookings`
  - เพิ่ม `check_in`, `check_out`, `status` (default 'draft') ใน `booking_rooms`
  - **ไม่มี** `price_amount` (ใช้ `rate_daily × nights` เหมือนเดิม)
  - เพิ่ม composite index: `booking_rooms(room_type_id, check_in, check_out, status)`

- [ ] **1.2 `Booking` model:**
  - ลบ `check_in`, `check_out` จาก `$fillable` และ `$casts`
  - State machine: `draft → paid → confirmed → completed` + `confirmed → no_show`
  - ลบ `cancelled`, `deleted`, `checked_in`, `checked_out` ออกจาก transitions
  - Role restrictions:
    - `draft → paid`: user, guest, admin, system (webhook)
    - `paid → confirmed`: admin only
    - `confirmed → completed`: admin/system (auto-trigger)
    - `confirmed → no_show`: admin only
  - เพิ่ม sync logic ใน `transitionStatus()` (เมื่อ `→ confirmed` → sync BookingRoom `draft → confirmed`)

- [ ] **1.3 `BookingRoom` model:**
  - เพิ่ม fillable: `check_in`, `check_out`, `status`
  - เพิ่ม casts: `check_in` => date, `check_out` => date
  - เพิ่ม state machine `transitionStatus($newStatus, $userRole)`:
    - Flow: `draft → confirmed → checked_in → checked_out`
    - Role restrictions:
      - `draft → confirmed`: auto-sync จาก Booking (ไม่ manual)
      - `confirmed → checked_in`: admin only
      - `checked_in → checked_out`: admin only
  - Auto-trigger `Booking → completed` เมื่อทุกห้อง `checked_out`
  - แก้ `assignAvailableRoom()` ใช้ `$this->check_in`/`$this->check_out`/`$this->status`

### Phase 2: Controllers + Requests

- [ ] **2.1 `StoreBookingRequest`:**
  - ย้าย validation `check_in`/`check_out` ไปอยู่ใน `booking_rooms.*.check_in`/`check_out`
- [ ] **2.2 `UpdateBookingRequest`:**
  - ลบ `check_in`/`check_out` ออก
- [ ] **2.3 `BookingController::createBooking()`:**
  - คำนวณ `$nights` ใน loop ของแต่ละห้อง (รองรับวันที่ต่างกัน)
  - ส่ง `check_in`/`check_out` ให้ `BookingRoom::create()`
  - ตั้ง `booking_room.status = 'draft'`
- [ ] **2.4 `BookingController` availability query:**
  - filter ทั้ง Booking status + BookingRoom status:
    ```php
    ->whereHas('booking', fn($q) => $q->whereIn('status', ['paid', 'confirmed']))
    ->whereIn('status', ['confirmed', 'checked_in']) // BookingRoom status
    ```
- [ ] **2.5 `BookingController::applyDateFilter()`:**
  - เปลี่ยนเป็น `whereHas('bookingRooms', fn($q) => $q->whereDate('check_in', ...))`
- [ ] **2.6 `RoomController::availability()`:**
  - แก้ query ผ่าน `bookingRooms` แทน `booking`
- [ ] **2.7 `FrontDeskController::checkIn()`:**
  - loop `$bookingRoom->transitionStatus('checked_in')` แทน `$booking->transitionStatus`
- [ ] **2.8 `FrontDeskController::checkOut()`:**
  - loop `$bookingRoom->transitionStatus('checked_out')` แทน `$booking->transitionStatus`
- [ ] **2.9 `FrontDeskController::walkIn()`:**
  - ส่ง `check_in`/`check_out` ให้ BookingRoom + ตั้ง status `checked_in`
  - Booking: `draft → paid → confirmed` (admin)
  - BookingRoom: `draft → confirmed → checked_in` (auto-sync + admin)
- [ ] **2.10 `DailyRoomMaintenance`:**
  - query ผ่าน `booking_rooms` แทน `bookings`
  - ลบ `bookingRooms.booking` self-reference ที่ซ้ำซ้อนใน `with()`
- [ ] **2.11 `CleanupExpiredDrafts`:**
  - เปลี่ยนจาก transition `deleted` → hard delete record (status ยังเป็น `draft`)

### Phase 3: Tests + Docs

- [ ] **3.1 แก้ Tests:**
  - `tests/Unit/BookingStateTest.php` — flow ใหม่ + sync logic
  - `tests/Feature/BookingTest.php` — create booking ใหม่ + dates ที่ BookingRoom
  - `tests/Feature/FrontDeskTest.php` — check-in/out per room + walk-in flow
  - `tests/Feature/RoomTest.php` — availability query
  - `tests/Feature/PaymentTest.php` — webhook + confirm sync
  - `tests/Feature/RouteProtectionTest.php` — verify ไม่พัง
- [ ] **3.2 แก้ Seeder:** `database/seeders/DatabaseSeeder.php`
- [ ] **3.3 อัปเดต Docs:**
  - `cline.md` — state machines + entity map
  - `docs/database-er.md` — โครงสร้างใหม่

---

## ⚠️ Notes สำคัญ

1. **Booking confirmed → sync booking_rooms:** เมื่อ booking จ่ายเงินครบและ admin confirm → ทุก `booking_room.status` จะถูก sync จาก `draft` → `confirmed` อัตโนมัติ (ฝังใน `Booking::transitionStatus()`)
2. **BookingRoom checkout → auto complete Booking:** เมื่อ BookingRoom สุดท้าย checkout แล้ว Booking จะ `confirmed → completed` อัตโนมัติ (ฝังใน `BookingRoom::transitionStatus()`)
3. **Check-in ทีละห้อง:** รองรับการ check-in ทีละห้องในจองหลายห้อง
4. **Payment:** ยังจ่ายรวมทั้งใบจอง (ไม่เปลี่ยน)
5. **ไม่มี cancel:** การยกเลิกทั้งใบถูกลบออกแล้ว — ใช้ `no_show` สำหรับกรณี confirm แล้วไม่มา
6. **Draft cleanup:** ใช้ hard delete แทน state `deleted`

---

## 📁 ไฟล์ที่กระทบ (ทั้งหมด)

### Models
- `app/Models/Booking.php` — state machine ใหม่ + sync logic + ลบ fillable/casts
- `app/Models/BookingRoom.php` — state machine ใหม่ + auto-complete + dates fillable

### Migrations
- `database/migrations/XXXX_XX_XX_XXXXXX_move_dates_to_booking_rooms.php` (สร้างใหม่)

### Controllers
- `app/Http/Controllers/Api/V1/BookingController.php`
- `app/Http/Controllers/Api/V1/RoomController.php`
- `app/Http/Controllers/Api/V1/FrontDeskController.php`
- `app/Http/Controllers/Api/V1/DashboardController.php`

### Requests
- `app/Http/Requests/StoreBookingRequest.php`
- `app/Http/Requests/UpdateBookingRequest.php`

### Console
- `app/Console/Commands/DailyRoomMaintenance.php`
- `app/Console/Commands/CleanupExpiredDrafts.php`

### Tests
- `tests/Unit/BookingStateTest.php`
- `tests/Feature/BookingTest.php`
- `tests/Feature/FrontDeskTest.php`
- `tests/Feature/RoomTest.php`
- `tests/Feature/PaymentTest.php`
- `tests/Feature/RouteProtectionTest.php`

### Seeders
- `database/seeders/DatabaseSeeder.php`

### Docs
- `cline.md`
- `docs/database-er.md`