# Research: Data-coverage audit — 15 รายงาน vs DB ปัจจุบัน

- **Ticket:** [`tickets/02-data-coverage-audit.md`](../tickets/02-data-coverage-audit.md)
- **Date:** 2026-09-22
- **Status:** RESOLVED — สรุป: **1 ✅ / 11 ⚠️ / 3 ❌** — gap 12 กลุ่ม (graduate เป็น tickets 08–11 แล้ว)
- **Scope checked (read-only):** `docs/report_docAndSample/report-templates/*.json` 15 ไฟล์ · `database/migrations/` 44 ไฟล์ · `app/Models/` · `docs/database-er.md` · `docs/api_guide.md` · `cline.md` — nothing modified

---

## 1. Schema facts used throughout (evidence base)

Final schema (after all 44 migrations):

| Table | Key columns | Migration(s) |
|---|---|---|
| `users` | name, email, role, is_ku_member, nationality | `0001_01_01_000000_create_users_table.php` |
| `room_types` | name_en, name_th, description, max_guests, extra_bed_enabled, max_extra_beds, extra_bed_price (rate_daily_general dropped → `global_rates`) | `2026_03_24_064200_create_room_types_table.php`, `2026_07_22_100100_move_room_rate_to_global_rates.php`, `2026_07_24_100000_add_description_to_room_types_table.php` |
| `rooms` | room_number, status (`available\|occupied\|checkout_makeup\|dirty\|prep_checkin\|maintenance\|reserved_closed`), builtin_extra_beds, floor/side/pos, bed_type (`twin\|king_size`), status_updated_at/by | `2026_03_24_064200_create_rooms_table.php`, `2026_07_13_105530_add_topology_to_rooms_table.php`, `2026_05_22_065759_standardize_room_statuses_to_lowercase.php` |
| `bookings` | confirmation (unique), user_id (nullable), source (`online\|admin\|line`), total_amount, is_paid, payment_deadline, status (`draft→pending→paid→confirmed→complete`, + `verify_error`), discount_code | `2026_03_24_064203_create_bookings_table.php`, `2026_06_04_043200`, `2026_08_27_110000_create_discount_system_tables.php` |
| `booking_rooms` | booking_id, room_type_id, room_id (nullable until check-in), check_in, check_out, bed_preference, **guests JSON** (`[{title, firstName, lastName, name, nationality, is_ku_member, email, phone}]`), billing_address/comment, status (`draft→confirmed→{checked_in→checked_out \| no_show}`), room_amount, discount_amount, amount | `2026_03_24_071824_create_booking_rooms_table.php`, `2026_07_13_105531_add_bed_preference…`, `2026_08_27_110000`, `2026_09_03_100000_add_amount_to_booking_rooms_table.php` (`has_children` dropped: `2026_08_21_100000`) |
| `addons` (1:1 per BookingRoom — `BookingRoom::addon()` HasOne) | extra_bed (**single int, whole stay**), breakfast (**single int qty**), early_checkIn_price/early_hours, late_checkOut_price/late_hours, extra_bed_price, breakfast_price | `2026_03_30_031825_create_addons_table.php`, `2026_08_27_120000_add_early_late_hours_to_addons_table.php` |
| `payments` | booking_id, amount (int baht), status, reference_number, received_by — **payment_method DROPPED** (`2026_08_19_100000_drop_payment_method_columns.php`) | `2026_03_30_042015_create_payments_table.php` |
| `booking_confirmations` | booking_id, transfer_time, status (`pending\|verified\|rejected`), reviewed_by/at, review_note — **no amount, no channel**; slip lives in `images` via morphOne `slipImage()` | `2026_07_24_140000_create_booking_confirmations_table.php`, `2026_08_19_110100_drop_slip_image…`, `2026_08_19_100000` |
| `receipts` | receipt_no, booking_id, payment_id, amount, billing_name, issued_at — **FROZEN legacy** (cline.md line 62, 860–906; `Receipt::create` removed from `FrontDeskController::recordPayment`) | `2026_04_01_031459_create_receipts_table.php` |
| `housekeeping_tasks` | room_id, assigned_to, task_type (`pre_checkin\|checkout\|checkout_then_in\|daily\|monthly\|group`), status (`unassigned→accepted→in_progress→done`), notes, accepted_at, scheduled_for, completed_at (checked_out_at/updated_at dropped) | `2026_03_25_025555`, `2026_07_15_100000_refactor_housekeeping_tasks_and_add_stock_inventories.php`, `2026_07_15_200000` |
| `stock_inventories` | **only** item_name, quantity, unit, notes | `2026_07_15_100000` (section d) |
| `images` | path, disk, mime, size, original_name, uploaded_by, polymorphic imageable | `2026_08_19_110000_reshape_images_table.php` |
| `status_change_logs` | entity_type (**`booking`\|`booking_room` only — NOT room**), entity_id, from_status, to_status, role, causer_id, note | `2026_08_04_100000_create_status_change_logs_table.php` |
| `discounts`, `discount_redemptions`, `global_rates`, `booking_sequences`, `receipt_sequences`, `housekeeping_photos` | discount system; rates (addon/daily/daily_ku/group/month); counters | `2026_08_27_110000`, `2026_07_22_100000/100100`, `2026_06_04_*` |

Payment write paths that exist today (grep-verified: only two `Payment::create` sites):

- `app\Http\Controllers\Api\V1\PaymentController.php:44` — `requestPayment` (mock QR, amount = full `total_amount`, status `pending`); webhook frozen 410.
- `app\Http\Controllers\Api\V1\FrontDeskController.php:468` — `recordPayment` (admin walk-in cash, arbitrary amount, `received_by`).
- **The dominant slip flow (`BookingConfirmationController`) writes NO amount anywhere** (grep for `amount` in that controller: zero hits). `bookings.is_paid` is the only paid flag.

Grep-confirmed absences across `app/`, `database/`, `routes/`: `inter_unit`, `erp_code`, `special_request`, `early_checkin_extra`, `agency/หน่วยงาน`, `complimentary`, `vip`, `deposit/มัดจำ/50%` — zero hits.

`_index.json` output_format claims noted: all reports "ดูหน้าจอ + Export PDF/Excel (+ Print)"; daily-financial is "Export PDF/Excel เพื่อปิดยอดบัญชี"; occupancy "ดูหน้าจอ (กราฟ) + Export Excel"; room-status and manager have null metadata.

## 2. Summary counts

| Verdict | Count | Reports |
|---|---|---|
| ✅ fully derivable | **1** | housekeeping-report (v1) |
| ⚠️ partial | **11** | check-in, check-out, daily-financial, deposit, occupancy, breakfast, erp-transfer, housekeeping-v2, room-status, extra-bed, manager |
| ❌ no backing table | **3** | supplies-report\*, out-of-service-room-report, additional-charges-report |

\* supplies-report graded ⚠️-leaning-❌: `stock_inventories` exists but covers 3 of 11 fields (kept as ⚠️ because a table exists; 8 of 11 columns unbacked).

## 3. Per-report audit

### 3.1 check-in-report — ⚠️

Template: `docs\report_docAndSample\report-templates\check-in-report.json`

| Column key | Source | Status |
|---|---|---|
| booking_no | `bookings.confirmation` | ✅ |
| room_no | `booking_rooms.room_id` → `rooms.room_number` (nullable until check-in) | ✅ |
| is_inter_unit_transfer | — | **MISSING** (no marker anywhere; grep zero hits; sample row 711 "คณะวิศวกรรมศาสตร์" = TRUE) |
| guest_name | `booking_rooms.guests[0]` via `BookingRoom::getPrimaryGuestNameAttribute()` (`app\Models\BookingRoom.php:123`) | ✅ |
| room_type | `booking_rooms.room_type_id` → `room_types.name_en` | ✅ |
| checkin_date / checkout_date | `booking_rooms.check_in` / `check_out` | ✅ |
| nights | derived `check_out − check_in` | ✅ |
| full_price | `booking_rooms.amount` (net incl. addons) or `room_amount` + addons — note `amount` added `2026_09_03_100000` with "ไม่มี backfill — ยังไม่มีข้อมูลจริง" | ✅ (data-population caveat) |
| paid_amount | `SUM(payments.amount WHERE status='completed')` — **only walk-in cash writes payments**; slip-verified flow (`booking_confirmations`) stores no amount → falls back to `bookings.is_paid` boolean | ⚠️ partial |
| outstanding_amount | `total_amount − paid` — inherits paid gap | ⚠️ partial |
| special_request | — | **MISSING** (no column on `bookings`/`booking_rooms`) |
| early_checkin_extra_bed_note | derivable composite from `addons.extra_bed > 0`, `addons.early_hours > 0` (+ prices) — display string, not stored | ✅ (synthesized) |

Filters: `checkin_date` → `booking_rooms.check_in` ✅; `room_type` → `room_types.name_en` ✅.
Sections `fully_paid` / `deposit_unpaid`: computable from outstanding (with the paid gap) ✅. Summary `total_rooms`, `by_room_type`: row counts ✅.
Note: template note says inter-unit row sits in "Fully Paid" with paid=0 — needs the transfer flag to special-case.

### 3.2 check-out-report — ⚠️

Template: `check-out-report.json`. Same as check-in minus `is_inter_unit_transfer`.

All columns map identically (see 3.1). Missing: `special_request` only; `paid_amount`/`outstanding_amount` partial (slip flow has no amount). Filter `checkout_date` → `booking_rooms.check_out` (or `status_change_logs` where `to_status='checked_out'` for actual checkout date — logs exist per `BookingRoom::transitionStatus`, `app\Models\BookingRoom.php:103`) ✅. Section: single "Grand Total" ✅. Summary `by_room_type` ✅.

### 3.3 daily-financial-report — ⚠️ (heaviest ⚠️)

Template: `daily-financial-report.json`

| Column key | Source | Status |
|---|---|---|
| date / time | `payments.created_at` (cash) / `booking_confirmations.created_at` or `transfer_time` (slip) | ✅ |
| booking_no | `bookings.confirmation` | ✅ |
| room_no | `booking_rooms.room_id` (1 booking : N rooms — row expansion caveat) | ✅ |
| user_name | `bookings.user_id` → `users.name` (or primary guest) | ✅ |
| checkin_date / checkout_date / nights | `booking_rooms` dates | ✅ |
| received_amount | `payments.amount` — **slip flow stores no amount** (`booking_confirmations` has no amount column) | ⚠️ partial |
| payment_status (`เต็มจำนวน` / `มัดจำ 50%`) | — | **MISSING** (only `bookings.is_paid`; no deposit-amount concept; grep for มัดจำ/deposit/50% = zero) |
| payment_channel (`QR Code`/`เงินสด`/`บัตรเครดิต`) | — | **MISSING** — dropped by `2026_08_19_100000_drop_payment_method_columns.php` (from both `payments` and `booking_confirmations`) |
| receipt_ref | — | **MISSING** — `receipts` FROZEN legacy, never new rows (cline.md:62, 860–906); no receipt issuance in current flow |
| slip_photo | `booking_confirmations` → morphOne `slipImage()` → `images.url` signed URL (`app\Models\BookingConfirmation.php:53`, `app\Models\Image.php:58`) | ✅ |

Filters: `date` ✅; `payment_channel` — **cannot be implemented** (column dropped). Summary `total_items`/`total_amount_baht` ✅ once amounts exist.

### 3.4 deposit-report — ⚠️

Template: `deposit-report.json` (alias Outstanding Payment Report)

| Column key | Source | Status |
|---|---|---|
| booking_date | `bookings.created_at` | ✅ |
| booking_no | `bookings.confirmation` | ✅ |
| room_no | `booking_rooms.room_id` → `rooms.room_number` | ✅ |
| user_name | `users.name` via `bookings.user_id` | ✅ |
| checkin_date / checkout_date / nights | `booking_rooms` | ✅ |
| full_amount | `bookings.total_amount` | ✅ |
| outstanding_amount | `total_amount − SUM(payments completed)` — partial (slip flow gap, as 3.1) | ⚠️ partial |

Filters: `date` ✅; `guest_type` (`หน่วยงาน`/`ทั่วไป`) — **no agency concept**; closest is `users.is_ku_member`/`users.role` (`0001_01_01_000000_create_users_table.php`) — definitional mismatch, **cannot be implemented faithfully**. `register_displayed_data` "วันครบกำหนด" → `bookings.payment_deadline` ✅. Summary totals ✅ once outstanding works. Note: sample outstanding = exactly 50% of full — implies deposit-rate semantics the schema doesn't track (ties to `payment_status` gap in 3.3).

### 3.5 occupancy-report — ⚠️ (one caveat field)

Template: `occupancy-report.json`

| Column key | Source | Status |
|---|---|---|
| date | generated day series from filter | ✅ |
| total_rooms | `COUNT(rooms)` | ✅ |
| occupied_rooms | `booking_rooms` spans overlapping date (status not draft/no_show) | ✅ |
| occupied_superior/deluxe/suite | same + `room_types.name_en` | ✅ |
| vacant_rooms | derived total − occupied − OOO | ✅ |
| reserved_rooms | BRs `confirmed` not yet checked_in overlapping date | ✅ |
| out_of_service_rooms | `rooms.status IN ('maintenance','reserved_closed')` — **point-in-time only**; `status_change_logs` does not cover rooms (`Room::transitionStatusTo` in `app\Models\Room.php:69` writes no log), so historical/planned per-date closures are not reconstructable | ⚠️ partial |
| arrivals / departures | `booking_rooms.check_in = d` / `check_out = d` (actuals via status_change_logs) | ✅ |

Filter `date` ✅. Summary: per-column sums ✅ (template note itself warns the sheet's summary row is misaligned — compute sums). Notes also flag %Occupancy and ADR as to-be-added columns — ADR needs `booking_rooms.amount` (exists, pop. caveat).

### 3.6 breakfast-report — ⚠️

Template: `breakfast-report.json`

| Column key | Source | Status |
|---|---|---|
| date | report date within stay | ✅ |
| booking_no / room_no / user_name / checkin / checkout | as 3.1 | ✅ |
| qty_set_100 | — | **MISSING** — `addons.breakfast` is ONE integer quantity per booking_room (`2026_03_30_031825_create_addons_table.php:19` comment `//quality`; `docs/database-er.md` ADDONS entity); **no breakdown by price set** (100/200) |
| qty_set_200 | — | **MISSING** (same) |

Filter `date` ✅; `breakfast_type` (ชุด 100/ชุด 200) — **cannot be implemented** without set breakdown. Summary `total_set_100`/`total_set_200` inherit the gap. Only aggregate `SUM(addons.breakfast)` is possible.

### 3.7 erp-transfer-report — ⚠️ (defining fields unbacked)

Template: `erp-transfer-report.json`

| Column key | Source | Status |
|---|---|---|
| date | report date | ✅ |
| booking_no / room_no / checkin / checkout / nights | `bookings` + `booking_rooms` | ✅ |
| agency_name | — | **MISSING** — no agency/organization table or field anywhere (grep `agency|หน่วยงาน` = zero) |
| erp_code | — | **MISSING** — no ERP fields anywhere (grep zero; sample codes are campus-prefixed placeholders B/K/S…) |
| price | `booking_rooms.amount` | ✅ |
| comment | — | **MISSING** — no free-text comment field on booking/BR (`billing_comment` is tax-invoice-specific) |

Filters: `date_range` ✅; `agency_code` — **cannot be implemented**. Summary totals ✅. Template notes list target fields not yet in the table (`จำนวนวันเข้าพัก/ราคารวม/ทำเรื่องแจ้งหนี้/ยอดค้างชำระ`) — "ยอดค้างชำระ" inherits the paid-amount gap; "ทำเรื่องแจ้งหนี้" (invoice requested flag) **MISSING**. Nothing marks a booking as inter-unit transfer (see 3.1).

### 3.8 housekeeping-report (v1) — ✅

Template: `housekeeping-report.json`

| Column key | Source | Status |
|---|---|---|
| room_no | `rooms.room_number` | ✅ |
| room_status (Vacant/Occupied/Out of Order) | `rooms.status` + active BR: `available/dirty/prep_checkin→Vacant`, `occupied→Occupied`, `maintenance/reserved_closed→OOO` | ✅ (enum mapping) |
| room_status_detail (Arrival/Due In/Due Out/Vacant Dirty/…) | derived from BR dates vs report date + room status | ✅ (composed) |
| house_status (Dirty/Clean/Inspected/OOO) | `rooms.status`: `dirty→Dirty`, `available→Clean`, `prep_checkin→Inspected`, `checkout_makeup→in-progress`, `maintenance/reserved_closed→OOO` | ✅ (mapping; "Inspected" has no dedicated flag — mapped from `prep_checkin`) |
| arrival / departure / nights | current BR `check_in`/`check_out` | ✅ |
| housekeeping_note | latest `housekeeping_tasks.notes` for room (tasks created at checkout; `scheduled_for` exists) | ✅ |

Filter `date` ✅ (BR spans + `housekeeping_tasks.scheduled_for`). All inputs exist in schema.

### 3.9 housekeeping-report-v2 — ⚠️

Template: `housekeeping-report-v2.json`

| Column key | Source | Status |
|---|---|---|
| room_no / room_type / arrival / departure / nights | as 3.8 + `room_types.name_en` (sample names "Superior Twin/Kingsize, Deluxe Twin/Triple/Kingsize, Suite" = seeded `name_en` values; `rooms.bed_type` corroborates Twin/Kingsize split) | ✅ |
| room_status (🟢 IN / 🔴 OUT / 🟡 Stay / ⚫ OOO) | BR status vs date: `checked_in` on date → IN; `checked_out` on date → OUT; occupied spanning → Stay; `maintenance/reserved_closed` → OOO | ✅ (derived) |
| cleaning_check_1 / cleaning_check_2 / cleaning_check_3 | — | **MISSING** — `housekeeping_tasks` has no boolean checklist fields (`2026_03_25_025555` + `2026_07_15_100000` add only task_type/accepted_at/scheduled_for); template notes confirm sheet columns G/H/I are unnamed TRUE/FALSE checkboxes pending owner confirmation |

Filter `date` ✅.

### 3.10 room-status-report — ⚠️

Template: `room-status-report.json`

| Column key | Source | Status |
|---|---|---|
| room_no / room_type / arrival / departure / nights | as 3.9 | ✅ |
| status (OCC/OOO/VC/VD/EA/VIP) | OCC/OOO/VC/VD/EA derivable (`rooms.status` + BR spans; EA = confirmed BR with `check_in` = today; VD = `dirty`; VC = `available`) | ✅ except |
| | VIP ("Very important person / ห้องผู้บริหาร") | **MISSING** — no VIP flag on `rooms`/`bookings` (grep zero) |
| guest_names | `booking_rooms.guests` JSON array → joined names (sample shows comma-separated multiple guests) via `BookingRoom::guests` cast (`app\Models\BookingRoom.php:46`) | ✅ |
| note | — | **MISSING** — OOO reason ("ปรับปรุงห้องน้ำ", "เครื่องปรับอากาศชำรุด") has no storage: `rooms` has no note column and no maintenance log exists |

Filter `status` ✅ once legend statuses are derived. Real-time timestamp = report render time ✅.

### 3.11 extra-bed-report — ⚠️

Template: `extra-bed-report.json`

| Column key | Source | Status |
|---|---|---|
| booking_no / guest_name / arrival / departure | `bookings` + `booking_rooms.guests` | ✅ |
| room_no (nullable — "-" = unassigned) | `booking_rooms.room_id` nullable ✅ | ✅ |
| beds_by_night (type `integer_by_date`, dynamic night columns) | — | **MISSING** — `addons.extra_bed` is a single integer per booking_room for the whole stay; no per-night date dimension anywhere |

Summary `total_allocated.by_night` inherits the gap; **`total_inventory.by_night` (35 beds)** — no extra-bed fleet-size stock record (`stock_inventories` could hold a row but no semantics/derivation exists today) — **MISSING**. Filter `date_range` ✅.

### 3.12 supplies-report — ⚠️ (effectively ❌ for 8/11 columns)

Template: `supplies-report.json`

| Column key | Source | Status |
|---|---|---|
| item_name | `stock_inventories.item_name` | ✅ |
| unit | `stock_inventories.unit` | ✅ |
| remaining | `stock_inventories.quantity` (≈ current stock) | ✅ (semantic mapping) |
| item_code | — | **MISSING** |
| category (Guest Amenities / Linen and Bedding) | — | **MISSING** |
| carried_over | — | **MISSING** (no period openings) |
| received | — | **MISSING** (no stock-in movements) |
| issued | — | **MISSING** (no stock-out movements) |
| reorder_point | — | **MISSING** |
| max_stock | — | **MISSING** |
| status (ปกติ/สั่งซื้อ) | — | **MISSING** (derivable only if reorder_point exists) |

Backing table `stock_inventories` (`2026_07_15_100000`, section d; `app\Models\StockInventory.php` — fillable: item_name, quantity, unit, notes only). No movement ledger, no master-item attributes. Filters `date_range` (needs movements) and `category` **cannot be implemented**.

### 3.13 out-of-service-room-report — ❌

Template: `out-of-service-room-report.json`

| Column key | Source | Status |
|---|---|---|
| report_date (วันที่แจ้งซ่อม) | — | **MISSING** |
| room_no | `rooms.room_number` | ✅ |
| work_type (ไฟฟ้า/ประปา/งานระบบ) | — | **MISSING** — `housekeeping_tasks.task_type` enum has no repair types (`pre_checkin|checkout|checkout_then_in|daily|monthly|group`) |
| repair_detail | — | **MISSING** (no room note/maintenance detail anywhere) |
| fixed_date | — | **MISSING** |
| repair_duration_days | — | **MISSING** (derivable only once report/fixed dates exist) |

No repair/maintenance-history table exists. `rooms` holds only current `status` + `status_updated_at` (`2026_03_24_064200`, `2026_07_13_105530`); `status_change_logs` intentionally covers only `booking`/`booking_room` (`app\Models\StatusChangeLog.php:16-19`), and `Room::transitionStatusTo` (`app\Models\Room.php:69`) writes no log — so even room-status history is unrecoverable. Filter `work_type` **cannot be implemented**. Summary `total_items` ✅ trivially.

### 3.14 additional-charges-report — ❌

Template: `additional-charges-report.json`

| Column key | Source | Status |
|---|---|---|
| transaction_date | — | **MISSING** |
| item_code | — | **MISSING** |
| item_name (ค่าเสียหาย… / ค่ายืม…) | — | **MISSING** |
| qty / unit | — | **MISSING** |
| price | — | **MISSING** |
| booking_no | `bookings.confirmation` | ✅ |

No charges/fines/equipment-rental table exists. `addons` is scoped to pre-booked extras only (extra_bed, breakfast, early/late check-in/out — `app\Models\Addon.php` fillable) and has no item_code/qty/unit/charge-type or damage semantics; template notes require every row bound to a booking (all sample rows have booking_no). Filters `date_range` ✅ trivially, `item_type` **cannot be implemented**. Summary `total_amount_baht` unbacked.

### 3.15 manager-report — ⚠️

Template: `manager-report.json`. Confirmed: **no `columns` key at all** — layout is `metrics(rows: 20) × periods(columns: 6)` (`layout: "metrics(rows) x periods(columns)"`), periods = Day / MTD / YTD + LY equivalents; filters `date`, `mode` (Complete/Week/Month), `date_range`.

| Metric key | Source | Status |
|---|---|---|
| total_persons | `SUM(count(booking_rooms.guests))` (`Booking::getTotalGuestsAttribute`, `app\Models\Booking.php:65`) | ✅ |
| total_rooms / rooms_occupied | `COUNT(rooms)`; BR spans | ✅ |
| confirmed | `bookings.status = 'confirmed'` | ✅ |
| provisional | — | **MISSING definitionally** — no `provisional` status; nearest is `pending`/`draft` (sheet typo "Provisisional" normalized in template) |
| unsold_rooms / unsold+prov / %Occupied / %-variants | derived arithmetic | ✅ |
| complimentary_rooms | — | **MISSING** — no complimentary flag on bookings/BRs (grep zero) |
| out_of_order_rooms / total−OOO / avail−OOO | `rooms.status IN ('maintenance','reserved_closed')` (same per-date history caveat as 3.5) | ✅ (current-day; ⚠️ for historical) |
| arrival_rooms / arrival_persons / departure_rooms / departure_persons | BR dates + guests JSON count | ✅ |
| no_show_rooms / no_show_persons | `booking_rooms.status='no_show'`; per-date timing via `status_change_logs` (`to_status='no_show'`, `created_at`) | ✅ |

Filters ✅ (dates/mode drive period windows). LY periods work as long as year-old booking data is retained (no schema issue).

## 4. Consolidated gap list (grouped by proposed storage area)

1. **Payment-transaction amount/channel/deposit semantics** (extend payments + booking_confirmations or unify into a ledger)
   - Slip-flow `received_amount` (booking_confirmations has no amount; only `transfer_time`/`status`) — evidence `2026_07_24_140000_create_booking_confirmations_table.php`, `2026_08_19_100000`
   - `payment_channel` — dropped by `2026_08_19_100000_drop_payment_method_columns.php`; blocks daily-financial filter + column
   - `payment_status` deposit marker (เต็มจำนวน vs มัดจำ 50%) and deposit-amount tracking — no concept anywhere (grep zero)
   - `receipt_ref` — receipts FROZEN (`cline.md:62,860-906`); daily-financial "ในอนาคต" field unbacked
   - Needed by: daily-financial-report (critical), check-in-report, check-out-report, deposit-report, erp-transfer-report (ยอดค้างชำระ)
2. **Inter-unit transfer marker + agency/ERP fields**
   - `is_inter_unit_transfer` flag; `agency_name`/agency table; `erp_code`; invoice-requested ("ทำเรื่องแจ้งหนี้") flag; booking-level `comment`
   - Evidence: grep `inter.?unit|erp|agency|หน่วยงาน` across app/database/routes = zero hits
   - Needed by: check-in-report, erp-transfer-report, deposit-report (`guest_type` filter)
3. **Booking-level `special_request` text** — no column on `bookings` or `booking_rooms` — needed by check-in/out-report
4. **Breakfast price-set breakdown** — `addons.breakfast` is one integer; no qty per set 100/200 — needed by breakfast-report
5. **Per-night addon quantities (extra bed by date)** — `addons.extra_bed` is single stay-level int; missing extra-bed fleet inventory constant (summary `total_inventory` = 35) — needed by extra-bed-report
6. **Supplies/inventory management tables** — `stock_inventories` lacks item_code/category/reorder_point/max_stock + any movement ledger (carried_over/received/issued) — needed by supplies-report (8/11 columns)
7. **Additional charges table (fines / equipment rental), booking-linked** — transaction_date, item_code/item_name, qty, unit, price, charge_type, booking_id — needed by additional-charges-report
8. **Room maintenance/repair log** — report_date, work_type, repair_detail, fixed_date, duration + OOO-reason note storage — needed by out-of-service-room-report (whole ❌), room-status-report (`note`), housekeeping-report v1
9. **Housekeeping cleaning checklist booleans** — `cleaning_check_1/2/3` (or N named checks) on `housekeeping_tasks` — needed by housekeeping-report-v2 (topics pending owner confirmation per template notes)
10. **Room-level flags** — VIP flag; explicit "Inspected" state — needed by room-status-report, housekeeping-report v1 (soft)
11. **Room status history** — `status_change_logs` deliberately excludes rooms; historical per-date OOO counts unrecoverable — needed by occupancy-report, manager-report, out-of-service-room-report
12. **Complimentary booking flag / `provisional` status definition** — needed by manager-report

Not gaps (confirmed derivable): nights (date arithmetic), guest names (guests JSON + accessors in `app\Models\BookingRoom.php`), slip photo (images morph, signed URL), room_type (name_en), booking_no (confirmation), bed_preference/billing fields, no_show timing (status_change_logs), ADR input (booking_rooms.amount — pop. caveat per `2026_09_03_100000` "ไม่มี backfill").
