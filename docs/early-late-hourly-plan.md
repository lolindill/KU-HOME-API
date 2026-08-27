# 📋 Handoff Plan — Early/Late Check-in/out คิดรายชั่วโมง (100 ฿/ชม.) + ลบ boolean ทั้งระบบ

> **สำหรับ: Gemini (หรือ agent ใดก็ตามที่รับงานนี้ต่อ)** — เอกสารนี้ self-contained ไม่ต้องมีบทสนทนาต้นทาง
> **แผนถูกอนุมัติโดยเจ้าของโปรเจกต์แล้ว (27/08/26)** — ทำตามนี้ได้เลย ห้ามขยายขอบเขตเอง
> **ก่อนเริ่ม:** อ่าน `AGENTS.md` (กฎโปรเจกต์) และส่วนที่เกี่ยวข้องใน `cline.md` ก่อนแตะโค้ด
> **สาขาปัจจุบัน:** `agust-11`

---

## 1. เป้าหมาย

เปลี่ยนการคิดค่า early check-in / late check-out จาก **flat ราคาเดียวต่อห้อง** เป็น **สูตรรายชั่วโมง**:

```
ราคา (satang) = จำนวนชั่วโมง (int 0–5) × ราคา/ชม. จาก global_rates
```

- `global_rates` code `early_checkin` / `late_checkout` (seed = **10000 satang = 100 ฿**) ถูก**ตีความใหม่**เป็น "ราคาต่อชั่วโมง" — **ไม่ต้องแก้ค่า**
- **ลบ boolean ทิ้งทั้งระบบ** (คำสั่งชัดเจนจากเจ้าของ: "ลบหมด รับ input as int"):
  - ฝั่ง **input**: `addons.early_checkin` / `addons.late_checkout` เปลี่ยน type จาก boolean → **integer 0–5** (ค่า = จำนวนชั่วโมง, 0/ไม่ส่ง = ไม่ใช้บริการ)
  - ฝั่ง **response**: ไม่มี `early_checkin` / `late_checkout` boolean บน booking_room อีกต่อไป — ดูจาก `addon.early_hours` / `addon.late_hours` แทน

⚠️ **Breaking change (ตั้งใจ):** client เดิมที่ส่ง `true`/`false` จะโดน 422, client ที่อ่าน boolean จาก response ต้องเปลี่ยนไปอ่าน `addon.early_hours > 0` — frontend (repo อื่น) ไม่อยู่ในขอบเขตงานนี้

### สัญญา JSON

**INPUT (เดิม):**
```json
"addons": { "breakfast": 2, "early_checkin": true, "late_checkout": false }
```

**INPUT (ใหม่):**
```json
"addons": { "breakfast": 2, "early_checkin": 3, "late_checkout": 0 }
```

**RESPONSE `booking_rooms[]` (ใหม่):**
```json
{
  "id": "c2f1...",
  "room_type_id": "9b3a...",
  "room_id": null,
  "check_in": "2026-09-01T00:00:00.000000Z",
  "check_out": "2026-09-03T00:00:00.000000Z",
  "guests": [],
  "status": "draft",
  "bed_preference": null,
  "billing_address": null,
  "billing_comment": null,
  "room_amount": 240000,
  "discount_amount": 0,
  "addon": {
    "id": "e77b...",
    "booking_room_id": "c2f1...",
    "extra_bed": 0,
    "breakfast": 2,
    "early_checkIn_price": 30000,
    "early_hours": 3,
    "late_checkOut_price": 0,
    "late_hours": 0,
    "extra_bed_price": 0,
    "breakfast_price": 10000,
    "created_at": "...",
    "updated_at": "..."
  }
}
```
หมายเหตุ: key `early_checkin`/`late_checkout` (boolean) **หายไปจาก booking_room** และ `early_hours`/`late_hours` **โผล่ใน addon อัตโนมัติ** (โปรเจกต์ไม่มี Resource layer — model serialize ตรงๆ)

---

## 2. สถานะปัจจุบัน (ตำแหน่งจริงที่ต้องแก้)

ตรวจสอบแล้ววันที่ 27/08/26 — ใช้ snippet เป็น anchor หาโค้ด (เลขบรรทัดอาจเลื่อนหลังแก้ไข):

| จุด | ไฟล์ | สิ่งที่มีเดี๋ยวนี้ |
|---|---|---|
| A | `app/Models/BookingRoom.php:52` | `protected $appends = ['early_checkin', 'late_checkout'];` + accessors `getEarlyCheckinAttribute()` / `getLateCheckoutAttribute()` (บรรทัด ~152–160) |
| B | `app/Models/Addon.php` | `$fillable` + `$casts` ยังไม่มี `early_hours`/`late_hours` |
| C | `app/Http/Requests/StoreBookingRequest.php:49-50` | `'booking_rooms.*.addons.early_checkin' => 'nullable|boolean'` (+ late) |
| D | `app/Http/Requests/AddBookingRoomsRequest.php:48-49` | เหมือน C |
| E | `app/Http/Requests/UpdateBookingRoomRequest.php:56-57` | `'addons.early_checkin' => 'nullable|boolean'` (โครงสร้าง flat ไม่มี prefix) |
| F | `app/Http/Requests/UpdateBookingRoomsRequest.php:58-59` | เหมือน C |
| G | `app/Http/Controllers/Api/V1/BookingController.php` | บล็อกคิดราคา flat 4 จุด (ดู §5) |
| H | `tests/Feature/BookingTest.php` | test 3 ตัวส่ง boolean / assert boolean (ดู §7) |
| I | docs + scripts | ดู §8 |

---

## 3. Task 1 — Migration ใหม่

สร้าง `database/migrations/2026_08_27_HHMMSS_add_early_late_hours_to_addons_table.php` (timestamp จริงต้องช้ากว่า `2026_08_26_100000_*`):

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('addons', function (Blueprint $table) {
            // 🕐 (27/08/26): สูตรรายชั่วโมง — เก็บจำนวนชั่วโมง early/late รายห้อง (0 = ไม่ใช้)
            $table->integer('early_hours')->default(0)->after('early_checkIn_price');
            $table->integer('late_hours')->default(0)->after('late_checkOut_price');
        });

        // แถวเดิมที่เคยจ่ายค่า flat ไปแล้ว = ย้อนถือว่าใช้ 1 ชม. (ราคา frozen เดิม = 1 ชม. × rate สมัยนั้น)
        DB::table('addons')->where('early_checkIn_price', '>', 0)->update(['early_hours' => 1]);
        DB::table('addons')->where('late_checkOut_price', '>', 0)->update(['late_hours' => 1]);
    }

    public function down(): void
    {
        Schema::table('addons', function (Blueprint $table) {
            $table->dropColumn(['early_hours', 'late_hours']);
        });
    }
};
```

- เป็น additive migration → dev/prod รัน `php artisan migrate` เฉยๆ (ห้าม `migrate:fresh` บน prod)
- ห้ามแก้ migration เก่า (`2026_03_30_031825_create_addons_table.php`, `2026_08_26_100000_*`) — เขียนใหม่เท่านั้น

## 4. Task 2–3 — Models

**`app/Models/Addon.php`** — เพิ่มใน `$fillable` และ `$casts`:
```php
'early_hours',   // $fillable
'late_hours',
// $casts:
'early_hours' => 'integer',
'late_hours' => 'integer',
```

**`app/Models/BookingRoom.php`** — **ลบทิ้ง 3 อย่าง:**
1. ลบ property: `protected $appends = ['early_checkin', 'late_checkout'];`
2. ลบ method: `getEarlyCheckinAttribute()`
3. ลบ method: `getLateCheckoutAttribute()`

(ไม่กระทบ DB — เป็น derived field ที่ model layer อย่างเดียว)

## 5. Task 4 — Validation 4 Form Requests (จุด C–F)

แทนที่ rule เดิม (`'nullable|boolean'`) ด้วย (array syntax):

```php
'booking_rooms.*.addons.early_checkin' => ['nullable', 'integer', 'min:0', 'max:5'],
'booking_rooms.*.addons.late_checkout' => ['nullable', 'integer', 'min:0', 'max:5'],
```

⚠️ `UpdateBookingRoomRequest` (จุด E) ใช้โครงสร้าง flat — ไม่มี prefix `booking_rooms.*.`:
```php
'addons.early_checkin' => ['nullable', 'integer', 'min:0', 'max:5'],
'addons.late_checkout' => ['nullable', 'integer', 'min:0', 'max:5'],
```

และเพิ่มข้อความไทยใน `messages()` ของทั้ง 4 requests (แบบเดียวกับ entry ที่มีอยู่):
```php
'booking_rooms.*.addons.early_checkin.integer' => 'จำนวนชั่วโมง early check-in ต้องเป็นตัวเลขจำนวนเต็ม (0-5) ค่ะ',
'booking_rooms.*.addons.early_checkin.min' => 'ชั่วโมง early check-in ต้องอยู่ระหว่าง 0-5 ชั่วโมงค่ะ',
'booking_rooms.*.addons.early_checkin.max' => 'ชั่วโมง early check-in ต้องอยู่ระหว่าง 0-5 ชั่วโมงค่ะ',
// (เช่นเดียวกัน late_checkout และแบบไม่มี prefix ใน UpdateBookingRoomRequest)
```

## 6. Task 5 — BookingController (4 code paths ผ่าน helper เดียว)

### 6.1 เพิ่ม private helper ท้ายคลาส (ข้าง `stripGuestFields`):

```php
/**
 * 🕐 (27/08/26): สูตรรายชั่วโมง — addons.early_checkin / addons.late_checkout รับ int จำนวนชั่วโมง (0-5)
 * ไม่ส่ง addons key มา = ใช้ค่าจากแถว addon เดิม (fallback ตามพฤติกรรมเดิมของ updateRoom/updateRooms)
 */
private function resolveEarlyLate(?array $addonInput, ?Addon $existing = null): array
{
    $earlyHours = is_array($addonInput)
        ? (int) ($addonInput['early_checkin'] ?? 0)
        : (! empty($existing?->early_checkIn_price) ? ($existing?->early_hours ?? 1) : 0);
    $lateHours = is_array($addonInput)
        ? (int) ($addonInput['late_checkout'] ?? 0)
        : (! empty($existing?->late_checkOut_price) ? ($existing?->late_hours ?? 1) : 0);

    return [$earlyHours, $lateHours];
}
```

(`use App\Models\Addon;` มีอยู่แล้วในไฟล์)

### 6.2 แทนบล็อกคิดราคา 4 จุด — **โค้ดเดิมที่ต้องหา (เหมือนกันทั้ง 4 จุด ยกเว้นชื่อตัวแปร input):**

```php
$earlyCheckInPrice = ! empty($addons['early_checkin']) ? ($rates['early_checkin'] ?? 0) : 0;
$lateCheckOutPrice = ! empty($addons['late_checkout']) ? ($rates['late_checkout'] ?? 0) : 0;
```

**แทนด้วย:**
```php
[$earlyHours, $lateHours] = $this->resolveEarlyLate($addons);
$earlyCheckInPrice = $earlyHours * ($rates['early_checkin'] ?? 0);
$lateCheckOutPrice = $lateHours * ($rates['late_checkout'] ?? 0);
```

พร้อมเพิ่ม `'early_hours' => $earlyHours, 'late_hours' => $lateHours` ใน `Addon::create([...])` ของจุดนั้น

4 จุดคือ (ชื่อ method + ตำแหน่ง anchor):
1. **`createBooking()`** — anchor: `$addons = $roomRequest['addons'] ?? [];` (~บรรทัด 1232) · `Addon::create` ที่ ~1254
2. **`addRooms()`** — anchor: `$addons = $roomRequest['addons'] ?? [];` (~334) · `Addon::create` ที่ ~357

สองจุดนี้ (create/add) `$existing` เป็น null เสมอ → เรียก `$this->resolveEarlyLate($addons)` ตรงๆ

3. **`updateRoom()`** — เดิม (~651–665):
```php
$earlyCheckInEnabled = is_array($addonInput)
    ? ! empty($addonInput['early_checkin'])
    : ! empty($existingAddon?->early_checkIn_price);
$lateCheckOutEnabled = is_array($addonInput)
    ? ! empty($addonInput['late_checkout'])
    : ! empty($existingAddon?->late_checkOut_price);
...
$earlyCheckInPrice = $earlyCheckInEnabled ? ($rates['early_checkin'] ?? 0) : 0;
$lateCheckOutPrice = $lateCheckOutEnabled ? ($rates['late_checkout'] ?? 0) : 0;
```
แทนด้วย:
```php
[$earlyHours, $lateHours] = $this->resolveEarlyLate($addonInput, $existingAddon);
$earlyCheckInPrice = $earlyHours * ($rates['early_checkin'] ?? 0);
$lateCheckOutPrice = $lateHours * ($rates['late_checkout'] ?? 0);
```
และใน `$addonData` (~667–674) เพิ่ม `'early_hours' => $earlyHours, 'late_hours' => $lateHours` — dirty-check loop `foreach ($addonData as $k => $v)` ทำงานกับ field ใหม่อัตโนมัติ (model มี fillable+cast แล้วจาก Task 2)

4. **`updateRooms()`** — บล็อกเดียวกันภายใน `foreach ($updates as $u)` (~923–936) + `$addonData` (~938–945) — แก้เหมือนจุด 3 (ใช้ `$addonInput`/`$existingAddon` ใน scope นั้น)

### 6.3 พฤติกรรมที่ต้องคงไว้ (มี test คุมอยู่แล้ว)
- **ไม่ส่ง `addons` key มาเลย** (updateRoom/updateRooms) → คงชั่วโมง+ราคาเดิมจากแถว addon เดิม (fallback ใน helper จัดการให้)
- **ส่ง `addons` มาแต่ไม่มี key early/late** → ถือว่าปิด (0 ชม. / 0 บาท) — เหมือนพฤติกรรมเดิมที่ส่ง `false`
- ราคา freeze ลงแถว `addons` ต่อห้อง — `DiscountService::reprice()` รวมยอดจากราคา frozen (ไม่ต้องแก้อะไรใน DiscountService)

## 7. Task 6 — Tests (`php artisan test`)

### 7.1 แก้ test เดิมใน `tests/Feature/BookingTest.php`

**`test_create_booking_returns_early_late_boolean_addons` (~847–899)** — เปลี่ยนชื่อเป็น `test_create_booking_charges_early_late_by_hours` แล้วแก้:
- input: `'addons' => ['early_checkin' => true, 'late_checkout' => true]` → `['early_checkin' => 2, 'late_checkout' => 1]`
- ลบ assert `booking_rooms.0.early_checkin` / `late_checkout` (boolean) ทั้งหมด — **เปลี่ยนเป็น**:
  - `assertJsonPath('booking_rooms.0.addon.early_hours', 2)` / `late_hours`, 1
  - `assertJsonPath('booking_rooms.0.addon.early_checkIn_price', 300)` (2 × 150) / `late_checkOut_price', 250` (1 × 250)
  - ห้องที่ไม่ส่ง addons: `addon.early_hours` = 0, ราคา 0
  - total: (1500×2)×2 ห้อง + 300 + 250 = 6550
- **เพิ่ม:** `$response->assertJsonMissingPath('booking_rooms.0.early_checkin');` (ยืนยัน boolean ถูกลบ)

**`test_update_room_without_addons_key_keeps_early_late_prices` (~905–966)**:
- setup `$br->addon->update([...])` เพิ่ม `'early_hours' => 1, 'late_hours' => 1` (สอดคล้องราคา 5000/7000 = 1 ชม.)
- เคส 2: `'addons' => ['early_checkin' => false, 'late_checkout' => true]` → `['early_checkin' => 0, 'late_checkout' => 1]`
- แทน assert `booking_room.early_checkin` (boolean) ด้วย `assertDatabaseHas('addons', [..., 'early_hours' => ..., 'late_hours' => ...])`

**`test_update_room_reprices_addons_server_side` (~801–841)** — ยังไม่แตะ early/late; เพิ่มให้ครอบคลุม: seed rate early 100 แล้วใน payload เพิ่ม `'early_checkin' => 2` → อัปเดต total ที่ assert (4900 → +200 = 5100) + assertDatabaseHas `early_hours: 2, early_checkIn_price: 200`

**ตรวจ test batch updateRooms** (~1096 บริบท "batch") ถ้าส่ง addons boolean → แปลงเป็น int ตามสัญญาใหม่

### 7.2 เพิ่ม test ใหม่ (ใน BookingTest)
1. `test_create_booking_rejects_boolean_early_checkin` — ส่ง `early_checkin: true` → 422 (ยืนยัน breaking change)
2. `test_create_booking_rejects_early_hours_out_of_range` — ส่ง `6` / `-1` / `2.5` → 422 ทั้งสาม
3. `test_update_rooms_batch_reprices_early_late_hours` — batch updateRooms เปลี่ยนชั่วโมงรายห้อง → ราคา/total ถูกต้อง + ห้องที่ไม่ส่ง addons key คงราคาเดิม

### 7.3 Test อื่นที่แตะ (ตรวจแล้วผ่านอยู่แล้ว — แค่ verify)
- `tests/Feature/GlobalRateSeederTest.php` — assert seeded 10000/10000 · ไม่ต้องแก้
- `tests/Feature/DiscountTest.php` + `tests/Unit/RoomAllocator/RoomAllocatorIntegrationTest.php` — fixtures สร้าง addon ราคา 0 → default `early_hours` = 0 ไม่กระทบ

## 8. Task 7 — Docs & Scripts

| ไฟล์ | สิ่งที่ต้องทำ |
|---|---|
| `docs/api_guide.md` | (1) input tables 3 จุด (~995, ~1210, ~1322): type boolean → integer 0–5 + อธิบายสูตร "ราคา = ชม. × rate/ชม. (สูงสุด 5 ชม.)" (2) response samples ทุกจุดที่โชว์ `early_checkin: true` บน booking_room → เอาออก (3) หมายเหตุ seeded rate (~2232) เปลี่ยนเป็น "10000 satang = 100 ฿ **ต่อชั่วโมง**" (4) เพิ่มกล่อง ⚠️ Breaking change: ส่ง boolean โดน 422, boolean response ถูกลบ — ใช้ `addon.early_hours` แทน |
| `cline.md` | changelog entry ใหม่ตามฟอร์แมตเดิม: สูตรรายชั่วโมง, drop boolean input + `$appends` (ย้อนส่วนหนึ่งของ commit `7e4f319`), breaking change, rate ตีความใหม่เป็นต่อชม., migration note (`php artisan migrate` เฉยๆ), อ้างอิงเอกสารนี้ |
| `docs/database-er.md` | ตาราง `addons` เพิ่ม column `early_hours`, `late_hours` |
| `docs/booking-verify-flow.md` | sample JSON ที่มี boolean early/late → ปรับตามสัญญาใหม่ |
| `test_scripts/api_guide.php` | payload ที่ส่ง `early_checkin: true` → เปลี่ยนเป็น int (เช่น `2`) |
| `test_scripts/api_test_remote.php` · `api_test_chain.php` · `test_create_booking_remote.php` | payload boolean → int เช่นกัน (สคริปต์พวกนี้ยิง domain จริง — ถ้าไม่แก้จะโดน 429/422 หลัง deploy) |

## 9. 🚫 ห้ามแตะ (out of scope)

- `app/Services/Discount/DiscountService.php` — `reprice()` รวมยอดจากราคา frozen ในแถว addon อยู่แล้ว (ฐานส่วนลด = ค่าห้องเท่านั้น ไม่แตะ addon)
- `database/seeders/GlobalRateSeeder.php` + migration `2026_08_26_100000_*` — ค่า 10000 ใช้ต่อ
- `app/Http/Controllers/Api/V1/GlobalRateController.php` — admin ปรับราคา/ชม. ได้อยู่แล้ว
- `App\Casts\PgBoolean` — 🔒 frozen ห้าม refactor (ดู AGENTS.md)
- `StoreAddonRequest` / `UpdateAddonRequest` — orphaned (ไม่มี route) ปล่อยไว้
- State machines / `transitionStatus()` / `RoomAllocator` — ไม่เกี่ยว

## 10. ⚠️ กฎโปรเจกต์ที่ต้องระวัง (สรุปจาก AGENTS.md)

- **เงินเป็น satang integer ทั้งระบบ** — ห้าม decimal
- **PostgreSQL คือ prod target** — อย่าเขียนอะไรที่พึ่ง SQLite-only quirks (dev ใช้ SQLite, test ใช้ SQLite in-memory)
- คอมเมนต์/error message ภาษาไทย + emoji marker (✅ 🌟 🕐) ตามสไตล์โค้ดรอบข้าง
- API shape: success `{"status":"success",...}` / error `{"status":"error","message":...}` — ห้าม leak `$e->getMessage()` บน 500
- Commit convention: `feat(booking): ...` / `fix(booking): ...` (ดู git log)

## 11. การตรวจรับ (Definition of Done)

```bash
php artisan test                    # ทั้งชุดผ่าน
vendor/bin/pint --dirty             # ผ่าน
php artisan migrate                 # บน local SQLite สำเร็จ
```

- [x] create/addRooms/updateRoom/updateRooms คิดราคา = ชม. × rate ถูกต้องทั้ง 4 ทาง
- [x] ส่ง `early_checkin: true` → 422 / ส่ง `6`, `-1`, `2.5` → 422
- [x] response ไม่มี boolean `early_checkin`/`late_checkout` บน booking_room และ `addon` มี `early_hours`/`late_hours`
- [x] ไม่ส่ง addons key ตอนแก้ห้อง → คงราคา/ชั่วโมงเดิม *(คุมโดย non-batch test — batch fallback ยังเปิด ดู F1 ใน §12)*
- [x] แถว addons เดิม (ราคา > 0) ได้ `early_hours`/`late_hours` = 1 จาก migration *(logic ตรงแผน — local SQLite ไม่มีแถวราคา > 0 จึงตรวจแบบ review อย่างเดียว)*
- [x] docs + test scripts อัปเดตครบตามตาราง §8 *(ยกเว้น `api_test_chain.php` — grep ยืนยันไม่มี reference early/late จึงไม่ต้องแก้)*

> ✅ **Verified 27/08/26 (scrutinize):** trace end-to-end 4 pricing paths + probe `resolveEarlyLate` ผ่าน reflection (legacy row / no-service row / numeric-string cast) — ผ่านหมด · รันจริง `php artisan test` = **323 passed (853 assertions)** · `pint --test --dirty` = PASS 13 files · `php artisan migrate` = Ran (additive) · §9 out-of-scope ไม่ถูกแตะแม้แต่บรรทัดเดียว

---

## 12. 📌 Handoff ต่อ — Fix Bug จากผล Scrutinize (2026-08-27)

> **สำหรับ agent ตัวต่อไป:** งานหลัก §3–§8 **เสร็จ + verify แล้วทั้งหมด** (ดูติ๊กใน §11) — ที่เหลือคือชิ้นเล็กตามรายงาน scrutinize เท่านั้น
> **ทำตามนี้ได้เลย ห้ามรื้องานหลัก · ห้ามขยายขอบเขต** (เข้าเงื่อนไข 🚫 §9 ตามเดิม)

### F1 — Test คุม batch fallback: ห้อง**ไม่ส่ง** `addons` key → ต้องคงชั่วโมง/ราคาเดิม (minor, recommended)

**ปัญหา:** `test_update_rooms_batch_reprices_early_late_hours` (anchor: อยู่ก่อน `test_update_room_rejects_when_no_availability` ใน `tests/Feature/BookingTest.php`) ส่ง `addons` ชัดเจน**ทั้ง 2 ห้อง** → fallback path ของ route **batch** (`resolveEarlyLate(null, $existingAddon)` เมื่อ payload ขาด key) ไม่มี test คุมโดยตรง — single-room fallback มี `test_update_room_without_addons_key_keeps_early_late_prices` คุมอยู่แล้ว และ code path เดียวกันจึง risk ต่ำ แต่ควร lock ไว้ให้แน่น

**วิธีแก้ — แก้ใน test เดิม (ไม่ต้องเพิ่ม function ใหม่):**

1. Pre-seed br2 ให้มีชั่วโมงเก่าจาก draft เดิม แล้วเปลี่ยน payload ของ br2 เป็น**ไม่มี `addons` key เลย**:

```php
        // br2: ไม่ส่ง addons key → ต้อง fallback คงชั่วโมงเดิมจากแถว addon (resolveEarlyLate(null, $existing))
        //    pre-seed early_hours = 2 เพื่อพิสูจน์ว่า fallback อ่าน "ชั่วโมง" ไม่ใช่ reset เป็น 0
        //    (rate early = 100 → หลัง batch ต้องเป็น 2 ชม. × 100 = 200 satang)
        $br2->addon->update(['early_hours' => 2]);

        // br1: early 3 ชม. (300) + late 1 ชม. (200) = 500 addon + 3000 room = 3500
        // br2: คงเดิม early 2 ชม. (200) = 200 addon + 3000 room = 3200
        // total = 6700
        $response = $this->actingAs($user, 'sanctum')
            ->putJson("/api/v1/bookings/{$booking->id}/rooms", [
                'booking_rooms' => [
                    [
                        'booking_room_id' => $br1->id,
                        'addons' => ['early_checkin' => 3, 'late_checkout' => 1],
                    ],
                    [
                        'booking_room_id' => $br2->id, // ❗ ไม่มี 'addons'
                    ],
                ],
            ]);
```

2. เปลี่ยน assert ฝั่ง br2 เดิม (`['early_hours' => 0, ..., 'late_hours' => 2, ...]`) เป็น:

```php
        $this->assertDatabaseHas('addons', [
            'booking_room_id' => $br2->id,
            'early_hours' => 2,          // ชั่วโมงเดิมถูกคงไว้
            'early_checkIn_price' => 200, // = 2 × rate 100 (recomputed จาก hours ที่คงมา)
            'late_hours' => 0,
            'late_checkOut_price' => 0,
        ]);
        $response->assertJsonPath('total_amount', 6700);
```

*(optionally เพิ่ม `$response->assertJsonPath('booking_rooms.1.addon.early_hours', 2);` ถ้า response มี mutated rooms ตาม input order — adapt ตาม shape จริงได้)*

3. อัปเดต docblock/comment ทดสอบให้ระบุว่าครอบ "batch fallback (no addons key)" ด้วย

### F2 — (nit, optional) Dedupe closure กัน boolean ซ้ำ 8 จุด

**ปัญหา:** closure `if (is_bool($value)) $fail(...)` ถูก copy-paste ซ้ำ 8 จุด (4 Form Requests × 2 fields) — ตามแผน §5 กำหนด inline มาเอง จึงเป็น per-plan ไม่ใช่ defect

**วิธีแก้ (ทำเฉพาะถ้ามีเวลา):** สร้าง `app/Rules/IntegerHoursRule.php` (`implements ValidationRule`) ที่ fail ทั้งกรณี `is_bool` + non-integer + out-of-range 0–5 พร้อม embed ข้อความไทยใน `$fail(...)` แล้วแทน rule array ในทั้ง 4 requests (ลบ entries ซ้ำใน `messages()` ออก) — ระวัง **ห้ามใช้ `not_in:true,false`** (Laravel `in/not_in` compare แบบ loose → `true == 1` เจ๊ง)

### DoD ของ follow-up

```bash
php artisan test         # F1 แก้ใน test เดิม → ยังต้อง 323 passed ครบ (ห้ามมี FAIL)
vendor/bin/pint --dirty  # ผ่าน
php artisan migrate      # ไม่ต้องแตะ migration เพิ่ม — status ควร remain "Ran"
```

- [x] F1: batch fallback ถูกคุมด้วย test แล้ว (payload ขาด addons key แล้วราคา/ชั่วโมงคงเดิม)
- [ ] F2 (optional): closure ซ้ำถูกรวมเป็น Rule เดียว — ข้ามได้ถ้าไม่จำเป็น
