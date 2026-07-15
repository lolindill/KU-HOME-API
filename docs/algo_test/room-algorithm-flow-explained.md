# 📐 Paper: วิธีการเลือก/จัดห้องของ KU HOME (v3 — Walking Distance Final Judge)

> อ้างอิง: `docs/room-algorithm-playground-eav3.html` (final version ของ algorithm discussion)
>
> เอกสารนี้อธิบาย **เฉพาะ flow การเลือกห้อง (room assignment)** ของ algorithm test ตัวนี้เท่านั้น — ไม่เกี่ยวกับส่วนอื่นของโปรเจกต์

---

## 📑 สารบัญ

1. [ภาพรวมระบบ](#1-ภาพรวมระบบ)
2. [ข้อมูลพื้นฐาน: Topology ของอาคาร](#2-ข้อมูลพื้นฐาน-topology-ของอาคาร)
3. [Global Position System — แปลงพิกัด 3 ฝั่งเป็นเส้นตรง 1D](#3-global-position-system)
4. [Walking Distance — ระยะเดินจริงผ่านบันได](#4-walking-distance)
5. [Cost Function (costFunction) — คะแนนความแพร่กระจาย](#5-cost-function)
6. [walkCost — Final Judge สำหรับ rank cross-algorithm](#6-walkcost--final-judge)
7. [Booking Priority — เรียงคิวก่อนจัดห้อง](#7-booking-priority)
8. [X09 Priority Seed — กฎพิเศษเรื่อง extra bed](#8-x09-priority-seed)
9. [5 Algorithms หลัก (A / C / D / FF)](#9-5-algorithms-หลัก)
10. [Hybrid และ Hybrid+ (E / EA) — ตัวจริงที่ใช้ผลิต](#10-hybrid-และ-hybrid)
11. [Flow เต็ม: Seed → Queue → Assign](#11-flow-เต็ม-seed--queue--assign)
12. [Cost Breakdown — อ่านค่าแยกแต่ละ dimension](#12-cost-breakdown)

---

## 1. ภาพรวมระบบ

ระบบมีหน้าที่ **จัดห้องให้แต่ละ booking** โดยเป้าหมายคือ "เลือกห้องที่ **กระจุกตัวกัน (clustered)** มากที่สุด" — คือเดินหากันได้สะดวก, อยู่ชั้นใกล้กัน, ฝั่งเดียวกัน

### แนวคิดสำคัญ 2 ชั้น

```
┌─────────────────────────────────────────────────────────┐
│  ชั้นที่ 1: "ตัวสร้างผลลัพธ์" — หาชุดห้องที่เป็นไปได้      │
│  ├─ Algorithm A  (Block Contiguous)                      │
│  ├─ Algorithm C  (Coordinate + Cost / brute-force)       │
│  ├─ Algorithm D  (Greedy Seed + Expand)                  │
│  └─ Algorithm FF (Floor-First Greedy)                    │
│                                                          │
│  ชั้นที่ 2: "ผู้ตัดสิน" — เลือกชุดที่ดีที่สุดจากหลาย algorithm │
│  ├─ Algorithm E  = min(C, D)                             │
│  └─ Algorithm EA = min(C, D, A, FF)  ← ตัวที่ใช้จริง        │
└─────────────────────────────────────────────────────────┘
```

- **`costFunction`** ใช้ในระหว่างการค้นหาของแต่ละ algorithm (เพื่อเปรียบเทียบชุดห้องภายใน algorithm เดียวกัน)
- **`walkCost`** ใช้เป็น **Final Judge** เพื่อ rank เปรียบเทียบ **ข้าม algorithm** (cross-algo) — นี่คือหัวใจของ v3

> 💡 ทำใช้สองตัว? เพราะ `costFunction` มีหลาย dimension (floor/side/pos/bed) เหมาะกับ guide การค้นหา แต่ `walkCost` วัด "ระยะเดินจริง" ซึ่งตรงกับเป้าหมายที่แท้จริงของผู้เข้าพัก

---

## 2. ข้อมูลพื้นฐาน: Topology ของอาคาร

อาคารมี **5 ชั้น** (5, 6, 7, 8, 9) แต่ละชั้นแบ่งเป็น **3 ฝั่ง (side)**:

| ฝั่ง | ชื่อ | ประเภทห้อง | จำนวนตำแหน่ง | คำอธิบาย |
|------|------|-----------|-------------|----------|
| **V1** | ฝั่งวิว 1 | Suite · Deluxe · Suite | pos 1–12 | ด้านหนึ่งของอาคาร (12 ห้อง) |
| **V2A** | ฝั่งวิว 2 (ซีก A) | Superior | pos 1–6 | อีกด้าน, มีบันไดซ้ายกั้น |
| **V2B** | ฝั่งวิว 2 (ซีก B) | Superior | pos 1–2 | อีกด้าน, มีลิฟต์ 2 ตัวกั้นจาก V2A |

```
ชั้น N (ตัวอย่าง):
  V1:  [N07 Suite][N08 Dlx][N09 Dlx-3bed][N10 Dlx]...[N18 Suite]   ← pos 1..12
                       ═════ ทางเดิน (CORRIDOR) ═══
  V2:  🪜บันได  [N06 Sup][N05 Sup][N04 Sup][N03 Sup][N02 Sup][N01 Sup]  🛗🛗  [N20 Sup][N19 Sup]  🪜บันได
                └── V2A: pos 1..6 ──┘                                  └─ V2B: pos 1..2 ─┘
```

### คุณสมบัติของแต่ละห้อง
```js
{
  num: "508",         // เลขห้อง → floor = num[0]
  type: "Deluxe",     // Suite | Deluxe | Superior
  side: "V1",         // V1 | V2A | V2B
  pos: 2,             // ตำแหน่งภายในฝั่ง
  beds: 1,            // จำนวนเตียงในตัวห้อง
  floor: 5,
  bed_type: "double", // double ทุกชั้น ยกเว้นชั้น 8 = "twin"
  reservations: [],   // รายการจองที่ทับซ้อน
}
```

> 🔒 ห้องชั้น 8 เป็น **twin** เท่านั้น — ใช้สำหรับตรงกับ `bed_preference: 'twin'`

---

## 3. Global Position System

เนื่องจากแต่ละฝั่งมี `pos` นับเองเอาเฉพาะในฝั่ง จึงต้องมีฟังก์ชันแปลง **3 ฝั่ง → เส้นตรง 1D** เพื่อให้วัดระยะข้ามฝั่งได้สม่ำเสมอ

```js
function getGlobalPos(r) {
  if (r.side === 'V1')  return r.pos;          // V1:  pos 1-12  → global 1-12
  if (r.side === 'V2A') return r.pos + 1;      // V2A: pos 1-6   → global 2-7   (เว้น 1 ให้บันไดซ้าย)
  if (r.side === 'V2B') return r.pos + 9;      // V2B: pos 1-2   → global 10-11 (ข้ามลิฟต์ 2 ตัว)
  return r.pos;
}
```

### ผัง global (ชั้นเดียวกัน)
```
global:  1     2    3    4    5    6    7    8    9   10   11   12
         V1   V2A                 V2A              V2B   V1
        N07  N06  ...            N01              N20   ...
         │    │                                    │     │
         └─ บันไดซ้าย (STAIR_LEFT = 1)               │     └─ บันไดขวา (STAIR_RIGHT = 12)
```

> ใช้ทั้งใน `costFunction`, `costBreakdown`, `walkCost` และ Greedy เพื่อความสอดคล้อง — ทุกส่วนนับพิกัดแบบเดียวกัน

---

## 4. Walking Distance

วัด **ระยะเดินจริง** ระหว่าง 2 ห้อง — เฉพาะแนวนอน (vertical คุมด้วย floor penalty แยกใน walkCost)

### กฎ
```js
const STAIR_LEFT = 1, STAIR_RIGHT = 12;

function getWalkingDist(roomA, roomB) {
  const gA = getGlobalPos(roomA);
  const gB = getGlobalPos(roomB);
  if (roomA.floor === roomB.floor) {
    return Math.abs(gA - gB);                    // เดินตรงในชั้น
  }
  // ต่างชั้น: ลงบันไดฝั่งหนึ่ง → ข้ามชั้น → เดินเข้าห้อง
  const viaLeft  = Math.abs(gA - STAIR_LEFT)  + Math.abs(gB - STAIR_LEFT);
  const viaRight = Math.abs(gA - STAIR_RIGHT) + Math.abs(gB - STAIR_RIGHT);
  return Math.min(viaLeft, viaRight);            // เลือกบันไดที่ใกล้กว่า
}
```

### ตัวอย่าง
| จาก → ไป | global | ระยะ |
|----------|--------|------|
| 508 (V1 p2) → 510 (V1 p4) | 2 → 4 | `|2-4| = 2` (ชั้นเดียวกัน) |
| 508 (V1 p2) → 608 (V1 p2) | 2 → 2 ต่างชั้น | viaLeft = `1+1=2`, viaRight = `10+10=20` → **2** |
| 508 (V1 p2) → 501 (V2A p6) | 2 → 7 | `|2-7| = 5` (ชั้นเดียวกัน) |
| 508 (V1 p2) → 620 (V2B p1) | 2 → 10 ต่างชั้น | viaLeft = `1+9=10`, viaRight = `10+2=12` → **10** |

> ⚠️ ไม่พับตัว U เหมือน v2 — pos 1 กับ pos 12 = 11 (ไม่ใช่ 0)

---

## 5. Cost Function

ใช้ระหว่างการค้นหาภายใน algorithm เพื่อ **guide** การเลือกชุดห้อง

```
cost = floorSpread + sideMismatch + posSpread + bedWaste + bedPrefPenalty
```

| Term | สูตร | น้ำหนัก (default) | ความหมาย |
|------|------|------------------|----------|
| **floorSpread** | `(จำนวนชั้นที่ต่าง - 1) × w.floor` | 50 | ข้ามชั้นแพงมาก |
| **sideMismatch** | `(จำนวนฝั่งที่ต่าง - 1) × w.side` | 8 | ข้ามฝั่ง V1↔V2 |
| **posSpread** | `(globalMax - globalMin)² × w.pos` | 3 | **ยกกำลังสอง** เพื่อลงโทษ scatter หนัก |
| **bedWaste** | `Σ max(0, room.beds - 1 - br.beds) × w.bed` | 5 | ห้องมีเตียงเกินความต้องการ |
| **bedPrefPenalty** | `+100 ต่อห้อง` ถ้า `bed_type ≠ preference` | (คงที่) | ผิด twin/double preference |

```js
// ตัวอย่าง posSpread (exponential)
// ห้องกระจาย global 2..7 → distance=5 → 5² × 3 = 75
// ห้องกระจาย global 2..11 → distance=9 → 9² × 3 = 243
```

> 🔑 `posSpread` ใช้ **ยกกำลังสอง** เพื่อให้ algorithm เลือก cluster แน่น ๆ แม้ค่าเฉลี่ยจะเท่ากัน

---

## 6. walkCost — Final Judge

หัวใจของ v3 — ใช้ rank ข้าม algorithm (cross-algo) เพื่อหา "ชุดห้องที่ระยะเดินรวมน้อยที่สุด"

```js
function walkCost(pairs) {
  const rooms = pairs.map(p => p.room).filter(r => r);
  const w = state.weights;

  // (1) horizontal: ผลรวมระยะเดินของทุกคู่
  let sumWalk = 0;
  for (let i = 0; i < rooms.length; i++)
    for (let j = i + 1; j < rooms.length; j++)
      sumWalk += getWalkingDist(rooms[i], rooms[j]);

  // (2) vertical: floor penalty แยก (เพราะ getWalkingDist ไม่มี vertical)
  const distinctFloors = new Set(rooms.map(r => r.floor));
  const floorPenalty = (distinctFloors.size - 1) * w.floor;

  return sumWalk * w.walk + floorPenalty;
}
```

```
walkCost = (Σ pairwise walking distance) × w.walk  +  (จำนวนชั้นที่ต่าง - 1) × w.floor
           └──────── horizontal ────────┘              └──── vertical ────┘
```

### ทำไม walkCost ใช้ "Σ ทุกคู่" ไม่ใช่ "max-min"?
- `posSpread` (ใน costFunction) ใช้ `max - min` = ดูแค่ขอบเขต
- `walkCost` ใช้ **ผลรวมทุกคู่** = จับ scatter ของ **กลุ่มทั้งหมด** (ถ้ามีห้อง 1 ตัวหลุดออกไปไกล จะถูกลงโทษทุกคู่ที่เกี่ยวข้อง)

---

## 7. Booking Priority

ก่อนจัดห้อง ระบบ **เรียงคิว (booking queue)** ตามลำดับความสำคัญ เพื่อให้ booking ที่ "จัดยาก" ได้สิทธิ์เลือกก่อน

### ลำดับ Priority (5 ขั้น)
```
1. 👑 Suite          — มีห้อง Suite (หายาก, 12 ห้อง/ชั้น × 2 ตำแหน่ง)
2. 🛏️ X09 free      — ต้องการ extra bed และ X09 (Deluxe 3-bed) ยังว่างจริง
3. 👯 Twin           — ต้องการ bed_preference: 'twin' (ชั้น 8 เท่านั้น)
4. 📦 Most rooms     — จองห้องเยอะกว่า
5. 📅 Least checkout — เช็คเอาท์เร็วกว่า (เพื่อปล่อยห้องคืนเร็ว)
```

```js
// comparator
state.bookingQueue.sort((a, b) => {
  const pa = computeBookingPriority(a);
  const pb = computeBookingPriority(b);
  if (pa.hasSuite   !== pb.hasSuite)   return pb.hasSuite   - pa.hasSuite;   // 1
  if (pa.hasX09Free !== pb.hasX09Free) return pb.hasX09Free - pa.hasX09Free; // 2
  if (pa.hasTwin    !== pb.hasTwin)    return pb.hasTwin    - pa.hasTwin;    // 3
  if (pa.roomCount  !== pb.roomCount)  return pb.roomCount  - pa.roomCount;  // 4 (desc)
  return pa.checkOutTs - pb.checkOutTs;                                       // 5 (asc)
});
```

> 💡 หลักการ: "booking ที่มีข้อจำกัดมาก (จัดยาก) ต้องได้เลือกก่อน" — เหมือนกับจองที่นั่งเครื่องบิน

---

## 8. X09 Priority Seed

ห้องที่ลงท้ายด้วย **`09`** (เช่น 509, 609, 709, 809, 909) เป็น **Deluxe 3-bed builtin** — เป็นห้องเดียวที่รองรับ extra bed โดยไม่ต้องเพิ่มเตียงเสริม

### กฎ
ถ้า booking มี slot ที่ `type: 'Deluxe'` และ `beds > 0` (ต้องการ extra bed) และมีห้อง X09 ว่าง → **เลือก X09 ทันที** ก่อนรัน algorithm

```js
function getX09IfNeeded(booking) {
  const idx = booking.findIndex(br => br.type === 'Deluxe' && br.beds > 0);
  if (idx < 0) return null;
  const x09Rooms = state.rooms.filter(r =>
    r.num.endsWith('09') && r.type === 'Deluxe' && r.beds === 3 &&
    isAvailable(r, checkIn, checkOut)
  );
  if (x09Rooms.length === 0) return null;
  const room = x09Rooms.sort((a, b) => a.floor - b.floor)[0];  // X09 ชั้นต่ำสุดก่อน
  return { room, idx };
}
```

> ผล: X09 ถูก pin ลงใน `assignments[idx]` → algorithm ที่เหลือทำงานกับ slot อื่น ๆ เท่านั้น และ X09 จะถูกนำมารวมในการคำนวณ cost (preAssignedPairs)

---

## 9. 5 Algorithms หลัก

ทุก algorithm รับ `booking` (array ของ room requests) และคืน `result`:
```js
result = {
  algo, assignments: [room|null, ...], seed, ok: bool,
  cost,              // = walkCost ถ้า ok, มิฉะนั้น Infinity
  steps: [...]       // สำหรับ animation
}
```

ทุก algorithm เริ่มต้นเหมือนกัน:
1. log overlap-filtered rooms (ห้องที่ถูกตัดเพราะ date ทับซ้อน)
2. ใช้ `getX09IfNeeded()` — pin X09 ถ้ามี
3. ทำงานกับ `remaining` slots

---

### 🅰️ Algorithm A — Block Contiguous

**เป้าหมาย:** หา **ห้องติดกันจริง (contiguous block)** บนฝั่งเดียวกัน

```
วิธี:
1. รวม candidates (ห้องที่ type ตรง + ไม่ถูกจอง)
2. จัดกลุ่มตาม (floor, side) → เรียงตาม pos
3. สไลด์ window ขนาด n ในแต่ละกลุ่ม:
   a. ตรวจ contiguous: pos[j] === pos[j-1]+1 ทุกตัว
   b. bipartite matching (slot ↔ window position) — ต้อง perfect
   c. คำนวณ costFunction → เก็บ block ที่ cost ต่ำสุด
4. cap MAX_WINDOWS = 5000 (กัน combinatorial explosion)
```

**จุดเด่น:** ได้ cluster แน่นที่สุด
**จุดอ่อน:** ถ้าไม่เจอ block ติดกัน → `ok: false` → ถูกตัดออกจาก Hybrid+ (best-effort ปิด)

---

### 🅲 Algorithm C — Coordinate + Cost (brute-force)

**เป้าหมาย:** หา **global minimum cost** จาก combination ทั้งหมด

```
วิธี:
1. เก็บ slotAvail = ห้องที่ type ตรง + ว่างตาม date ของ slot
2. ตัดสินใจว่าจะ brute-force หรือ greedy:
   - ใช้ bruteComboFeasible(n, len):
       n=1 → brute เสมอ
       n=2 → brute เสมอ (C(100,2)=4950)
       n=3 → len ≤ 80
       n=4 → len ≤ 40
       n=5 → len ≤ 25
       n=6 → len ≤ 20
       n>6 → greedy fallback
3. brute-force path:
   - generate combinations(slotAvail, n)
   - แต่ละ combo: bipartite match slot↔type → คำนวณ costFunction รวม preAssignedPairs
   - เก็บชุดที่ cost ต่ำสุด → global minimum แน่นอน
4. greedy fallback path (multi-seed):
   - ลอง seed แต่ละตัวของ slot 0 (cap 15 trials)
   - expand แบบ proximity (เหมือน D)
   - เก็บชุดที่ cost ต่ำสุด
```

**จุดเด่น:** เจอ global min เมื่อ brute-force (เช่น 518+519 แทน 507+505)
**จุดอ่อน:** combinatorial — ต้องมี cap + fallback

> 💖 [FIX] เดิมใช้ threshold flat `<= 30` ทำให้เคส n=2 ตก Greedy ได้ผลห่ำ → เปลี่ยนเป็น formula ตาม n

---

### 🅳 Algorithm D — Greedy Seed + Expand

**เป้าหมาย:** เร็ว + กระจุกตัว โดยเลือก seed แล้วขยายไปหาใกล้สุด

```
วิธี:
1. seedCandidates = ห้องที่ type ตรง slot 0, เรียงตาม (floor, pos)
2. Multi-seed (cap 15 trials):
   สำหรับแต่ละ seedRoom:
     a. chosen = [seedRoom]
     b. สำหรับ slot j = 1..n-1:
        - candidates = ห้องที่ type ตรง + ว่าง + ยังไม่ถูกใช้
        - เรียงตามระยะจาก seed:
            da = |Δfloor| + |ΔglobalPos| × 0.5 + (ฝั่งต่าง ? 10 : 0)
        - หยิบ candidates[0] (ใกล้สุด)
     c. คำนวณ costFunction → เก็บชุดที่ cost ต่ำสุด
3. push accept steps เฉพาะชุดที่ชนะ (รักษา animation)
```

**จุดเด่น:** เร็วมาก, ใช้ได้กับ n ขนาดใหญ่
**จุดอ่อน:** local optimum — อาจไม่ใช่ global min

---

### 🆕 Algorithm FF — Floor-First Greedy

**เป้าหมาย:** เก็บห้องไว้ **ในชั้นเดียว** ด้วยโครงสร้าง (ไม่พึ่ง floor weight)

```
Phase A — คำนวณ available pool (เหมือน D)
Phase B — หาชั้นที่ "จุได้ครบทุก slot" (bipartite perfect match ในแต่ละชั้น)
Phase C — Edge multi-seed + in-floor expand:
          seed เริ่มที่ปลายซ้าย/ขวาของฝั่ง slot 0 ในชั้นนั้น
          → expand เฉพาะในชั้นเดียวกัน (ห้ามข้ามชั้น)
Phase D — Overflow fallback (ไม่มีชั้นไหนจุครบ):
          เติมชั้นที่จุได้เยอะสุด แล้วเอาห้องที่เหลือ → ชั้นข้างเคียงใกล้สุด (±1, ±2...)
```

**จุดเด่น:** รักษาชั้นเดียวกันได้แม้ floor weight ต่ำ
**จุดอ่อน:** ถ้าไม่มีชั้นจุครบ → กระจายข้ามชั้น (Phase D)

> 💡 FF ถูกออกแบบมาแก้ปัญหา "แม้ปรับ floor weight ต่ำ ก็ยังอยากให้อยู่ชั้นเดียว"

---

## 10. Hybrid และ Hybrid+

### Algorithm E — Hybrid (C + D)
```
run C, run D → เลือก min(cost) ที่ ok
```

### ⭐ Algorithm EA (J) — Hybrid+ = C + D + A + FF tie-breaker
**นี่คือตัวที่ใช้ผลิตจริง**

```js
function algoHybridPlus(booking) {
  const coordResult  = algoCoordinate(booking);   // C
  const greedyResult = algoGreedy(booking);       // D
  const blockResult  = algoBlock(booking);        // A
  const ffResult     = algoFloorFirst(booking);   // FF

  // กรองเฉพาะ ok — A ที่หา block ไม่ได้จะถูกตัดออก
  const candidates = [coordResult, greedyResult, blockResult, ffResult]
    .filter(r => r.ok);

  // เลือก cost ต่ำสุด (tie → candidate แรก = C ชนะ)
  let best = null;
  for (const r of candidates) {
    if (!best || r.cost < best.cost) best = r;
  }
  if (!best) best = coordResult;  // ทั้งหมดล้มเหลว → default C
  // ...
}
```

### เหตุผลที่ใช้หลาย algorithm
| Algorithm | เก่งเรื่อง | เมื่อไหร่จะชนะ |
|-----------|-----------|---------------|
| **C** | global min (brute-force) | n เล็ก, ผลลัพธ์เป๊ะ |
| **D** | รอบด้าน, ทน n ใหญ่ | n ใหญ่ ตก greedy |
| **A** | contiguous สุด | มี block ว่างพอดี |
| **FF** | รักษาชั้นเดียว | floor weight ต่ำ/มีชั้นจุครบ |

> การรวม 4 algorithm แล้วให้ walkCost เป็นกรรมการ = ได้ผลลัพธ์ที่ดีที่สุดในแต่ละสถานการณ์โดยอัตโนมัติ

---

## 11. Flow เต็ม: Seed → Queue → Assign

นี่คือ **end-to-end flow** ของการจัดห้องทั้งระบบ:

```
┌──────────────────────────────────────────────────────────────┐
│ STEP 1: เตรียมข้อมูลต้นทาง                                       │
│   buildRooms() → สร้าง 100 ห้อง (5 ชั้น × 20) สะอาด             │
│                                                                │
│   applySeed(seedKey) → เพิ่ม reservation ที่ "pre-booked"       │
│     ├─ ห้องที่ถูก seed จอง → reservations[] มี {bookingIdx: -1}  │
│     └─ แสดงเป็น 🔒 บนแผนผัง (ห้าม algorithm ใช้)                 │
└──────────────────────────────────────────────────────────────┘
                              │
                              ▼
┌──────────────────────────────────────────────────────────────┐
│ STEP 2: โหลด Case / Sequence                                  │
│   single case: bookingQueue = [1 booking]                     │
│   sequence:    bookingQueue = [booking1, booking2, ...]       │
│                                                                │
│   แต่ละ booking = {                                            │
│     checkIn, checkOut,                                         │
│     rooms: [{ type, beds, bed_preference, checkIn?, checkOut? }]│
│   }                                                            │
│                                                                │
│   → Per-Room Dates: แต่ละ slot อาจมี date ของตัวเอง              │
│     (brDates() fallback ไป global ของ booking)                 │
└──────────────────────────────────────────────────────────────┘
                              │
                              ▼
┌──────────────────────────────────────────────────────────────┐
│ STEP 3: เรียง Queue ตาม Booking Priority (§7)                  │
│   Suite → X09-free → Twin → Most-rooms → Least-checkout        │
│                                                                │
│   เหตุผล: booking ที่จัดยาก ต้องได้สิทธิ์เลือกห้องก่อน               │
└──────────────────────────────────────────────────────────────┘
                              │
                              ▼
┌──────────────────────────────────────────────────────────────┐
│ STEP 4: ประมวลผลทีละ booking (processQueue)                    │
│   สำหรับ booking ใน queue (ตามลำดับที่เรียงแล้ว):                  │
│   ┌────────────────────────────────────────────────────┐      │
│   │ 4a. กำหนด evalDates = booking.checkIn/Out          │      │
│   │ 4b. clearAssigned() — ล้าง state ห้องจากรอบก่อน      │      │
│   │ 4c. res = algoHybridPlus(booking.rooms)  ← ด้านล่าง  │      │
│   │ 4d. ถ้า res.ok:                                     │      │
│   │       commit reservations ลง state.rooms            │      │
│   │       (พร้อม bookingIdx = i เพื่อระบายสี)              │      │
│   │     ถ้าไม่ ok: mark failed                            │      │
│   │ 4e. booking ถัดไปเห็นห้องที่ commit แล้วเป็น "occupied"  │      │
│   └────────────────────────────────────────────────────┘      │
└──────────────────────────────────────────────────────────────┘
                              │
                              ▼
┌──────────────────────────────────────────────────────────────┐
│ STEP 5: ภายใน algoHybridPlus (รายละเอียดของ 4c)                 │
│                                                                │
│  ┌─ log overlap-filtered rooms (debug/animation)             │
│  │                                                            │
│  ├─ getX09IfNeeded() → pin X09 ถ้ามี slot ต้องการ extra bed    │
│  │   └─ assignments[idx] = X09 room (คู่กับ preAssignedPairs)   │
│  │                                                            │
│  ├─ remaining = slots ที่ยังไม่ถูก pin                            │
│  │                                                            │
│  ├─ รัน 4 algorithm ขนาน (แต่ละตัวเริ่มจาก remaining):            │
│  │   ├─ C: brute-force global min (หรือ multi-seed greedy)    │
│  │   ├─ D: greedy seed + expand (proximity)                   │
│  │   ├─ A: block contiguous (bipartite matching)              │
│  │   └─ FF: floor-first (edge seed + in-floor expand)         │
│  │                                                            │
│  ├─ filter r.ok → candidates                                  │
│  │   (A ที่หา block ไม่ได้จะถูกตัด)                                │
│  │                                                            │
│  └─ best = min(cost) โดย cost = walkCost (Final Judge)        │
│      tie → C ชนะ (candidate แรก)                              │
└──────────────────────────────────────────────────────────────┘
                              │
                              ▼
┌──────────────────────────────────────────────────────────────┐
│ STEP 6: ผลลัพธ์ + commit                                         │
│   res = {                                                      │
│     algo: "Hybrid+ (C+D+A+FF)",                                │
│     winner: "Coordinate + Cost",   ← algorithm ที่ชนะ            │
│     assignments: [room, room, ...],                            │
│     seed, ok: true,                                            │
│     cost: walkCost(pairs)                                      │
│   }                                                            │
│                                                                │
│   commit: assignments แต่ละตัว → state.rooms[reservations.push]│
│           (พร้อม bookingIdx สำหรับสี + การตรวจ overlap รอบถัดไป)   │
└──────────────────────────────────────────────────────────────┘
```

### ตัวอย่าง concrete: SEQ2 (5 bookings วันเดียวกัน)

```
ก่อนเรียง:
  #1 Suite + Deluxe
  #2 Deluxe×3
  #3 Superior×2
  #4 Deluxe +1bed (extra bed)
  #5 Deluxe×2

หลังเรียง priority:
  #1 (👑 Suite)          →  จัดก่อน
  #4 (🛏️ X09-free)       →  ได้ X09
  #5 (📦 2 rooms)        →  ...
  #2 (📦 3 rooms)        →  ...
  #3 (📅 checkout)       →  จัดทีหลัง

แต่ละ booking รัน Hybrid+ → commit → booking ถัดไปเห็นห้องที่ถูกจอง
```

---

## 12. Cost Breakdown

`costBreakdown()` แยกค่าทุก dimension เพื่อ debug/แสดงผล (แต่ **total = walkCost** เสมอ ใน v3)

```js
return {
  floor,   // (จำนวนชั้น-1) × w.floor        ← ส่วน vertical ของ walkCost
  side,    // (จำนวนฝั่ง-1) × w.side
  pos,     // (globalMax-globalMin)² × w.pos
  bed,     // Σ bed waste × w.bed
  pref,    // +100 ต่อห้องที่ผิด preference
  walk,    // = walkCost (Σ pairwise × w.walk + floor penalty)
  total: walk   // ← v3: total = walk เพราะเป็น final judge
};
```

> การ์ดผลลัพธ์ใน UI แสดง `[f50 s8 p75 b0 🚶w127]` = breakdown แยก (เก็บไว้ดู info), ส่วน **Cost หลัก = walk**

---

## 📊 สรุป: ทำไมถึงออกแบบแบบนี้?

| การออกแบบ | เหตุผล |
|-----------|--------|
| **2 ชั้น cost** (costFunction + walkCost) | costFunction = guide การค้นหา, walkCost = เกณฑ์ตัดสินจริง |
| **Σ pairwise ใน walkCost** | จับ scatter ของกลุ่มทั้งหมด ไม่ใช่แค่ขอบเขต |
| **รัน 4 algorithm แล้วเทียบ** | แต่ละ algorithm เก่งเรื่องต่างกัน → ครอบคลุมทุกสถานการณ์ |
| **A กรอง ok เท่านั้น** | A ที่ไม่ contiguous จริง ไม่มีความหมาย → ตัดออก |
| **Booking priority 5 ขั้น** | booking ที่จัดยาก (Suite/Twin/X09) ต้องได้สิทธิ์ก่อน |
| **X09 pin ก่อน** | Deluxe 3-bed หายาก → จองก่อน ไม่งั้นถูก Deluxe ทั่วไปแย่ง |
| **Global position 1D** | ทำให้วัดระยะข้ามฝั่งได้สม่ำเสมอ ไม่สับสน |
| **posSpread ยกกำลังสอง** | ลงโทษ scatter หนัก กระตุ้นให้ cluster แน่น |
| **bruteComboFeasible formula** | กัน combinatorial explosion แต่ยังเจอ global min เมื่อ n เล็ก |

---

> 📁 อ้างอิงโค้ดทั้งหมด: `docs/room-algorithm-playground-eav3.html`
>
> ✍️ เอกสารนี้อธิบาย flow การ assign ห้องของ algorithm test ตัวนี้เท่านั้น — ไม่ครอบคลุมส่วนอื่นของโปรเจกต์ค่ะ
