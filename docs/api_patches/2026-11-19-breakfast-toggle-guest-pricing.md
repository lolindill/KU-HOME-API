# 🍳 FE Patch Notes 2026-11-19 — Breakfast toggle + เรทรายหัว (breaking)

> **Breaking wire-format change** — payload/response ของ `addons` เปลี่ยนรูปทั้งระบบจอง
> Backend commit: feat(breakfast) reshape — toggle + rate-per-guest pricing

## สรุปเปลี่ยนแปลง

Breakfast เดิมเป็น **จำนวนชุด** (`breakfast_sets.set_100` / `set_200` เป็นตัวเลข) → ใหม่เป็น
**toggle (bool) ราคาห้องละชุดเดียว** โดยเลือกเรทได้ 1 แบบจาก `global_rates`:

```
breakfast_total = rate(breakfast_code) × nights × จำนวนผู้เข้าพักของห้อง
```

- จำนวนผู้เข้าพัก = `count(booking_room.guests)` — **guests ว่าง/ไม่ส่ง นับเป็น 1 คน**
- เรทที่หยิบมาใช้ตอนคิดเงินอ่านจาก `global_rates` แถว `rate_type='addon'`, `is_active`,
  `code` ขึ้นต้น `breakfast` (ปัจจุบัน: `breakfast_100` = 100฿, `breakfast_200` = 200฿) —
  เพิ่มเรทใหม่ฝั่ง backend แล้ว FE หยิบไปโชว์ได้ทันที ไม่ต้องแก้โค้ด

## Wire format ใหม่

### Request — `addons` (ทุก endpoint จอง/แก้ห้อง)

```jsonc
"addons": {
  "breakfast_enabled": true,        // 🆕 canonical — toggle (bool)
  "breakfast_code": "breakfast_200" // 🆕 optional — เรทจาก global_rates (ไม่ส่ง = คงเดิม/default ถูกสุด)
}
```

- `breakfast_code` ที่ไม่มีในระบบ (ไม่มี row / ถูกปิด active) → **422**
- ส่ง code ตอน `breakfast_enabled=false` → ถูกล้างเป็น null (code ผูกกับ toggle เท่านั้น)

### Legacy alias — ยังรับช่วง transition (normalize ฝั่ง server)

| ของเดิม | แปลงเป็น |
|---|---|
| `breakfast_sets: { set_200: N }` (N > 0) | `enabled=true, code=breakfast_200` |
| `breakfast_sets: { set_100: N }` (N > 0) | `enabled=true, code=breakfast_100` |
| `breakfast_sets: { set_100: 0, set_200: 0 }` | `enabled=false` |
| `breakfast: N` (int > 0) | `enabled=true, code=breakfast_200` (เรทเดิม) |
| `breakfast: 0` | `enabled=false` |
| ไม่ส่ง breakfast key เลย | คงค่าเดิมของแถว addon (ไม่เปลี่ยน) |

⚠️ FE ควรเปลี่ยนไปส่ง canonical (`breakfast_enabled` + `breakfast_code`) ให้เร็วที่สุด —
ตัวเลข `breakfast`/`breakfast_sets` ที่ > 0 จะถูกตีความเป็น "เปิด toggle" เท่านั้น จำนวนชุดหาย
(คิดเงินเป็นชุดเดียว × หัวเสมอ)

### Response — Addon object

| เดิม | ใหม่ |
|---|---|
| `breakfast_set_100` (int) | ❌ ถูกถอด |
| `breakfast_set_200` (int) | ❌ ถูกถอด |
| — | 🆕 `breakfast_enabled` (bool) |
| — | 🆕 `breakfast_code` (string\|null — null เมื่อปิด) |
| `breakfast_price` (int snapshot ยอด) | คงเดิม (ยอดตามสูตรใหม่) |

ตัวอย่าง: ห้อง 2 คน × 3 คืน × code breakfast_200 → `breakfast_price = 1200`

## Money impact

- สูตรใหม่คิด **× หัว (จำนวน guests)** เพิ่มจากเดิม × คืน — บิลเก่าที่พักหลายคนจะเห็น
  breakfast แพงขึ้นเมื่อ reprice ทำงาน (แก้ addon/วันที่/ชั่วโมง = reprice ทั้งบิลตามเดิม)
- `breakfast_price` ยังเป็น snapshot ที่ reprice เขียน — อ่านจาก response ตรง ๆ ได้
- downgrade guard (บิลจ่ายเต็มแล้ว) ยัง block การ "ปิด toggle" เพราะยอดลด — เหมือนเดิม

## Endpoint ที่กระทบ

- `POST /bookings` (create) · `PUT /bookings/{id}/rooms/{rid}` · `PUT /bookings/{id}/rooms` (batch)
  · `POST /bookings/{id}/rooms` (addRooms) — input `addons`
- Response `booking_rooms[].addon` ทุก endpoint อ่านบิล
- split-upgrade (`POST /front-desk/{bid}/rooms/{brid}/split-upgrade`) — BR-2 ได้ toggle+code
  copy จาก BR-1

## 📊 Report รายงานอาหารเช้า (GET /reports/breakfast/export)

- คอลัมน์ `qty_set_100` / `qty_set_200` ถูกถอด → 🆕 `breakfast_code` + `qty`
  (qty = จำนวนผู้เข้าพักของห้อง — ทุกคนในห้องกิน)
- filter `breakfast_type`: เดิมรับ `ทั้งหมด|ชุด 100|ชุด 200` → ใหม่รับ
  `ทั้งหมด` หรือ **code** (`breakfast_100`, `breakfast_200`, … เรทใหม่ที่เพิ่มอนาคต)
- summary ใช้ key `qty`

## เรท (global_rates)

- `breakfast_100` / `breakfast_200` ยัง active อยู่เดิม — ห้ามปิดเรทที่ห้องใช้ live อยู่
  (code หาย = ยอด breakfast คิดเป็น 0)
- เพิ่มเรทใหม่ = สร้าง row `rate_type='addon'`, `code` ขึ้นต้น `breakfast` (เช่น `breakfast_300`)
