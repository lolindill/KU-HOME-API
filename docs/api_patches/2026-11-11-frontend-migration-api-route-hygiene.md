# 📢 [แจ้ง Frontend] API Patch 2026-11-11 — api-route-hygiene (tickets 03–04)

> **สำหรับ:** ทีม Frontend (`ku-home`) · **จาก:** Backend KU HOME API
> **สถานะ:** merge บน `dev` แล้ว (commits `11af6b8` + `a977e3a`) — มีผลเมื่อ backend รุ่นที่รวม commits นี้ขึ้น prod
> **Patch notes เต็ม (ฝั่ง backend):** [`2026-11-11-unify-booking-update-route.md`](./2026-11-11-unify-booking-update-route.md) · [`2026-11-11-unify-evidence-addressing.md`](./2026-11-11-unify-evidence-addressing.md)

---

## ⚡ TL;DR — FE ต้องทำอะไรบ้าง

| # | สิ่งที่ต้องทำ | หน้าที่กระทบ | ความเร่งด่วน |
|---|---|---|---|
| 1 | 📞 **สลับ URL** `PUT /bookings/update/{id}` → `PUT /bookings/{id}/status` | `admin-confirm-bookings` | 🔴 **บังคับ** — เส้นเก่าถูกถอด (ยิงแล้ว 404 ทันที) |
| 2 | 🧹 เช็ค error handling ที่ parse body ของ 404 "id ไม่ใช่ UUID" | หน้าเดียวกัน | 🟡 แนะนำ (พฤติกรรม body เปลี่ยน — ด้านล่าง) |
| 3 | ℹ️ ไม่ต้องทำอะไร: evidence endpoints ย้ายแล้ว — reverse index (snapshot 2026-11-06) ยืนยัน **FE ไม่มี caller** | — | 🟢 FYI |
| 4 | ℹ️ ไม่ต้องทำอะไร: `assign-rooms` URL คงเดิม (แค่เพิ่ม UUID guard ที่ router) | — | 🟢 FYI |

**จำง่าย ๆ:** มี **call site เดียว** ที่พังถ้าไม่แก้ = การเปลี่ยนสถานะบิลในหน้า `admin-confirm-bookings` (reverse index 2026-11-06 · backend grep สดไม่ได้เพราะ FE repo ไม่อยู่บนเครื่อง build backend — ถ้ามี caller อื่นที่เอกสารนี้ไม่เห็น รบกวนแจ้งกลับด้วยนะคะ 🙏)

---

## 🔴 1. Breaking: `PUT /bookings/update/{id}` → `PUT /bookings/{id}/status`

เส้นเปลี่ยนสถานะบิลด้วยมือ (admin) ถูกรวมเข้า REST subresource convention เดียวกับ `GET /bookings/{id}/status-logs`

```typescript
// ❌ เดิม — เส้นถูกถอดแล้ว (404 Not Found)
await api.put(`/bookings/update/${bookingId}`, { status: newStatus });

// ✅ ใหม่
await api.put(`/bookings/${bookingId}/status`, { status: newStatus });
```

| รายการ | เดิม | ใหม่ |
|---|---|---|
| **URL** | `PUT /bookings/update/{id}` | `PUT /bookings/{id}/status` |
| **Request body** | `{ "status": "draft"\|"paid"\|"confirmed"\|"complete" }` | **เหมือนเดิมทุกอย่าง** |
| **Response 200** | ข้อมูลบิล | **เหมือนเดิมทุกอย่าง** |
| **Auth / Throttle** | admin only / ไม่มี throttle | **คงเดิม** |
| `{id}` ไม่ใช่ UUID | หลุดเข้า controller → JSON 404 | ⚠️ **router 404 ก่อนถึง controller** (ดูหัว 2) |

### 🟡 2. Error body ที่เปลี่ยน (พฤติกรรมเดิมที่เหลือคงเดิมหมด)

| Case | HTTP | Body | เทียบเดิม |
|---|---|---|---|
| `id` **ไม่ใช่ UUID** | 404 | Laravel router 404 (`{"message":"... could not be found."}`) | ⚠️ เดิมเป็น JSON `{"status":"error",...}` จาก controller |
| UUID แต่ไม่มีบิลจริง | 404 | `{"status":"error","message":"ไม่พบข้อมูล Booking..."}` | คงเดิม |
| transition ไม่อนุญาต / status ไม่ valid | 422 | JSON error | คงเดิม |
| ไม่ใช่ admin | 403 | JSON error | คงเดิม |

> FE ถ้ามี switch/case จับ 404 แบบอ่าน `status:"error"` — กรณี non-uuid จะไม่เข้าครบอีกต่อไป แนะนำจับจาก HTTP code ล้วน ๆ

---

## 🟢 3. FYI — evidence endpoints ย้ายเป็น nested (FE ไม่มี caller)

```
เดิม:  POST/GET /front-desk/booking-rooms/{id}/evidence           (ถอดแล้ว — 404)
ใหม่:  POST/GET /front-desk/{bookingId}/rooms/{bookingRoomId}/evidence
```

- ตรงรูปแบบพี่น้องใน prefix `front-desk` (check-in/out · change-room · split-upgrade)
- Guard ใหม่: `bookingRoomId` ไม่อยู่ใต้ `bookingId` → **404** (ไม่ใช่ 403)
- RBAC `admin,staff` · 365-day retention · signed URL 15 นาที — คงเดิมทุกอย่าง
- **หลักฐานว่า FE ไม่ต้องแก้:** reverse index snapshot 2026-11-06 ไม่พบ caller ทั้ง POST/GET

## 🟢 4. FYI — `PUT /bookings/{bookingId}/assign-rooms` (zero-URL-change)

URL/พฤติกรรมปกติคงเดิม 100% — มีแต่ guard ใหม่: `bookingId` ที่ไม่ใช่ UUID โดน router 404 (เดิมหลุดเข้า controller) เรียกด้วย uuid ปกติไม่กระทบเลย

---

## 📌 พบระหว่างตรวจ (นอกเหนือจาก patch นี้ — แจ้งมาให้รู้)

- 🚧 5 หน้ายังยิง `/mock/availability-ranges` (prod-gated OFF) — เส้นจริง `/availability-ranges` รออยู่แล้ว ตาม DELETION PLAN ของ MockController
- ⚠️ `housekeeping.ts` เตรียมเรียก `GET /dashboard/cleaning-tasks` — backend **ไม่มีเส้นนี้** (ของจริงคือ `GET /dashboard/tasks`) จะ 404 เมื่อหน้า Housekeeping เลิกใช้ mock

---

## 🔗 อ้างอิง

- Merge: `11af6b8` (ticket 03) · `a977e3a` (ticket 04) บน `dev` — suite 925 passed / pint PASS
- Wayfinder map `api-route-hygiene` ปิดครบ 4 tickets 2026-11-11 (design record บนเครื่อง dev เดิม)
- คำถาม/เจอ caller ที่พลาด: แจ้งกลับฝั่ง backend ได้เลยค่ะ ✨
