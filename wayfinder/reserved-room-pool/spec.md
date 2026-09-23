---
label: ready-for-agent
title: "Admin include_reserved — sell/see reserved rooms (reserved_closed) via availability, booking, allocator, walk-in"
status: open
assignee:
blocked-by:
---

# Spec: Admin `include_reserved` — put the reserved-room pool to work

> Source: grill-me session (7 resolved questions), 2026-09-11 + codebase audit of room-status machine, availability endpoints, booking capacity checks, RoomAllocator, walk-in.
> Core rule (user-confirmed): **`maintenance` is excluded from every pool in every case — the flag extends only `reserved_closed`.**

## Problem Statement

The hotel keeps some rooms as ห้องสำรอง (reserved rooms, status `reserved_closed`). Today an admin has **no API path** to actually use them: every availability endpoint excludes them from the counts, the room allocator refuses to assign them, and walk-in rejects them at the status guard. When the sellable pool is full, the only workaround is a manual status flip before each step — and the admin cannot even *see* how many reserved rooms could be mobilized, because no endpoint reports them.

While auditing this, a second user-facing problem surfaced: the booking capacity checks (create / add-room / edit-room) count **all physical rooms** of a room type as capacity — including maintenance and reserved rooms — while the availability display and the allocator only count truly sellable rooms. A user can therefore book past real sellable capacity and end up with a booking that can never receive a room number.

## Solution

One **admin-only, request-scoped parameter** — `include_reserved` — accepted by the availability endpoints, the booking create/edit capacity checks, the auto room-assignment endpoint, and walk-in:

- When an **admin** sends it, pools count and acceptors accept `reserved_closed` rooms (never `maintenance`), and availability responses replace `available_rooms` with the extended-pool number plus a transparent `sellable_rooms` / `reserved_rooms` breakdown.
- When **anyone else** sends it, it is silently ignored — the response is the normal user payload.
- When **nobody** sends it, every response stays byte-identical to today.
- As part of the same change, booking capacity denominators are aligned to the sellable pool (the bug fix), so a booking can no longer be accepted past the rooms that can actually be assigned.

No schema change, no migration, no change to the room state machine.

## User Stories

1. As an admin, I want availability counts that include reserved rooms when I ask, so that I know the true mobilizable capacity when the hotel is nearly full.
2. As an admin, I want a transparent breakdown of sellable vs reserved rooms in availability responses, so that I can explain the numbers to management at a glance.
3. As an admin, I want to create a booking whose capacity check counts reserved rooms, so that I can accommodate VIPs or overflow without manually flipping room status first.
4. As an admin, I want auto room assignment to consider reserved rooms when I pass the flag, so that bookings I created with the flag still get room numbers when the sellable pool is exhausted.
5. As an admin, I want walk-in to accept a reserved room when I pass the flag, so that front desk can seat a guest immediately in an emergency.
6. As an admin, I want the flag to never pull maintenance rooms, so that a broken room is never given to a guest no matter what.
7. As an admin, I want consistent flag behavior across all availability endpoints, so that the admin dashboard can use any of them interchangeably.
8. As an admin editing a draft booking (adding or changing a room), I want the flag respected in those capacity checks too, so that I do not hit a capacity wall mid-edit after creating the booking with the flag.
9. As an admin, I want the flag to be request-scoped, so that I control each call explicitly and no hidden persistent state changes later behavior behind my back.
10. As an admin, I want the room's physical status to remain `reserved_closed` after it is assigned to a booking, so that physical room state stays under explicit human control (pending the lifecycle decision).
11. As a regular user, I want availability to keep showing only sellable rooms, so that what I see is always bookable by me.
12. As a regular user, I want an accidental `include_reserved` parameter to be silently ignored, so that shared links or buggy params never show me numbers I cannot book or error my flow.
13. As a regular user, I want the booking capacity check to count only truly sellable rooms, so that my accepted booking can always be assigned a room (bug fix).
14. As an anonymous visitor, I want the public availability endpoints unchanged when I don't send the flag, so that the existing frontend keeps working byte-for-byte.
15. As a KU member booking normally, I want pricing and discount flows untouched by this flag, so that member rates keep working exactly as before.
16. As a frontend developer, I want the king-size counters to honor the same flag, so that king-room numbers stay consistent with the overall pool numbers.
17. As a frontend developer, I want the flag request's response to record that it was applied, so that I can verify in transit which pool the numbers came from.
18. As a staff member auditing status logs, I want this feature to reuse the existing assignment path, so that no new state-changing bypass appears outside the allocator.
19. As a developer, I want the room state machine untouched, so that existing status transitions and their tests remain authoritative.
20. As a developer, I want regression tests locking the aligned capacity denominator, so that the overbooking-past-sellable bug can never silently return.
21. As a developer, I want tests proving non-admin flag requests return the exact same payload as no-flag requests, so that the "silently ignore" contract is enforced.
22. As an operations person, I want the walk-in runbook documented for reserved rooms (flip first, or pass the flag), so that front desk knows both paths.

## Implementation Decisions

- **Param contract:** `include_reserved`, boolean, request-scoped (query on GET endpoints, body field on POST/PUT). Effective **only** when the authenticated user's role is `admin`, resolved in-controller — the availability routes are public and have no role middleware to lean on. Non-admin and anonymous requests: flag silently ignored (never a 403 — user decision #3).
- **Surfaces touched (5):**
  1. The three availability endpoints (summary, per-day calendar, ranges) — pool counts include `reserved_closed` under the flag, plus transparent fields.
  2. King-size counters inside the summary endpoint — `king_total_rooms` follows the same extended-pool rule so king numbers stay consistent with the total (precedent: the `bed_type=king_size` param work).
  3. Booking capacity checks in create-booking, add-rooms, and update-room — denominator aligned to the sellable pool by default (bug fix), extended to sellable + reserved under the flag.
  4. Auto room-assignment — the allocator entry point gains an optional `include_reserved` boolean (default `false`) threaded into the room-pool loading and the booking-priority sellable filter; the admin-only assign-rooms endpoint passes the flag through.
  5. Walk-in — the room status guard accepts `reserved_closed` additionally when the flag is present (the route is already admin-only; the in-controller admin check still applies for symmetry).
- **Response shape under the flag (user decision #4):** `available_rooms` is **replaced** by the extended-pool number (sellable + reserved − booked); new transparent fields `sellable_rooms` and `reserved_rooms` expose the breakdown; search criteria records that the flag applied. Without the flag, responses are byte-identical to today.
- **Maintenance is absolute:** `maintenance` is excluded from every pool on every surface in every case — flag or no flag, admin or not.
- **No persistence (user decision #2):** no new column, no migration; the flag lives and dies with each request. If an admin creates a booking with the flag but later calls assign-rooms without it, the allocator may fail when only reserved rooms remain — accepted as fail-safe direction.
- **No state-machine change (user decision #6):** a room assigned to a booking keeps status `reserved_closed`. `reserved_closed → available` is already a legal transition for the manual flip before check-in; `reserved_closed → occupied` is illegal (locked by the existing room state tests). The auto-flip-vs-manual question is deferred to a separate wayfinder ticket (`90-reserved-room-checkin-lifecycle`).
- **Conventions preserved:** all booking-side capacity work stays inside the existing transactional checks; assignment happens only via the allocator; the dead `assignAvailableRoom()` model method is left alone (out of scope).

## Testing Decisions

- **What makes a good test here:** assert external behavior only — HTTP status, response JSON shape and numbers, booking acceptance/rejection, resulting room assignments — never internal query shapes or private helpers. The "silently ignore" contract is behavioral: a non-admin flag response must equal the no-flag response byte-for-byte.
- **Single seam (user-confirmed):** HTTP feature tests against the real endpoints on the in-memory SQLite test DB. The allocator is exercised indirectly through the assign-rooms endpoint.
- **Prior art to follow:** the king-size availability feature test (availability shape, king counters, byte-identical payload without the param — the closest sibling), the booking feature tests (capacity/ownership flows, walk-in included), the front-desk feature tests (status guards), and the route-protection tests (role gating patterns).
- **Required coverage:**
  - admin flag: availability numbers include reserved + breakdown fields, on all three endpoints; king counters follow.
  - non-admin + anonymous: flag ignored, payload equals the no-flag payload.
  - regression (bug fix): a user cannot book past sellable capacity even though physical rooms exist; admin with flag can reach sellable + reserved but never maintenance.
  - booking lifecycle: admin creates with flag → assign-rooms with flag assigns a `reserved_closed` room; assign-rooms without flag fails when only reserved rooms remain.
  - walk-in: into `reserved_closed` with flag succeeds; without flag rejected; into `maintenance` always rejected.
  - no-flag responses byte-identical (guarded by the existing king test style).

## Out of Scope

- **Reserved-room lifecycle at check-in** — auto-flip vs manual flip; tracked in ticket `90-reserved-room-checkin-lifecycle` (user decision #6: "mark this in a ticket").
- Exposing `maintenance` rooms to any pool, ever.
- Persisting the flag on the bookings table / any schema change.
- A separate admin-only availability endpoint (the flag on existing endpoints won).
- Frontend changes, pricing/discount logic, and the room state machine.
- Removing the dead `assignAvailableRoom()` model method.

## Further Notes

- **Grilled decisions (2026-09-11):** (1) scope = view + actually bookable; (2) request-scoped param, no persistence; (3) non-admin → silently ignore; (4) replace `available_rooms` + transparent fields, user payload unchanged; (5) align booking denominators to sellable pool in the same change; (6) no auto-flip — lifecycle deferred to a ticket; (7) walk-in included in scope.
- **Audit findings feeding this spec:** the room status machine already has `reserved_closed` (wildcard transitions in/out) and all display/allocator pools already exclude it — the "user sees only bookable, non-maintenance rooms" behavior predates this feature; the gap was admin access and the capacity denominator mismatch (booking checks count physical rooms while display/allocator count sellable).
- Assignment in production flows through exactly one path today (the admin assign-rooms endpoint → allocator); the model-level `assignAvailableRoom()` is dead code — the flag therefore only needs to reach the allocator and the capacity checks.
- Money and discount logic are untouched; the existing integer-baht wire convention is unaffected.
