# 📝 API Documentation Handoff — Ticket 06 (REQ-048: Housekeeping Photos)

เอกสารส่งมอบสำหรับนำไปรวมใน `docs/api_guide.md` (ตามกติกาห้ามแตะ `docs/api_guide.md` โดยตรงใน branch ticket)

---

## 🧹 Housekeeping Task Photos API (REQ-048)

ระบบอัปโหลดและดูรูปภาพงานแม่บ้าน 4 หมวด (ก่อน/หลัง, ของที่ลืม, ความเสียหาย) พร้อมบันทึกหมายเหตุงาน จัดเก็บด้วยตาราง `images` (polymorphic morph กับ `HousekeepingTask`) บน private disk (`local`) และเปิดดูผ่าน Temporary Signed URL อายุ 15 นาที โดยมีระบบ Retention เก็บรักษา 90 วันผ่านคำสั่ง `app:cleanup-images`

---

### 1. อัปโหลดรูปภาพงานทำความสะอาด

- **Endpoint:** `POST /api/v1/dashboard/tasks/{id}/photos`
- **Auth:** `auth:sanctum`
- **Role:** `admin`, `housekeeping`
  - `housekeeping`: อัปโหลดได้เฉพาะ task ที่ตนเองได้รับมอบหมาย (`assigned_to === auth.id`) หากเป็นงานของคนอื่นหรือยังไม่มีผู้รับ จะได้ `403 Forbidden`
  - `admin`: อัปโหลดได้กับทุก task
- **Content-Type:** `multipart/form-data`
- **Headers:** `Accept: application/json`

#### Request Parameters (Form-Data)

| Field | Type | Required | Description |
|---|---|---|---|
| `category` | String | Yes | หมวดหมู่รูปภาพ: `before`, `after`, `forgotten_item`, `damage` |
| `photo` | File | Yes* | ไฟล์รูปภาพ (`image/*`, ขนาดสูงสุด 10MB) (*จำเป็นเมื่อไม่ส่ง `photos`) |
| `photos` | File[] | Yes* | อาร์เรย์ของไฟล์รูปภาพ (*จำเป็นเมื่อไม่ส่ง `photo`) |
| `notes` | String | No | ข้อความหมายเหตุงานทำความสะอาด (บันทึกลงฟิลด์ `notes` ของ task) |

#### Validation & Cap Rules

1. **Cap 5+5 ภาพ:**
   - หมวด `before` สามารถอัปโหลดได้สูงสุด **5 ภาพ** ต่อ 1 task
   - หมวด `after` สามารถอัปโหลดได้สูงสุด **5 ภาพ** ต่อ 1 task
   - หากจำนวนรูปเดิม + รูปใหม่เกิน 5 ระบบจะปฏิเสธด้วย `422 Unprocessable Entity` พร้อมข้อความแจ้งเตือน
2. **หมวด forgotten_item / damage:** ไม่ติด cap 5 ภาพ
3. **⚠️ Damage Photo Disclaimer:** รูปภาพความเสียหายเป็นหลักฐานสภาพห้องเท่านั้น **ไม่มีผลทางบัญชี** ไม่มีการสร้างรายการชำระเงินหรือบันทึกค่าเสียหายลงในระบบการเงินโดยอัตโนมัติ

#### Response `201 Created`

```json
{
  "status": "success",
  "message": "อัปโหลดรูปภาพงานทำความสะอาดเรียบร้อยค่ะ ✨",
  "photo": {
    "id": "01a1386b-dbd1-7002-8d82-631ef9f41e35",
    "task_id": "01a1386a-72ef-70aa-9a74-d2c610bca3f1",
    "category": "before",
    "original_name": "before_cleaning.jpg",
    "size": 245120,
    "mime_type": "image/jpeg",
    "url": "http://localhost:8000/api/v1/images/01a1386b-dbd1-7002-8d82-631ef9f41e35/file?expires=1791948900&signature=abc123...",
    "uploaded_by": "01a13869-7c85-7075-8eb6-a367469a924b",
    "created_at": "2026-10-14T03:20:00.000000Z"
  },
  "photos": [
    {
      "id": "01a1386b-dbd1-7002-8d82-631ef9f41e35",
      "task_id": "01a1386a-72ef-70aa-9a74-d2c610bca3f1",
      "category": "before",
      "original_name": "before_cleaning.jpg",
      "size": 245120,
      "mime_type": "image/jpeg",
      "url": "http://localhost:8000/api/v1/images/01a1386b-dbd1-7002-8d82-631ef9f41e35/file?expires=1791948900&signature=abc123...",
      "uploaded_by": "01a13869-7c85-7075-8eb6-a367469a924b",
      "created_at": "2026-10-14T03:20:00.000000Z"
    }
  ],
  "task_id": "01a1386a-72ef-70aa-9a74-d2c610bca3f1",
  "notes": "พบรอยเปื้อนบนพรม"
}
```

---

### 2. ดูรายการรูปภาพของงานทำความสะอาด

- **Endpoint:** `GET /api/v1/dashboard/tasks/{id}/photos`
- **Auth:** `auth:sanctum`
- **Role:** `admin`, `housekeeping`
  - `housekeeping`: ดูได้เฉพาะงานที่ได้รับมอบหมาย (`assigned_to === auth.id`) หากเป็นงานของแม่บ้านคนอื่น จะได้ `403 Forbidden`
  - `admin`: ดูได้ทุกงาน
- **Headers:** `Accept: application/json`

#### Query Parameters

| Parameter | Type | Required | Description |
|---|---|---|---|
| `category` | String | No | กรองดูเฉพาะหมวด: `before`, `after`, `forgotten_item`, `damage` |

#### Response `200 OK`

```json
{
  "status": "success",
  "task_id": "01a1386a-72ef-70aa-9a74-d2c610bca3f1",
  "notes": "พบรอยเปื้อนบนพรม",
  "total_photos": 2,
  "counts": {
    "before": 1,
    "after": 1,
    "forgotten_item": 0,
    "damage": 0
  },
  "photos": [
    {
      "id": "01a1386b-dbd1-7002-8d82-631ef9f41e35",
      "task_id": "01a1386a-72ef-70aa-9a74-d2c610bca3f1",
      "category": "before",
      "original_name": "before_cleaning.jpg",
      "size": 245120,
      "mime_type": "image/jpeg",
      "url": "http://localhost:8000/api/v1/images/01a1386b-dbd1-7002-8d82-631ef9f41e35/file?expires=1791948900&signature=abc123...",
      "uploaded_by": "01a13869-7c85-7075-8eb6-a367469a924b",
      "created_at": "2026-10-14T03:20:00.000000Z"
    },
    {
      "id": "01a1386c-7f12-7003-9112-a1b2c3d4e5f6",
      "task_id": "01a1386a-72ef-70aa-9a74-d2c610bca3f1",
      "category": "after",
      "original_name": "after_cleaning.jpg",
      "size": 268400,
      "mime_type": "image/jpeg",
      "url": "http://localhost:8000/api/v1/images/01a1386c-7f12-7003-9112-a1b2c3d4e5f6/file?expires=1791948900&signature=def456...",
      "uploaded_by": "01a13869-7c85-7075-8eb6-a367469a924b",
      "created_at": "2026-10-14T03:35:00.000000Z"
    }
  ]
}
```

---

### 3. การแสดงผลรูปภาพและความปลอดภัย (Signed URL)

- URL ของรูปภาพ (`url`) เป็น Temporary Signed Route ของ `GET /api/v1/images/{id}/file` อายุ **15 นาที**
- ผู้ใช้ปลายทาง (Browser/App) สามารถใช้ `<img src="url">` ได้โดยตรงโดยไม่ต้องแนบ Sanctum Token (ลายเซ็นใน URL ทำหน้าที่ยืนยันสิทธิ์แทน)
- หากลายเซ็นหมดอายุหรือถูกแก้ไข ระบบจะปฏิเสธด้วย `403 Invalid signature`
- การลบงานทำความสะอาด (`HousekeepingTask::delete()`) จะลบแถวรูปภาพในตาราง `images` และลบไฟล์จริงออกจาก Storage โดยอัตโนมัติ

---

### 4. นโยบายการจัดเก็บและล้างข้อมูล (Retention Policy 90 วัน)

- คำสั่ง `php artisan app:cleanup-images` (ตั้งเวลาไว้ใน `routes/console.php` ทำงานทุกวันเวลา 02:30 น.) จะทำการ:
  1. ลบไฟล์กำพร้าในโฟลเดอร์ `housekeeping` บน local disk
  2. ลบแถว `images` ที่ผูกกับ `HousekeepingTask` ที่ถูกลบไปแล้ว
  3. ลบรูปภาพงานแม่บ้านที่มีอายุเกิน **90 วัน** (ปรับแต่งได้ผ่าน ENV: `HOUSEKEEPING_PHOTO_RETENTION_DAYS`)
