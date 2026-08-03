# AGENTS.md — KU HOME API

> Project-specific workspace instructions for ZCode agents. Read this **before** editing.
> The detailed long-form memory lives in [`cline.md`](./cline.md) — consult it for history, bug logs, and refactor notes.

## What is this?

**KU HOME API** — hotel management REST API for Kasetsart University. Handles room bookings, payments (slip-based), front-desk (check-in/out), housekeeping tasks, and room allocation (cluster algorithm). Consumed by a separate React frontend (`ku-home`).

- **Runtime:** PHP 8.3+ · **Framework:** Laravel 13 · **Auth:** Sanctum 4 (bearer token)
- **DB:** PostgreSQL (Supabase) — will migrate to org server. **Tests:** SQLite in-memory.
- **API-only** — no Blade views. Vite is minimal (frontend is a separate repo).

## Commands

```bash
composer run setup    # install deps + key + migrate + build assets
composer run dev      # concurrent: serve + queue:listen + pail logs + vite
composer run test     # config:clear + php artisan test

php artisan test                              # run full PHPUnit suite
php artisan test --filter=HousekeepingTaskTest # focused
php artisan test tests/Unit/RoomAllocator      # directory
vendor/bin/pint --dirty                        # lint changed files (Laravel Pint)
```

Manual API test scripts (run from repo root, not PHPUnit): `test_scripts/api_guide.php` (full lifecycle, recommended).

## Architecture & Layer Rules

- **🔒 GLOBAL MIDDLEWARE (เด็ดขาด / mandatory):** ทุก `/api/*` request ต้อง "ยอมรับ" JSON — บังคับด้วย `App\Http\Middleware\RequireJsonAccept` (ลงทะเบียนใน `bootstrap/app.php` ผ่าน `$middleware->api(prepend: [...])` ทำงานก่อน `throttle`/`auth:sanctum`).
  - **ถ้าไม่ส่ง Accept** → middleware **ใส่ `application/json` ให้เป็น default** แล้วปล่อยผ่าน (สุภาพ).
  - **ถ้าส่ง Accept มาแต่ไม่ยอมรับ JSON** (เช่น `text/html`, `application/xml`) → **reject ทันที** ด้วย HTTP `406 Not Acceptable` + body `{"status":"error","message":"Accept: application/json is required for all /api/* requests. 🥺"}`.
  - ยอมรับ: `application/json`, `*/*`, `application/*`, `+json` suffix (เช่น `application/vnd.api+json`), และ multiple media types ที่มี JSON/wildcard รวมอยู่.
  - **Why:** API-only project ไม่มี Blade ให้ fallback — ป้องกัน browser/crawler/spider, ป้องกัน unauthenticated scan, และ lock-in ให้ทุก response เป็น JSON สม่ำเสมอ.
  - **Do NOT:** ห้ามตรวจแค่ `Content-Type` — ต้องตรวจ `Accept`. ห้ามทำเป็น per-route middleware (ต้องเป็น global-on-API-group). ห้าม bypass ด้วย allowlist โดยไม่ document ใน `cline.md` ก่อน.
- **Controllers** all live in `app/Http/Controllers/Api/V1/`. REST routes prefixed `/api/v1/` (see `routes/api.php`).
- **No API Resources / Transformers** — models are returned directly. No `data` wrapper.
- **No Policy classes** — authorization is **`CheckRole` middleware only** (`app/Http/Middleware/CheckRole.php`), plus an in-controller `role` re-check (defense-in-depth) in sensitive methods.
- **Roles:** `user`, `guest`, `ku_member`, `staff`, `admin`, `housekeeping`, `system`.
- **Service layer** starts at `app/Services/RoomAllocator/` — pure classes invoked via `app(...)`, bound in `AppServiceProvider`.
- **Synchronous state changes** — no events/listeners for booking/payment transitions. (Broadcasting is intentionally NOT wired — see housekeeping note below.)
- **Form Requests** in `app/Http/Requests/`, named `Store{Model}Request` / `Update{Model}Request`.

## Critical Conventions (gotchas that bite)

- **PostgreSQL strict boolean typing** — PostgreSQL rejects integer `0`/`1` in a boolean column, but PDO turns a PHP bool into exactly that, so writing `true`/`false` to a boolean column throws "Datatype mismatch" (SQLite/MySQL are lenient — this only breaks in prod). The **`App\Casts\PgBoolean`** custom cast is the canonical fix: it emits `DB::raw('TRUE'/'FALSE')` on write. Always attach it to boolean columns (`is_paid`, `extra_bed_enabled`, `is_active`, `is_ku_member`, `ver`) and never write raw bools / raw `DB::raw('TRUE')` by hand.
  - 🔒 **DO NOT try to "fix" or simplify `PgBoolean` (re-litigation freeze).** We already scrutinized this and attempted alternative fixes (plain PHP `true`/`false`, string `'true'`/`'false'`, relying on Eloquent's built-in `boolean` cast) — **none of them work on PostgreSQL**. The `DB::raw('TRUE'/'FALSE')` approach inside the cast is the **final, proven solution** — leave it as-is. If you're tempted to refactor it, you are almost certainly reintroducing the bug.
- **Money is integer satang/cents** — `total_amount`, `amount` columns/casts/validation are all `integer`, never decimal.
- **UUID PKs everywhere** — most models use `HasUuids`. When asserting UUID equality in tests, cast to `(string)` first.
- **Draft/testing code** is marked with `🚧 DRAFT / TESTING` comment prefix — treat as non-production.
- **API response shape:** success `{"status":"success","message":...}` · error `{"status":"error","message":...}`. Don't leak `$e->getMessage()` on 500s — return a generic message + `Log::error()`.
- **Throttle:** `5,1` on login/booking/confirm; `10,1` on lookups.

## State Machines (do not bypass)

- **Booking (container):** `draft → paid → confirmed → complete` (no `cancelled`; expired drafts are hard-deleted by `CleanupExpiredDrafts` at 02:00). Transitions via `Booking::transitionStatus()`.
- **BookingRoom (per-room):** `draft → confirmed → checked_in → checked_out` (+ `no_show`). check_in/out + status live on **BookingRoom**, not Booking.
- **Room:** `available`, `occupied`, `checkout_makeup`, `dirty`, `prep_checkin`, `maintenance`, `reserved_closed` — all lowercase, via `Room::transitionStatusTo()`.
- **HousekeepingTask:** `unassigned → accepted → in_progress → done` (done is **terminal/locked**) — via `HousekeepingTask::transitionStatus()`. Always pass `task_id`, not `room_id`.

## Housekeeping Dashboard — WebSocket Decision

> **Does the housekeeping dashboard need WebSocket?** — **No, not yet.**

This is a **deliberately deferred decision** documented in [`house_keep_plan.md`](./house_keep_plan.md) and `cline.md`:

- **Phase A (DONE 2026-07-15):** task refactor + state machine + assign/accept + roles/routes + master stock — uses **polling**.
- **Phase B (DEFERRED):** WebSocket realtime — stack is Laravel Reverb + Echo, but **only after polling is proven insufficient**.

**Why deferred (do not re-litigate without new evidence):**
1. **Auth gap (S-B5):** Echo/Reverb private channels need `/broadcasting/auth` (web guard/session), but this is an API-only + Sanctum project → private channels return 403. Workarounds (public channel, custom auth driver, token-in-channel-name) all add complexity.
2. **YAGNI:** no evidence yet that polling is too slow for housekeeping workflow.
3. **Cost:** Reverb server + queue worker (currently `database` driver) + Echo client wiring vs. benefit.

If asked to add realtime: use a **public** `housekeeping` channel first (simplest, no auth gap), and confirm a queue worker is running before dispatching `ShouldBroadcast` events.

## Room Allocation (read before touching allocation)

`app/Services/RoomAllocator/` — the **Hybrid+ (EA)** cluster algorithm (Phase 4, 2026-07-14). Replaces first-available greedy.

- **`bed_preference` is a HARD constraint**, not a soft cost (was a bug — fixed). Filtering happens in every algorithm via `matchesBedPreference()`.
- **`walkCost` is the Final Judge** (Σ pairwise walking distance) — used to rank across algorithms.
- Weights/caps are env-tunable via `config/allocation.php`.
- Reference R&D: `docs/algo_test/room-algorithm-playground-eav3.html` + `room-algorithm-flow-explained.md`.
- `RoomAllocator` is bound in `AppServiceProvider` (must pass `Weights` value object — autowire fails on primitive `int`).

## Documents to read before sensitive edits

- [`cline.md`](./cline.md) — full history, bug index (#1–#41), refactor changelogs. **Always read the relevant section first.**
- [`house_keep_plan.md`](./house_keep_plan.md) — housekeeping decisions D1–D3 + scrutinize findings S-B1…S-B7.
- [`docs/api_guide.md`](./docs/api_guide.md) — API reference (state machines, enums, validation rules).
- [`docs/database-er.md`](./docs/database-er.md) — ER diagram.
- [`docs/project-status.md`](./docs/project-status.md) — per-module completion %.

## Frozen / Deprecated (do not extend)

- **`payments` + `receipts` tables are FROZEN (2026-07-24)** as read-only legacy. New payment flow uses `booking_confirmations` (slip → admin verify/reject). `PaymentController::webhook` returns **`410 GONE`**. `FrontDeskController::recordPayment` no longer creates receipts.
- **Webhook has NO HMAC signature verification** (blocker #4) — waiting on payment gateway decision. Do not assume it's secure.
- `Image` upload and `Discount` (`validate-discount`, only `WELCOME10`) are draft/incomplete.

## Conventions

- Code comments and some error messages are in **Thai** with emoji markers (✅ 🌟 🏨 🧹 🚧 ❄️) — match the surrounding style.
- Currency in satang (integer); dates ISO format.
- Migrations are numbered `YYYY_MM_DD_HHMMSS_*.php`; UUID PKs; atomic sequence tables (`booking_sequences`, `receipt_sequences`) for confirmation/receipt numbers.

## Migration gotchas

Several refactors require **`php artisan migrate:fresh --seed`** (not just `migrate`) because seeders changed shape (e.g., 8 → 100 rooms). Always check `cline.md` "Migration Required" notes before pulling/running.
