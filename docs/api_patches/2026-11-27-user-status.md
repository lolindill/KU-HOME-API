# 📢 API Patch Notes (Frontend) — 🔐 User status `active` / `inactive` แทน `ver` (2026-11-27)

> **สำหรับ:** ทีม Frontend (`ku-home`)
> **ประเด็น:** ลบ field legacy `ver` ออกจาก user + เพิ่มสถานะบัญชี `status` ที่มีผลจริง (ปิดบัญชี = login ไม่ได้)

---

## ⚡ TL;DR — FE ต้องทำอะไร

| # | หัวข้อ | ประเภท | ผลกระทบ / สิ่งที่ FE ต้องทำ |
|---|---|---|---|
| 1 | 🔴 **`PUT /users/{id}/verify` ถูกถอด** | Breaking (`404`) | เปลี่ยนไปใช้ `PATCH /users/{id}/status` body `{"status": "active" \| "inactive"}` |
| 2 | 🔴 **field `ver` หายจาก user object** | Breaking (wire) | ทุก response ที่มี user (`/me`, `/users*`, SSO exchange, booking attach user) ไม่มี `ver` แล้ว → มี `status` แทน — ลบ UI ป้าย "verified" เดิม |
| 3 | 🆕 **`status` ใน user object** | Field ใหม่ | `"active"` (default ทุกคน) \| `"inactive"` |
| 4 | 🆕 **`GET /users?status=inactive`** | Filter ใหม่ | กรองรายชื่อตามสถานะได้ (ใช้คู่กับ `auth_provider` ได้) |
| 5 | 🟡 **Login ของบัญชีที่ปิด → `403`** | Error ใหม่ | `/login`, `/auth/sso/exchange`, `/auth/sso/google/exchange` ตอบ `403` + `message` — แสดงข้อความให้ติดต่อผู้ดูแล (อย่า retry) |
| 6 | 🟡 **ปิดบัญชีแล้ว token เดิมใช้ไม่ได้ทันที** | Behavior | request ถัดไปของ user คนนั้นได้ `401` → flow เดิมของ 401 (เด้งไปหน้า login) ใช้ได้เลย |

---

## 🆕 `PATCH /api/v1/users/{id}/status` (admin only)

```json
{ "status": "inactive" }
```

| Response | เมื่อไหร่ |
|---|---|
| `200` | สำเร็จ — คืน `user` ที่อัปเดตแล้ว |
| `422` | `status` ไม่ใช่ `active`/`inactive` · หรือ admin พยายามปิดบัญชี**ตัวเอง** (ซ่อน/disable ปุ่มบนแถวของตัวเองได้) |
| `403` | ไม่ใช่ admin |

ผลของ `inactive`: ลบ token ทุก device ของ user นั้นทันที + login ทุกช่องทางได้ `403` · ข้อมูลอื่น (booking ฯลฯ) ไม่ถูกแตะ · ตั้งกลับ `active` แล้ว login ใหม่ได้ตามปกติ

## 🛡️ ตั้ง `status` ทางอื่นไม่ได้

`POST /register`, `PUT /profile`, `POST /users`, `PUT /users/{id}` — ส่ง `status` มาจะ**ถูกเมิน** (ไม่ error) · ผู้ใช้ใหม่เป็น `active` เสมอ

## 🔐 Login `403` response

```json
{
  "status": "error",
  "message": "บัญชีนี้ถูกปิดการใช้งาน กรุณาติดต่อผู้ดูแลระบบค่ะ 🔒"
}
```

> รหัสผ่านผิดยังได้ `401` เหมือนเดิม — `403` จะออกก็ต่อเมื่อรหัสถูกแล้วเท่านั้น

## 🗄️ Migration

`2026_11_27_100000_replace_ver_with_status_on_users_table` — เพิ่ม `users.status` (string, default `active`, index) + drop `ver` · user เดิมทุกคนเป็น `active` · ใช้ `php artisan migrate` ได้เลย (ไม่ต้อง fresh)
