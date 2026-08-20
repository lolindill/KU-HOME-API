# 🏨 KU HOME API — Postman Guide

This directory contains the complete **Postman Collection (v2.1.0)** and **Environment Files** for testing and developing against the **KU HOME API** hotel management backend (Laravel 13, Sanctum 4, PHP 8.3+).

---

## 📂 Included Files

| File | Description |
|------|-------------|
| [`KU_HOME_API.postman_collection.json`](./KU_HOME_API.postman_collection.json) | Complete Postman collection containing 73 requests across 12 modules + E2E Runner chain + Auto Cleanup. |
| [`KU_HOME_Local.postman_environment.json`](./KU_HOME_Local.postman_environment.json) | Environment config for local development (`http://localhost:8000/api/v1`). |
| [`KU_HOME_Production.postman_environment.json`](./KU_HOME_Production.postman_environment.json) | Environment config for staging/production (`https://ku-home.ku.ac.th/backend/api/v1`). |

---

## 🚀 Quick Start (Importing into Postman)

1. Open **Postman**.
2. Click **Import** (top left).
3. Select and import:
   - `KU_HOME_API.postman_collection.json`
   - `KU_HOME_Local.postman_environment.json`
   - `KU_HOME_Production.postman_environment.json`
4. In the top-right environment dropdown, select **"KU HOME API - Local Environment"** (or Production).

---

## 🔁 Re-runnability Guarantee

The test collection is engineered with full **idempotency and state isolation**, guaranteeing **0 failed assertions across consecutive Newman runs against the SAME SQLite database** without requiring `php artisan migrate:fresh --seed` between runs:

1. **Dual-Path Housekeeping State Coverage (Folder 10):**
   - Resolves real, distinct unassigned housekeeping tasks dynamically (`{{task_assign_id}}` and `{{task_accept_id}}`) rather than reusing terminal `done` tasks.
   - Genuinely exercises both the Admin assignment path (`PUT .../assign` -> 200 OK) and Maid acceptance path (`POST .../accept` -> 200 OK) on every run.
2. **Independent Slip Verification & Rejection Flows (Folder 05):**
   - Verifies the primary booking slip with strict 200 assertions (`paid → confirmed`).
   - Dynamically provisions a dedicated draft booking and slip submission (`{{reject_booking_id}}` & `{{reject_confirmation_id}}`) using an isolated guest user token, strictly exercising the reject flow (`PUT .../reject` -> 200 OK) without collision or rate limit exhaustion.
3. **Automated End-of-Run Housekeeping Cleanup (Folder 12):**
   - Automatically closes all residual unassigned tasks (`assign → done`) at the end of every run, ensuring clean initial state for subsequent test runs.
4. **Rate Limit Throttling Isolation:**
   - Multi-token strategy (`user_token`, `admin_token`, `housekeeping_token`, `guest_token`, `registered_user_token`) ensures operations stay well within Laravel's `throttle:5,1` rate limits per user bucket.

---

## 🔑 Authentication & Token Management

The collection is pre-configured with **Sanctum Bearer Token** authentication.

### Default Seeded Users
- **Admin:** `admin@kuhome.com` / `password123`
- **User (Member):** `user@kuhome.com` / `password123`
- **Guest:** `guest@kuhome.com` / `password123`
- **Housekeeping (Local / Dev):** `housekeeping@kuhome.com` / `password123` *(Seeded via `UserSeeder`)*
- **Housekeeping (Production / Staging):** `maid@kuhome.com` / `password123` *(Configured in `KU_HOME_Production.postman_environment.json`)*

### Slip Upload Asset
- A sample payment slip image is located at [`postman/assets/sample_slip.png`](./assets/sample_slip.png).
- Used automatically by `POST Submit Payment Slip` in folder `05` when Newman runs with `--working-dir postman`. GUI Postman users can also select custom PNG/JPEG slips from file picker.

### Dynamic Environment Variables
| Variable | Description |
|----------|-------------|
| `{{admin_token}}` | Bearer token for `admin@kuhome.com` |
| `{{user_token}}` | Bearer token for `user@kuhome.com` |
| `{{housekeeping_token}}` | Bearer token for `housekeeping@kuhome.com` (or `maid@kuhome.com`) |
| `{{guest_token}}` | Dedicated token for `guest@kuhome.com` used in isolated reject flow |
| `{{registered_user_token}}` | Token captured from registration test in Folder 01 |
| `{{room_id}}` / `{{room_id_2}}` | Primary and secondary physical room UUIDs captured from room listings |
| `{{booking_id}}` / `{{booking_room_id}}` | Primary test booking and booking room UUIDs |
| `{{confirmation_id}}` | Payment confirmation UUID for verify test flow |
| `{{reject_booking_id}}` / `{{reject_confirmation_id}}` | Dedicated booking and confirmation UUIDs for reject test flow |
| `{{task_assign_id}}` / `{{task_accept_id}}` | Housekeeping task UUIDs for admin assign and maid accept tests |

---

## 📋 Collection Structure (12 Folders)

1. **`01. 🔑 Authentication & Profile`** (7 requests)
   - Register member, login (Admin/Housekeeping/User), profile fetch (`/me`), profile update (`/profile`), and logout (`/logout`).
2. **`02. 🏨 Rooms & Availability (Public)`** (10 requests)
   - List rooms (captures `room_id` and `room_id_2`), room status overview, room details, room types, availability lookup, per-day calendar, and sold-out range scanners.
3. **`03. 💰 Rates & Global Pricing`** (4 requests)
   - Global rate list (daily rates + addons), single rate details, admin rate update, and toggle active status.
4. **`04. 📅 Bookings & Room Management`** (9 requests)
   - Create booking (draft), list bookings, booking details, add rooms, update single room, batch update rooms, remove room, delete draft booking, and validate discount code.
5. **`05. 💳 Payment & Confirmations (Slip Flow)`** (8 requests)
   - Submit payment slip (multipart), list pending confirmations, admin verify slip (strict 200), submit payment slip for reject, admin reject slip (strict 200), view slip image via signed URL, demo payment request, and deprecated webhook (strict 410 Gone).
6. **`06. 🗂️ Booking State Machine & Audit`** (3 requests)
   - Admin manual booking status transitions, auto-assign physical rooms (cluster algorithm), and status transition audit logs (`status_change_logs`).
7. **`07. 🛎️ Front Desk Operations`** (5 requests)
   - Walk-in booking + instant check-in, manual front payment recording, guest check-in, guest check-out (triggers housekeeping task), and no-show mark.
8. **`08. 👥 User Management`** (6 requests)
   - Admin user CRUD, pagination, and user verification toggle (`ver: true/false`).
9. **`09. 🛏️ Room Management`** (1 request)
   - Admin room status transition (`available`, `maintenance`, `reserved_closed`, etc.).
10. **`10. 🧹 Housekeeping Dashboard`** (7 requests)
    - List all tasks (smoke test), manual task creation, list unassigned tasks (admin target resolution), admin assign task to maid (strict 200), list unassigned tasks (maid), maid accept task (strict 200), and maid complete task (strict 200 -> room returned to `available`).
11. **`11. 🔄 Automated E2E Runner Chains`** (12 requests)
    - Sequential full-lifecycle test chain: Admin Login → Room Type Fetch → Draft Booking → Payment Record → Admin Confirm → Room Auto-Assign → Check-In → Check-Out → Maid Accept → Maid Done → State Audit Verification.
12. **`12. 🧹 Run Cleanup (Auto)`** (1 request)
    - Queries active unassigned housekeeping tasks and systematically closes them to ensure clean database state for subsequent runs.

---

## ⚡ Running Automated Tests with Newman CLI

Start local server:
```bash
php artisan serve --host=127.0.0.1 --port=8000
```

Execute the full collection via Newman:
```bash
npx --yes newman run postman/KU_HOME_API.postman_collection.json \
  -e postman/KU_HOME_Local.postman_environment.json \
  --working-dir postman \
  --delay-request 200 \
  --insecure
```

Execute only the Automated E2E Chain (Folder 11):
```bash
npx --yes newman run postman/KU_HOME_API.postman_collection.json \
  -e postman/KU_HOME_Local.postman_environment.json \
  --working-dir postman \
  --folder "11. 🔄 Automated E2E Runner Chains" \
  --insecure
```

---

## 🛡️ Important Backend Rules & Constraints

1. **Mandatory JSON Accept Header:**
   - All `/api/*` endpoints strictly require `Accept: application/json` (enforced via `RequireJsonAccept` middleware). If missing or set to `text/html`, the API responds with `406 Not Acceptable`.
   - *Exception:* `GET /images/{id}/file` uses Signed URLs and accepts `image/*`.
2. **State Machine Transitions:**
   - **Booking Container:** `draft → paid → confirmed → complete`
   - **Booking Room:** `draft → confirmed → checked_in → checked_out` (+ `no_show`)
   - **Room:** `available ⇄ occupied ⇄ checkout_makeup ⇄ available` (also supports `maintenance`, `prep_checkin`, `reserved_closed`)
   - **Housekeeping Task:** `unassigned → accepted → in_progress → done`
3. **Draft Editable Window:**
   - A booking and its rooms can only be edited or removed while both parent booking and booking room are in `draft` status.
4. **Rate Limiting:**
   - `throttle:5,1` on login, booking creation, room modifications, and payment confirmation.
   - `throttle:10,1` on lookups and image streaming.
