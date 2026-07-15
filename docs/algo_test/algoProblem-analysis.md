# Algo Problem — Analysis Summary (vs Playground Reality)

> สรุปการ review เอกสาร `algoProblem.md` (Section 1–9) **เทียบกับโค้ด playground จริง**
> ในไฟล์ `docs/room-algorithm-playground-ea.html`
> ผู้ review: **GLM-5.2 (ZCode)** · วันที่ 2026-07-10

---

## 0. TL;DR — บทสรุปรวดเร็ว

| # | ข้อค้นพบ | ระดับ |
|---|----------|:-----:|
| 1 | เอกสารเป็น **แผนล้วน** — playground ยังไม่ได้ implement อะไรเลย | 🔴 |
| 2 | ทางเลือก Nearest-stair (3.6) **พลาด STRIDE** — ใช้ 5 ทำให้ห้องกลางตึกชนกันข้ามชั้น | 🔴 |
| 3 | ตาราง 3.2 Single Serpentine — **สูตรกับเลขไม่ตรงกัน** | 🟡 |
| 4 | Gemini Tie-Breaker `±0.1` — **แก้ ambiguity ไม่ครบ** | 🟡 |
| 5 | playground มี **3 นิยามระยะ** ที่ไม่สอดคล้องกัน | 🟡 |
| 6 | ไอเดีย Graph + BFS (GPT-5.5) = ground truth แต่เป็นงาน refactor ใหญ่ | 🟢 |

---

## 1. 🚨 Gap ที่ใหญ่สุด: เอกสาร ≠ โค้ดจริง

เอกสาร Section 6 ตัดสินใจเลือก **Nearest-stair flatten** และจะ "ลบ FF ทิ้ง" แต่ตรวจโค้ด playground แล้ว **ยังไม่มีอะไรถูกแก้เลย:**

| ที่เอกสารบอกว่า "เลือก/จะทำ" | สถานะในโค้ดจริง | หลักฐาน (บรรทัดใน playground) |
|---|---|---|
| ใช้ `getFlatPos()` แทน | ❌ **ไม่มีฟังก์ชันนี้** มีแค่ `getGlobalPos` เดิม | L554–559 |
| ลบ weight แนวดิ่ง (floor term) | ❌ **ยังอยู่** `floorSpread = (distinctFloors-1) × w.floor` | L831 |
| ลบ FF (algoFloorFirst) ทิ้ง | ❌ **ยังรันอยู่** ใน `algoHybridPlus` | L1719, L1750 |
| posSpread วัดจาก flat | ❌ ยังใช้ `getGlobalPos` + ยกกำลังสอง | L841–842 |

> เอกสารเองยอมรับใน Section 7: *"ยังไม่ได้แก้โค้ดใดๆ"* — ฉะนั้น playground ตอนนี้ยังวิ่งด้วย **โมเดล 2-term เดิม** อยู่ ไม่ใช่ flatten ที่เลือกไว้

---

## 2. 🔴 Bug ที่เอกสารมองข้าม: STRIDE=5 ทำให้ชั้นทับซ้อนกัน

เอกสาร Section 3.6 ทดสอบแค่ **Suite 4 มุม** (stairDist=0 ทั้งหมด) เลยเห็นแค่ ambiguity แบบเดียว (507↔518) พอลองคำนวณ **ห้องกลางตึก** จะเจอ collision:

| ห้อง | floorIdx | globalPos | stairDist | flat (STRIDE=5) |
|------|----------|-----------|-----------|-----------------|
| 512 (F5 V1 pos6) | 0 | 6 | 5 | **5** |
| 513 (F5 V1 pos7) | 0 | 7 | 5 | **5** |
| 607 (F6 Suite pos1) | 1 | 1 | 0 | **5** |
| 618 (F6 Suite pos12) | 1 | 12 | 0 | **5** |

**512, 513, 607, 618 — flat เท่ากันหมด (5) ทั้งที่จริงๆ อยู่คนละชั้น + ห่างกัน 5 ห้อง!**

**สาเหตุ:** `stairDist` ∈ [0, 5] แต่ `STRIDE = 5` → ขอบเขตชั้นทับซ้อนกันทุกชั้น
```
F5 = [0, 5]   F6 = [5, 10]   F7 = [10, 15]   ← ทับกันที่ปลายทุกชั้น
```

**แก้:** STRIDE ต้องเป็น **6 ขึ้นไป** ถึงจะปลอด collision
```
F5 = [0, 5]   F6 = [6, 11]   F7 = [12, 17]   ← ไม่ทับกัน
```

> ⚠️ รุนแรงกว่า ambiguity แบบ Suite ที่เอกสารพูดถึง เพราะเกิดกับ **ห้องทั่วไป (Deluxe กลางตึก)** ที่เจอบ่อย ไม่ใช่แค่ Suite

---

## 3. 🟡 ทางเลือก 3.2 Single Serpentine — สูตร ≠ ตาราง

เอกสารเขียนสูตร:
```
flat = floorIdx × STRIDE + (floorIdx คี่ ? (STRIDE+1) - pos : pos)
```
607 (pos1, floorIdx=1 คี่) ควรได้ `13 + (13+1-1) = 26` แต่ตารางบอก **25**

**เลขในตารางใช้สูตร `(STRIDE - pos)` ไม่ใช่ `(STRIDE+1) - pos`** ยังดีที่ทางเลือกนี้ถูกปัดทิ้งอยู่แล้วเลยไม่กระทบ

---

## 4. 🟡 Gemini Tie-Breaker ±0.1 — แก้ไม่ครบ

Gemini เสนอเพิ่ม `directionBias = (globalPos > 6.5) ? 0.1 : -0.1` เพื่อแยกซ้าย-ขวา แต่เมื่อรวมกับ STRIDE=5 จะเกิดกรณี:

- **513** (g=7, ฝั่งขวา) → `flat = 5 + 0.1 = 5.1`
- **618** (g=12, ฝั่งขวา) → `flat = 5 + 0.1 = 5.1`

ทั้งคู่ฝั่งขวาเหมือนกัน → **bias เท่ากัน → ชนกันอยู่ดี** bias แยกซ้าย-ขวาได้ แต่แยกห้องในฝั่งเดียวกันไม่ได้

> ต้องเพิ่ม floorOffset อีกชั้นถึงจะแก้ได้จริง (หรือแก้ STRIDE ตามข้อ 2)

---

## 5. 🟡 playground มี 3 นิยามระยะที่ไม่สอดคล้องกัน

| ที่ | สูตร | ใช้ตอนไหน |
|-----|------|-----------|
| `costFunction` | `floorSpread + pos²` (2-term) | คะแนนรวม |
| `expandFromSeed` | `\|Δfloor\| + \|Δglobal\|×0.5 + side` (2-term) | Greedy/Coordinate เลือกห้องใกล้ |
| (เป้าหมาย) `getFlatPos` | `floorIdx×STRIDE + stairDist` (1-term) | ยังไม่มี |

ถ้า implement `getFlatPos` แล้วไม่เปลี่ยน `expandFromSeed` ด้วย → **"คะแนนวัดอย่าง แต่ expand เลือกอีกอย่าง"** ค่าออกมาไม่สอดคล้องกัน

> 💡 **แนะนำ:** แทนที่ทั้ง 3 ที่พร้อมกันถึงจะสอดคล้องกัน

---

## 6. 🟢 Graph + BFS = Ground Truth (แต่เป็นงานใหญ่)

GPT-5.5 เสนอให้เปลี่ยนจาก flatten 1D → **graph-based shortest-path** (ห้อง=node, ทางเดิน=Edge, บันได=Edge ข้ามชั้น)

**ข้อดี:**
- ไม่เกิด asymmetry / ambiguity เลย
- รองรับ topology จริง + ขยายไปตึกอื่นได้
- ไม่ต้องออกแบบ flatten function

**ข้อเสีย:**
- เป็น refactor ใหญ่ — แทนที่ `costFunction` + `getGlobalPos` + `expandFromSeed` ทั้งก้อน
- ต้องสร้าง adjacency list + precompute Distance Matrix
- playground ปัจจุบัน **ไม่มี graph model เลย** ต้องเริ่มจากศูนย์

---

## 7. ตารางสรุป — ทางเลือกที่ถูกต้อง

| ทางเลือก | เลขในเอกสาร | วิจารณ์ GLM | ความเห็นของหนู |
|----------|:-----------:|:-----------:|----------------|
| 3.1 Row-major | ✅ ถูก | ✅ ถูก | STRIDE ต้อง ≥ 13 ไม่ใช่ 5 |
| 3.2 Single Serpentine | ❌ สูตร≠ตาราง | ✅ ถูก | ปัดทิ้งถูกแล้ว |
| 3.3 Distance from center | ✅ ถูก | ✅ ถูก | ทำลายข้อมูลแนวนอนจริง |
| 3.4 Double Serpentine | ✅ ถูก | ✅ ถูก | งูยัง turn ที่ปลายเดียวจริง |
| 3.5 Mirror Serpentine | ✅ ถูก | ✅ ถูก | ตรงข้าม 3.2 |
| **3.6 Nearest-stair** | ✅ ถูก (แค่ Suite) | ✅ ถูก | **STRIDE=5 ผิด → ต้องเป็น ≥6** |

---

## 8. ข้อเสนอแนะถัดไป

### ระดับเร็ว (Quick Fix)
1. **แก้ STRIDE 5 → 6** ใน getFlatPos เมื่อ implement จริง → กัน collision ห้องกลางตึก
2. **wire getFlatPos เข้าทั้ง 3 ที่** (costFunction + expandFromSeed + ใหม่) พร้อมกัน

### ระดับกลาง (Mid-term)
3. เพิ่ม tie-breaker **ที่ฝั่งขวาแบบจริง** — bias ต้องฝัง floor ด้วย ไม่ใช่แค่ ±0.1 ตามฝั่ง
4. เก็บ metrics แยกใน `computeMetrics` — เพิ่ม `flatSpread` ใน breakdown

### ระดับใหญ่ (Strategic)
5. พิจารณา **Graph + BFS precompute** ถ้าอยากความถูกต้อง 100% และรองรับตึกใหม่ในอนาคต

---

## 9. หมายเหตุ
- เอกสารนี้เป็นการ review/สรุปเทียบกับ `room-algorithm-playground-ea.html` เท่านั้น
- ตัวเลขทั้งหมดคำนวณด้วยมือ ควร verify ใน playground จริงอีกครั้งหลัง implement
- หากต้องการให้หนู implement `getFlatPos` (STRIDE=6) หรือ graph model สั่งได้ทันที
