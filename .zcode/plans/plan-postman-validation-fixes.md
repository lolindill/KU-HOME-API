# Plan: Validate & Fix KU HOME Postman Collection (handoff for executing agent)

> Source session: 2026-08-20 · Branch `agust-11` · Scope: `postman/` only — no app code, no migrations, no DB schema changes.

## Context — validation already done (do NOT re-explore)

Static cross-check of `postman/KU_HOME_API.postman_collection.json` (Postman v2.1.0, 70 requests, 11 folders) against `routes/api.php` is **PASSED**:

- All 70 requests map to real routes (incl. batch `PUT /bookings/{id}/rooms`, `images.file` signed URL, webhook expecting 410). No dead endpoints (`addon-rates` / `cleaning-tasks` absent — good).
- Auth matrix correct (noauth public / `{{token}}` / `{{admin_token}}` / `{{housekeeping_token}}`), `Accept: application/json` on all requests except slip-image (correctly `image/*` per the documented `RequireJsonAccept` exemption on `GET /images/{id}/file`).
- Every request has test scripts with status-code + envelope assertions and env-var chaining (`booking_id`, `task_id`, tokens, etc.).
- Collection-level pre-request script auto-fills `check_in_date` (tomorrow) / `check_out_date` (+3 days).

**3 blockers for a live Newman run** → fix list in Task 1.

Environment facts (verified 2026-08-20): Windows / Git Bash · PHP 8.3.30 · node 22 + npm 10 · **newman NOT installed globally** (use `npx --yes newman`) · no server running on :8000 · local SQLite (`database/database.sqlite`) has all 7 UserSeeder accounts — `housekeeping@kuhome.com` exists, **`maid@kuhome.com` does NOT**.

## Task 1 — Fix blockers (collection + env files)

1. **`postman/KU_HOME_Local.postman_environment.json`**: change `housekeeping_email` value `maid@kuhome.com` → `housekeeping@kuhome.com` (password stays `password123`).
   ⚠️ **Do NOT touch `KU_HOME_Production.postman_environment.json`** — prod DB reportedly has a real `maid@kuhome.com`; changing it would break prod runs. Document the difference in README (Task 3).
2. **Slip upload asset**: create `postman/assets/sample_slip.png` (small valid PNG, e.g. 600×400, few KB — generate with PHP GD or write a base64-decoded PNG; must satisfy validation `mimes:jpeg,png,jpg` + `max:4096` KB). In the collection, request `POST Submit Payment Slip (/bookings/{bookingId}/confirm)` (folder `05. 💳 Payment & Confirmations`): set formdata `slip_image` entry `"src": "./assets/sample_slip.png"` (keep `"type": "file"`), keep description noting GUI users may pick their own file.
3. **Dynamic `transfer_time`**: same confirm request — add a request-level pre-request script:
   ```javascript
   pm.variables.set('transfer_time', new Date(Date.now() - 3600e3).toISOString());
   ```
   and change the formdata `transfer_time` value from hardcoded `2026-08-20T08:00:00Z` to `{{transfer_time}}` (validation requires `transfer_time ≤ now`; the hardcoded value will 422 when run earlier in the day than 08:00Z).

## Task 2 — Live Newman validation run

1. Start server in background from repo root:
   `php artisan serve --host=127.0.0.1 --port=8000`
   Poll until ready: `curl -s -o /dev/null -w "%{http_code}" -H "Accept: application/json" http://localhost:8000/api/v1/room-types` → expect 200.
2. Run from repo root:
   ```bash
   npx --yes newman run postman/KU_HOME_API.postman_collection.json \
        -e postman/KU_HOME_Local.postman_environment.json \
        --working-dir postman --insecure
   ```
   (`--working-dir postman` makes the formdata `./assets/sample_slip.png` src resolve.)
3. Triage failures:
   - **Collection-side** (wrong assertion, wrong body field, wrong env var) → fix collection, re-run.
   - **API-side** (genuine backend bug) → do NOT hot-fix the API; document in the final report.
4. Throttle note: `throttle:5,1` on login/booking/confirm — a single sequential pass makes only 4 login calls (under the 5/min limit). If a re-run within 1 minute trips 429s, add `--delay-request 2`.
5. Stop the server when done.

Known non-blockers to expect (assertions already tolerate them): webhook returns 410 Gone; `validate-discount` is a draft endpoint; `Create Cleaning Task` allows 201-or-422 on re-run.

## Task 3 — Docs & report

1. Update `postman/README.md`: Newman command incl. `--working-dir postman`, the slip asset note, and the Local-vs-Production `housekeeping_email` difference (`housekeeping@kuhome.com` vs `maid@kuhome.com`).
2. Final report (chat, Thai, maid persona per user AGENTS.md): static validation summary (70/70 routes OK), fixes applied, Newman pass/fail counts per folder, any API bugs found, caveats:
   - Production env untested from local (do not hit prod base_url).
   - A run leaves test data in dev SQLite (a new `somchai_<timestamp>@example.com` user + bookings/walk-in per run — by design, register body uses `{{$timestamp}}`).
   - Money fields are integer satang (e.g. `amount: 2400` = 24.00 THB).

## Gotchas for the executing agent

- Never edit `KU_HOME_Production.postman_environment.json` values (Task 1.1 is Local only).
- Postman JSON is v2.1 — edit with a JSON-aware method (parse → modify → pretty-print), don't regex-poke raw strings.
- `CheckRole` 403 body is `{"error": "Forbidden..."}` — NOT the standard `{"status":"error"}` envelope. Don't "fix" assertions to expect the standard envelope.
- Route params are UUID-constrained (36 chars) — standalone runs without prior env captures will 404 by design; the E2E folder (`11. 🔄 Automated E2E Runner Chains`) chains captures in order (folder 10 must capture `task_id` before folder 11 steps 10–11).
- Booking state machines are strict (`draft → paid → confirmed → complete`; housekeeping task `done` is terminal) — if a chained step fails, downstream steps will fail too; root-cause the first failure, not each one.
- Keep changes confined to `postman/` (+ nothing in app code, migrations, DB).
