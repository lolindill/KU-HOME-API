---
label: completed
title: Room-type `rates` object across all room-type APIs (baht-decimal at the wire, satang storage)
status: closed
assignee: maid
blocked-by:
---

# Spec: Room-type `rates` object across all room-type APIs

> Source: grill-me session (6 resolved questions) + seed audit + frontend mock comparison, 2026-09-03
> Money policy (user-confirmed): **storage = integer satang (unchanged convention) · wire = decimal baht string on room-type endpoints**

## Problem Statement

Guests and staff cannot see trustworthy room prices anywhere in the product. The frontend
hardcodes a mock rate card (daily personnel/general, group, monthly) and even mocks room
availability, because the API no longer exposes the rates it once promised: the general daily
rate column was removed from room types (moved into the global rates table) without a
replacement field, KU-member and group/monthly rates were never modeled, and the seeded rate
values use a different money unit (baht-scale) than the booking engine actually computes with
(satang) — so a freshly seeded environment would charge 10 THB for a 1-night Superior stay.
On top of that, every consumer must know to divide by 100 to display a price, and the API
guide's examples contradict each other (baht-scale in one section, satang-scale in another).

## Solution

Expose one canonical `rates` object on **every** API response that includes a room type —
`{ daily: {general, ku_member}, group: {min_5_rooms, min_10_rooms}, monthly }` — authored and
served in **decimal baht** (2-dp strings, e.g. `"1200.00"`), derived ("cast") from the
global rates table whose storage remains **integer satang**. Reseed all room-type money
values from the frontend's real rate card (seeder authored in baht, stored as satang), so
the frontend can delete its entire mock and render prices directly with no unit conversion.

## User Stories

1. As a guest browsing the booking site, I want to see the real daily rate for each room type, so that I know the actual price before booking.
2. As a KU member, I want to see the rate that applies to university personnel, so that I know my eligible price without contacting staff.
3. As a guest planning a group trip, I want to see group rates for 5+ and 10+ rooms, so that I can estimate the group cost.
4. As a guest considering a long stay, I want to see the monthly rate, so that I can compare it against the daily price.
5. As a frontend developer, I want one `rates` object returned wherever a room type appears, so that I can delete the frontend mock rate card entirely.
6. As a frontend developer, I want rate values served as decimal baht strings, so that I render prices directly with no /100 conversion and no float artifacts.
7. As a frontend developer, I want keys named after system roles (`ku_member`), so that rate keys map 1:1 to the domain roles I already use.
8. As a frontend developer, I want snake_case keys everywhere in the rates object, so that I don't mix naming conventions in my types.
9. As a frontend developer, I want the rates object in the availability search response, so that search results show prices without extra requests.
10. As a frontend developer, I want the rates object in the per-day and sold-out calendar endpoints, so that the calendar can display prices without a second fetch.
11. As an admin, I want room rates stored in the same global rates table as every other rate type, so that I can manage all prices through the existing rates endpoints.
12. As an admin, I want a sensible starting rate set seeded for all three room types, so that a fresh environment shows real prices immediately.
13. As an admin, I want missing or inactive rate rows to fall back to zero, so that the API never invents a price for a room type.
14. As a hotel operator, I want booking totals to keep computing in integer satang from the daily (general) rate row, so that the price a guest sees matches what they are charged to the satang.
15. As a maintainer, I want the satang-storage convention untouched, so that the booking engine, discounts, and per-room amounts that already assert in satang keep passing.
16. As a maintainer, I want seeder tests asserting the exact seeded rate values, so that future seed edits are intentional and visible in CI.
17. As a maintainer, I want the API guide updated with the new rates shape on every affected endpoint, so that integrators have an accurate contract.
18. As a maintainer, I want the money policy (satang storage / baht wire) recorded in the project memory document, so that future agents don't reintroduce baht-scale storage or the old integer rate field.
19. As an API consumer, I want the response shape change documented as a breaking change of the old integer rate field, so that I can migrate before it lands.

## Implementation Decisions

- **One `rates` attribute, not three fields.** The RoomType model exposes a single appended
  `rates` attribute and drops the existing appended integer `daily_rate`. Wire shape
  (from the approved sample JSON in session; values are 2-dp baht **strings**, never floats):

  ```json
  "rates": {
    "daily":   { "general": "1000.00", "ku_member": "800.00" },
    "group":   { "min_5_rooms": "750.00", "min_10_rooms": "750.00" },
    "monthly": "15000.00"
  }
  ```

- **Money policy: satang storage, baht wire.** The database, booking math, and the global
  rates table keep integer satang (the project convention and the just-shipped per-room
  `amount` semantics are untouched). Only the room-type serialization edge converts:
  satang ÷ 100 formatted to a 2-dp string. A small shared formatting helper is introduced so
  future endpoints can adopt the same wire convention without duplicating logic.
- **Room-type money fields are consistent at the edge:** the display-only `extra_bed_price`
  column is also serialized as a baht string (e.g. `"500.00"`) on room-type-bearing
  responses, matching `rates`. Storage stays integer satang.
- **Sourced from the global rates table, never stored on room types.** Storage mapping:
  `daily.general` ← rate_type `daily` · `daily.ku_member` ← rate_type `daily_ku` (new; named
  after the existing `ku_member` role, replacing the frontend's "personnel" concept) ·
  `group.min_5_rooms` / `min_10_rooms` ← rate_type `group` rows keyed by the existing `code`
  column · `monthly` ← rate_type `month`. Missing or inactive rows fall back to `"0.00"`
  (same semantics as the existing room-rate lookup helper).
- **Eager-loadable.** New relationships mirror the existing daily-rate relationship so every
  endpoint can eager-load all rate rows (no N+1); relation objects stay hidden from JSON.
- **Endpoint coverage = all 7 room-type-bearing responses:** room-type list, room-type detail,
  availability (embedded room type), availability-per-day, availability-ranges,
  unavailable-dates, availability-ranges-all. The mock availability-ranges endpoint is
  excluded (it is deletion-planned).
- **Booking money math untouched.** Booking creation/repricing keep computing in integer
  satang from the daily (general) rate row; `ku_member`/`group`/`monthly` are display-only
  until eligibility and group pricing are separately designed. Extra-bed charging also
  unchanged (the addon rate row remains the source of truth).
- **Reseed from the real rate card.** The room-type seeder is authored in **baht decimals**
  (human-readable) and writes satang (×100) into the global rates table — five rate rows per
  room type — plus `extra_bed_price` stored in satang (0 / 50,000 / 60,000). Authored values:

  | Room type | daily general | daily ku_member | group min5 | group min10 | monthly | extra_bed_price |
  |---|---|---|---|---|---|---|
  | Superior | 1,000.00 | 800.00 | 750.00 | 750.00 | 15,000.00 | 0.00 |
  | Deluxe | 1,200.00 | 1,000.00 | 900.00 | 750.00 ⚠️ | 18,000.00 | 500.00 |
  | Suite | 1,800.00 | 1,500.00 | 1,350.00 | 1,350.00 | 27,000.00 | 600.00 |

  (baht; stored satang = ×100 · Deluxe `extra_bed_price` aligns with the 500 THB addon rate)
  ⚠️ Deluxe `min_10_rooms` intentionally follows the frontend mock (750 < min5 900 —
  suspected mock typo). Other seeders are already correct (addon rates satang; discount
  seeder is percent) and are not touched.
- **Data path: seeder + fresh seed only.** No data migration; existing environments correct
  rates through the admin rates API. The change requires `migrate:fresh --seed` on dev and
  must be recorded as "Migration Required" in the project memory document.
- **Docs updated in the same change.** The API guide's room-type examples move to the
  `rates` object in baht strings, the new rate types are documented (including the four
  calendar endpoints gaining `rates`), and the money policy is stated explicitly so the
  current baht/satang example contradiction is resolved once.

## Testing Decisions

- A good test asserts external behavior only: the HTTP JSON consumers receive (baht strings,
  exact values, zero fallback) and the values that land in the rates table after seeding
  (integer satang) — never accessor internals.
- **Two seams (user-confirmed), both existing:**
  1. **HTTP API feature seam** — requests against the seven room-type-bearing endpoints
     asserting the `rates` object presence, exact baht-string values, the embedded
     room-type variant, `extra_bed_price` as a baht string, and the zero fallback for a
     missing rate row. Prior art: the existing Room feature tests (same request/assert
     style, SQLite in-memory).
  2. **Seeder seam** — run the seeders, then assert the five global-rate rows per room type
     in **satang** via the model's rate lookups. Prior art: the existing seeder test that
     asserts global addon rate defaults.
- No separate unit seam for the accessor: it is fully covered through the HTTP seam
  (fewest-seams rule).

## Out of Scope

- Converting other money surfaces to the baht wire (booking totals, per-room `amount`,
  addons, discounts stay satang on the wire for now — a follow-up effort may extend the
  shared formatting helper to them).
- KU-member eligibility/pricing at booking time (display only for now — separate design task).
- Group/monthly rates feeding booking totals or a group-booking pricing flow.
- Changing extra-bed charging logic or the addon rate rows.
- Data migration for pre-existing environments (admin fixes rates via API instead).
- Removing the mock availability-ranges endpoint (separate deletion plan).
- Frontend repo changes (delete mocks / render baht strings) — different repository.
- Images, amenities, size fields, and frontend availability-mock fixes — not part of rates.

## Further Notes

- The old appended integer `daily_rate` is removed in favor of the `rates` object. This is a
  contract break of a documented field, deliberately taken now: no in-repo consumer reads it
  (booking math queries the rates table directly; no test asserts it) and the frontend has
  not adopted it yet. The API guide is the contract and is updated in the same change.
- Money strings vs numbers: JSON has no decimal type; 2-dp **strings** are deliberate to
  avoid float artifacts (`0.1 + 0.2`). Frontend math should parse (`parseFloat`) then format.
- Frontend adoption path: replace mock `rates` with the API object, rename `personnel` →
  `ku_member`; no unit conversion needed anymore.
- Open flag for the requester: Deluxe `group.min_10_rooms` = 750.00 follows the frontend mock
  despite being cheaper than min5 (suspected typo) — amend the seeder value before
  implementation if unintended.
