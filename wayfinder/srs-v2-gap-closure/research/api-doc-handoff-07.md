# API Documentation Handoff — Ticket 07 (REQ-051/052)
## ระบบจัดการสต็อกสินค้าและวัสดุสิ้นเปลือง (Stock Inventory & Movement Logs)

สำหรับนำไปผนวกเข้ากับ `docs/api_guide.md` โดยผู้จัดการ

---

### ภาพรวม (Overview)
- **Base URL Prefix:** `/api/v1/stock`
- **Authentication:** Bearer Token (Sanctum) ทุก endpoint
- **Role Permissions:**
  - `admin`, `staff`: สิทธิ์อ่านและเขียนเต็มรูปแบบ (CRUD + Adjust Stock)
  - `housekeeping`: สิทธิ์อ่านอย่างเดียว (`GET /stock`, `GET /stock/{id}`, `GET /stock/{id}/movements`), เขียนจะได้รับ HTTP 403
  - `user`, `guest`: ไม่มีสิทธิ์ (HTTP 403)
- **Derived Status:**
  - `out`: เมื่อ `quantity <= 0`
  - `low`: เมื่อ `quantity <= reorder_point` (และ `quantity > 0`)
  - `ok`: สต็อกปกติ (`quantity > reorder_point`)
  - *หมายเหตุ:* Backend ส่ง enum สถานะ (`ok`, `low`, `out`) ให้ frontend จัดการแสดงสีแถบสถานะตาม UI Design
- **Deletion Strategy:** Soft Delete (`deleted_at`) เพื่อรักษาประวัติการเคลื่อนไหวสต๊อก (Movement Logs) ย้อนหลัง 1 ปี (365 วัน)
- **Audit & Movement Log:** ทุกครั้งที่มีการเพิ่ม/ลด `quantity` (ไม่ว่าจะผ่าน Create, Update, หรือ Adjust) ระบบจะบันทึก `stock_movements` โดยอัตโนมัติ

---

### Endpoint Reference

#### 1. รายการสต็อกสินค้า (List Stock Items)
`GET /api/v1/stock`
- **Headers:** `Accept: application/json`, `Authorization: Bearer <token>`
- **Query Parameters:**
  - `search` (string, optional): ค้นหาชื่อสินค้า, รหัสสินค้า, หรือหมายเหตุ
  - `category` (string, optional): กรองหมวดหมู่สินค้า เช่น `Guest Amenities`, `Linen and Bedding`
  - `status` (string, optional): กรองตามสถานะ derived (`ok`, `low`, `out`)
- **Response (200 OK):**
```json
{
  "status": "success",
  "data": [
    {
      "id": "019266e7-...",
      "item_code": "SKU-WATER-01",
      "item_name": "น้ำดื่ม 500ml",
      "category": "Guest Amenities",
      "quantity": 150,
      "unit": "ขวด",
      "reorder_point": 50,
      "max_stock": 500,
      "status": "ok",
      "notes": "สต็อกชั้น 1",
      "created_at": "2026-10-14T03:00:00.000000Z",
      "updated_at": "2026-10-14T03:00:00.000000Z"
    }
  ]
}
```

#### 2. ดูรายละเอียดสต็อกสินค้า (Show Stock Item)
`GET /api/v1/stock/{id}`
- **Headers:** `Accept: application/json`, `Authorization: Bearer <token>`
- **Response (200 OK):**
```json
{
  "status": "success",
  "data": {
    "id": "019266e7-...",
    "item_code": "SKU-WATER-01",
    "item_name": "น้ำดื่ม 500ml",
    "category": "Guest Amenities",
    "quantity": 150,
    "unit": "ขวด",
    "reorder_point": 50,
    "max_stock": 500,
    "status": "ok",
    "notes": null,
    "created_at": "2026-10-14T03:00:00.000000Z",
    "updated_at": "2026-10-14T03:00:00.000000Z"
  }
}
```

#### 3. เพิ่มรายการสินค้าในคลัง (Create Stock Item)
`POST /api/v1/stock`
- **Role:** `admin`, `staff`
- **Headers:** `Accept: application/json`, `Authorization: Bearer <token>`, `Content-Type: application/json`
- **Request Body:**
```json
{
  "item_code": "SKU-SOAP-01",
  "item_name": "สบู่เหลวอาบน้ำ",
  "category": "Guest Amenities",
  "quantity": 100,
  "unit": "ขวด",
  "reorder_point": 30,
  "max_stock": 300,
  "notes": "ยี่ห้อ KU Fresh",
  "reason": "เปิดคลังรอบใหม่"
}
```
- **Response (201 Created):**
```json
{
  "status": "success",
  "message": "เพิ่มรายการสินค้าในคลังเรียบร้อยแล้วค่ะนายท่าน ✨",
  "data": {
    "id": "019266e8-...",
    "item_code": "SKU-SOAP-01",
    "item_name": "สบู่เหลวอาบน้ำ",
    "category": "Guest Amenities",
    "quantity": 100,
    "unit": "ขวด",
    "reorder_point": 30,
    "max_stock": 300,
    "status": "ok",
    "notes": "ยี่ห้อ KU Fresh",
    "created_at": "2026-10-14T03:10:00.000000Z",
    "updated_at": "2026-10-14T03:10:00.000000Z"
  }
}
```

#### 4. แก้ไขข้อมูลสินค้า (Update Stock Item)
`PUT /api/v1/stock/{id}`
- **Role:** `admin`, `staff`
- **Headers:** `Accept: application/json`, `Authorization: Bearer <token>`, `Content-Type: application/json`
- **Request Body:**
```json
{
  "item_name": "สบู่เหลวอาบน้ำ (สูตรอ่อนโยน)",
  "reorder_point": 40,
  "quantity": 120,
  "reason": "นับสต็อกประจำสัปดาห์พบส่วนเกิน"
}
```
- **Response (200 OK):**
```json
{
  "status": "success",
  "message": "อัปเดตข้อมูลสินค้าในคลังเรียบร้อยแล้วค่ะนายท่าน ✨",
  "data": { ... }
}
```

#### 5. ปรับปรุงจำนวนสต็อก / เบิก-จ่าย / รับเข้า (Adjust Stock Quantity)
`POST /api/v1/stock/{id}/adjust`
- **Role:** `admin`, `staff`
- **Headers:** `Accept: application/json`, `Authorization: Bearer <token>`, `Content-Type: application/json`
- **Request Body (ระบุ delta หรือ quantity อย่างใดอย่างหนึ่ง):**
```json
{
  "delta": -5,
  "reason": "เบิกใส่ห้องพัก",
  "housekeeping_task_id": "019266e9-...",
  "notes": "ห้อง 204"
}
```
- **Response (200 OK):**
```json
{
  "status": "success",
  "message": "ปรับปรุงจำนวนสต็อกสินค้าเรียบร้อยแล้วค่ะนายท่าน ✨",
  "data": {
    "id": "...",
    "quantity": 95,
    "status": "ok",
    ...
  },
  "movement": {
    "id": "019266ea-...",
    "stock_inventory_id": "...",
    "delta": -5,
    "quantity_before": 100,
    "quantity_after": 95,
    "reason": "เบิกใส่ห้องพัก",
    "housekeeping_task_id": "019266e9-...",
    "user_id": "...",
    "notes": "ห้อง 204",
    "created_at": "2026-10-14T03:15:00.000000Z"
  }
}
```

#### 6. ลบรายการสินค้า (Delete Stock Item)
`DELETE /api/v1/stock/{id}`
- **Role:** `admin`, `staff`
- **Headers:** `Accept: application/json`, `Authorization: Bearer <token>`
- **Response (200 OK):**
```json
{
  "status": "success",
  "message": "ลบรายการสินค้าในคลังเรียบร้อยแล้วค่ะนายท่าน 🗑️"
}
```

#### 7. ดูประวัติการเคลื่อนไหวสต๊อก (List Stock Movements)
`GET /api/v1/stock/{id}/movements`
- **Headers:** `Accept: application/json`, `Authorization: Bearer <token>`
- **Response (200 OK):**
```json
{
  "status": "success",
  "data": [
    {
      "id": "019266ea-...",
      "stock_inventory_id": "...",
      "delta": -5,
      "quantity_before": 100,
      "quantity_after": 95,
      "reason": "เบิกใส่ห้องพัก",
      "housekeeping_task_id": "019266e9-...",
      "user_id": "...",
      "notes": "ห้อง 204",
      "created_at": "2026-10-14T03:15:00.000000Z",
      "user": { ... },
      "housekeeping_task": { ... }
    }
  ]
}
```

---

### Scheduled Command: Cleanup Stock Movements
`php artisan app:cleanup-stock-movements {--dry-run}`
- รันอัตโนมัติทุกวันเวลา 03:00 น. (`dailyAt('03:00')`)
- ลบประวัติ `stock_movements` ที่มีอายุเกิน `STOCK_MOVEMENT_RETENTION_DAYS` (default 365 วัน)
- ตัวเลือก `--dry-run` สำหรับการนับจำนวนโดยไม่ลบจริง
