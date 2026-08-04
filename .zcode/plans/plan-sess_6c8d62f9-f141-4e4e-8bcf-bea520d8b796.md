# Plan: เพิ่มฟิลด์ billing ใหม่ + rename `children` → `has_children` (boolean)

## สรุปการเปลี่ยนแปลง
เพิ่มฟิลด์ใหม่ 2 ตัว และ **rename** คอลัมน์เดิม `children` (integer count) → `has_children` (boolean, default false) บนตาราง `booking_rooms`

## 1. Migration ใหม่ (rename + add columns)
**ไฟล์ใหม่:** `database/migrations/2026_08_04_000000_rename_children_to_has_children_and_add_billing_fields_to_booking_rooms.php`

เนื่องจากโปรเจกต์นี้ใช้ `migrate:fresh --seed` เป็นประจำ (ตามที่ AGENTS.md ระบุ) จะใช้วิธี **2 ส่วน**:

**(a) แก้ migration ต้นฉบับ** `2026_03_24_071824_create_booking_rooms_table.php`:
- เปลี่ยน `$table->integer('children')->default(0)` → `$table->boolean('has_children')->default(false)`
- เพิ่ม `$table->string('billing_address')->nullable()->after('guests')`
- เพิ่ม `$table->string('billing_comment')->nullable()->after('billing_address')`
*(เพื่อให้ `migrate:fresh` สร้างสถานะล่าสุดที่ถูกต้องทันที)*

**(b) Migration ใหม่** สำหรับ DB ที่รันไปแล้ว (data migration ปลอดภัย):
```php
public function up(): void
{
    Schema::table('booking_rooms', function (Blueprint $table) {
        // เพิ่ม boolean ใหม่ก่อน (default false)
        $table->boolean('has_children')->default(false)->after('guests');
        // เพิ่ม billing fields
        $table->string('billing_address')->nullable()->after('has_children');
        $table->string('billing_comment')->nullable()->after('billing_address');
    });
    // ย้ายข้อมูล: children > 0 → has_children = true (ใช้ raw SQL เพื่อความปลอดภัยกับ PostgreSQL)
    DB::table('booking_rooms')->where('children', '>', 0)->update(['has_children' => true]);
    // ลบคอลัมน์ children เดิม
    Schema::table('booking_rooms', function (Blueprint $table) {
        $table->dropColumn('children');
    });
}
```
`down()` ย้อนกลับ: เพิ่ม `children` integer คืน + copy `has_children` → 1 + drop fields ใหม่

## 2. Model `app/Models/BookingRoom.php`
- **`$fillable`** (line 21-32):
  - ลบ `'children'`
  - เพิ่ม `'has_children'`, `'billing_address'`, `'billing_comment'`
- **`$casts`** (line 34-39):
  - ลบ `'children' => 'integer'`
  - เพิ่ม `'has_children' => \App\Casts\PgBoolean::class` *(ใช้ PgBoolean ตาม convention PostgreSQL strict boolean — ตาม AGENTS.md Critical Conventions)*
- **`getTotalGuestsAttribute()`** (line 105-108): เปลี่ยนเป็นนับแค่ guests array เท่านั้น (เพราะ `has_children` เป็น boolean flag ไม่ใช่ count อีกต่อไป):
  ```php
  return is_array($this->guests) ? count($this->guests) : 0;
  ```
  *(Behavioral change ที่ต้องแจ้ง: total_guests จะไม่นับเด็กรวมอีกต่อไป เพราะไม่มี field count เด็กแล้ว)*

## 3. Form Requests (validation rules)
- **`StoreBookingRequest.php`** (line 37): `booking_rooms.*.children` → `booking_rooms.*.has_children` (`boolean` instead of `integer`) + เพิ่ม `booking_rooms.*.billing_address` (nullable|string) + `booking_rooms.*.billing_comment` (nullable|string)
- **`StoreBookingRoomRequest.php`** (line 31): เช่นเดียวกัน (rename + add billing)
- **`UpdateBookingRoomRequest.php`** (line 29): เช่นเดียวกัน

## 4. Controllers (จุดสร้าง BookingRoom)
- **`BookingController::createBooking`** (line 242):
  ```php
  'children' => $roomRequest['children'] ?? 0,
  ```
  →
  ```php
  'has_children' => $roomRequest['has_children'] ?? false,
  'billing_address' => $roomRequest['billing_address'] ?? null,
  'billing_comment' => $roomRequest['billing_comment'] ?? null,
  ```
- **`FrontDeskController::walkIn`**:
  - validation (line 38): `'children' => 'nullable|integer|min:0'` → `'has_children' => 'nullable|boolean'` + เพิ่ม billing validation
  - create (line 76): `'children' => $validated['children'] ?? 0` → `'has_children' => $validated['has_children'] ?? false` + เพิ่ม billing fields

## 5. Test scripts (อัพเดตให้ตรง)
- `test_scripts/api_guide.php` (line 317): `'children' => 0` → `'has_children' => false`
- `test_scripts/api_test_remote.php` (line 301): เช่นเดียวกัน
- `test_scripts/api_test_chain.php` (line 278): เช่นเดียวกัน

## 6. AGENTS.md
- **ไม่แก้** (ตามคำตอบนายท่าน — AGENTS.md เป็น instructions ฉบับสมบูรณ์อยู่แล้ว และ feature change ไม่ใช่ doc change)

## 7. ขั้นตอน verify
1. `php artisan migrate:fresh --seed` (dev) เพื่อสร้าง schema ใหม่
2. รัน `php artisan test` เพื่อเช็คว่าไม่มี test พังจากการเปลี่ยนแปลง
3. (ถ้ามี test ที่ reference `children` จะแก้ให้ด้วย)
4. lint: `vendor/bin/pint --dirty`

## ⚠️ Behavioral change ที่ต้องทราบ
- **`total_guests`** จะไม่นับเด็กแล้ว (เนื่องจากไม่มี field count เด็ก) — ถ้า frontend/อื่นๆ พึ่งพาตัวเลขนี้ที่รวมเด็ก ต้องแจ้งให้รู้
- **API breaking change**: client ที่ส่ง `children` (integer) ต้องเปลี่ยนมาส่ง `has_children` (boolean) แทน