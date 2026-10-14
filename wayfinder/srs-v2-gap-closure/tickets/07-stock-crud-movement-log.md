# 07: สต๊อกครบชุด — CRUD + reorder_point + movement log 1 ปี (REQ-051/052)

- **label:** `wayfinder:task`
- **type:** AFK
- **status:** open
- **blocked-by:** (ไม่มี — frontier)
- **assignee:** (ว่าง)
- **summary:** สต๊อก CRUD /stock + reorder_point/max_stock + สถานะ derived + movement log เก็บ 1 ปี
- **phase:** 3

## Question

`stock_inventories` มีแค่ master table (item_name, quantity, unit, notes) **ไม่มี CRUD route เลย** และ `SuppliesReportData` เว้น `item_code/category/carried_over/received/issued/reorder_point/max_stock/status` เป็น null รอมาตั้งแต่ owner deferral "เอาไว้ก่อน" (2026-10-05) — SRS REQ-051 (เพิ่ม/แก้ไข/ลบ คลังสินค้า + เก็บ 1 ปีย้อนหลัง) + REQ-052 (สถานะคงเหลือแยกแถบสี) จะปิดยังไง?

**Decision ที่ล็อกแล้ว (owner 2026-10-14) — ทำเต็ม:**

- CRUD `/stock` — role: admin+staff เขียน, housekeeping อ่าน (list/show) · ลบ = จดว่าเป็น soft หรือ hard (FK movement log จะ restrict อยู่แล้วถ้า hard)
- เพิ่มคอลัมน์ `reorder_point`, `max_stock` (+ `item_code`, `category` ถ้าจะเติม Supplies report ให้เต็ม)
- **สถานะ derived** `ok` / `low` (≤ reorder_point) / `out` (≤ 0) — accessor บน model · **แถบสีให้ frontend render** (backend ส่ง status enum อย่างเดียว)
- **Movement log** (ตารางใหม่): ทุกการเพิ่ม/ลด quantity เก็บ delta + เหตุผล + ผูก `housekeeping_task_id` ได้ (nullable) · ปริมาณคงเหลือ = ผลจาก master `quantity` (movement เป็นประวัติ ไม่ใช่ source of truth) · retention **365 วัน** + scheduled cleanup ตาม precedent `CleanupStatusLogs`

**จุดแตะ:**

- Migration + model + routes + `StockController` + FormRequests
- เติม `SuppliesReportData` จากคอลัมน์/สถานะใหม่ (ถอด null ทิ้ง — ตาม spec รายงานที่เว้นไว้)
- Tests: CRUD สิทธิ์ครบ 3 role · low/out ตรงขอบ · movement ถูกเขียนทุกจุดเปลี่ยน quantity · cleanup 1 ปี
- `php artisan test` เขียว + `docs/api_guide.md` + deviation ledger อัปเดต REQ-051/052

## Resolution

(ยังว่าง)
