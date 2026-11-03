# 📊 Report Guide — พารามิเตอร์รายงาน Excel 15 ฉบับ (KU HOME API)

> คู่มือเฉพาะทาง **query parameters** ของ `GET /api/v1/reports/{slug}/export` ทั้ง 15 ฉบับ
> (ภาพรวมระบบ/สถาปัตยกรรม ดูที่ [`docs/api_guide.md`](./api_guide.md) หัวข้อ "📊 Reports & Additional Charges" · spec เดิม `wayfinder/excel-reports` §4)
> อัปเดตล่าสุด: 26/10/26 — ตรวจสอบตรงจาก `filterRules()` ของ `app/Services/ReportExcel/Data/*` จริงทุกตัว

---

## 1. สัญญาร่วมของทุกรายงาน (Common Contract)

| หัวข้อ | ค่า |
|---|---|
| Method | `GET` — พารามิเตอร์ทั้งหมดเป็น **query string** |
| Base URL | `/api/v1/reports/{slug}/export` |
| Auth | `Authorization: Bearer <sanctum-token>` (อายุ 8 ชม.) |
| สิทธิ์ | `admin` + `staff` ทุกใบ · `housekeeping` เพิ่มได้เฉพาะ `housekeeping`, `housekeeping-v2` |
| Throttle | `10,1` (10 request/นาที) — เกิน → `429` |
| Response สำเร็จ | stream ไฟล์ `.xlsx` ทันที (`Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet`, `Content-Disposition: attachment`) — ไม่มี JSON ตอนสำเร็จ |

### 1.1 กติกาพารามิเตอร์ที่ใช้ร่วมกัน

- **รูปแบบวันที่:** `YYYY-MM-DD` (rule `date` ของ Laravel — แนะนำ ISO เคร่งครัด)
- **เพดานช่วงวันที่:** รายงานที่ใช้ `date_from`/`date_to` ต้องมีช่วง **≤ 366 วัน** (config `reporting.max_export_range_days`) — เกิน → `422`
- **`date_to ≥ date_from`** เสมอ (`after_or_equal:date_from`) — เกิน → `422`
- **พารามิเตอร์ enum (nullable):** ไม่ส่ง = ใช้ค่า default · ส่งค่านอกชุดที่อนุญาต = `422`
- **ค่า enum ภาษาไทยมีเว้นวรรค/วงเล็บ** (เช่น `สลิป (โอน/QR)`) — ต้อง **URL-encode** ใน query string
- **พารามิเตอร์ string filter (`room_type`, `category`, `agency_code`):** เทียบ **exact match แบบ case-sensitive** (`WHERE =`) ไม่ใช่ LIKE · max 100 ตัวอักษร · ส่งค่าคลุมเครือเช่น `ทุกประเภท`/`ทั้งหมด`/`all` = ระบบข้าม filter (เท่ากับไม่ส่ง)
- **Accept header (global middleware `RequireJsonAccept`):** ห้ามส่ง `Accept: text/html` → `406` · ไม่ส่ง = ระบบ default เป็น `application/json` ให้เอง · response สำเร็จเป็น xlsx เสมอไม่ว่า Accept ใด (ภายในชุดที่ยอมรับ)

### 1.2 Error Contract (เฉพาะจุดที่เกี่ยวกับพารามิเตอร์)

| Status | เมื่อไร | Body |
|---|---|---|
| `422` | พารามิเตอร์ไม่ผ่าน `filterRules()` (ขาด required / รูปแบบวันที่ผิด / ช่วง > 366 วัน / enum นอกชุด) | Laravel validation มาตรฐาน (ดูตัวอย่างด้านล่าง) |
| `401` | ไม่ส่ง/หมดอายุ Bearer token | `{"status":"error","message":"กรุณาเข้าสู่ระบบก่อนนะคะนายท่าน!"}` |
| `403` | role ไม่อยู่ในสิทธิ์ของรายงาน (เช่น housekeeping ขอ inbyte ทั่วไป) | `{"status":"error","message":"คุณไม่มีสิทธิ์เข้าถึงรายงานนี้ค่ะ"}` |
| `406` | ส่ง `Accept` ที่ไม่ยอมรับ JSON (เช่น `text/html`) | `{"status":"error","message":"Accept: application/json is required for all /api/* requests. 🥺"}` |
| `429` | เกิน throttle `10,1` | Laravel throttle มาตรฐาน |
| `500` | error ฝั่ง server (generic message + `Log::error`) | `{"status":"error","message":"เกิดข้อผิดพลาดในการสร้างรายงาน กรุณาลองใหม่อีกครั้งค่ะนายท่าน 😭"}` |

ตัวอย่าง 422 (ขาด `date`):

```json
{
  "message": "The date field is required. (and 1 more error)",
  "errors": {
    "date": ["The date field is required."]
  }
}
```

ตัวอย่าง 422 (ช่วงวันที่เกิน 366 วัน — message จาก closure ใน `dateRangeRules()`):

```json
{
  "message": "ช่วงวันที่ของรายงานยาวเกิน 366 วัน (ไม่เกิน 1 ปีต่อการ export) ค่ะ 📅",
  "errors": {
    "date_to": ["ช่วงวันที่ของรายงานยาวเกิน 366 วัน (ไม่เกิน 1 ปีต่อการ export) ค่ะ 📅"]
  }
}
```

### 1.3 ชื่อไฟล์ที่ตอบกลับ

ชื่อไฟล์ = ชื่อรายงานไทย + ช่วงวันที่ของพารามิเตอร์หลัก **เป็น พ.ศ.** (`d-m-YYYY`) — อยู่ใน `Content-Disposition` แบบ `filename*=UTF-8''` (URL-encoded):

- รายงานช่วงวันที่: `รายงานเตียงเสริม (14-09-2569_ถึง_20-09-2569).xlsx`
- รายงานวันเดียว: `รายงานอาหารเช้า (15-09-2569).xlsx`
- รายงาน real-time ไม่มีวันที่ (`room-status`): `รายงานสถานะห้องพัก.xlsx`

---

## 2. ตระกูลพารามิเตอร์ 3 แบบ

| ตระกูล | พารามิเตอร์ | กติกา |
|---|---|---|
| **วันเดียว** | `date`, `checkin_date`, `checkout_date` | required · rule `date` · ไม่มีเพดานช่วง (เป็นวันเดียว) |
| **ช่วงวันที่** | `date_from`, `date_to` | required ทั้งคู่ · `date_to ≥ date_from` · ช่วง ≤ 366 วัน |
| **filter เสริม** | enum (`payment_channel`, `guest_type`, `breakfast_type`, `mode`, `work_type`, `item_type`, `status`) / string (`room_type`, `category`, `agency_code`) / ช่วงเงื่อนไข (`manager` mode=Month) | nullable ทั้งหมด — ไม่ส่ง = default |

⚠️ ชื่อวันที่ของ check-in/check-out ไม่ได้เป็น `date` แต่เป็น `checkin_date`/`checkout_date` ตามชีตต้นทาง — ส่งผิดชื่อ = `422`

---

## 3. พารามิเตอร์รายงานทั้ง 15 ฉบับ

### 3.1 `check-in` — รายงานห้องเข้าพัก (admin, staff)

| Param | Required | Rules | ความหมาย / Semantics |
|---|---|---|---|
| `checkin_date` | ✅ | `date` | วันที่เช็คอิน — คัด `booking_rooms.check_in` ตรงวันนี้ (BR สถานะ `checked_in` + `checked_out`) |
| `room_type` | — | `string, max:100` | ประเภทห้อง — เทียบ exact `room_types.name_en` (เช่น `Deluxe`) · `ทุกประเภท` หรือเว้น = ทุกประเภท |

```
GET /api/v1/reports/check-in/export?checkin_date=2026-10-26&room_type=Deluxe
```

### 3.2 `check-out` — รายงานห้องออก (admin, staff)

| Param | Required | Rules | ความหมาย / Semantics |
|---|---|---|---|
| `checkout_date` | ✅ | `date` | วันที่เช็คเอาท์ — คัด `booking_rooms.check_out` ตรงวันนี้ |
| `room_type` | — | `string, max:100` | เหมือน check-in (exact `name_en`, `ทุกประเภท` = ข้าม) |

```
GET /api/v1/reports/check-out/export?checkout_date=2026-10-26
```

### 3.3 `daily-financial` — รายงานการเงินประจำวัน (admin, staff)

| Param | Required | Rules | ความหมาย / Semantics |
|---|---|---|---|
| `date` | ✅ | `date` | วันที่ทำรายการ — คัด `payments.created_at` ตรงวันนี้ |
| `payment_channel` | — | `in: ทั้งหมด, สลิป (โอน/QR), เงินสด` | แหล่งเงิน (derive: `reference_number` ตรง confirmation UUID = สลิป, ไม่งั้น = เงินสด) · default `ทั้งหมด` |

```
GET /api/v1/reports/daily-financial/export?date=2026-10-26&payment_channel=%E0%B8%AA%E0%B8%A5%E0%B8%B4%E0%B8%9B%20(%E0%B9%82%E0%B8%AD%E0%B8%99%2FQR)
# payment_channel = "สลิป (โอน/QR)" (URL-encoded)
```

### 3.4 `deposit` — รายงานยอดมัดจำ (admin, staff)

| Param | Required | Rules | ความหมาย / Semantics |
|---|---|---|---|
| `date` | ✅ | `date` | วันที่สร้างการจอง (`bookings.created_at` ตรงวันนี้) |
| `guest_type` | — | `in: หน่วยงาน/ทั่วไป, หน่วยงาน, ทั่วไป` | `หน่วยงาน` = `organization_id` not null · `ทั่วไป` = null · default `หน่วยงาน/ทั่วไป` (ทั้งหมด) |

```
GET /api/v1/reports/deposit/export?date=2026-10-26&guest_type=หน่วยงาน
```

### 3.5 `occupancy` — รายงานการเข้าพัก 7 คืนข้างหน้า (admin, staff)

| Param | Required | Rules | ความหมาย / Semantics |
|---|---|---|---|
| `date` | ✅ | `date` | วันเริ่มช่วง — รายงาน render คืนที่ 1–7 นับจากวันนี้ (1 แถว = 1 คืน) ไม่มี filter เสริม |

```
GET /api/v1/reports/occupancy/export?date=2026-10-26
```

### 3.6 `breakfast` — รายงานอาหารเช้า (admin, staff)

| Param | Required | Rules | ความหมาย / Semantics |
|---|---|---|---|
| `date` | ✅ | `date` | วันที่รับอาหารเช้า — นิยามคืน D คือคืนที่ `check_in < D ≤ check_out` (เช้าวันถัดจากคืนนอน) |
| `breakfast_type` | — | `in: ทั้งหมด, ชุด 100, ชุด 200` | ตัดแถวที่ชุดนั้น qty = 0 ทิ้ง · default `ทั้งหมด` |

```
GET /api/v1/reports/breakfast/export?date=2026-10-27&breakfast_type=ชุด 100
```

### 3.7 `erp-transfer` — รายงานการโอนระหว่างหน่วยงาน (admin, staff)

| Param | Required | Rules | ความหมาย / Semantics |
|---|---|---|---|
| `date_from` | ✅ | `date` | ตัดจาก `bookings.created_at` ≥ (จองหน่วยงานที่สร้างในช่วง) |
| `date_to` | ✅ | `date, after_or_equal:date_from, ≤366 วัน` | `bookings.created_at` ≤ |
| `agency_code` | — | `string, max:100` | รหัส ERP ของหน่วยงาน — เทียบ exact `organizations.erp` · `ทั้งหมด`/เว้น = ทุกหน่วยงาน |

```
GET /api/v1/reports/erp-transfer/export?date_from=2026-10-01&date_to=2026-10-31&agency_code=ERP0142
```

### 3.8 `housekeeping` — รายงานแม่บ้าน v1 (admin, staff, housekeeping)

| Param | Required | Rules | ความหมาย / Semantics |
|---|---|---|---|
| `date` | ✅ | `date` | วันที่ดูงานแม่บ้าน + สถานะห้อง — ไม่มี filter เสริม |

```
GET /api/v1/reports/housekeeping/export?date=2026-10-26
```

### 3.9 `housekeeping-v2` — รายงานแม่บ้าน v2 (admin, staff, housekeeping)

| Param | Required | Rules | ความหมาย / Semantics |
|---|---|---|---|
| `date` | ✅ | `date` | เหมือน v1 (หัวคอลัมน์ checklist อ่านจาก config `reporting.cleaning_checks`) |

```
GET /api/v1/reports/housekeeping-v2/export?date=2026-10-26
```

### 3.10 `room-status` — รายงานสถานะห้องพัก real-time (admin, staff)

| Param | Required | Rules | ความหมาย / Semantics |
|---|---|---|---|
| `status` | — | `in: ทุกสถานะ, OCC, OOO, EA, VD, VC` | กรองสถานะห้อง snapshot **ณ เวลา export** (ลำดับตัดสิน OOO > OCC > EA > VD > VC) · default `ทุกสถานะ` |

> 🚫 รายงานเดียวที่ **ไม่รับพารามิเตอร์วันที่** — ส่ง `date` มาจะถูกเมิน (ไม่ 422)

```
GET /api/v1/reports/room-status/export?status=OOO
```

### 3.11 `extra-bed` — รายงานเตียงเสริม (admin, staff)

| Param | Required | Rules | ความหมาย / Semantics |
|---|---|---|---|
| `date_from` | ✅ | `date` | วันเริ่มช่วง — **คอลัมน์ชีตขยาย dynamic ตามจำนวนคืนในช่วง** |
| `date_to` | ✅ | `date, after_or_equal:date_from, ≤366 วัน` | วันสิ้นสุด |

> ไม่มี filter เสริม · inventory รวมอิง config `reporting.extra_bed_fleet_size`

```
GET /api/v1/reports/extra-bed/export?date_from=2026-10-01&date_to=2026-10-31
```

### 3.12 `supplies` — รายงานวัสดุสิ้นเปลือง (admin, staff)

| Param | Required | Rules | ความหมาย / Semantics |
|---|---|---|---|
| `date_from` | ✅ | `date` | วันเริ่มช่วงเบิก/เติมของ |
| `date_to` | ✅ | `date, after_or_equal:date_from, ≤366 วัน` | วันสิ้นสุด |
| `category` | — | `string, max:100` | หมวดพัสดุ — เทียบ exact `stock_inventories.category` · `ทั้งหมด`/`all`/เว้น = ทุกหมวด |

```
GET /api/v1/reports/supplies/export?date_from=2026-10-01&date_to=2026-10-31&category=Amenities
```

### 3.13 `out-of-service-room` — รายงานห้องชำรุด/บำรุงรักษา (admin, staff)

| Param | Required | Rules | ความหมาย / Semantics |
|---|---|---|---|
| `date_from` | ✅ | `date` | ตัดจากช่วง period ห้องตัดจากการใช้งาน (`room_state_periods`) |
| `date_to` | ✅ | `date, after_or_equal:date_from, ≤366 วัน` | วันสิ้นสุด |
| `work_type` | — | `in: ทุกประเภท, ไฟฟ้า, ประปา, งานระบบ` | ประเภทงานซ่อม (ค่าจาก `RoomStatePeriod::WORK_TYPES`) · default `ทุกประเภท` |

```
GET /api/v1/reports/out-of-service-room/export?date_from=2026-10-01&date_to=2026-10-31&work_type=ไฟฟ้า
```

### 3.14 `additional-charges` — รายการค่าปรับ/ขออุปกรณ์เพิ่มเติม (admin, staff)

| Param | Required | Rules | ความหมาย / Semantics |
|---|---|---|---|
| `date_from` | ✅ | `date` | ตัดจาก `additional_charges.transaction_date` |
| `date_to` | ✅ | `date, after_or_equal:date_from, ≤366 วัน` | วันสิ้นสุด |
| `item_type` | — | `in: ทุกประเภท, ค่าเสียหาย, ค่ายืม, บริการเสริม` | แมปเป็น `charge_type`: `ค่าเสียหาย`→`damage`, `ค่ายืม`→`rental`, `บริการเสริม`→`addon` · default `ทุกประเภท` |

```
GET /api/v1/reports/additional-charges/export?date_from=2026-10-01&date_to=2026-10-31&item_type=ค่าเสียหาย
```

### 3.15 `manager` — รายงานผู้จัดการ / Manager's Report (admin, staff)

Matrix 20 metrics × 6 คอลัมน์ช่วงเวลา — พารามิเตอร์ซับซ้อนที่สุดของชุดนี้:

| Param | Required | Rules | ความหมาย / Semantics |
|---|---|---|---|
| `date` | ✅ | `date` | วันที่ประจำรายงาน (anchor ของ Day / MTD / YTD ทุก mode) |
| `mode` | — | `in: Complete, Week, Month` (case-sensitive) | นิยามคอลัมน์ "MTD": `Complete` = ต้นเดือนถึงวันที่ `date` · `Week` = วันจันทร์ของสัปดาห์ถึง `date` · `Month` = **ใช้ช่วง `date_from`–`date_to` ที่ผู้ใช้เลือกแทน** · default `Complete` |
| `date_from` | ⚠️ `required_if:mode,Month` | `nullable, date` | ต้องส่ง**เฉพาะ** `mode=Month` — ไม่ส่งตอน mode=Month → `422` |
| `date_to` | ⚠️ `required_if:mode,Month` | `nullable, date, ≥ date_from, ≤366 วัน` | ปลายทางช่วง From-TO (ปิดช่วงแบบ inclusive) |

> mode อื่น (`Complete`/`Week`) ส่ง `date_from`/`date_to` มาจะถูก**เมิน** (closure validate ข้ามไป) — ไม่ 422

```
GET /api/v1/reports/manager/export?date=2026-10-26&mode=Complete
GET /api/v1/reports/manager/export?date=2026-10-26&mode=Month&date_from=2026-10-01&date_to=2026-10-26
```

---

## 4. ตารางสรุปด่วน (Param × Report)

| Slug | วันที่ | ช่วงวันที่ | filter เสริม | default ของ filter |
|---|---|---|---|---|
| `check-in` | `checkin_date` | — | `room_type` (string) | — |
| `check-out` | `checkout_date` | — | `room_type` (string) | — |
| `daily-financial` | `date` | — | `payment_channel` (enum) | `ทั้งหมด` |
| `deposit` | `date` | — | `guest_type` (enum) | `หน่วยงาน/ทั่วไป` |
| `occupancy` | `date` | — | — | — |
| `breakfast` | `date` | — | `breakfast_type` (enum) | `ทั้งหมด` |
| `erp-transfer` | — | `date_from`, `date_to` | `agency_code` (string) | — |
| `housekeeping` | `date` | — | — | — |
| `housekeeping-v2` | `date` | — | — | — |
| `room-status` | **ไม่มี** | — | `status` (enum) | `ทุกสถานะ` |
| `extra-bed` | — | `date_from`, `date_to` | — | — |
| `supplies` | — | `date_from`, `date_to` | `category` (string) | — |
| `out-of-service-room` | — | `date_from`, `date_to` | `work_type` (enum) | `ทุกประเภท` |
| `additional-charges` | — | `date_from`, `date_to` | `item_type` (enum) | `ทุกประเภท` |
| `manager` | `date` | `date_from`, `date_to` (เมื่อ mode=Month เท่านั้น) | `mode` (enum) | `Complete` |

---

## 5. ตัวอย่างการเรียก (curl)

```bash
# รายงานวันเดียว + filter enum (URL-encode ค่าไทย)
curl -G "https://<host>/backend/api/v1/reports/daily-financial/export" \
  -H "Authorization: Bearer $TOKEN" -H "Accept: application/json" \
  --data-urlencode "date=2026-10-26" \
  --data-urlencode "payment_channel=เงินสด" \
  -o daily-financial.xlsx

# รายงานช่วงวันที่
curl -G "https://<host>/backend/api/v1/reports/additional-charges/export" \
  -H "Authorization: Bearer $TOKEN" -H "Accept: application/json" \
  --data-urlencode "date_from=2026-10-01" \
  --data-urlencode "date_to=2026-10-31" \
  -o additional-charges.xlsx

# manager mode=Month (บังคับ date_from/date_to)
curl -G "https://<host>/backend/api/v1/reports/manager/export" \
  -H "Authorization: Bearer $TOKEN" -H "Accept: application/json" \
  --data-urlencode "date=2026-10-26" \
  --data-urlencode "mode=Month" \
  --data-urlencode "date_from=2026-10-01" \
  --data-urlencode "date_to=2026-10-26" \
  -o manager-month.xlsx
```

> 💡 บันทึกไฟล์ที่ได้ด้วยนามสกุล `.xlsx` — ชื่อไฟล์จริง (ภาษาไทย+พ.ศ.) อยู่ใน header `Content-Disposition` เสมอ
