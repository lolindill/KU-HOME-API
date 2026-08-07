# 📅 How To: List Bookings with Filter Params

> คู่มือใช้งาน `GET /api/v1/bookings` แบบเน้นที่ query filter parameters — ตัวอย่าง, พฤติกรรมของแต่ละ filter, และข้อควรระวัง
> Reference หลักอยู่ที่ [`api_guide.md`](./api_guide.md) section **Bookings (Core)**; doc นี้เป็น deep-dize ของฝั่ง read/filter เท่านั้น

---

## Endpoint

```
GET /api/v1/bookings
```

| Property      | Value                                                          |
|---------------|----------------------------------------------------------------|
| Auth          | 🔒 `auth:sanctum` (Bearer token) — **จำเป็น**                  |
| Role gate     | ไม่มี role middleware ที่ route; แบ่งตาม role **ใน controller** |
| Rate limit    | ไม่มี per-route throttle (ติดอยู่ที่ global throttle เท่านั้น)  |
| Returns       | JSON envelope (ไม่ใช่ paginator ดิบ)                           |

> ⚠️ **Global middleware:** ทุก `/api/*` request ต้องส่ง header `Accept: application/json`
> ถ้าไม่ส่ง → ใส่ default ให้เองและปล่อยผ่าน แต่ถ้าส่งมาแล้วไม่ยอมรับ JSON (เช่น `text/html`) →
> **HTTP 406 Not Acceptable** ทันทีค่ะ 🥺

---

## Role-based Visibility (สำคัญ — ไม่ใช่ filter แต่ส่งผลต่อผลลัพธ์)

ผลลัพธ์ถูก scoping ตาม role ของ user ที่ล็อกอินอยู่อัตโนมัติ ไม่มี param ควบคุม:

| Role           | เห็น bookings                | Eager-load `user`? |
|----------------|------------------------------|--------------------|
| `admin`        | ทั้งหมด (ทุก user)            | ✅ ใช่              |
| role อื่นทั้งหมด | เฉพาะของตัวเอง (`user_id`)    | ❌                 |

→ ถ้า user ทั่วไปอยากเห็น booking คนอื่น ทำไม่ได้ผ่าน endpoint นี้ ไม่ว่าจะส่ง filter อะไรก็ตาม

---

## Query Parameters

| Param       | Type             | Required | Default | คำอธิบาย                                                                              |
|-------------|------------------|----------|---------|---------------------------------------------------------------------------------------|
| `term`      | string / UUID    | ❌        | `null`  | ค้นหาด้วยคำ — ดู [term filter](#-term--ค้นหาด้วยคำ) ด้านล่าง                          |
| `room_type` | UUID หรือ `all`   | ❌        | `all`   | UUID ของ room type ที่จะกรอง; ส่ง `all` หรือไม่ส่ง = ไม่กรอง                          |
| `check_in`  | date (ISO)       | ❌        | `null`  | ใช้คู่กับ `check_out` เท่านั้น — กรองแบบ date **overlap**                              |
| `check_out` | date (ISO)       | ❌        | `null`  | ใช้คู่กับ `check_in` เท่านั้น                                                          |
| `per_page`  | integer          | ❌        | `15`    | ขนาดหน้า pagination                                                                   |
| `page`      | integer          | ❌        | `1`     | Laravel paginator อ่านอัตโนมัติ (ไม่ได้ read เองใน controller)                         |

> ❌ **ไม่มี `status` filter** — แม้ booking container จะมี state machine
> (`draft → paid → confirmed → complete`) list endpoint นี้กรองตาม status ไม่ได้
> ถ้าต้องการ ต้อง filter ฝั่ง client หรือเพิ่มใน controller เอง

> ❌ **ไม่มี `sort` param** — sort ถูก hardcode เป็น `created_at DESC`

---

## 🔍 `term` — ค้นหาด้วยคำ

`term` ค้นหาแบบ LIKE (case-insensitive) เทียบกับหลายฟิลด์ โดยใช้ OR:

1. **ชื่อ user** — `users.name LIKE %term%` (ผ่าน `whereHas('user', ...)`)
2. **UUID ของ user** — ถ้า `term` มีรูปแบบ UUID (`Str::isUuid()`) จะเทียบ `user_id` ตรงๆ ด้วย
3. **ชื่อแขกคนแรก** — ค้นใน `booking_rooms.guests` JSON field → `guests[0].name`
   (ใช้ `JSON_EXTRACT` บน MySQL/SQLite, `#>>` operator บน PostgreSQL, fallback `CAST AS TEXT`)

✅ Security note: LIKE wildcards (`%`, `_`) ใน `term` ถูก escape ก่อน — กัน LIKE injection

---

## 📆 `check_in` + `check_out` — Date Overlap Filter

⚠️ ใช้งาน**ได้ต่อเมื่อส่งทั้งสองค่าพร้อมกันเท่านั้น** — ส่งอันเดียวจะถูกข้าม (no-op)

- ทั้งสองค่าถูก parse ด้วย Carbon แล้วขยายเป็น `startOfDay()` / `endOfDay()`
- ตรรกะ: คืน booking ที่มี booking_room ตรงเงื่อนไข overlap **อย่างน้อย 1 ใน 3**:
  - วันเช็คอินของห้องตกอยู่ในช่วง `[check_in, check_out]` ที่ระบุ **หรือ**
  - วันเช็คเอาท์ของห้องตกอยู่ในช่วง **หรือ**
  - ห้องครอบคลุมช่วงที่ระบุทั้งหมด (`check_in <= start AND check_out >= end`)
- วันที่อยู่ในระดับ **`booking_rooms`** ไม่ใช่ `bookings` (หลัง refactor 25/06/26)

---

## 🏷 `room_type` — กรองตามประเภทห้อง

- ส่ง **UUID ของ room type** → คืนเฉพาะ booking ที่มี booking_room ตรง `room_type_id` นั้น
- ส่ง `all` หรือไม่ส่ง → ไม่กรอง (เห็นทุก room type)
- ใช้ `whereHas('bookingRooms', ...)` → ตรวจที่ระดับ per-room

---

## Response Shape

ส่งกลับเป็น JSON envelope ที่แยก `bookings` (array) กับ `pagination` ออกจากกัน พร้อม echo filter ที่ใช้กลับมาใน `search_criteria`:

```json
{
  "status": "success",
  "message": "ดึงข้อมูลสำเร็จแล้วค่ะนายท่าน! ✨",
  "user": "<auth user id>",
  "search_criteria": {
    "term": null,
    "check_in": null,
    "check_out": null,
    "room_type": "all"
  },
  "bookings": [
    {
      "id": "<uuid>",
      "confirmation_number": "KU20260807001",
      "user_id": "<uuid>",
      "created_at": "2026-08-07T10:00:00.000000Z",
      "booking_rooms": [
        {
          "id": "<uuid>",
          "room_type": { "id": "<uuid>", "name": "Standard" },
          "room": { "id": "<uuid>", "room_number": "101" },
          "addon": null
        }
      ]
    }
  ],
  "pagination": {
    "current_page": 1,
    "last_page": 3,
    "per_page": 15,
    "total": 42
  }
}
```

> หมายเหตุ: Laravel serialize relation เป็น `snake_case` ใน JSON (`booking_rooms`) แม้ใน code จะ eager-load ชื่อ `bookingRooms` (camelCase)
> ฝั่ง `admin` จะมี `user` object เพิ่มเข้ามาในแต่ละ booking ด้วย

---

## ตัวอย่างการใช้งาน (cURL)

ทุกตัวอย่างด้านล่างสมมติว่า `$TOKEN` = Bearer token ของผู้ใช้ที่ล็อกอินแล้ว

### 1. List ทั้งหมด (default)

```bash
curl -s -H "Accept: application/json" \
     -H "Authorization: Bearer $TOKEN" \
     "http://localhost:8000/api/v1/bookings"
```

### 2. ค้นหาด้วยคำ (`term`)

```bash
curl -s -H "Accept: application/json" \
     -H "Authorization: Bearer $TOKEN" \
     "http://localhost:8000/api/v1/bookings?term=somchai"
```

ค้นด้วย UUID ของ user ก็ได้ (admin เห็นทุกคน → หา booking ของ user คนใดคนได้):

```bash
curl -s -H "Accept: application/json" \
     -H "Authorization: Bearer $TOKEN" \
     "http://localhost:8000/api/v1/bookings?term=9c1f...uuid"
```

### 3. กรองตาม room type

> Room types จริงในระบบ (ส.ค. 2026):
> - `c7b820d2-1818-4fe4-84bf-b8625d66de86` → **Superior** (ห้องซูพีเรียร์)
> - `4db6a3e2-0fc2-4f5e-813c-544ad3bef21b` → **Deluxe** (ห้องดีลักซ์)
> - `642c5451-2da8-4dee-a421-80e5ee140e67` → **Suite** (ห้องสวีท)

```bash
curl -s -H "Accept: application/json" \
     -H "Authorization: Bearer $TOKEN" \
     "http://localhost:8000/api/v1/bookings?room_type=4db6a3e2-0fc2-4f5e-813c-544ad3bef21b"
```

### 4. กรองตามช่วงวันที่ (ต้องส่งครบทั้งคู่)

```bash
curl -s -H "Accept: application/json" \
     -H "Authorization: Bearer $TOKEN" \
     "http://localhost:8000/api/v1/bookings?check_in=2026-08-10&check_out=2026-08-15"
```

### 5. ผสมหลาย filter + pagination

```bash
curl -s -H "Accept: application/json" \
     -H "Authorization: Bearer $TOKEN" \
     "http://localhost:8000/api/v1/bookings?term=somchai&room_type=4db6a3e2-0fc2-4f5e-813c-544ad3bef21b&check_in=2026-08-10&check_out=2026-08-15&per_page=25&page=2"
```

### 6. เช็คในหน้าต่างเดือน (ทั้งเดือนส.ค. 2026)

```bash
curl -s -H "Accept: application/json" \
     -H "Authorization: Bearer $TOKEN" \
     "http://localhost:8000/api/v1/bookings?check_in=2026-08-01&check_out=2026-08-31"
```

---

## 🧩 ข้อควรระวัง (Gotchas)

1. **`check_in` / `check_out` ใช้คู่กันเท่านั้น** — ส่งมาอันเดียวจะถูกข้ามทั้งคู่ (filter ไม่ทำงานเงียบๆ)
2. **ไม่มี `status` filter** — state machine มีอยู่แต่ list endpoint ไม่เปิดให้กรอง
3. **scope ตาม role lock ไว้** — user ทั่วไปไม่มีทางเห็น booking คนอื่นแม้จะส่ง filter อะไรก็ตาม
4. **`term` ค้นได้หลายฟิลด์** — รวมชื่อ user, UUID user, และชื่อแขกคนแรก อย่าหวังว่าจะจำกัดเฉพาะฟิลด์ใดฟิลด์หนึ่งได้
5. **sort ไม่ได้กำหนดเองได้** — hardcode `created_at DESC` เสมอ
6. **date overlap ไม่ใช่ exact-range match** — booking ที่ทับซ้อนแม้แค่วันเดียวก็จะถูกคืนมา
7. **`Accept: application/json` บังคับ** — ลืม header นี้อาจโดน 406 (ถ้าส่ง Accept อื่นที่ไม่ใช่ JSON)
8. **`per_page` ไม่มี upper-bound** — ส่งเลขใหญ่ได้ แต่ควรระวัง memory/timeout ฝั่ง client + DB

---

## 🔗 Reference

- Route: `routes/api.php` — `Route::get('/bookings', [BookingController::class, 'getBookings'])` (ภายใน group `prefix:v1` + `auth:sanctum`)
- Controller: `app/Http/Controllers/Api/V1/BookingController.php` → `getBookings()` + private helpers
  `applyUserFilter()` / `applyDateFilter()` / `applyRoomTypeFilter()`
- State machine ของ Booking: `app/Models/Booking.php` → `transitionStatus()`
- คู่มือหลัก: [`api_guide.md`](./api_guide.md) section **Bookings (Core)**
