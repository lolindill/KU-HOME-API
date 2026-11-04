# 🧹 Housekeeping Refactor Plan + Scrutinize

> **วันที่สร้าง:** 2026-07-14
> **วันที่ implement Phase A:** 2026-07-15 ✅
> **สถานะ:** 🟢 Phase A DONE — Phase B (WebSocket) เลื่อนจนกว่า polling พิสูจน์ว่าไม่พอ
> **Decisions ที่ตัดสินใจแล้ว:** D1=Phase A ก่อน (polling), D2=Daily wire สร้าง pre_checkin, D3=Master stock (ลอย)
> **โมดูลที่เกี่ยวข้อง:** Housekeeping (60% → **90%** ✅), Dashboard API, Room State Machine
> **เอกสารอ้างอิง:** `cline.md`, `app/Http/Controllers/Api/V1/DashboardController.php`, `app/Http/Controllers/Api/V1/FrontDeskController.php`, `app/Console/Commands/DailyRoomMaintenance.php`

---

## 🎯 Goal

> เปลี่ยน housekeeping จาก "งานกองกลาง auto ตอน checkout" → **"ระบบจัดการงานเต็มรูปแบบ มีหลาย type, assign/accept ได้, และ dashboard เรียลไทม์ผ่าน websocket"**

โดยยึดตาม requirement ของนายท่าน:
- ลบ `housekeeping_inventories` → สร้าง `stock_inventories` ใหม่
- Dashboard มี task type หลายแบบ + state machine `unassigned → accepted → done`
- Housekeeper accept งานเองได้ (`assigned_to` = user id)
- WebSocket สำหรับ realtime dashboard

---

## 📐 PART 1 — Implementation Plan

### A. Data Model Changes

#### A1. ลบ `HousekeepingInventory` → สร้าง `StockInventory`

**ลบ:**
- `database/migrations/2026_03_30_064919_create_housekeeping_inventories_table.php` (rollback)
- `app/Models/HousekeepingInventory.php`
- `HousekeepingTask::inventories()` relationship (Fix L2 ต้อง revert ส่วนนี้)

**สร้าง:** `stock_inventories` table + `StockInventory` model

> ⏳ **รอ Decision D3** — นิยาม StockInventory มี 3 ทางเลือก (master / consumption / ทั้งสอง)

#### A2. เพิ่ม fields ใน `HousekeepingTask`

```php
// migration: add_columns_to_housekeeping_tasks_table
'task_type'       // enum: pre_checkin | checkout | checkout_then_in | daily | monthly | group
'status'          // enum: unassigned | accepted | in_progress | done  (เดิม: pending|in_progress|done)
'assigned_to'     // UUID user (housekeeper) — nullable จนกว่าจะ accept/assign
'accepted_at'     // timestamp
'scheduled_for'   // date — สำหรับ monthly/group/daily
```

> ⚠️ **Breaking change สถานะเดิม:** task ที่เคยใช้ `pending` ต้อง migrate เป็น `unassigned`
> Guard เดิม (Fix L3) ที่ block `done → *` ยังใช้ได้ แต่ต้อง extend ให้รองรับ transition ใหม่

#### A3. Seeder — เพิ่ม `housekeeping` role user

```php
// UserSeeder.php
['name' => 'Nong Maid', 'email' => 'maid@kuhome.com', 'role' => 'housekeeping']
```

---

### B. Task Types & Auto-Generation Rules

| Type | Trigger | สร้างโดย | Notes |
|---|---|---|---|
| **`checkout`** | `FrontDeskController::checkOut()` | auto | มีอยู่แล้ว — เปลี่ยนแค่เซ็ต `task_type` |
| **`pre_checkin`** | ⏳ รอ Decision D2 | auto/manual | ⚠️ `prep_checkin` status ยังไม่มีจุดเกิดจริง (ดู S-B1) |
| **`checkout_then_in`** | checkout + มี checkin ใหม่ภายใน X ชม. | auto | ⚠️ ต้องกัน duplicate กับ `checkout` (ดู S-B2) |
| **`daily`** | default = ❌ ไม่สร้าง → admin สร้างเมื่อลูกค้าขอ | manual admin | |
| **`monthly` / `group`** | admin สร้าง | manual admin | |

---

### C. Task Lifecycle (State Machine)

```
                 ┌── admin create ──┐
                 ▼                  │
            [unassigned]            │
                 │                  │
   ┌─────────────┼─────────────┐    │
   ▼             ▼             ▼    │
housekeeper   admin assigns   (ลอยรอ)
 accepts      directly
   │             │
   ▼             ▼
[accepted] ──> [in_progress] ──> [done] 🔒 (lock)
```

**Transitions & Role gating:**
| Transition | ผู้ทำ | Endpoint |
|---|---|---|
| `unassigned → accepted` | housekeeper (role) | `POST /tasks/{id}/accept` |
| `accepted → in_progress → done` | assigned housekeeper | `PATCH /tasks/{id}/status` |
| `unassigned → assigned` (skip accepted) | admin | `PUT /tasks/{id}/assign` |
| `done → *` | ❌ ไม่ได้ (guard เดิม Fix L3) | — |

---

### D. API Endpoints (Dashboard refactor)

```php
// routes/api.php — แยก admin vs housekeeper

// Admin (role:admin)
Route::prefix('dashboard')->middleware('role:admin')->group(function () {
    Route::get ('/tasks',                    [DashboardController::class, 'listTasks']);
    Route::post('/tasks',                    [DashboardController::class, 'createTask']);      // manual: daily/monthly/group
    Route::put ('/tasks/{id}/assign',        [DashboardController::class, 'assignTask']);
});

// Housekeeper (role:housekeeping หรือ admin)
Route::prefix('dashboard')->middleware('role:admin,housekeeping')->group(function () {
    Route::get ('/tasks/unassigned',         [DashboardController::class, 'unassignedTasks']);
    Route::post('/tasks/{id}/accept',        [DashboardController::class, 'acceptTask']);
    Route::patch('/tasks/{id}/status',       [DashboardController::class, 'updateStatus']);
});

// 🗑️ Deprecate: PUT /cleaning-tasks/{roomId} → เปลี่ยนเป็น PATCH /tasks/{id}/status
//    (เพราะใช้ room_id ทำให้สับสนเมื่อมีหลาย task/ห้อง — ดู S-B2)
```

> ⚠️ ปัจจุบัน routes `/dashboard/*` ทั้งหมดอยู่ใต้ `role:admin` → housekeeper เข้าไม่ได้ (ดู S-B4)

---

### E. WebSocket (Realtime Dashboard) — ⏳ Phase B

> ⏳ **รอ Decision D1** — จะทำ Phase A ก่อน หรือควบ Phase A + B

#### Stack: Laravel Reverb + Laravel Echo (Pusher protocol)

1. `composer require laravel/reverb laravel/echo`
2. `.env`: `BROADCAST_CONNECTION=reverb` + Reverb server keys
3. `composer run dev` เพิ่ม `php artisan reverb:start` (มี `queue:work` อยู่แล้ว ✅)
4. Events implement `ShouldBroadcast`

#### Events & Channels

| Event | Channel | Trigger | ผู้รับ |
|---|---|---|---|
| `TaskCreated` | `housekeeping` | task ถูกสร้าง | admin + housekeeper dashboard |
| `TaskAccepted` | `housekeeping` | housekeeper accept | admin เห็นว่าใครรับแล้ว |
| `TaskStatusChanged` | `housekeeping` | status เปลี่ยน | dashboard refresh ทันที |
| `TaskCompleted` | `housekeeping` | done + room → available | admin เห็นห้องว่าง |

#### Auth gap (S-B5) ⚠️

Laravel Echo + Reverb **private channel** ใช้ `/broadcasting/auth` (web guard/session) แต่โปรเจกต์เป็น API-only + Sanctum → ต้องเลือกทางใดทางหนึ่ง:
- **(แนะนำ)** ใช้ **public channel** `housekeeping` — ง่ายสุด ไม่ต้อง auth endpoint
- custom auth driver ที่อ่าน Bearer token จาก Sanctum (ซับซ้อน)
- token ฝังใน channel name `housekeeping.{user_id}.{token}` (hacky)

---

## 🔍 PART 2 — Scrutinize Findings

> ตามกรอบ `scrutinize` skill — ไล่ trace code path จริง ไม่ใช่ตามแผน

### 🔴 S-B1 — `pre_checkin` trigger ไม่มีจุดเกิดจริง (BLOCKER)

**Finding:** Plan บอกว่า type `pre_checkin` → "check if room is in prep_checkin status on daily wire" แต่หนู grep ทั้งโปรเจกต์แล้ว **`prep_checkin` status ไม่เคยถูก set ที่ไหนเลย**

**Evidence:**
```bash
$ grep -rn "prep_checkin" app/ database/ routes/
→ FrontDeskController.php:44   # แค่อ่าน (validate available|prep_checkin)
→ Room.php:62,65,81,83-85      # แค่ declare ใน state machine
→ migration                     # แค่ comment เก่า
# ❌ ไม่มี transitionStatusTo('prep_checkin') ที่ไหนเลย
```

**Why it matters:** ถ้าทำ type `pre_checkin` โดยดักจับ `prep_checkin` room → rule นี้จะ **ไม่ทำงานเลย**

**Suggested change:** ต้องนิยามก่อนว่า `prep_checkin` เกิดตอนไหน (ดู Decision D2)

---

### 🔴 S-B2 — `checkout` vs `checkout_then_in` overlap + duplicate task risk (BLOCKER)

**Finding:** Type ทั้งสอง trigger ตอน checkout แต่เงื่อนไข "then in" ไม่ชัด และทั้งคู่อาจสร้าง 2 task ซ้อนในห้องเดียว

**Why it matters:**
- `Room::transitionStatusTo()` ปัจจุบันใช้ `first()` (DashboardController:54) — ถ้ามีหลาย task active ในห้องเดียวจะเลือกผิด
- Dashboard สับสน + state machine room อาจพัง

**Suggested change:**
- นิยาม `checkout_then_in` = "checkout ที่มี checkin ใหม่ภายใน X ชม."
- ก่อนสร้าง task → เช็ค `where room_id, status in [unassigned,accepted,in_progress]` — ถ้ามี อย่าสร้างซ้ำ หรือ upgrade type แทน
- เปลี่ยน `updateCleaningStatus` ให้ระบุ `task_id` แทน `room_id`

---

### 🟡 S-B3 — ลบ `HousekeepingInventory` ต้องลบ `inventories()` relationship ด้วย

**Finding:** ลบ table ปลอดภัย (leaf table, cascade delete) แต่ `HousekeepingTask::inventories()` method (HousekeepingTask.php:41) ยังคงอยู่ → เรียกแล้ว error

**Suggested change:** Drop table + drop `inventories()` method + drop import

---

### 🟡 S-B4 — Housekeeper route/role ยังไม่มี (MAJOR)

**Finding:** Plan บอก housekeeper accept งาน แต่:
1. Routes `/dashboard/*` ทั้งหมดอยู่ใต้ `role:admin` (api.php:111)
2. `housekeeping` role user ไม่มีใน UserSeeder

**Why it matters:** Housekeeper ไม่สามารถ accept งานได้ เพราะ middleware จะ block หมด

**Suggested change:**
- แยก routes admin vs housekeeper (ดู §D)
- เพิ่ม housekeeping user ใน seeder (ดู §A3)
- เพิ่ม in-controller role check (เหมือน Fix L4 ใน checkOut) — defense-in-depth

---

### 🟡 S-B5 — WebSocket auth ผ่าน Sanctum ไม่ตรงกับ Echo default (MAJOR)

**Finding:** Plan บอก auth channel ผ่าน Sanctum แต่ Laravel Echo + Reverb private channel ต้องการ `/broadcasting/auth` ซึ่ง default ใช้ web guard (session)

**Why it matters:** ถ้าใช้ private channel → housekeeper ที่ auth ด้วย Bearer token (Sanctum) จะ authorize ไม่ผ่าน (403)

**Suggested change:** ดู §E Auth gap

---

### 🟢 S-B6 — `StockInventory` นิยามยังหลวม

**Finding:** Plan บอก "new create → stock inv" แต่ไม่ได้บอกว่าเชื่อมกับ task อย่างไร

**Suggested change:** ดู Decision D3

---

### 🟢 S-B7 — Broadcast ต้องการ queue worker รัน

**Finding:** Events `ShouldBroadcast` จะถูก dispatch ไป queue — ปัจจุบัน queue driver = `database`

**Verification:** ✅ `composer run dev` มี `queue` อยู่แล้ว (ตาม cline.md) — ไม่มีปัญหา
**Fallback:** ใช้ `ShouldBroadcastNow` สำหรับ dev ถ้าไม่อยาก start worker

---

## ✅ Verify Checklist (สำหรับตอน implement)

| # | Claim ของ plan | ตรวจสอบยังไง | สถานะ |
|---|---|---|---|
| V1 | checkout auto-สร้าง task ตอน checkout | trace `FrontDeskController::checkOut()` ยังเรียก `HousekeepingTask::create` | ✅ มีอยู่แล้ว + เพิ่ม task_type |
| V2 | pre_checkin auto-สร้างได้จริง | `DailyRoomMaintenance` Phase 2 สร้าง task + set room prep_checkin | ✅ DONE (test `test_daily_maintenance_creates_pre_checkin_task`) |
| V3 | housekeeper accept งานได้ | เพิ่ม role + route + seeder + in-controller check | ✅ DONE (test `test_housekeeper_can_accept_task_via_api`) |
| V4 | dashboard realtime อัปเดต | ต้อง verify Reverb + worker + Echo client | ❌ Phase B (เลื่อน) |
| V5 | task state machine lock เมื่อ done | `HousekeepingTask::transitionStatus()` throw ถ้า done→* | ✅ DONE (เขียนใหม่ทำงานจริง — test `test_done_is_terminal_cannot_transition_back`) |
| V6 | stock inventory ทำงานได้ | `StockInventory` model + migration | ✅ DONE (D3 master stock) |

---

## 🎯 Verdict

**🟠 fix-then-ship** (สำหรับ Phase A — task refactor)

**เหตุผลหลัก:** Plan มีโครงร่างดีแต่มี gap สำคัญ 3 ข้อที่ต้องปิดก่อน implement:
1. **S-B1** `pre_checkin` trigger ไม่มีอยู่จริง → ต้องสร้าง trigger ก่อน
2. **S-B2** `checkout` vs `checkout_then_in` overlap → ต้องกำหนดเงื่อนไขให้ชัด + กัน duplicate task
3. **S-B4** housekeeper route/role ยังไม่มี → ระบบ accept ใช้งานไม่ได้

**คำแนะนำ:** ทำ **Phase A ก่อน** (task types + state machine + assign + role/routes + stock inventory) แล้วเลื่อน **websocket ไป Phase B** หลัง polling พิสูจน์ว่าไม่พอ เพราะ websocket บน API-only + Sanctum มี complexity/auth gap (S-B5) ที่คุ้มที่สุดตอนนี้คือ polling

---

## ❓ Decisions (รอนายท่านตัดสินใจ)

### D1 — WebSocket scope
- [ ] **(แนะนำ)** Phase A ก่อน — task refactor + role/routes + stock inventory ใช้ polling ชั่วคราว เลื่อน websocket ไป Phase B
- [ ] ทำควบ Phase A + B — ทำทั้ง task refactor และ websocket (Reverb + Echo) ในรอบเดียว ต้องแก้ S-B5 ด้วย
- [ ] เฉพาะ WebSocket — ข้าม task refactor ทำแค่ realtime dashboard บน structure เดิม

### D2 — `prep_checkin` trigger
- [ ] **(แนะนำ)** Daily wire สร้างให้ — `DailyRoomMaintenance` หา booking ที่ check_in พรุ่งนี้ → set room → `prep_checkin` → สร้าง task `pre_checkin` อัตโนมัติ
- [ ] Admin set ด้วยมือ — admin เป็นคน set room → `prep_checkin` เอง แล้วถึงสร้าง task
- [ ] ยกเลิก type `pre_checkin` — รวมเข้ากับ daily/checkout

### D3 — StockInventory model
- [ ] **(แนะนำ)** Master stock (ลอย) — เก็บ stock รวมของโรงแจม (item_name, quantity, unit) ไม่ผูก task ใช้สำหรับนับลาย
- [ ] Consumption log (ผูก task) — เก็บการใช้ของต่อ task (task_id, item_name, consumed_quantity) เพื่อตามรอยการเบิก
- [ ] ทั้งสองแบบ — master stock + consumption log แยกกัน (เหมือนระบบ inventory จริง)

---

## 📋 Phase Plan (หลังตัดสินใจ)

### Phase A — Task Refactor (เดือนนี้)
1. Migration: drop `housekeeping_inventories` + add columns to `housekeeping_tasks`
2. Model: drop `HousekeepingInventory` + create `StockInventory` (ตาม D3) + update `HousekeepingTask` (state machine + fillable)
3. Seeder: เพิ่ม housekeeping user
4. Controller: refactor `DashboardController` (createTask/acceptTask/assignTask/updateStatus)
5. Routes: แยก admin vs housekeeper (ตาม S-B4)
6. Trigger: `prep_checkin` (ตาม D2) + guard duplicate task (ตาม S-B2)
7. Tests: state machine + role gating + duplicate prevention

### Phase B — WebSocket (ภายหลัง — หลัง polling พิสูจน์ว่าไม่พอ)
1. `composer require laravel/reverb laravel/echo`
2. `.env` + `composer run dev` config
3. Events: `TaskCreated/TaskAccepted/TaskStatusChanged/TaskCompleted` implement `ShouldBroadcast`
4. Channel auth (ตาม S-B5)
5. Frontend Echo client subscribe `housekeeping` channel
6. Tests: broadcast assertions
