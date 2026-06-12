# 🚀 Plan: ย้าย Database จาก Supabase ไปเซิร์ฟเวอร์บริษัท

## สรุป
ย้าย PostgreSQL database จาก Supabase (cloud) ไปยังเซิร์ฟเวอร์ของบริษัท (on-premise)

---

## 🔍 สิ่งที่ตรวจสอบแล้ว

### Supabase ถูกใช้แค่ไหน?
- Supabase ทำหน้าที่เป็น **PostgreSQL โฮสต์เท่านั้น**
- ❌ ไม่มี Supabase Auth
- ❌ ไม่มี Supabase Storage
- ❌ ไม่มี Supabase Realtime
- ❌ ไม่มี Supabase SDK (ไม่มี `supabase-php` package)
- ✅ Connection ผ่าน Laravel `pgsql` driver มาตรฐาน

### Auth ใช้อะไร?
- **Laravel Sanctum 4** (token-based) — ไม่ใช่ Supabase Token
- Token ถูกสร้างด้วย `$user->createToken('ku_home_auth_token')->plainTextToken`
- เก็บในตาราง `personal_access_tokens` ใน database ของเราเอง
- เมื่อย้าย DB, tokens จะย้ายตามไปด้วยใน `pg_dump`

### การเชื่อมต่อปัจจุบัน (จาก `.env`)
```env
DB_CONNECTION=pgsql
DB_HOST=aws-1-ap-northeast-2.pooler.supabase.com
DB_PORT=6543
```

---

## 📋 Migration Steps

### Step 1: เตรียม PostgreSQL บนเซิร์ฟเวอร์บริษัท
- [ ] ติดตั้ง PostgreSQL บนเซิร์ฟเวอร์ (แนะนำเวอร์ชัน 16+)
- [ ] สร้าง database: `CREATE DATABASE kuhome;`
- [ ] สร้าง user: `CREATE USER kuhome_user WITH PASSWORD '<password>';`
- [ ] ให้สิทธิ์: `GRANT ALL PRIVILEGES ON DATABASE kuhome TO kuhome_user;`
- [ ] ตรวจสอบว่าเซิร์ฟเวอร์อนุญาต connection จาก app server (firewall/pg_hba.conf)

### Step 2: ย้ายข้อมูลจาก Supabase
```bash
# Dump ข้อมูลจาก Supabase
pg_dump "postgresql://postgres:[PASSWORD]@db.vpdaetslnmupapuveuct.supabase.co:5432/postgres" \
  --no-owner --no-acl \
  -f kuhome_backup.sql

# Restore ไปเซิร์ฟเวอร์บริษัท
psql "postgresql://kuhome_user:[PASSWORD]@[SERVER_IP]:5432/kuhome" \
  < kuhome_backup.sql
```

> **หมายเหตุ**: ใช้ direct connection (`db.xxx.supabase.co:5432`) สำหรับ pg_dump ไม่ใช่ pooler

### Step 3: เปลี่ยน `.env`
```env
# เปลี่ยนจาก
DB_HOST=aws-1-ap-northeast-2.pooler.supabase.com
DB_PORT=6543

# เป็น
DB_HOST=<server-IP-หรือ-domain>
DB_PORT=5432
DB_DATABASE=kuhome
DB_USERNAME=kuhome_user
DB_PASSWORD=<password>
```

### Step 4: อัปเดต `.env.example` (optional แต่แนะนำ)
```env
DB_CONNECTION=pgsql
DB_HOST=127.0.0.1
DB_PORT=5432
DB_DATABASE=kuhome
DB_USERNAME=root
DB_PASSWORD=
```

### Step 5: รัน Migration & ตรวจสอบ
```bash
php artisan migrate          # ตรวจสอบว่า schema ตรง
php artisan config:clear     # เคลียร์ cache
composer run test            # รัน test suite
```

### Step 6: ทดสอบ API endpoints สำคัญ
- [ ] `POST /api/v1/login` — Login ได้ปกติ
- [ ] `GET /api/v1/rooms` — ดึงข้อมูลห้องได้
- [ ] `POST /api/v1/bookings` — สร้าง booking ได้
- [ ] `POST /api/v1/payments/webhook` — Payment webhook ทำงาน

---

## ✅ สิ่งที่ **ไม่ต้องแก้**

| ไฟล์/ส่วน | เหตุผล |
|---|---|
| `config/database.php` | ใช้ `pgsql` driver อยู่แล้ว |
| `app/Http/Controllers/Api/V1/AuthController.php` | Sanctum token ไม่ผูกกับ Supabase |
| Models (User, Booking, Room, etc.) | Eloquent ORM ทำงานกับ PostgreSQL ตัวไหนก็ได้ |
| Controllers | ไม่มี Supabase-specific code |
| Migrations | รันบน PostgreSQL ใหม่ได้เลย |
| `composer.json` | ไม่มี `supabase-php` dependency |
| Routes (`routes/api.php`) | ไม่มีการเปลี่ยนแปลง |

---

## ⚠️ ข้อควรระวัง

### Boolean vs Integer Issues (มีอยู่แล้วในปัจจุบัน)
จาก log พบปัญหา PostgreSQL strict type checking หลายจุด:
- `is_ku_member` (bookings, users) — boolean column แต่ส่งค่า integer
- `extra_bed_enabled` (room_types) — boolean column แต่ส่งค่า integer
- `ver` (users) — boolean column แต่ส่งค่า integer
- `is_active` (addons) — `where("is_active" = 1)` ควรเป็น `true`

> ปัญหาเหล่านี้ **จะยังอยู่** เมื่อย้ายไปเซิร์ฟเวอร์ใหม่ (เพราะเป็น PostgreSQL เหมือนกัน) แต่ไม่เกี่ยวกับการย้าย DB — เป็น bug ที่ควรแก้แยกต่างหาก

### Connection String
- Supabase ใช้ pooler port `6543` — เซิร์ฟเวอร์บริษัทจะใช้ standard port `5432`
- ถ้ามี `DB_URL` ใน `.env` ต้องเปลี่ยนด้วย

### UUID Extension
- ตรวจสอบว่า `uuid-ossp` หรือ `pgcrypto` extension ถูก enable บนเซิร์ฟเวอร์ใหม่
- `CREATE EXTENSION IF NOT EXISTS "uuid-ossp";`

---

## 📊 ระยะเวลาที่คาดการณ์
| ขั้นตอน | เวลา |
|---|---|
| เตรียมเซิร์ฟเวอร์ | 30-60 นาที (depends on infra) |
| pg_dump + restore | 10-30 นาที (depends on data size) |
| เปลี่ยน .env + ทดสอบ | 15 นาที |
| **รวม** | **~1-2 ชั่วโมง** |

---

## 🎯 สรุป
การย้าย DB จาก Supabase ไปเซิร์ฟเวอร์บริษัท **ง่ายมาก** เพราะ:
1. ใช้แค่ standard PostgreSQL connection
2. Auth เป็น Laravel Sanctum (ไม่ใช่ Supabase Auth)
3. ไม่มี Supabase-specific SDK หรือ features
4. แก้ไขไฟล์โค้ด = **0 ไฟล์** — เปลี่ยนแค่ `.env`