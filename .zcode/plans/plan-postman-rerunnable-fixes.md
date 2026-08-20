# Plan: Make KU HOME Postman Suite Re-runnable (handoff for executing agent — e.g. Gemini)

> Follow-up to `plan-postman-validation-fixes.md` (Task 1 of that plan is DONE and verified).
> Source session: 2026-08-20 · Branch `agust-11` · Scope: `postman/` only (+ README inside postman/).

## Mission

The collection currently passes **only on lucky DB state**. An independent re-run produced **4 failures** (folder 10). Fix the collection so that:

> **Acceptance criterion: 3 consecutive Newman runs against the SAME local SQLite DB (no `migrate:fresh` between runs) all report 0 failed assertions, AND the assign/accept happy paths are genuinely exercised each run.**

## Verified code facts (do NOT re-explore — these are the root causes)

State machine (`app/Models/HousekeepingTask.php::transitionStatus`):
- `unassigned → accepted → in_progress → done`; `done` is terminal (any transition out throws 422).
- **Same-state transition returns `false` silently** (no exception) → endpoint still responds 200 → a test can "pass" as a no-op.
- `assignTask` (admin) transitions `unassigned → accepted` directly, setting `assigned_to` from body.
- `acceptTask` (admin/housekeeping) transitions `unassigned → accepted` with the auth user.

Ordering & guards:
- `GET /dashboard/tasks` orders `created_at ASC` → `tasks[0]` = **oldest** task (long `done`) — the current `task_id` fallback is guaranteed-broken.
- `GET /dashboard/tasks/unassigned` filters `status=unassigned`, ASC — this is the correct source for chaining.
- `POST /dashboard/tasks` 422s if the room already has an active task (`unassigned|accepted|in_progress`) — S-B2 duplicate guard.
- Folder 07's `POST /front-desk/{id}/check-out` auto-creates a housekeeping task ("Guest checked out normally…") that the collection never closes → **unassigned residue accumulates every run**.
- `GET /rooms` has no ORDER BY → `rooms[0]` is non-deterministic on SQLite (varies per run).
- `POST /bookings/{id}/confirm` 422s if a pending confirmation already exists for the booking; `verify` and `reject` on the same confirmation conflict (2nd review 422s).
- Laravel log evidence (storage/logs/laravel.log): `Create housekeeping task failed: ห้องนี้มีงาน…`, `Assign task failed: Invalid task status transition from 'done' to 'accepted'`, same for `Accept task failed` — timestamps 2026-08-20 03:16:33–35 (the failing re-run).

Existing verified-good pieces (keep them):
- Local env `housekeeping@kuhome.com` ✅ · Production env UNTOUCHED (`maid@kuhome.com`) — do not edit it now either.
- `postman/assets/sample_slip.png` wired via formdata `src: ./assets/sample_slip.png` ✅
- Dynamic `{{transfer_time}}` pre-request script ✅
- Folder 11 E2E chain self-recovers (passed even in the failing run) — do not redesign it, just re-verify.
- Newman invocation (from repo root): `npx --yes newman run postman/KU_HOME_API.postman_collection.json -e postman/KU_HOME_Local.postman_environment.json --working-dir postman --insecure --delay-request 200`
- Server: `php artisan serve --host=127.0.0.1 --port=8000`

## Task 1 — Folder 10 (`10. 🧹 Housekeeping Dashboard`): real dual-path coverage

1. **Stop capturing `task_id` from the all-tasks list.** "GET List All Tasks" becomes a listing smoke test only (assert 200 + envelope). Remove `pm.environment.set("task_id", jsonData.tasks[0].task_id)`.
2. **Add env vars** to `KU_HOME_Local.postman_environment.json` (and mirror in the collection variable list): `room_id_2`, `task_assign_id`, `task_accept_id`. Capture `room_id_2` in folder 02's "GET List All Rooms" test script (`rooms[1].id`; if list has 1 entry, fall back to `rooms[0].id`).
3. **Create task (existing request)**: keep body `{room_id: {{room_id}}, task_type: "daily", notes: …}`. On 201 → capture `task_assign_id`. On 422 (duplicate-active guard) → do NOT fail; the next step resolves the target.
4. **New request after create: `GET /dashboard/tasks/unassigned` with admin token** (route is admin-or-housekeeping). In its test script:
   - `task_accept_id` = first unassigned task whose id ≠ `task_assign_id`.
   - If `task_assign_id` is still empty (create 422'd) → assign target = another distinct unassigned task ≠ `task_accept_id`.
   - If not enough unassigned tasks exist → `pm.sendRequest` POST create a task on `{{room_id_2}}` and use it (retry loop not needed; a single attempt + clear `pm.test` failure message is enough if even that 422s).
   - Existing "GET List Unassigned Tasks (Housekeeping)" request stays later in the folder with `{{housekeeping_token}}`.
5. **`PUT …/tasks/{{task_assign_id}}/assign`**: STRICT assertions again — 200 only; assert `status:"success"` AND response `task.status === "accepted"`.
6. **`POST …/tasks/{{task_accept_id}}/accept`** (housekeeping token): STRICT — 200 only; assert `task.status === "accepted"` and `task.assigned_to` equals the housekeeping user id if exposed. This now tests the real maid-accepts-unassigned path (distinct task ⇒ no silent no-op).
7. **`PATCH …/tasks/{{task_accept_id}}/status`** body `{"status":"done"}`: STRICT 200; assert `task.status === "done"`.
8. Folder 11 keeps using its own `task_id` chaining — verify it still captures from the E2E check-out task, don't redesign.

## Task 2 — Folder 05: give `reject` its own confirmation

Current flaw: `verify` consumes the only `confirmation_id`, so `PUT …/reject` always 422s (tolerated) — happy path never tested.

1. Add env var `reject_booking_id`.
2. After the verify request, add a pre-request script (or a new hidden step) that creates a **second draft booking** via `pm.sendRequest` (reuse the pattern already used by "DELETE Delete Entire Draft Booking": POST `/bookings` with `{{token}}`, one room, `{{check_in_date}}`/`{{check_out_date}}`) → capture `reject_booking_id`. (This adds 1 more request to Newman's count — expected.)
3. Add a new request `POST Submit Payment Slip (for Reject)` → `{{reject_booking_id}}/confirm` with the same formdata (`./assets/sample_slip.png`, `{{transfer_time}}`) → capture `reject_confirmation_id` and refresh `slip_image_url` from THIS response.
4. `PUT …/booking-confirmations/{{reject_confirmation_id}}/reject`: STRICT — 200 only; assert `status:"success"` and the documented reject payload (e.g. booking returns to `paid`/`draft` per `BookingConfirmationController@reject` — read it and assert the actual field).
5. Keep `PUT …/verify` on the original `confirmation_id` STRICT 200.
6. `POST Request Payment (Admin - Demo)` may stay tolerant `[200,201,400,422]` (demo endpoint, state-dependent) — but add a `console.log` of the actual code so runs are auditable.

## Task 3 — New final folder `12. 🧹 Run Cleanup (Auto)` — the reproducibility guarantee

Best-effort cleanup at the end of every run (script-driven via `pm.sendRequest`, non-fatal):

1. Admin `GET /dashboard/tasks/unassigned` + `GET /dashboard/tasks?status=accepted` (+ `in_progress`):
   - For each active task: `PUT …/tasks/{id}/assign` body `{"assigned_to": "{{current_user_id}}"}` (any existing user id passes `exists:users,id`) — moves `unassigned → accepted`; then `PATCH …/status` `{"status":"done"}` — closes it. For tasks already `accepted`/`in_progress`: PATCH `done` directly (valid transition).
   - This closes folder 07's checkout residue AND folder 10's leftover assign-target, so the next run starts with zero active tasks ⇒ `createTask` never 422s on residue ⇒ deterministic chaining.
2. Assertions: soft only (e.g. `pm.test("cleanup executed", …)` on script completion, not per-call status). Cleanup must never turn a green run red; log leftovers with `console.log`.
3. Note in README: the cleanup folder intentionally closes ALL active housekeeping tasks on the dev DB — fine for local dev, do not run the collection against prod.

## Task 4 — Prove it (validation the agent must run itself)

1. Ensure server up (`php artisan serve --host=127.0.0.1 --port=8000`, poll `GET /api/v1/room-types` until 200).
2. Run the Newman command above **3 times back-to-back against the same DB** — all 3 must show `failed: 0`. If a run fails, fix and restart the 3-run streak.
3. Confirm in run output that assign & accept each returned 200 with `task.status` assertions passing (not skipped).
4. `php artisan test` → still 256 passed, 553 assertions.
5. Stop the server.

## Task 5 — Report & docs

1. Update `postman/README.md`: re-runnability guarantee, the cleanup folder behavior, new env vars (`room_id_2`, `task_assign_id`, `task_accept_id`, `reject_booking_id`, `reject_confirmation_id`), updated expected request count (~74–76 depending on final structure).
2. Final report must include: 3-run Newman summaries, proof of strict-path assertions, files changed. **Any change outside `postman/` must be explicitly declared** (note: `test_scripts/test_draft_ops_remote.php` was modified in the previous session — user cleanup additions; keep it, but it must be listed and committed knowingly).

## Guardrails

- Edit the collection JSON via a parse-modify-serialize script (node or php), never regex on raw strings.
- Do NOT touch `KU_HOME_Production.postman_environment.json` values.
- Do NOT weaken the re-tightened strict assertions back to `[200,422]`-style tolerances — that's how the suite lied the first time.
- No app-code changes: if an API bug is discovered, report it, don't fix it here.
- Known accepted tolerances that stay: webhook 410, `Create Cleaning Task` 201-or-422 (with fallback), `Request Payment` demo permissiveness, `Validate Discount` draft endpoint.
