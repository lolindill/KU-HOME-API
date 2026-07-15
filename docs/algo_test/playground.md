# 🎮 Playground — AI Agent Guide

> **ไฟล์เดียวที่ต้องรู้:** `docs/room-algorithm-playground.html` (HTML+CSS+JS แบบ single-file, รันในเบราว์เซอร์)
> **Test harness:** `docs/test-matrix.cjs` (compose model: seed × case, node vm sandbox, ดึง script จาก playground มารัน offline)
> **วันที่สร้างไฟล์นี้:** 2026-07-06
> **วัตถุประสงค์:** ให้ AI agent เข้าใจ playground ได้ทันทีโดยไม่ต้องสำรวจทั้งโปรเจกต์

---

## 🚧 Boundary — Playground แยกจาก Backend Laravel

> **⚠️ กฎเหล็กสำหรับทุก session:** เมื่อทำงานกับ playground (`docs/room-algorithm-playground.html`, `docs/test-matrix.cjs`, `docs/playground.md`) **ห้ามอ้างอิง / คุย / เปรียบเทียบกับส่วน Laravel backend** ของ project

- Playground = **self-contained JS** (single-file HTML + vm sandbox) — state/algorithm/cost/metrics อยู่ในไฟล์เดียวจบ
- Backend Laravel (`app/`, `database/`, `BookingRoom` model ฯลฯ) = อีกโลกนึง ไม่เกี่ยวกับผล playground
- คำว่า "seed" ใน playground = `type:'seed'` reservation ฝังใน `room.reservations` (pre-booked state) — **ไม่ใช่** Laravel database seeder
- หากนายท่านถามเรื่อง backend → ออกจาก context playground แล้วค่อยสำรวจ `app/`

---

## 📖 Glossary — คำศัพท์ที่ใช้คุยกันใน session นี้

> **⚠️ สำคัญมาก:** เมื่อนายท่านใช้คำเหล่านี้ ให้ตีความตามนิยามด้านล่าง **ไม่ใช่** ตามความหมายทั่วไป

| คำ | ความหมายใน context นี้ | ตัวอย่าง |
|----|----------------------|----------|
| **seed** | **pre-booked room state** — reservation ที่ฝังไว้ใน `room.reservations` **ก่อน** รัน booking sequence/case — เป็น `type:'seed'`, จำลอง "มีคนจองแล้ว" ให้ algorithm ต้องหลีก | R2: seed = จอง 508 ไว้ก่อน → algorithm ต้องหลีก |
| **case** | **booking หนึ่งรายการ** = `state.currentBooking` (checkIn/checkOut + rooms[]) ที่กำลังจะประมวลผล | "case R4" = booking Deluxe×4 |
| **sequence** | **list ของ bookings** = `state.bookingQueue` — หลาย case ที่ประมวลผลทีละอัน (online/sequential) | O1 = sequence 3 bookings ไม่ overlap |

### 🔑 ความสัมพันธ์
```
seed (pre-booked state)
  │   ฝังก่อนเป็นพื้นฐาน → จำลอง "มีคนจองแล้ว" ให้ทุกห้องว่าง/ไม่ว่าง
  ▼
sequence (list of cases)
  │   ┌─────────────────────────────────────────┐
  ├──►│ case 0  (booking: checkIn + rooms[])    │
  ├──►│ case 1  (booking: checkIn + rooms[])    │  ◄── processQueue()
  └──►│ case 2  (booking: checkIn + rooms[])    │       ประมวลผลทีละ case
      └─────────────────────────────────────────┘
                     │
                     ▼
              algorithm รัน ──→ result (assignments + cost)
```

> 💡 **ลำดับการเกิด:** `seed` ฝังก่อน (เป็นพื้น) → แล้ว `sequence` = list ของ `case` แต่ละ booking → algorithm รันทีละ case บนพื้น seed

### 💡 ตัวอย่างการใช้คำ
- "เพิ่ม seed 50 ห้อง" = ฝัง pre-booked reservation 50 ห้องก่อนรัน sequence
- "case นี้โอเคไหม" = booking หนึ่งรายการใน sequence ผลเป็นยังไง
- "sequence นี้ชนกัน" = list ของ cases ใน `bookingQueue` overlap กัน

---

## ⚡ TL;DR — ทำความเข้าใจใน 30 วินาที

Playground นี้คือ **interactive tool** สำหรับทดสอบ/เปรียบเทียบ **5 + 4 algorithm** จัดห้องโรงแรม (KU HOME 100 ห้อง) บน input เดียวกัน มี floor map + step-by-step visualization + metrics

- **งานปกติ:** เพิ่ม preset, แก้ algorithm, วัด metrics
- **งานบ่อย:** implement algorithm stub F/G/H/I ที่ยัง delegate → E
- **กฎเหล็ก:** ทุกอย่างอยู่ใน HTML ไฟล์เดียว — แก้ที่เดียวจบ

---

## 🏨 Domain Model — KU HOME Topology

### ห้อง 100 ห้อง, 5 ชั้น (5,6,7,8,9) × 20 ห้อง/ชั้น

```
ฝั่ง V1 (วิว 1 — 12 ห้อง):
  X07(Suite,pos1) X08-17(Deluxe,pos2-11) X18(Suite,pos12)
  ⚠️ X09 = Deluxe 3 เตียง builtin (pos3, beds=3) — ห้องเดียวที่ beds ≠ 1

ฝั่ง V2A (วิว 2A — Superior 6 ห้อง, pos ตามลำดับเดินจริง):
  X06(p1) X05(p2) X04(p3) X03(p4) X02(p5) X01(p6)

ฝั่ง V2B (วิว 2B — Superior 2 ห้อง):
  X20(p1) X19(p2)

พิเศษ: ชั้น 8 ทุกห้อง bed_type='twin' (เตียงคู่) | ชั้นอื่น = 'double'
Layout ฝั่ง V2: [🪜บันได] [X06..X01] [🛗ลิฟต์×2] [X20 X19] [🪜บันได]
```

**X = เลขชั้น** เช่น `508` = ชั้น 5 pos 2 (Deluxe), `809` = ชั้น 8 pos 3 (Deluxe 3 เตียง twin)

### Room object shape (จาก `buildRooms()` บรรทัด 596)
```js
{
  num: '508',              // string 3 หลัก "ชั้น+pos"
  type: 'Deluxe',          // 'Suite' | 'Deluxe' | 'Superior'
  side: 'V1',              // 'V1' | 'V2A' | 'V2B'
  pos: 2,                  // ตำแหน่งตามแนวเดิน (1-12 สำหรับ V1, 1-6 V2A, 1-2 V2B)
  beds: 1,                 // จำนวนเตียง (1 ทุกห้อง ยกเว้น X09 = 3)
  floor: 5,                // parseInt(num[0])
  bed_type: 'double',      // 'twin' (ชั้น 8) | 'double' (ชั้นอื่น)
  reservations: [],        // array ของ { checkIn, checkOut, label, type }
  assigned: false,         // UI state: โดน algorithm เลือก
  isSeed: false,           // UI state: เป็น seed ของ algorithm
  state: null,             // UI state: 'considering'|'accepted'|'rejected'
}
```

---

## 📋 Data Shapes

### Booking room (input ที่ผู้ใช้เพิ่ม)
```js
{ type: 'Deluxe', beds: 0, bed_preference: 'any' }
// beds: 0 = ไม่ต้องการเตียงเสริม, >0 = ต้องการเตียงเสริม (trigger X09 priority)
// bed_preference: 'any' | 'twin' | 'double'
```

### Reservation
```js
{ checkIn: '2026-07-07', checkOut: '2026-07-09', label: 'Existing', type: 'seed' }
// type: 'seed' (preset seed) | 'queue' (จาก processQueue)
```

### Algorithm result (ทุก algorithm คืน shape เดียวกัน)
```js
{
  algo: 'Hybrid (C+D)',          // display name
  steps: [...],                  // step explorer log (ดู step shape ด้านล่าง)
  assignments: [room|null, ...], // index = booking slot, length = booking.length
  seed: '508' | null,            // ห้อง seed (string|null)
  ok: true | false,              // สำเร็จไหม (assignments เต็มทุก slot)
  cost: 19.0 | Infinity,         // costFunction รวม (Infinity ถ้า !ok)
  fallback: false,               // [เฉพาะ F/G/H/I stub] = true ถ้า delegate ไป E
  bookingDates: { checkIn, checkOut }, // [FIX Bug #6] sync evalDates กับ result
  time: 1.23,                    // ms (set โดย caller, ไม่ใช่ algorithm)
  algoKey: 'E',                  // set โดย caller
}
```

### Step (สำหรับ step explorer)
```js
{ type: 'info'|'consider'|'accept'|'reject'|'done', room: roomObj|null, thought: string, cost: number, assignedRooms: [roomNums] }
// assignedRooms = snapshot ของ room numbers ที่ assigned ณ ขั้นนั้น
```

---

## 🎯 Cost Function v2 (`costFunction` บรรทัด 1334)

```
TOTAL = floorSpread + sideMismatch + posSpread + bedWaste + bedPrefPenalty
```

| Component | Formula | Weight | Note |
|-----------|---------|--------|------|
| `floorSpread` | `(distinct floor − 1) × W` | **W_floor=50** | กระจายหลายชั้น = แพงมาก |
| `sideMismatch` | `(distinct normSide − 1) × W` | **W_side=8** | V2A/V2B รวมเป็น "V2" เดียว |
| `posSpread` | `Σ(max−min pos per raw side) × W` | **W_pos=3** | raw side = V1/V2A/V2B แยก |
| `bedWaste` | `Σ max(0, beds−1−requested) × W` | **W_bed=5** | X09 (3 เตียง) ให้ booking 0 เตียง = +10 |
| `bedPrefPenalty` | `Σ 100` ถ้า `bed_preference ≠ 'any'` และ `bed_type` ไม่ match | 100/room | เช่น pref=twin แต่ได้ double |

> ⚠️ **Cost non-separable** — `floorSpread`/`sideMismatch` ขึ้นกับทุกห้องร่วมกัน → Hungarian/assignment-problem ใช้ตรงไม่ได้

Weights ตั้งใน `state.weights = { floor:50, side:8, pos:3, bed:5 }` (บรรทัด 651) — แก้ได้ใน UI

---

## 🤖 Algorithms — `ALGOS` registry (บรรทัด 2094)

| Key | Name | Function | บรรทัด | สถานะ | Strategy |
|-----|------|----------|--------|------|----------|
| **A** | Block Contiguous | `algoBlock` | 1508 | ✅ production | หา contiguous block (same floor+side+pos±1) แล้ว permutation |
| **B** | Graph Connected | `algoGraph` | 1638 | ✅ production | BFS หา largest connected component แล้ว brute-force subset |
| **C** | Coordinate + Cost | `algoCoordinate` | 1784 | ✅ production | brute-force combo+perm (gated `n≤4 && avail≤30`) หรือ greedy fallback |
| **D** | Greedy Seed + Expand | `algoGreedy` | 1914 | ✅ production | seed = mid room ของ type[0], expand ตาม Manhattan distance |
| **E** | Hybrid (C+D) | `algoHybrid` | 1993 | ✅ production | รัน C+D เลือก cost ต่ำสุด (default `selectedAlgo:'E'`) |
| **F** | Exact (BnB) | `algoExact` | 2033 | 🚧 **STUB → E** | TODO: backtracking + branch&bound, guard MAX_NODES/MAX_MS |
| **G** | DP Bitmask | `algoDPBitmask` | 2050 | 🚧 **STUB → E** | TODO: Held-Karp O(2ⁿ·n·M), N≤16 |
| **H** | Simulated Annealing | `algoSimulatedAnnealing` | 2066 | 🚧 **STUB → E** | TODO: perturbation + cooling schedule |
| **I** | GRASP + 2-opt | `algoGRASP2opt` | 2082 | 🚧 **STUB → E** | TODO: RCL construction + 2-opt local search |

### Stub pattern (F/G/H/I ปัจจุบัน)
```js
function algoXxx(booking) {
  const fb = algoHybrid(JSON.parse(JSON.stringify(booking)));
  fb.algo = 'Xxx'; fb.fallback = true;
  fb.steps.unshift({ type: 'info', room: null, thought: `🅵 [STUB → E]: TODO ...`, cost: 0, assignedRooms: [] });
  return fb;
}
```
เมื่อ implement จริง แทนที่ body ทั้งหมด และเอา `fallback:true` ออก

### Known weak spots (ที่ F ควรแก้)
| Case | C/E (เดิม) | A/B | ปัญหา |
|------|-----------|-----|------|
| **R4** Deluxe×4 | cost 19 ❌ | cost 9 ✅ | C/E greedy โดน 509 (3 เตียง) → bedWaste=10 |
| **S2** Deluxe×10 +occ | cost 94 ❌ | cost 37 ✅ | C/E greedy ไม่ยอมข้ามชั้นไป block ว่าง |
| **S3** Mixed×8 | ✅ | ❌ FAIL | A/B ตายเพราะต้องการ contiguous block |

---

## 🧰 Reusable Helpers

### Date
- `todayISO()` (538) → `'YYYY-MM-DD'`
- `addDays(dateStr, n)` (541) → date string
- `daysBetween(d1, d2)` (546) → integer nights
- `getNextFriday()` (554) → date string

### Availability
- `isAvailable(room, checkIn, checkOut)` (579) → bool (เช็ค overlap กับ `room.reservations`)
- `hasOverlap(room, checkIn, checkOut)` (571) → bool
- `getOverlap(room, checkIn, checkOut)` (575) → overlap days

### Cost & metrics
- `costFunction(pairs)` (1334) → number — **pairs = `[{room, br}, ...]`** (ห้ามส่ง assignedRooms ตรง ๆ)
- `costBreakdown(pairs)` (1389) → `{floor, side, pos, bed, pref, total}`
- `countContiguousPairs(rooms)` (1415) → int (คู่ติดกัน same floor+side+pos±1)
- `computeMetrics(result, booking)` (1428) → `{floors, sides, contiguous, bedWaste, breakdown}`
  - ⚠️ **`booking` ต้องเป็น array** ของ room specs (ใช้ `booking[i]`) — ไม่ใช่ queue item object
- `costFromAssigned(assignedRooms, booking)` (1443) → wrapper แปลงเป็น pairs แล้วเรียก costFunction

### Logging ใน algorithm
- `logOverlapFiltered(result, booking)` (1454) — push info step บอกว่ามีห้องไหนถูกกรองเพราะ overlap
- `getX09IfNeeded(booking, checkIn, checkOut)` (1491) → room|null — X09 priority (Deluxe + beds>0 → เลือก X09 ชั้นต่ำสุด)

### Combinatorics
- `combinations(arr, k)` (1889) → array of combos
- `permutations(arr)` (1899) → array of perms

---

## 🎮 State (`state` บรรทัด 639, `stepState` บรรทัด 658)

```js
state = {
  rooms: [...100 rooms],                  // buildRooms()
  currentBooking: {
    checkIn: 'YYYY-MM-DD',                // default addDays(today,1)
    checkOut: 'YYYY-MM-DD',               // default addDays(today,3)
    rooms: [bookingRoom, ...],            // input ปัจจุบัน
  },
  bookingQueue: [                         // processQueue จะ process ทีละอัน
    { checkIn, checkOut, rooms: [...], status: 'pending'|'processing'|'done'|'failed', result? }
  ],
  evalDates: { checkIn, checkOut },       // วันที่ใช้ evaluate availability (sync กับ active result)
  weights: { floor:50, side:8, pos:3, bed:5 },
  lastResults: { A:res, B:res, ... } | null,  // ผลล่าสุดจาก runAll
  lastBooking: [bookingRoom, ...],        // [metrics] array ของ booking ล่าสุด (สำหรับ computeMetrics)
  selectedAlgo: 'E',                      // default
  modalRoom: null,
}

stepState = { steps: [], current: -1, playing: false, interval: null, speed: 600, activeResult: null }
```

### Critical: `state.lastBooking` (เพิ่มสำหรับ metrics)
`renderResults()` ใช้ `state.lastBooking` เพื่อคำนวณ metrics ต้อง set ทุกที่ที่ set `state.lastResults`:
- `processQueue` (~876) → `state.lastBooking = lastBooking.rooms` (queue item → เอา .rooms)
- `runSelected` (~2150) → `state.lastBooking = booking` (booking = `state.currentBooking.rooms` อยู่แล้ว)
- `runAll` (~2175) → `state.lastBooking = booking`

---

## 🧪 Test Presets — `loadPreset(name)` (บรรทัด 982)

`resetAll()` ถูกเรียนเสมอในต้น `loadPreset()` → state สะอาดก่อน switch

### 17 preset cases

#### 🛡️ Regression (R1-R6) — ยืนยัน bug ไม่กลับมา
| Case | Scenario | Expected |
|------|----------|----------|
| R1 | Suite+Deluxe ว่างสะอาด | cost=3, contig 100% |
| R2 | + จอง 508 ไว้ก่อน | หลีก 508, cost=3 |
| R3 | Suite×3 | floors=2 (ไม่ใช่ 3) |
| R4 | Deluxe×4 | floors=1, cost ≤9 |
| R5 | Superior×2 (V2A+V2B) | sideMismatch=0 |
| R6 | Deluxe + pref=twin | ได้ชั้น 8 |

#### 💪 Stress (S1-S4)
| Case | Scenario | Expected |
|------|----------|----------|
| S1 | Deluxe×10 | cost ≤37 |
| S2 | Deluxe×10 + occ 50% (ชั้น 5,6,7) | cost ≤37 (หลีกไปชั้น 8) |
| S3 | Mixed×8 (2S+4D+2Sup) | ok (A/B อาจ FAIL ได้) |
| S4 | Deluxe×20 (impossible) | **FAIL สวย ไม่ crash** |

#### 🌐 Online/Sequential (O1-O3)
| Case | Scenario |
|------|----------|
| O1 | 3 bookings ไม่ overlap (ใน queue) |
| O2 | 5 bookings ชนกัน (ใน queue) |
| O3 | จอง 508-510 + Deluxe×4 → ต้องหา block อื่น |

#### 🔍 Edge (E1-E4)
| Case | Scenario | Expected |
|------|----------|----------|
| E1 | 1 ห้อง | cost=0 (boundary) |
| E2 | back-to-back (checkout=checkin อื่น) | ใช้ 508 ได้ (no overlap) |
| E3 | ทุกชั้นเต็มยกเว้น 8 | ยอมใช้ชั้น 8 |
| E4 | Deluxe×5 มีแค่ X09 ว่าง | bedWaste > 0 |

#### Classic (เดิม)
`simple`, `mix3`, `group5`, `extrabed`, `highocc` (random), `sequential`, `weekend`

### ⚠️ กฎเหล็กตอนเขียน preset
1. `state.currentBooking.checkIn/checkOut` default = `addDays(today,1)` → `addDays(today,3)` — preset ที่อ้างถึง checkIn ใช้ค่านี้
2. **`const` ใน switch case** ต้องครอบด้วย `{ }` หรือใช้ชื่อไม่ซ้ำ (เช่น `ci2`) — ดู E2
3. preset ที่ seed reservations ต้องใช้ `state.currentBooking.checkIn/checkOut` (ไม่ใช่ `today` ตรง ๆ) เพื่อให้ตรงกับ evalDates

---

## 🧪 Test Harness — `docs/test-matrix.cjs` (canonical, compose model)

**วิธีรัน:** `node docs/test-matrix.cjs`

**โครงสร้าง (compose model ตาม glossary บนสุด):** `seed (pre-booked) → sequence (list of cases) → case (one booking)` — harness ทำ cartesian product **SEED × CASE**

### Registries
- **`SEEDS` (34 entries)** — pre-booked state
  - 7 handcrafted: `S-CHK`, `S-HALF`, `S-ADJ`, `S-CONFLICT`, `S-PREMIUM`, `S-WEEKEND`, `S-TWIN`
  - 7 regression-derived: `S-NONE`, `S-508`, `S-508BK2`, `S-F8`, `S-X09ONLY`, `S-D40`, `S-D5K-`
  - 20 random deterministic (mulberry32): `S-RND1`..`S-RND20` (50-70 mixed + 40-70 RS-equivalent)
  - signature: `fn(state, ci, co) => void` — push reservations into `state.rooms`
- **`CASES` (22 entries)** — booking to assign
  - 12 single: `C-SOLO`, `C-G4`, `C-MIX`, `C-FAM`, `C-L10`, `C-TWIN`, `C-SD`, `C-S3`, `C-D5`, `C-SUP2`, `C-TWIN1`, `C-D20`
  - 10 sequence: `SEQ1`..`SEQ10` (list of cases — simulate `processQueue`, algo E, commit each)
  - shape: single = `{kind:'single', checkIn, checkOut, rooms}` | sequence = `{kind:'sequence', queue:[...]}`

### Main loop = **34 × 22 = 748 pairs** (ก่อน skip)
- single case → run A/B/C/D/E + metrics
- sequence case → simulate processQueue (algo E หลัก, commit reservations ระหว่าง booking)
- skip pairs ที่ availability ไม่พอล่วงหน้า (`shouldSkip`)

### Output sections (3)
1. **Matrix run** — per-pair detail (A/B/C/D/E + Σ sequence) + 34×22 grid
2. **🛡️ Regression Checks (11 assertions)** — migrated จาก `test-presets.cjs` เดิม, force-run ข้าม skip
3. **🔬 Special per-room-date checks (4 assertions)** — D1/D2/D3 (coupled, ใช้ `loadPreset` เพราะ slot ต่างวันใน booking เดียว ไม่ decompose)

### Preset (UI) → (seed, case) mapping
Preset switch ใน `loadPreset()` (UI buttons) ยังเก็บไว้เพราะ UI ต้องใช้ — แต่ harness canonical ใช้ compose model แทน:

| Preset (UI) | = (seed, case) ใน matrix |
|-------------|--------------------------|
| R1 | (S-NONE, C-SD) |
| R2 | (S-508, C-SD) |
| R3 | (S-NONE, C-S3) |
| R4 | (S-NONE, C-G4) |
| R5 | (S-NONE, C-SUP2) |
| R6 | (S-NONE, C-TWIN1) |
| S2 | (S-D5K-, C-L10) |
| S4 | (S-D40, C-D20) |
| O3 | (S-ADJ, C-G4) |
| E1 | (S-NONE, C-SOLO) |
| E2 | (S-508BK2, C-SOLO) |
| E3 | (S-F8, C-G4) |
| E4 | (S-X09ONLY, C-D5) |
| RS1-RS10 | (S-RND11..S-RND20, C-G4) |
| D1/D2/D3 | (special, ไม่ decompose) — ใช้ loadPreset |

### ข้อควรระวังเมื่อแก้ harness
1. **stub DOM ต้องคืน element เสมอ** — `querySelector`/`getElementById` ห้ามคืน `null` (render จะ crash)
2. **`state`/`ALGOS` เป็น `let`/`const` ใน script** → ต้อง expose ผ่าน `this.__expose = function(){...}` ท้าย script eval
3. **reset rooms ทุกครั้ง** ระหว่าง pair loop เพราะ algorithm + sequence commit ฝัง reservations ลง `state.rooms`
4. **`addDaysLocal`/`todayLocal` (TZ-safe)** ใช้แทน `addDays`/`todayISO` ของ playground เพราะ `toISOString()` drift ใต้ TZ+7
5. `computeMetrics(res, booking)` คาดหวัง `booking` = array ของ room specs
6. **regression block force-run** — ข้าม `shouldSkip` เพราะคาดหวังผลเฉพาะ (เช่น S4 ต้อง FAIL)

### Acceptance ปัจจุบัน
- Matrix: ~748 pairs, ~2000+ runs, ~30 skips, ~270 fails
- 🛡️ Regression: **11/11 passed**
- 🔬 Special: **3/4 passed** (D3 ยังตก — bug per-room-date matching ยังไม่ได้แก้)

---

## 🧪 Test Harness — `docs/test-seed-pairs.cjs` (enhanced, stress-focused)

**วิธีรัน:** `node docs/test-seed-pairs.cjs`

**เป้าหมาย:** stress-test algorithm ในสภาพ high-occupancy โดยใช้ date overlap ที่คุมได้

### โครงสร้าง (compose model) — เหมือน test-matrix แต่เน้น stress
`seed (pre-booked, 65+ rooms) × case (5+ booking × 3-5 rooms) = 380 pairs`

### Registries
- **`SEEDS` (20 entries)** — pre-booked state
  - 7 handcrafted: `S-CHK`, `S-HALF`, `S-ADJ`, `S-CONFLICT`, `S-PREMIUM`, `S-WEEKEND`, `S-TWIN`
  - 13 random deterministic (mulberry32): `S-RND1`..`S-RND13` — **ใช้ `randomSeedNarrow` (overlap ±2 วันรอบ ci/co)**, ไม่ใช่ `randomSeed` แบบกระจาย today+1..7 แบบเดิม
  - **3 stress seeds (65+ booked):** `S-RND11` (72), `S-RND12` (68), `S-RND13` (75)
  - signature: `fn(state, ci, co) => void` — push reservations into `state.rooms`
- **`CASES` (19 entries)** — booking to assign
  - 6 single: `C-SOLO`, `C-G4`, `C-MIX`, `C-FAM`, `C-L10`, `C-TWIN`
  - 13 sequence: `SEQ1`..`SEQ13` — ทุก booking overlap ±1-2 วัน (relative กับ ci/co ของ case แรก)
  - **3 heavy sequences (5+ booking × 3-5 rooms):** `SEQ11` (6× Deluxe×4), `SEQ12` (7× mixed 3-5), `SEQ13` (5× 3-5 overlap ±1 วัน)
  - shape: single = `{kind:'single', checkIn, checkOut, rooms}` | sequence = `{kind:'sequence', queue:[...]}`

### Date overlap policy (ใหม่ — ทุก booking ทับซ้อน ±1-2 วัน)
> **กฎเหล็ก:** seed reservations + case bookings ทั้งหมดใช้ **ci/co ของ case เป็นจุดศูนย์กลาง** (relative ไม่ใช่ absolute)

| Component | date logic | ทำไม |
|-----------|-----------|------|
| Handcrafted seed | sync กับ `ci/co` parameter ตรงๆ | overlap 100% เสมอ |
| Random seed (`randomSeedNarrow`) | `ci ± dateOffset` (default ±2 วัน) + nights 2-4 | sync กับ case interval → push occupancy |
| Sequence case | ทุก booking ใช้ `T(1)..T(7)` cluster รอบจุดเดียว | บังคับ overlap ไม่ใช่ no-overlap |

### `randomSeedNarrow(rng, count, dateOffset=2, nightsMax=4)` (บรรทัด 152)
สร้าง random seed ที่ date ทับซ้อนกับ ci/co ของ case แน่นอน:
```js
// start ในช่วง ci-dateOffset .. ci+dateOffset (±2 วันรอบ ci)
const start = addDaysLocal(ci, -dateOffset + Math.floor(rng() * (dateOffset * 2 + 1)));
const nights = 2 + Math.floor(rng() * (nightsMax - 1)); // 2..nightsMax
```

### Output
1. **Matrix run** — 380 pairs, แต่ละ pair รัน A/B/C/D/E (single) หรือ processQueue E (sequence)
2. **Matrix grid** — 20×19 cells (cell = E cost หรือ Σ total สำหรับ sequence)
3. ผ่าน `shouldSkip()` ก่อน (availability conservative check)

### ⚠️ type-constraint trap
แม้ seed จะจอง 75 ห้อง + case ต้องการ 29 ห้อง = 104, occupancy จริงได้แค่ **81/100** เพราะ:
- random seed สุ่ม type (Deluxe 40% / Superior 40% / Suite 20%)
- บาง booking Deluxe-heavy จะ fail เพราะ Deluxe ว่างไม่พอในวัน peak
- ดู `docs/test-occupancy-95.cjs` สำหรับการวิเคราะห์ occupancy แบบละเอียด

### Acceptance ปัจจุบัน
- 380 pairs, max occupancy = **81/100** (top: `S-RND11 × SEQ12`)
- ไม่มี pair ไหนถึง 95 — ติด type constraint (seed สุ่ม Deluxe 40%)

---

## 📅 Occupancy Analyzer — `docs/test-occupancy-95.cjs`

**วิธีรัน:** `node docs/test-occupancy-95.cjs`

**เป้าหมาย:** หา seed × case pair ที่ max simultaneous occupancy ≈ 95 ห้อง พร้อมระบุ date ที่ peak

### วิธีการ
- reuse setup + SEEDS + CASES จาก test-seed-pairs.cjs (extract source ก่อน Main loop)
- สำหรับแต่ละ pair: คำนวณ daily occupancy (seed reservations + booking rooms ที่ overlap จริงในวันนั้น)
- หา `maxOcc` + `peakDates` + เรียงตาม `|maxOcc − 95|`
- reuse DOM stub + playground eval pattern เดียวกับ test-matrix/test-seed-pairs

### Output
1. **TOP 25 pairs** ที่ใกล้ 95 ที่สุด + peak date
2. **PAIRS ที่ occupancy ≥ 95** + date (ถ้ามี)
3. **DATE COVERAGE** — pair ที่ ≥90 แสดงวันที่ครอบคลุม

### สถิติล่าสุด (2026-07-08)
- 380 pairs, max occupancy = **81** (ติด type constraint)
- Top 5: `S-RND11 × SEQ12` (81), `S-RND13 × SEQ12` (80), `S-WEEKEND × SEQ12` (74), `S-RND12 × SEQ9` (73), `S-RND5 × SEQ12` (72)

---

## 🛠️ Common Tasks

### Implement algorithm stub (F/G/H/I) จริง
1. อ่าน strategy ใน comment ด้านบน function
2. แทนที่ body (delegate → E) ด้วย logic จริง
3. เอา `fb.fallback = true` ออก
4. รูปร่าง result ต้องตรง schema (ดู "Algorithm result" ด้านบน)
5. เรียก `logOverlapFiltered(result, booking)` ต้น function (consistency)
6. ใช้ `getX09IfNeeded` สำหรับ X09 priority
7. รัน `node docs/test-matrix.cjs` ต้องผ่าน regression 11/11

### เพิ่ม preset case ใหม่
1. เพิ่ม `case 'XX':` ใน `loadPreset()` switch (บรรทัด 982)
2. ใช้ `state.currentBooking.checkIn/checkOut` เมื่อ seed reservation
3. ถ้ามี `const` ครอบด้วย `{ }`
4. เพิ่มปุ่มใน preset UI (ค้น `🎯 Presets` ใน HTML body)
5. (optional) เพิ่ม SEED/CASE ใน `test-matrix.cjs` (registries SEEDS/CASES) หรือ assertion ใน block REGRESSIONS/Special

### เพิ่ม seed/case ใน `test-seed-pairs.cjs` (stress harness)
1. เพิ่ม entry ใน `SEEDS` หรือ `CASES` registry
2. สำหรับ random seed ใช้ `randomSeedNarrow(rng, count, dateOffset=2)` (ไม่ใช่ `randomSeed`) เพื่อให้ overlap กับ case แน่นอน
3. สำหรับ sequence case บังคับ booking ทุกตัว overlap ±1-2 วันรอบ `T(1)..T(7)` (ไม่ใช่ no-overlap/back-to-back แบบเดิม)
4. รัน `node docs/test-seed-pairs.cjs` ตรวจว่า pair ใหม่รันได้
5. รัน `node docs/test-occupancy-95.cjs` ดูผลกระทบต่อ max occupancy

### ปรับ occupancy target ใน `test-occupancy-95.cjs`
- แก้ `>= 95` หรือ `>= 90` threshold ในบรรทัด filter (ค้น `reach95`/`reach90`)
- หรือเพิ่ม seed/case ที่หนักขึ้นใน test-seed-pairs.cjs ก่อน แล้ว analyzer จะเห็นอัตโนมัติ

### เพิ่ม metric ใหม่ใน result card
1. เพิ่มใน `computeMetrics()` return (บรรทัด 1428)
2. แสดงใน `renderResults()` card.innerHTML (บรรทัด 2190)
3. CSS class `metric-group`/`metric.tiny` มีอยู่แล้ว

### แก้ cost function
1. แก้ `costFunction` (1334) **และ** `costBreakdown` (1389) ให้สอดคล้องกัน
2. รัน test — expected results ทั้งหมดจะเปลี่ยน ต้องอัปเดต assertions + expected table ใน `plan_for_test.md`

---

## 📐 Line Reference (อาจเลื่อนเมื่อแก้ไฟล์ — ยืนยันด้วย grep)

```
538   todayISO()
541   addDays()
546   daysBetween()
571   hasOverlap() / getOverlap()
579   isAvailable()
594   const FLOORS = [5,6,7,8,9]
596   buildRooms()              ← room topology
639   let state                 ← state object
658   let stepState
670   render()                  ← floor map
856   processQueue()
953   resetAll()
982   loadPreset(name)          ← 17 preset cases
1334  costFunction(pairs)       ← cost v2
1389  costBreakdown()
1415  countContiguousPairs()
1428  computeMetrics()
1491  getX09IfNeeded()
1508  algoBlock (A)
1638  algoGraph (B)
1784  algoCoordinate (C)
1889  combinations() / permutations()
1914  algoGreedy (D)
1993  algoHybrid (E)
2033  algoExact (F) — STUB
2050  algoDPBitmask (G) — STUB
2066  algoSimulatedAnnealing (H) — STUB
2082  algoGRASP2opt (I) — STUB
2094  const ALGOS               ← registry (9 entries)
2117  applyResult()
2132  runSelected() / runAll()
2190  renderResults()           ← result card + metrics
2244  renderPrompt()
2280  startStepMode()
```

---

## ⚠️ Gotchas & Pitfalls

1. **`booking` parameter ใน algorithm** = array ของ room specs (เช่น `[{type:'Deluxe',beds:0}]`) ไม่ใช่ queue item
2. **`computeMetrics(result, booking)`** คาดหวัง `booking` = array ด้วย — ใน `processQueue` ต้องส่ง `lastBooking.rooms`
3. **X09 priority** (`getX09IfNeeded`) — algorithm ทุกตัวควรเรียก เพราะ X09 (3 เตียง) เป็น policy ไม่ใช่ optimization
4. **`state.evalDates`** ต้อง sync กับ `result.bookingDates` ตอน step mode — ไม่งั้น floor map จะ highlight ผิดวัน
5. **`resetAll()` reset `state.lastBooking` ไม่ได้** — แต่ `renderResults` มี `|| []` guard ไว้ ไม่ crash
6. **E4 (`bedWaste > 0`)** — assertion ผ่านเพราะ C/D/E ยอมใช้ X09 ทุกชั้น
7. **S4 (impossible)** — assertion คาดหวัง FAIL ทุก algorithm ถ้า algorithm ใหม่ success แปลว่า logic พัง
8. **`br-bedpref` element** — ค่าจาก `<select>` เป็น string `'any'|'twin'|'double'` ไม่ใช่ boolean
9. **ชั้น 8 = twin** — `bed_type='twin'` เฉพาะชั้น 8 (set ใน `buildRooms` บรรทัด 630)
10. **Hungarian/Min-cost-flow ใช้ไม่ได้ตรง** เพราะ cost non-separable (floorSpread/sideMismatch) — ต้อง BnB หรือ DP

---

## 📚 Related Files (อ่านเพิ่มเมื่อต้องการบริบาร)

| ไฟล์ | สำหรับ |
|------|-------|
| `docs/room-algorithm-explainer.html` | reference doc อธิบาย A-E พร้อม demo + comparison table |
| `docs/plan_for_test.md` | plan เดิมที่สร้าง preset + metrics (เสร็จแล้ว) |
| `docs/test-matrix.cjs` | **canonical test harness** — compose model seed × case (748 pairs + 15 assertions) |
| `docs/test-seed-pairs.cjs` | **enhanced seed × case harness** — 20 seeds × 19 cases = **380 pairs**, date overlap ±1-2 วัน (relative ci/co), push occupancy สูงสุด 81/100 |
| `docs/test-occupancy-95.cjs` | **occupancy analyzer** — คำนวณ max simultaneous occupancy ของทุก pair + ระบุ peak date (ใช้หา pair ที่ fit 95) |
| `docs/_extracted.js` | (ถ้ามี) JS ที่ extract จาก playground สำหรับ analyze |

---

## ✅ Quick Sanity Check หลังแก้ code

```bash
# 1. JS syntax
node -e "const fs=require('fs');const h=fs.readFileSync('docs/room-algorithm-playground.html','utf8');const m=h.match(/<script>([\s\S]*?)<\/script>/);new Function(m[1]);console.log('✅ syntax OK');"

# 2. Test harness (canonical, compose model)
node docs/test-matrix.cjs

# 3. ผลคาดหวัง: matrix ~748 pairs + 🛡️ Regression 11/11 + 🔬 Special 3/4 (D3 ยังตก)

# 4. Enhanced seed × case harness (380 pairs, overlap ±1-2 วัน)
node docs/test-seed-pairs.cjs

# 5. Occupancy analyzer (หา pair ที่ occupancy ≈ 95 + peak date)
node docs/test-occupancy-95.cjs
```
