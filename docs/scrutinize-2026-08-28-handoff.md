# 🔍 Handoff — Scrutinize งาน 27–28 ส.ค. 2026 (Discount v2.1 / Hourly Early-Late / Bed Rename)

> **สำหรับ agent/session ถัดไปที่จะลงมือแก้ findings จากการ scrutinize** — เอกสารนี้ self-contained ไม่ต้องอ่านบทสนทนาต้นทาง
>
> - **ขอบเขตที่รีวิว:** 5 commits ระหว่าง `26547ec..1fd32f5` (branch `agust-11`)
> - **Baseline ตอนรีวิว:** `php artisan test` = **327 passed (866 assertions)** · working tree clean
> - **Status (28/08/26):** 🟢 **RESOLVED & SHIPPED** — F1, F2 (Option 1), และ Nit แก้ไขและผ่าน automated tests ครบ (`332 passed / 900 assertions`)

---

## ✅ สิ่งที่ตรวจแล้วผ่าน (อย่าไล่ซ้ำ)

- **Quota lifecycle ครบวง:** `applyToDraft` (lock discount row + all-or-nothing + belt-and-suspenders recount) → `held` ค้างผ่าน pending/verify_error → พลิก `used` ใน `Booking::transitionStatus()` (`app/Models/Booking.php:175-180`) → cascade ปล่อย slot เมื่อลบ booking/BR (FK `cascadeOnDelete`)
- **Scrutinize fixes #42–#45 อยู่จริง:** rethrow ValidationException (`BookingController.php:139-141`), block rename มี redemption, บังคับ stay_from/stay_until เป็นคู่, case-insensitive duplicate
- **ราคา hourly สอดคล้องทั้ง 4 endpoints + `reprice()`** — controller freeze ราคาลง addon row, `reprice()` อ่านจาก row เดียวกัน
- **Bed rename prod-parity:** fresh + pgsql-existing ได้ default `'twin'` ถูกต้อง, backfill ครบ, validation 4 FormRequests = `in:king_size` เหมือนกัน
- **`bed_preference` hard constraint จริง** — `matchesBedPreference()` ถูกใช้เป็น filter ในทั้ง 3 algorithms
- **Max 5→7:** ไม่มี reference `0-5` ค้างใน app/docs/test_scripts

---

## 🔴 F1 (Major — แก้ก่อน prod): `PUT /discounts/{id}` เปลี่ยน `type` โดยไม่ส่ง `value` → ส่วนลด 100%

### ปัญหา
`DiscountController::update()` (`app/Http/Controllers/Api/V1/DiscountController.php:214-226`) ใช้ `sometimes` กับ `value` — ถ้า request ไม่ส่ง `value` closure ตรวจ percent 1–100 **ไม่รันเลย** แต่ `type` เปลี่ยนได้ → value เดิม (พัน satang) ถูกใช้กับสูตร percent → `computeForRoom()` (`app/Services/Discount/DiscountService.php:134-137`) ติด `min($amt, $roomBase)` = **roomBase เต็มจำนวน = ห้องฟรี 100%** แล้ว flow verify ก็ผ่านปกติจน redemption เป็น `used`

ฝั่ง **create** (`:157-161`) กันจุดนี้ไว้แล้ว — งานนี้คือปิดรูรั่วฝั่ง update ให้เหมือนกันเท่านั้น

### Reproduce (verified จริงตอน scrutinize — ใช้ยืนยันว่าแก้แล้วหาย)
```bash
php artisan tinker --execute="
use App\Models\Discount; use App\Services\Discount\DiscountService;
\$d = Discount::create(['code'=>'TMPGAP01','type'=>'fixed','value'=>100000]);
\$d->update(['type'=>'percent']);           # จำลอง PUT ที่ส่งแต่ type
echo app(DiscountService::class)->computeForRoom(\$d->fresh(), 2000000, 3);  # bug: 6000000 (= roomBase เต็ม)
\$d->delete();
"
```

### ทางแก้ (เลือกข้อเดียว)

**Option A — แนะนำ (เปลี่ยน contract: type กับ value ไปด้วยกันเสมอ เหมือน create):**

ใน `DiscountController::update()` validation:
```php
'value' => [
    'sometimes',
    'required_with:type',   // ← เพิ่มบรรทัดนี้ (แทน 'required' เดิมก็ได้)
    'integer',
    'min:0',
    ...closure เดิมไม่ต้องแตะ...
],
```
เพิ่ม message: `'value.required_with' => 'เปลี่ยนประเภทส่วนลดต้องส่ง value มาพร้อมกันเสมอค่ะ'`

**Option B — ยืดหยุ่นกว่า (อนุญาต swap ถ้า value เดิมผ่าน range):** เพิ่ม closure ที่ rule `type`:
```php
function ($attribute, $value, $fail) use ($request, $discount) {
    if ($value === 'percent' && ! $request->has('value')) {
        $effectiveValue = (int) $discount->value;
        if ($effectiveValue < 1 || $effectiveValue > 100) {
            $fail('เปลี่ยนเป็น percent ต้องส่ง value (1-100) มาพร้อมกันด้วยค่ะ');
        }
    }
},
```

### Tests ที่ต้องเพิ่ม (`tests/Feature/DiscountTest.php`)
```
test_update_discount_type_swap_without_value_is_rejected
  - สร้าง fixed value=100000 → PUT {"type":"percent"} → assert 422
  - PUT {"type":"percent","value":50} → assert 200 + fresh value=50
```
**อย่าแตะ create endpoint** — contract ฝั่งนั้นถูกอยู่แล้ว และ `test_create_discount_rejects_percent_over_100` ต้องยังเขียว

---

## 🟡 F2 (Minor — UX dead-end): แก้ห้องใน draft ที่ถือโค้ด → rollback ทั้งการแก้ + error กำกวม

### ปัญหา
Reconcile block หลังแก้ห้องเรียก `applyToDraft()` (pipeline เต็ม ไม่ใช่แค่ reprice):
- `addRooms` — `app/Http/Controllers/Api/V1/BookingController.php:374-379`
- `updateRoom` — `:690-695`
- `updateRooms` — `:959-964`

`applyToDraft()` ลบ redemption แล้ว filter `isEligible()` ใหม่ (room_type + **stay window**) — เจอ 2 กับดัก:
1. แก้วันที่ออกนอก stay window จน **ทุกห้อง ineligible** → 422 "ไม่มีห้องในการจองนี้ที่ใช้โค้ด...ได้ค่ะ 🏨" → rollback ทั้งการแก้ โดย user ไม่รู้ว่าต้อง `DELETE /bookings/{id}/discount-code` ก่อน
2. Admin ปิดโค้ด (toggle) หรือ `usable_until` หมดอายุ → **draft ที่ถือโค้ดแก้/เพิ่มห้องไม่ได้เลย** จนกว่าเจ้าของจะลบโค้ดเอง

เคส partial-eligible (หลายห้อง เพี้ยนบางห้อง) ปล่อย slot เงียบๆ + `discount_amount=0` — **by design ถูกแล้ว ห้ามแตะ**
ส่วน `destroyRoom` เรียก `reprice()` ตรงๆ (`:1079`) ไม่เดิน path นี้ — ไม่กระทบ

### ทางแก้

**Option 1 — แนะนำ (แค่ทำให้ error ชี้ทางออก, ไม่เปลี่ยน behavior):**
สกัด helper ส่วนตัวใน `BookingController` (กัน triplicate 3 จุด):
```php
private function reconcileDiscount(Booking $booking): void
{
    if (! $booking->discount_code) {
        app(DiscountService::class)->reprice($booking);
        return;
    }
    try {
        app(DiscountService::class)->applyToDraft($booking, $booking->discount_code);
    } catch (\Exception $e) {
        if ($e->getCode() === 422) {
            throw new \Exception(
                'การแก้ไขทำให้การจองไม่เข้าเกณฑ์โค้ด '.$booking->discount_code.' อีกต่อไป — '.
                'กรุณาลบโค้ดส่วนลดก่อน (DELETE /bookings/'.$booking->id.'/discount-code) แล้วลองแก้ไขอีกครั้งค่ะ',
                422
            );
        }
        throw $e;
    }
}
```
แล้วเปลี่ยน block ทั้ง 3 จุดเป็น `$this->reconcileDiscount($locked)` (`createBooking` ใช้ pattern if/else เดิมอยู่ `:1269-1274` — เปลี่ยนด้วยได้ถ้า signature เข้ากัน: ตอน create ยังไม่มี code บน object → helper จะเดิน reprice ถูกต้องอยู่แล้วกรณีส่ง code เข้ามาใน `validated` ต้องคง `applyToDraft($booking, $validated['discount_code'])` เดิมไว้)

**Option 2 — ต้องถาม owner ก่อน (เปลี่ยน product behavior):** auto-drop โค้ดตอน eligibility-fail + ติด `discount_dropped: true` ใน response ให้ frontend แจ้ง user — โดยเฉพาะเคส "โค้ดถูกปิด/หมดอายุ" ที่ถือไว้ก็ไม่มีประโยชน์

### Tests ที่ต้องเพิ่ม (`BookingTest`)
```
- สร้าง draft + code มี stay window → PUT updateRoom ย้ายวันออกนอก window → assert 422 + message มีคำว่า "discount-code"
- (ถ้าทำ Option 2) toggle code inactive → edit room → assert 200 + discount_dropped
```

---

## 🟡 F3 (Minor — latent, dormant): SQLite DB เดิมคง default `rooms.bed_type='double'`

### ปัญหา
Migration `2026_08_27_160317` ตั้ง default ใหม่เฉพาะ pgsql (`SET DEFAULT 'twin'`) — SQLite เดิม (config server ปัจจุบัน) ยังมี default `'double'` ซึ่งตายจาก vocabulary ใหม่

**ตอนนี้ dormant:** ไม่มี `POST /rooms` endpoint, `RoomSeeder` ส่ง `bed_type` ชัดเจนเสมอ → ไม่มีทางเกิด row ใหม่ที่ได้ default

### Action
- **เดี๋ยวนี้:** ไม่ต้องทำอะไร — entry นี้ใน `cline.md` ถือเป็นการจดไว้แล้ว
- **เมื่อสร้าง room-create/update endpoint (อนาคต):** บังคับ `'bed_type' => 'required|in:twin,king_size'` ใน FormRequest — ทำให้ DB default ไร้ความหมาย จบถาวน

---

## ⚪ Nit: dead code `?? 1` ใน `resolveEarlyLate`

`BookingController.php:1677,1680` — `$existing?->early_hours ?? 1` เป็น dead branch (column `NOT NULL DEFAULT 0` + backfill แล้ว, null เกิดไม่ได้) — เผื่อแตะไฟล์นี้ค่อยลดเป็น `?? 0` ไม่รีบ

---

## 🛡️ Guardrails สำหรับคนลงมือ

1. **Baseline:** `php artisan test` ต้องเขียว **≥ 327 passed** หลังแก้ (เพิ่ม test ใหม่ต้องมากกว่า) · `vendor/bin/pint --dirty`
2. **ห้ามแตะ:** `PgBoolean` (freeze), create endpoint validation ฝั่ง `DiscountController::store`, เคส partial-eligible ของ `applyToDraft`, `transitionStatus` hooks
3. **ธรรมเนียม:** comment ไทย + emoji ตาม style รอบๆ · error shape `{"status":"error","message":...}` · 422 business / 500 ซ่อน message + `Log::error()`
4. **ลำดับงาน:** F1 → F2 Option 1 → (F2 Option 2 รอ owner ตัดสินใจ) → F3 จดไว้แล้ว · Nit เผื่อแตะ
5. **ตรวจของจริงหลังแก้ F1 (ถ้าต้องการ):** `test_scripts/test_discount_remote.php` (19 checks, ระวัง 429 + pacing 1.5s/คำสั่ง)
