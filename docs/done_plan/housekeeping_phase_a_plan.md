# 🧹 FINAL PLAN — Housekeeping Refactor (Phase A)

> **Status:** 🟠 Draft — เก็บ decisions + scrutinize findings ไว้ review (ยังไม่ implement โค้ด)
> **Date:** 2026-07-14
> **Owner:** Nong Maid 💖
> **โมดูลที่เกี่ยวข้อง:** Housekeeping (60% → target 90%), Dashboard API, Room State Machine
> **เอกสารอ้างอิง:** `cline.md`, `house_keep_plan.md` (plan ต้นฉบับ), `app/Http/Controllers/Api/V1/DashboardController.php`, `app/Http/Controllers/Api/V1/FrontDeskController.php`, `app/Console/Commands/DailyRoomMaintenance.php`

---

## 🎯 Goal

เปลี่ยน housekeeping จาก "งานกองกลาง auto ตอน checkout" → **"ระบบจัดการงานเต็มรูปแบบ มีหลาย type, assign/accept ได้, dashboard อัปเดตผ่าน polling (Phase A) → WebSocket (Phase B)"**

โดยยึดตาม requirement ของนายท่าน:
- ลบ `housekeeping_inventories` → สร้าง `stock_inventories` ใหม่ (master stock ลอย)
- Dashboard มี task type หลายแบบ + state machine `unassigned → accepted → in_progress → done`
- Housekeeper accept งานเองได้ (`assigned_to` = user id)
- WebSocket สำหรับ realtime dashboard (เลื่อน Phase B)

---

## 📋 Decision Summary

| # | หัวข้อ | ตัดสินใจ | รายละเอียด |
|---|---|---|---|
| Sec | Security (S-B8 + S-B9) | **แก้ใน Phase A เลย** | ปิด impersonation ผ่าน `verified_by` (ใช้ `$request->user()`) + เปลี่ยน `$guarded=[]` → `$fillable` |
| D1 | WebSocket scope | **Phase A ก่อน + polling** | ทำ task refactor + role/routes + stock inventory ใช้ polling ชั่วคราว เลื่อน WebSocket ไป Phase B |
| D2 | `prep_checkin` trigger | **Daily wire สร้างให้** | `DailyRoomMaintenance` หา booking ที่ check_in พรุ่งนี้ → set room → `prep_checkin` → สร้าง task `pre_checkin` อัตโนมัติ |
| D3 | StockInventory model | **Master stock ลอย** | เก็บ stock รวมของโรงแจม (item_name, quantity, unit) ไม่ผูก task ใช้สำหรับนับลาย |

---

## 🔍 Scrutinize Findings (S-B1 → S-B14)

> ตามกรอบ `scrutinize` skill — ไล่ trace code path จริง ไม่ใช่ตามแผน
> สถานะ: 🔴 BLOCKER · 🟡 MAJOR · 🟢 LOW

### 🔴 BLOCKER (แก้ใน Phase A)

#### 🔴 S-B8 — `verified_by` จาก client = ช่องโหว่ impersonation + ขัด Fix L4 (NEW — plan ต้นฉบับพลาด)

**Finding:** `DashboardController::updateCleaningStatus()` อ่าน actor จาก `$validated['verified_by']` ที่ client ส่งมา แล้วใช้เป็นคนเซ็ต `transitionStatusTo('available', $validated['verified_by'])` — ไม่ได้เช็ค role ใน controller เลย

**Why it matters:**
- ใครก็ตามที่ผ่าน `role:admin` middleware สามารถส่ง `verified_by` เป็น UUID ของ **คนอื่น** ได้ → ระบบบันทึก audit trail ผิดคน (ปลอมตัวได้)
- ขัดกับ Fix L4 ที่ `checkOut/markNoShow/recordPayment` ทำไว้ (ใช้ `$request->user()` + role check) — housekeeping ไม่ได้ทำตาม
- พอ plan เปิดให้ housekeeper accept งานใน Phase A → ช่องโหว่นี้จะรุนแรงขึ้น เพราะ housekeeper จะสามารถปลอมว่า admin เป็นคน done งานได้

**Evidence:**
```php
// DashboardController.php:46-49
$validated = $request->validate([
    'status' => 'required|in:in_progress,done',
    'verified_by' => 'required|uuid|exists:users,id'   // ❌ client-controlled actor
]);
// DashboardController.php:80
$room->transitionStatusTo('available', $validated['verified_by']);  // ❌ trust client
```
เทียบกับ `FrontDeskController::checkOut()` (บรรทัด 232-238) ที่ทำถูก:
```php
$user = $request->user();
if (!$user || $user->role !== 'admin') { ... 403 }
```

**Suggested change:** ลบ `verified_by` ออกจาก validation ใช้ `$request->user()->id` เป็น actor เสมอ + เพิ่ม in-controller role check (admin หรือ housekeeping ที่เป็น assignee)

**สถานะ:** ✅ แก้ใน Phase A (Step 6)

---

#### 🔴 S-B9 — `HousekeepingTask` ใช้ `$guarded = []` = mass-assignment ทะลุ state machine (NEW — plan ต้นฉบับพลาด)

**Finding:** `HousekeepingTask.php:15` ใช้ `protected $guarded = []` ทำให้ทุก field รวม `status`, `assigned_to`, `completed_at`, `checked_out_at` ถูก mass-assign ได้โดยตรง

**Why it matters:** Plan §A2 จะเพิ่ม `task_type`, `status` (unassigned/accepted/in_progress/done), `assigned_to`, `accepted_at`, `scheduled_for` และวาง state machine + role gating ไว้คุม แต่ถ้ายัง `$guarded = []` อยู่ → client ส่ง `{"status":"done","assigned_to":"<uuid>"}` มาตรงๆ ใน createTask ก็ได้ → **state machine และ role gating ที่วางไว้ใช้ไม่ได้เลย** เพราะสามารถข้ามได้ผ่าน mass-assignment

**Evidence:**
```php
// HousekeepingTask.php:13-17
class HousekeepingTask extends Model
{
    use HasFactory, HasUuids;
    protected $guarded = [];          // ❌ ทุก field เปิดหมด
    public $incrementing = false;
    protected $keyType = 'string';
```
เทียบกับ `Room.php:22` ที่เปลี่ยนจาก `$guarded = []` → `$fillable` แล้ว (Fix #17)

**Suggested change:** เปลี่ยนเป็น `$fillable` และ list เฉพาะ field ที่อนุญาต (`room_id`, `task_type`, `notes`, `scheduled_for`) — ส่วน `status/assigned_to/accepted_at/completed_at` ต้องผ่าน dedicated method (accept/transition) เท่านั้น

**สถานะ:** ✅ แก้ใน Phase A (Step 3)

---

#### 🔴 S-B1 — `pre_checkin` trigger ไม่มีจุดเกิดจริง (จาก plan ต้นฉบับ — ยืนยันแล้ว)

**Finding:** หนู grep ทั้งโปรเจกต์แล้ว `prep_checkin` **ไม่เคยถูก set ที่ไหนเลย** — มีแค่ใน state machine declaration (`Room.php:81,83-85`) และ validation ที่อ่าน (`FrontDeskController.php:44`)

**Evidence:**
```
FrontDeskController.php:44   → แค่อ่าน (in_array check)
Room.php:62,65,81,83-85      → แค่ declare ใน $allowedTransitions
migration                    → แค่ comment
# ❌ ไม่มี transitionStatusTo('prep_checkin') ที่ไหนเลย
```

**Why it matters:** ถ้าทำ type `pre_checkin` โดยดักจับ room `prep_checkin` → rule นี้จะไม่ทำงานเลย เพราะไม่มีทางที่ห้องจะเข้าสถานะนี้ได้

**Suggested change:** นิยามว่า `prep_checkin` เกิดตอนไหน (ตาม D2 — DailyRoomMaintenance สร้างให้)

**สถานะ:** ✅ แก้ใน Phase A (Step 8) ตาม Decision D2

---

#### 🔴 S-B2 — `checkout` vs `checkout_then_in` overlap + duplicate task risk (จาก plan ต้นฉบับ — ยืนยันแล้ว)

**Finding:** `FrontDeskController::checkOut()` (บรรทัด 280-286) สร้าง `HousekeepingTask::create(...)` โดยไม่เช็คว่ามี task active อยู่แล้ว → double-submit หรือ retry จะสร้างซ้อน

**Evidence:**
```php
// FrontDeskController.php:280-286
$task = HousekeepingTask::create([
    'id' => Str::uuid(),
    'room_id' => $room->id,
    'status' => 'pending',         // ❌ ไม่เช็ค existing active task
    'notes' => $validated['notes'] ?? 'Auto-generated from Check-out',
    'checked_out_at' => Carbon::now()
]);
```
และ `DashboardController::updateCleaningStatus()` (บรรทัด 54-56) ใช้ `where('room_id', $roomId)->first()` → ถ้ามีหลาย task active ในห้องเดียวจะเลือกผิด

**Why it matters:** Dashboard สับสน + state machine room อาจพัง + plan อยากให้ใช้ `task_id` แทน `room_id` (§D) แต่ code ปัจจุบันยังใช้ `room_id`

**Suggested change:**
- ก่อน create → เช็ค `where('room_id', ...)->whereIn('status', ['unassigned','accepted','in_progress'])` ถ้ามีอยู่แล้ว อย่าสร้างซ้ำ หรือ upgrade type แทน
- เปลี่ยน `updateCleaningStatus` ให้ระบุ `task_id` แทน `room_id`

**สถานะ:** ✅ แก้ใน Phase A (Step 6, 7)

---

### 🟡 MAJOR

#### 🟡 S-B10 — Fix L3 guard เป็น dead code (NEW — plan ต้นฉบับเข้าใจผิด)

**Finding:** Plan §A2 บอกว่า "Guard เดิม (Fix L3) ที่ block `done → *` ยังใช้ได้" — **ไม่จริง** มันเป็น dead code ที่ไม่มีทางทำงาน

**Evidence:**
```php
// DashboardController.php:54-56 — query filter ออกตั้งแต่ต้น
$task = HousekeepingTask::where('room_id', $roomId)
    ->whereIn('status', ['pending', 'in_progress'])   // ← ดึงแค่ pending/in_progress
    ->first();

if (!$task) { throw ... }   // ← ถ้าไม่มี ก็ throw ไปแล้ว

// DashboardController.php:62-65 — guard นี้ไม่มีทางทำงาน เพราะ $task ไม่มีทางเป็น 'done'
if ($task->status === 'done') {
    throw new \Exception('งานทำความสะอาดนี้ทำเสร็จแล้ว...');
}
```

**Why it matters:** Plan อาศัย guard นี้ในการ "extend ให้รองรับ transition ใหม่" แต่มันไม่ได้ guard อะไรเลย → ต้องเขียนใหม่ให้เป็น state machine จริง ไม่ใช่ "ต่อยอดจากของเดิม"

**Suggested change:** ลบ dead code นี้ทิ้ง และเขียน state machine ใหม่ตาม §C (unassigned→accepted→in_progress→done) โดยใช้ `$task->transitionStatus()` ที่มี guard จริง เหมือน `Booking::transitionStatus()`

**สถานะ:** ✅ แก้ใน Phase A (Step 3, 6)

---

#### 🟡 S-B4 — Housekeeper route/role ยังไม่มี (จาก plan ต้นฉบับ — ยืนยันแล้ว)

**Finding:**
1. Routes `/dashboard/*` ทั้งหมดอยู่ใต้ `role:admin` (api.php:112-115) → housekeeper เข้าไม่ได้
2. `housekeeping` role user ไม่มีใน `UserSeeder.php` (มีแค่ admin)

**Evidence:**
```php
// routes/api.php:112-115
Route::prefix('dashboard')->group(function () {   // ← อยู่ใต้ role:admin ข้างนอก
    Route::get('/cleaning-tasks', ...);
    Route::put('/cleaning-tasks/{roomId}', ...);
});
```
```php
// UserSeeder.php — มีแค่ admin
\App\Models\User::create([
    'name' => 'Super Admin',
    'email' => 'admin@kuhome.com',
    'password' => 'password123',
    'role' => 'admin'
]);
```

**Why it matters:** Housekeeper ไม่สามารถ accept งานได้เพราะ middleware จะ block หมด + ไม่มี user สำหรับ login

**Suggested change:** แยก routes admin vs housekeeper (ตาม §D) + เพิ่ม housekeeping user ใน seeder (ตาม §A3) + เพิ่ม in-controller role check (defense-in-depth เหมือน S-B8)

**สถานะ:** ✅ แก้ใน Phase A (Step 9, 10, 11)

---

#### 🟡 S-B5 — WebSocket auth ผ่าน Sanctum ไม่ตรงกับ Echo default (จาก plan ต้นฉบับ — ยืนยันแล้ว)

**Finding:** Laravel Echo + Reverb private channel ใช้ `/broadcasting/auth` (web guard/session) แต่โปรเจกต์เป็น API-only + Sanctum

**Why it matters:** ถ้าใช้ private channel → housekeeper ที่ auth ด้วย Bearer token (Sanctum) จะ authorize ไม่ผ่าน (403)

**Suggested change:** ใช้ public channel `housekeeping` (ง่ายสุด) หรือ custom auth driver — แต่แนะนำเลื่อนไป Phase B หลัง polling พิสูจน์ว่าไม่พอ

**สถานะ:** 🕐 เลื่อน Phase B (ตาม Decision D1)

---

#### 🟡 S-B11 — `checkOut` ยัง create task ด้วย `'status' => 'pending'` (NEW)

**Finding:** Plan §A2 บอกจะ migrate status `pending` → `unassigned` แต่ `FrontDeskController::checkOut()` (บรรทัด 283) ยัง hardcoded `'status' => 'pending'` อยู่

**Evidence:**
```php
// FrontDeskController.php:280-286
$task = HousekeepingTask::create([
    'id' => Str::uuid(),
    'room_id' => $room->id,
    'status' => 'pending',                          // ❌ จะไม่ตรงกับ enum ใหม่
    'notes' => $validated['notes'] ?? 'Auto-generated from Check-out',
    'checked_out_at' => Carbon::now()
]);
```

**Why it matters:** หลัง migration เปลี่ยน default เป็น `unassigned` แล้ว ถ้าไม่แก้จุดนี้ → จะสร้าง task ด้วย status เก่าที่ไม่มีใน enum ใหม่ (หรือต้อง set `task_type='checkout'` ด้วย)

**Suggested change:** เปลี่ยนเป็น `'status' => 'unassigned', 'task_type' => 'checkout'` และไม่ต้องส่ง `status` เลย (ใช้ default จาก migration) + เพิ่ม `task_type`

**สถานะ:** ✅ แก้ใน Phase A (Step 7)

---

### 🟢 LOW

#### 🟢 S-B3 — ลบ `HousekeepingInventory` ต้องลบ `inventories()` + request classes ด้วย (จาก plan ต้นฉบับ — ยืนยัน + เสริม)

**Finding:** Plan ระบุถูกว่าต้องลบ `HousekeepingTask::inventories()` แต่พลาดที่ต้องลบ `StoreHousekeepingInventoryRequest` + `UpdateHousekeepingInventoryRequest` ด้วย

**Evidence:** grep เจอ 5 ไฟล์เกี่ยวข้อง — model, migration, 2 request classes, และ relationship ใน HousekeepingTask

**Suggested change:** Drop table + drop model + drop `inventories()` method + drop import + drop 2 request classes

**สถานะ:** ✅ แก้ใน Phase A (Step 1, 3, 5)

---

#### 🟢 S-B6 — `StockInventory` นิยามยังหลวม (จาก plan ต้นฉบับ — ยืนยัน ✅)

**Finding:** Plan บอก "new create → stock inv" แต่ไม่ได้บอกว่าเชื่อมกับ task อย่างไร

**Suggested change:** ใช้ master stock ลอยตาม Decision D3

**สถานะ:** ✅ แก้ใน Phase A (Step 2, 4) ตาม Decision D3

---

#### 🟢 S-B7 — Broadcast ต้องการ queue worker (จาก plan ต้นฉบับ — ยืนยัน ✅)

**Finding:** Events `ShouldBroadcast` จะถูก dispatch ไป queue — ปัจจุบัน queue driver = `database`

**Verification:** ✅ `composer run dev` มี `queue` อยู่แล้ว (ตาม cline.md) — ไม่มีปัญหา

**สถานะ:** 🕐 เลื่อน Phase B (เกี่ยวข้องกับ WebSocket)

---

#### 🟢 S-B12 — `DailyRoomMaintenance` mark dirty ไม่ส่ง actor → ไม่มี audit trail (NEW)

**Finding:** `DailyRoomMaintenance.php:102` เรียก `$room->transitionStatusTo('dirty')` โดยไม่ส่ง `$updatedByUserId`

**Evidence:**
```php
// DailyRoomMaintenance.php:102
$room->transitionStatusTo('dirty');   // ❌ ไม่ส่ง actor
```
```php
// Room.php:107-109
if ($updatedByUserId) {
    $this->status_updated_by = $updatedByUserId;   // ถ้า null จะไม่อัปเดต
}
```

**Why it matters:** `status_updated_by` จะเป็นค่าเดิม (หรือ null) → ไม่มี audit trail ว่าเป็น system ที่ mark dirty

**Suggested change:** ส่ง system user id (เพิ่ม system user ใน UserSeeder)

**สถานะ:** ✅ แก้ใน Phase A (Step 8, 9)

---

#### 🟢 S-B13 — `cleaningTasks` response field `pending_tasks` ชื่อไม่ตรงกับข้อมูล (NEW)

**Finding:** `DashboardController.php:21,38` query ดึงทั้ง `pending` และ `in_progress` แต่ field ชื่อ `pending_tasks`

**Evidence:**
```php
->whereIn('status', ['pending', 'in_progress'])   // ดึง 2 สถานะ
// ...
'pending_tasks' => $tasks->count(),               // ❌ ชื่อบอกแค่ pending
```

**Why it matters:** Frontend อาจเข้าใจผิดว่า count นี้คือแค่ pending → แสดงผลผิด

**Suggested change:** เปลี่ยนชื่อ field เป็น `active_tasks` หรือ `incomplete_tasks`

**สถานะ:** ✅ แก้ใน Phase A (Step 6)

---

#### 🟢 S-B14 — Dead code: `StoreHousekeepingTaskRequest` / `UpdateHousekeepingTaskRequest` ไม่ถูกใช้ (NEW)

**Finding:** Request classes พวกนี้ไม่ได้ถูก inject ใน route หรือ controller ไหนเลย → dead code ค้างอยู่ + ยัง validate status เป็น `pending|in_progress|done` (เก่า)

**Evidence:** grep ไม่เจอการใช้งานใน `routes/api.php` หรือ `DashboardController` — controller ใช้ inline `$request->validate()` แทน

**Why it matters:** ถ้า implement ใหม่ตาม plan แล้วไม่เก็บให้หมด จะสับสนว่า validation จริงอยู่ที่ไหน + มี 2 source of truth

**Suggested change:** ตอน implement ใหม่ ใช้ Form Request จริงจัง หรือลบ dead code ทิ้ง

**สถานะ:** ✅ แก้ใน Phase A (Step 5)

---

## 🚦 Task State Machine (Phase A — Final)

### **HousekeepingTask** (task lifecycle + role gating):

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

### Transitions & Role gating:

| Transition | ผู้ทำ | Endpoint |
|---|---|---|
| `unassigned → accepted` | housekeeper (role) | `POST /tasks/{id}/accept` |
| `accepted → in_progress → done` | assigned housekeeper | `PATCH /tasks/{id}/status` |
| `unassigned → accepted` (skip, admin direct assign) | admin | `PUT /tasks/{id}/assign` |
| `done → *` | ❌ ไม่ได้ (state machine lock) | — |

### Task Types & Auto-Generation Rules:

| Type | Trigger | สร้างโดย | Notes |
|---|---|---|---|
| **`checkout`** | `FrontDeskController::checkOut()` | auto | มีอยู่แล้ว — เปลี่ยนแค่เซ็ต `task_type` + `status=unassigned` |
| **`pre_checkin`** | `DailyRoomMaintenance` (D2) | auto | หา BR check_in พรุ่งนี้ → room `prep_checkin` → สร้าง task |
| **`checkout_then_in`** | checkout + มี checkin ใหม่ภายใน X ชม. | auto | ⚠️ ต้องกัน duplicate กับ `checkout` (S-B2) |
| **`daily`** | default = ❌ ไม่สร้าง → admin สร้างเมื่อลูกค้าขอ | manual admin | |
| **`monthly` / `group`** | admin สร้าง | manual admin | |

### Room Status Flow (เกี่ยวข้อง):

```
available ──> occupied ──> checkout_makeup ──> available (via housekeeping done)
    │              │
    │              └──> prep_checkin ──> available / dirty / occupied
    ├──> dirty ──> available / checkout_makeup
    ├──> maintenance ──> * (any status)
    └──> reserved_closed ──> * (any status)
```

Valid room statuses: `available`, `occupied`, `checkout_makeup`, `dirty`, `prep_checkin`, `maintenance`, `reserved_closed`

---

## 📐 Architecture Decisions (สรุปก่อนลงมือ — สำหรับ implement ในอนาคต)

1. **Task state machine** ย้ายไปอยู่ใน `HousekeepingTask` model เป็น `transitionStatus(string $new, string $actorRole)` — mirror `Booking::transitionStatus()` (มี role gating + guard)
2. **Actor มาจาก `$request->user()`** เสมอ ไม่รับจาก client อีก (ปิด S-B8 impersonation)
3. **`$guarded = []` → `$fillable`** ใน `HousekeepingTask` — ปิด S-B9 mass-assignment (status/assigned_to/accepted_at ไม่อยู่ใน fillable)
4. **Task identifier = `task_id`** ไม่ใช่ `room_id` (ปิด S-B2) — route เปลี่ยนจาก `/cleaning-tasks/{roomId}` → `/tasks/{taskId}/status`
5. **StockInventory = master stock ลอย** (D3) — ไม่ผูก task
6. **prep_checkin trigger** (D2) อยู่ใน `DailyRoomMaintenance` — หา BR check_in พรุ่งนี้ → set room `prep_checkin` → สร้าง task `pre_checkin`
7. **Status migrate** `pending → unassigned` ด้วย data-only migration (mirror `standardize_room_statuses_to_lowercase`) + model `$attributes` default (portable ข้าม SQLite(test)/PostgreSQL(prod) — ไม่ใช้ `->change()` เพราะไม่มี doctrine/dbal)
8. **Duplicate task guard** — ก่อน create เช็ค existing active task ในห้อง
9. **WebSocket = Phase B** (D1) — Phase A ใช้ polling, ไม่ติดตั้ง Reverb
10. **ไม่ใช้ FormRequest ใหม่** — mirror `AddonRateController` ที่ใช้ inline `$request->validate()` (ลบ dead `StoreHousekeepingTaskRequest`/`UpdateHousekeepingTaskRequest` + 2 inventory requests ด้วย — S-B3/S-B14)

---

## 📋 Phase A Scope — Implementation Outline (ยังไม่ implement — เก็บไว้ทำในอนาคต)

> ⚠️ ส่วนนี้เป็นเพียง outline สำหรับการ implement ในอนาคต ตอนนี้ยังไม่ได้ลงมือทำโค้ดใด ๆ

### Step 1 — Migration: drop `housekeeping_inventories` + เพิ่ม task columns (S-B3, S-B11, ปิด S-B9 schema)
**ไฟล์ใหม่:** `database/migrations/2026_07_14_000000_refactor_housekeeping_tasks.php`
- `Schema::dropIfExists('housekeeping_inventories')` (guard `Schema::hasTable`)
- `Schema::table('housekeeping_tasks')` เพิ่ม: `task_type`, `accepted_at`, `scheduled_for` + index `['room_id', 'status']`
- Data migration: `pending` → `unassigned`
- Default column บน PostgreSQL: raw `DB::statement` guard ด้วย `DB::getDriverName() === 'pgsql'`

### Step 2 — Migration: สร้าง `stock_inventories` (D3 master stock ลอย)
**ไฟล์ใหม่:** `database/migrations/2026_07_14_000001_create_stock_inventories_table.php`
- `item_name`, `quantity` (default 0), `unit`, `category`

### Step 3 — Model: `HousekeepingTask` refactor (S-B9, S-B10, S-B11)
**ไฟล์:** `app/Models/HousekeepingTask.php` (rewrite)
- `$guarded = []` → `$fillable = ['room_id','task_type','notes','scheduled_for']`
- `$attributes = ['status' => 'unassigned', 'task_type' => 'checkout']`
- `$casts` +เพิ่ม `accepted_at=>datetime, scheduled_for=>date`
- ลบ `inventories()` method + import
- เพิ่ม state machine `transitionStatus(string $new, string $actorRole): void`
- เพิ่ม helper `accept()` + `assign()` + scope `active()`

### Step 4 — Model: `StockInventory` ใหม่ (D3)
**ไฟล์ใหม่:** `app/Models/StockInventory.php`
- HasUuids, `$fillable`, `$casts=['quantity'=>'integer']`

### Step 5 — ลบ dead code (S-B3, S-B14)
- ลบ `app/Models/HousekeepingInventory.php`
- ลบ `app/Http/Requests/StoreHousekeepingTaskRequest.php`
- ลบ `app/Http/Requests/UpdateHousekeepingTaskRequest.php`
- ลบ `app/Http/Requests/StoreHousekeepingInventoryRequest.php`
- ลบ `app/Http/Requests/UpdateHousekeepingInventoryRequest.php`

### Step 6 — Controller: `DashboardController` refactor (S-B8, S-B10, S-B2, S-B13)
**ไฟล์:** `app/Http/Controllers/Api/V1/DashboardController.php` (rewrite)
- Methods: `listTasks()`, `unassignedTasks()`, `createTask()`, `assignTask()`, `acceptTask()`, `updateStatus()`
- ทุก method ใช้ `$request->user()` ไม่รับ `verified_by` จาก client + in-controller role check
- ลบ dead guard, เปลี่ยน `pending_tasks` → `active_tasks`
- Duplicate guard ใน `createTask()`

### Step 7 — Controller: `FrontDeskController::checkOut` แก้ task creation (S-B2, S-B11)
**ไฟล์:** `app/Http/Controllers/Api/V1/FrontDeskController.php` (edit)
- เปลี่ยน `'status' => 'pending'` → ไม่ส่ง (ใช้ default `unassigned`)
- เพิ่ม `'task_type' => 'checkout'`
- เพิ่ม duplicate guard

### Step 8 — Command: `DailyRoomMaintenance` + prep_checkin (S-B1, D2, S-B12)
**ไฟล์:** `app/Console/Commands/DailyRoomMaintenance.php` (edit)
- เพิ่ม section หา BR check_in พรุ่งนี้ → room `prep_checkin` → สร้าง task `pre_checkin`
- ใช้ `Carbon::today()->addDay()`
- ส่ง actor (system user id)
- แก้ `transitionStatusTo('dirty')` ให้ส่ง actor ด้วย

### Step 9 — Seeder: เพิ่ม housekeeping + system user (S-B4, A3)
**ไฟล์:** `database/seeders/UserSeeder.php` (edit)
- เพิ่ม `maid@kuhome.com` (role=housekeeping) + `system@kuhome.com` (role=system)

### Step 10 — Routes: แยก admin vs housekeeping (S-B4)
**ไฟล์:** `routes/api.php` (edit)
- Admin: `GET /tasks`, `POST /tasks`, `PUT /tasks/{taskId}/assign`
- Housekeeping+admin: `GET /tasks/unassigned`, `POST /tasks/{taskId}/accept`, `PATCH /tasks/{taskId}/status`
- ลบ route `/cleaning-tasks/{roomId}` เดิม

### Step 11 — Test helpers (S-B4)
**ไฟล์:** `tests/TestCase.php` (edit)
- เพิ่ม `actingAsHousekeeping()` + `createHousekeeping()`

### Step 12 — Tests (mirror BookingStateTest + RouteProtectionTest)
**ไฟล์ใหม่:**
- `tests/Unit/HousekeepingTaskStateTest.php` — transitions + role restrictions + done lock
- `tests/Feature/HousekeepingTaskTest.php` — createTask/acceptTask/assignTask/updateStatus + duplicate prevention
- `tests/Feature/StockInventoryTest.php` — admin CRUD + auth

### Step 13 — Docs (cline.md + api_guide.md)
- อัปเดต cline.md: state machine housekeeping, StockInventory, housekeeping role, daily prep_checkin
- อัปเดต api_guide.md: new endpoints + deprecate `/cleaning-tasks/{roomId}`

### Step 14 — Run tests + Pint
```bash
composer run test    # ต้องผ่านทั้งหมด (existing + new)
./vendor/bin/pint    # format
```

---

## 📡 Phase B Scope — WebSocket (เลื่อน — หลัง polling พิสูจน์ว่าไม่พอ)

> ⚠️ ส่วนนี้เลื่อนไปทำหลัง Phase A เสร็จและ polling พิสูจน์ว่าไม่เพียงพอ

### Stack: Laravel Reverb + Laravel Echo (Pusher protocol)

1. `composer require laravel/reverb laravel/echo`
2. `.env`: `BROADCAST_CONNECTION=reverb` + Reverb server keys
3. `composer run dev` เพิ่ม `php artisan reverb:start` (มี `queue:work` อยู่แล้ว ✅ — S-B7 ยืนยัน)
4. Events implement `ShouldBroadcast`

### Events & Channels

| Event | Channel | Trigger | ผู้รับ |
|---|---|---|---|
| `TaskCreated` | `housekeeping` | task ถูกสร้าง | admin + housekeeper dashboard |
| `TaskAccepted` | `housekeeping` | housekeeper accept | admin เห็นว่าใครรับแล้ว |
| `TaskStatusChanged` | `housekeeping` | status เปลี่ยน | dashboard refresh ทันที |
| `TaskCompleted` | `housekeeping` | done + room → available | admin เห็นห้องว่าง |

### Auth gap (S-B5)

Laravel Echo + Reverb **private channel** ใช้ `/broadcasting/auth` (web guard/session) แต่โปรเจกต์เป็น API-only + Sanctum → ต้องเลือก:
- **(แนะนำ)** ใช้ **public channel** `housekeeping` — ง่ายสุด ไม่ต้อง auth endpoint
- custom auth driver ที่อ่าน Bearer token จาก Sanctum (ซับซ้อน)
- token ฝังใน channel name `housekeeping.{user_id}.{token}` (hacky)

---

## ⚠️ Risks / Notes ที่ต้องระวัง (สำหรับตอน implement)

1. **Default column change** — ใช้ model `$attributes` + data migration ไม่ใช่ `->change()` (ไม่มี doctrine/dbal); raw `ALTER TABLE ... ALTER COLUMN` guard ด้วย driver name
2. **Schedule เที่ยงคืน** — `daily()` = 00:00 → prep_checkin สร้างตอนเริ่มวัน check-in จริง (อาจสายถ้าต้องการ buffer) — flag ให้นายท่านตัดสินใจภายหลัง
3. **Breaking change status enum** — `pending` → `unassigned` + `in_progress`/`done` ยังอยู่ + `accepted` ใหม่ — ต้องแจ้ง frontend
4. **Route breaking** — `/cleaning-tasks/{roomId}` → `/tasks/{taskId}/status` (frontend ต้อง update)
5. **SQLite test vs PostgreSQL prod** — raw `ALTER TABLE ... ALTER COLUMN` ใช้ไม่ได้บน SQLite → ใช้ model `$attributes` default เป็นหลัก, raw statement guard ด้วย `DB::getDriverName() === 'pgsql'`
6. **`assigned_to` มีอยู่แล้ว** — `housekeeping_tasks.assigned_to` (nullable FK → users) มีอยู่แล้วใน migration ต้นฉบับ ไม่ต้องเพิ่มใหม่
7. **`CheckRole` middleware ใช้ key `"error"` ไม่ใช่ `"status":"error"`** — inconsistency ที่มีอยู่แล้วในโค้ด ไม่แตะใน Phase A (controller ใช้ `"status":"error"` ตาม convention)
8. **FormRequest convention** — `authorize()` return `true`, auth อยู่ที่ route middleware (pattern ของโปรเจกต์)
9. **Tests ใช้ SQLite in-memory + RefreshDatabase** — ไม่มี factory สำหรับ HousekeepingTask (สร้าง inline ด้วย `Str::uuid()` เหมือน RoomStateTest)
10. **`actingAsHousekeeping()` ยังไม่มี** — ต้องเพิ่มใน `tests/TestCase.php` (จะเป็น multi-role test แรกของโปรเจกต์)

---

## ✅ Verify Checklist (สำหรับตอน implement ในอนาคต)

| # | Claim ของ plan | ตรวจสอบยังไง | สถานะปัจจุบัน |
|---|---|---|---|
| V1 | checkout auto-สร้าง task ตอน checkout | trace `FrontDeskController::checkOut()` ยังเรียก `HousekeepingTask::create` | ✅ มีอยู่แล้ว (ต้องแก้ status + task_type) |
| V2 | pre_checkin auto-สร้างได้จริง | ต้องสร้าง trigger ก่อน | ❌ (S-B1) |
| V3 | housekeeper accept งานได้ | ต้องเพิ่ม role + route + ปิด impersonation | ❌ (S-B4, S-B8) |
| V4 | dashboard realtime อัปเดต | ต้อง verify Reverb + worker + Echo client | ❌ (Phase B) |
| V5 | task state machine lock เมื่อ done | มี guard เดิม (Fix L3) | ❌ (S-B10 — guard เดิมเป็น dead code) |
| V6 | stock inventory ทำงานได้ | ตาม D3 (master stock ลอย) | ❌ (S-B6) |
| V7 | mass-assignment ปลอดภัย | `$guarded = []` ต้องเปลี่ยนเป็น `$fillable` | ❌ (S-B9) |

---

## ✅ Acceptance Criteria (สำหรับตอน implement ในอนาคต)

- [ ] `composer run test` ผ่านทั้งหมด (existing 153 + new ~25)
- [ ] ไม่มี `$guarded = []` ใน HousekeepingTask
- [ ] ไม่มี `verified_by` จาก client ใน DashboardController
- [ ] housekeeper (role) accept งานได้ผ่าน route `role:admin,housekeeping`
- [ ] duplicate task ในห้องเดียวกันถูก block
- [ ] `done` task เปลี่ยนสถานะไม่ได้ (state machine lock)
- [ ] DailyRoomMaintenance สร้าง prep_checkin + pre_checkin task สำหรับ check-in พรุ่งนี้
- [ ] `housekeeping_inventories` + dead request classes ถูกลบ
- [ ] `stock_inventories` + `StockInventory` model ใช้งานได้
- [ ] cline.md + api_guide.md อัปเดต

---

## 🎯 Verdict

**🔴 rework-before-ship** (สำหรับ Phase A — ต้องปิด blocker ก่อน)

**เหตุผลหลัก:** Plan ต้นฉบับมีโครงร่างดี แต่มี gap สำคัญ **5 ข้อที่ต้องปิดก่อน implement** (3 จาก plan ต้นฉบับ + 2 ที่หนูเจอใหม่):

1. **S-B8** `verified_by` จาก client = ช่องโหว่ impersonation (security — plan พลาด)
2. **S-B9** `$guarded = []` = mass-assignment ทะลุ state machine (security — plan พลาด)
3. **S-B1** `pre_checkin` trigger ไม่มีอยู่จริง → ต้องสร้าง trigger ก่อน
4. **S-B2** `checkout` vs `checkout_then_in` overlap → ต้องกำหนดเงื่อนไขให้ชัด + กัน duplicate
5. **S-B4** housekeeper route/role ยังไม่มี → ระบบ accept ใช้งานไม่ได้

**คำแนะนำ:** ทำ **Phase A ก่อน** (ปิด S-B8, S-B9 ด้วย — security) แล้วเลื่อน **websocket ไป Phase B** หลัง polling พิสูจน์ว่าไม่พอ

---

## 📚 เอกสารอ้างอิง

- `house_keep_plan.md` — plan ต้นฉบับ (ก่อน scrutinize)
- `cline.md` — project memory
- `docs/done_plan/plan_re_booking_final.md` — ตัวอย่างรูปแบบ plan file
- `app/Http/Controllers/Api/V1/DashboardController.php` — controller ที่จะ refactor
- `app/Http/Controllers/Api/V1/FrontDeskController.php` — checkOut ที่สร้าง task
- `app/Console/Commands/DailyRoomMaintenance.php` — command ที่จะเพิ่ม prep_checkin
- `app/Models/HousekeepingTask.php` — model ที่จะ refactor
- `app/Models/Room.php` — room state machine
- `app/Models/Booking.php` — reference state machine pattern (`transitionStatus()`)
- `app/Http/Middleware/CheckRole.php` — role middleware (variadic `...$roles`)
- `database/migrations/2026_03_25_025555_create_housekeeping_tasks_table.php` — schema ปัจจุบัน
- `database/migrations/2026_05_22_065759_standardize_room_statuses_to_lowercase.php` — pattern สำหรับ data migration
- `routes/api.php` — routes ปัจจุบัน
- `routes/console.php` — schedule (`->daily()`)
- `tests/TestCase.php` — test helpers (ต้องเพิ่ม `actingAsHousekeeping()`)
- `tests/Unit/BookingStateTest.php` — reference สำหรับ state machine test
- `tests/Feature/RouteProtectionTest.php` — reference สำหรับ role gating test
