# Research: Integration approach — hand-rolled HTTP exchange or package?

- **Ticket:** `wayfinder/ku-sso/tickets/05-integration-approach-package-or-hand-rolled.md`
- **Date:** 2026-09-01
- **Status:** RESOLVED — claim **CONFIRMED** (no package needed)
- **Scope checked (read-only):** `composer.json`, `composer.lock`, `config/sanctum.php`, `../ku-sso-sanctum-flow.json`, `routes/api.php` — nothing modified.

---

## 1. Repo facts (verified from the repo)

**`composer.json` (require):**

| Package | Constraint | Present? |
|---|---|---|
| `php` | `^8.3` | yes |
| `laravel/framework` | `^13.0` | yes |
| `laravel/sanctum` | `^4.0` | yes |
| `laravel/tinker` | `^3.0` | yes |

- **require-dev:** `fakerphp/faker`, `laravel/pail`, `laravel/pint`, `mockery/mockery`, `nunomaduro/collision`, `phpunit/phpunit ^12.5.12`.
- **OAuth/JWT/Socialite packages present:** **NONE.** No `laravel/socialite`, no `league/oauth2-client`, no `firebase/php-jwt`, no keycloak package — neither direct nor transitive (checked `composer.lock`; the only `league/*` packages are `commonmark`, `config`, `flysystem*`, `mime-type-detection`, `uri*` — unrelated to OAuth).
- **`config/sanctum.php`:** `'expiration' => null` — Sanctum bearer tokens never expire by age (matches AGENTS.md "stateless Bearer tokens" contract); `'guard' => ['web']`, token prefix empty. New SSO tokens will behave exactly like login/register tokens from `createToken('ku_home_auth_token')`.
- **`routes/api.php`:** public auth routes live outside the `auth:sanctum` group; a new `POST /api/v1/auth/sso/exchange` would be a public route with `throttle:5,1` (same treatment as login) — no routing obstacles found.
- **No `Http::fake()` usage exists in `tests/` yet** — the HTTP-client testing convention is still open, which favors the approach whose HTTP layer is natively fakeable by Laravel's `Http::fake()`.

---

## 2. Is local JWT/id_token signature verification actually unnecessary? — **YES, in this flow**

The planned backend does exactly two network calls, both to Keycloak over HTTPS:

1. `POST {TOKEN_ENDPOINT}` — `code` + `client_id` + `client_secret` (+ `redirect_uri`) → returns `access_token`, `id_token` (RFC 6749 §4.1.3 authorization-code grant).
2. `GET {USERINFO}` with `Authorization: Bearer <access_token>` → returns standard claims (`sub`, `email`, `name`, …).

**Verification chain:** the backend never *trusts a JWT it received out-of-band*. The access_token is handed straight back to Keycloak (userinfo), and Keycloak validates it server-side; a `200` response means the token was valid. The identity used for find-or-create comes from the **TLS-protected userinfo response**, not from parsing a token. There is no place in this flow where an unverified signature could be exploited — the classic JWT attacks (alg confusion, `none` algorithm, wrong-key verification) all require the client to *accept a token it did not obtain directly from the issuer*.

**Spec backing — OpenID Connect Core §3.1.3.7 (ID Token Validation), step 6:**

> "If the ID Token is received via direct communication between the Client and the Token Endpoint (which it is in this flow), the TLS server validation MAY be used in place of checking the token signature."
> — https://openid.net/specs/openid-connect-core-1_0.html (ID Token Validation), discussion: https://security.stackexchange.com/questions/273579/does-an-oidc-id-token-need-validation-in-authorization-code-flow

Keycloak's own docs describe local validation (JWKS/introspection) as the mechanism you need only when you validate tokens **without** calling back to Keycloak:
- https://www.keycloak.org/securing-apps/oidc-layers (introspection endpoint = "validate access tokens"; userinfo = bearer-protected claims endpoint)
- Keycloak forum (lightweight tokens thread): "If you don't need information from the token itself and you are just using it to call the userinfo endpoint, there's no reason to verify it" — https://forum.keycloak.org/t/should-lightweight-access-tokens-always-be-introspected/31363

**What is lost by not decoding the `id_token`?** Practically nothing security-critical for this design:

- **Claims:** everything needed to find-or-create the user (`sub`, `email`, `email_verified`, `name`, `preferred_username`) is also returned by userinfo given proper scopes. `email_verified` is **not lost** — userinfo includes it when the `email` scope is granted; it becomes a policy question (reject or flag unverified emails at find-or-create time), not a technical gap.
- **`nonce` binding:** the id_token's nonce proves *which browser request* the token answers. In our design the front channel is already protected by `state` (+ recommended PKCE) between React and Keycloak, and the back channel is protected by `client_secret` + single-use `code` over TLS — so nonce adds nothing here.
- **`id_token_hint` for logout:** still fine — `POST /api/v1/logout` revokes the Sanctum token, and the optional Keycloak `END_SESSION` redirect passes the id_token as an **opaque string**; it does not need to be parsed or verified to be relayed (https://www.keycloak.org/securing-apps/oidc-layers).
- **`at_hash`:** only meaningful if the id_token itself were the proof of the access token — we never treat it that way.

**Conditions the hand-rolled code MUST honor (the real security surface):**
1. TLS certificate verification stays **on** (never `['verify' => false]` in the Http call) — the whole argument rests on TLS.
2. `code` is single-use server-side at Keycloak; add `throttle:5,1` on the exchange endpoint (mirror login) per repo convention.
3. React owns `REDIRECT_URI` (flow JSON already notes: if the API accepted the browser callback, `RequireJsonAccept` would 406 the `Accept: text/html` request).
4. Match users on a stable claim (`sub` preferred; `email` only if KU scope actually returns it — flagged as "must test" in `../ku-sso-sanctum-flow.json`).

**Verdict:** the claim is correct — *local* JWT verification is unnecessary because no JWT is ever consumed locally as an authentication assertion.

---

## 3. Package survey

### 3.1 `laravel/socialite` + `socialiteproviders/keycloak`

| Aspect | Finding |
|---|---|
| What it does | OAuth "social login" framework: server-side redirect → provider login → callback with code → token exchange → user mapping. `socialiteproviders/keycloak` adds a Keycloak driver on top of `socialiteproviders/manager`. |
| Last release / maintenance | `laravel/socialite` **v5.30.1 (2026-08-24)**, PHP `^8.1`, illuminate `^6.0…^13.0` — actively maintained (https://packagist.org/packages/laravel/socialite). `socialiteproviders/keycloak` **5.3.0 (2023-04-10)** — last tagged release 2023, ~2.44M installs, 0 advisories; thin driver but stale cadence (https://packagist.org/packages/socialiteproviders/keycloak). |
| Fit for API-only | **Poor.** Socialite is architected around *its own* server-side redirect/callback with session state. Our redirect URI is a React route; the API only receives the code as a JSON body. You can force `stateless()->user()` to exchange a foreign code, but you fight the framework's config (base URL/scopes/redirect shape) for zero benefit. |
| Fit with issuing Sanctum afterwards | Fine (any approach ends in `createToken()`), but irrelevant to Socialite itself. |
| Testability with `Http::fake` | **No.** Socialite ships its own HTTP stack (`guzzlehttp/guzzle` is a direct dependency) and bypasses the `Http` facade; tests need `Socialite::fake()` / Guzzle mocking — a different paradigm than the rest of the suite. |
| Laravel 13 / PHP 8.3 risk | Socialite itself compatible (illuminate `^13.0`). Risk is the third-party Keycloak driver's staleness + heavy transitive deps (also pulls `firebase/php-jwt`, `league/oauth1-client`, `phpseclib/phpseclib` — for OAuth1, which we will never use). |

### 3.2 `league/oauth2-client` (generic provider)

| Aspect | Finding |
|---|---|
| What it does | Generic OAuth 2.0 client: `getAccessToken('authorization_code', [...])` + provider abstraction. No OIDC/id_token logic built in (OIDC usually via separate providers, e.g. `stevenmaguire/oauth2-keycloak`). |
| Last release / maintenance | **v2.9.0 (2025-11-25**, adds PHP 8.5 support), PHP `>=8.0 <8.6`, 136M installs, 1,340 dependents, 0 advisories — healthy and actively maintained (https://packagist.org/packages/league/oauth2-client, https://github.com/thephpleague/oauth2-client/blob/master/CHANGELOG.md). |
| Fit for API-only | **Good** — `getAccessToken('authorization_code', ['code' => …])` is exactly our step (a); no redirect assumption. Keycloak provider package maintenance is the weaker link (thin wrapper, infrequent tags). |
| Fit with Sanctum afterwards | Fine. |
| Testability with `Http::fake` | **No.** Uses PSR-18 (Guzzle by default), not the `Http` facade → tests need `MockHttpClient`/Guzzle handler mocks. |
| Laravel 13 / PHP 8.3 risk | Low (supports PHP to 8.5). But it abstracts exactly **two HTTP calls** we would otherwise write in ~15 lines. |

### 3.3 Keycloak guard packages (e.g. `robsontenorio/laravel-keycloak-guard`)

| Aspect | Finding |
|---|---|
| What it does | **A DIFFERENT problem.** Registers a Laravel auth *guard* that validates the **Keycloak access token (JWT) on every API request** (signature via `firebase/php-jwt`, expiry, resource_access roles) and loads the user — i.e., Keycloak becomes the per-request credential store. Variants: `robsontenorio/laravel-keycloak-guard` (v2.0.1, 2026-04-17, PHP `^8.2`, active — https://packagist.org/packages/robsontenorio/laravel-keycloak-guard), `leandrose/laravel-keycloak-guard` (introspection-based), `mariovalney/laravel-keycloak-web-guard` (session/web side; README itself says "needs maintainer" and points API users to keycloak-guard). |
| Fit for our flow | **Not applicable / conflicting.** Our contract (AGENTS.md "Multi-Client & Concurrency") is: `auth:sanctum` checks **only** `personal_access_tokens`; "ห้ามใช้ KU access_token ยิง API ตรง" (per `../ku-sso-sanctum-flow.json`). A Keycloak guard would either sit unused (we only need a one-time exchange) or actively undermine the documented Sanctum-only design. |
| Testability / compatibility | Would need JWT mocks or a Keycloak stub for every protected-route test; PHP `^8.2` + testbench `^10|^11` suggests recent-Laravel support, but irrelevant given the mismatch. |

---

## 4. Comparison table

| Approach | Deps added | Code surface | API-only fit (no server-side redirect) | Maintenance risk | Testability w/ `Http::fake` |
|---|---|---|---|---|---|
| **A. Hand-rolled: `Http` facade + `SsoService`** | **0** | ~1 service class (~80–120 lines: token exchange + userinfo + find-or-create) + 1 controller method + env keys | **Perfect** — endpoint just accepts `{ code }` as JSON; no redirect logic server-side | Low — protocol (RFC 6749 §4.1.3) is stable; only our own code to maintain | **Native `Http::fake()`** |
| B. `laravel/socialite` + `socialiteproviders/keycloak` | 3+ (socialite, keycloak provider + manager, guzzle, php-jwt, phpseclib, oauth1-client transitively) | Similar logic anyway + Socialite driver/config glue; redirect-flow assumptions to circumvent | Poor — built around its own redirect/callback; our callback belongs to React | Medium — main lib active, Keycloak driver stale since 2023 | No — Guzzle under the hood; needs `Socialite::fake()` |
| C. `league/oauth2-client` (+ Keycloak provider) | 1–2 | Service class still needed; provider wraps 2 HTTP calls | Good | Low–medium (client healthy; keycloak provider thin/stale) | No — PSR-18/Guzzle, needs `MockHttpClient` |
| D. Keycloak guard packages | 1 (+ firebase/php-jwt) | Guard/middleware replacing Sanctum — wrong problem | N/A — solves per-request Keycloak auth, not one-time code exchange | Active (robsontenorio v2.0.1, 2026-04), but conflicts with Sanctum-only contract | Hard — every route test needs JWT fixtures |

---

## 5. Recommendation

**Winner: Approach A — hand-rolled. `POST /api/v1/auth/sso/exchange` implemented with the Laravel `Http` facade + a dedicated `app/Services/*/Sso` service class. No package.**

The existing design note ("no package needed — Laravel HTTP client + a Service class is enough") is **confirmed by outside sources**: the flow's entire security rests on TLS + `client_secret` + single-use `code` + `state`/PKCE, and OIDC Core §3.1.3.7 explicitly permits TLS server validation in place of signature checks when the token comes directly from the token endpoint (https://openid.net/specs/openid-connect-core-1_0.html) — which is exactly our case, with the extra simplification that the id_token is never consumed locally at all (userinfo over TLS supplies the claims). Socialite and keycloak-guard packages either optimize for a redirect dance we don't perform or solve per-request token validation that our Sanctum-only contract forbids, while dragging HTTP layers (`guzzle`/PSR-18) that `Http::fake()` cannot intercept — the zero-dependency approach is also the most testable one.

### Implementation checklist derived from this research
- TLS verify stays on; no `verify => false`.
- `throttle:5,1` on the exchange route (mirror login).
- Server-side state: recommend React stores `state`/PKCE verifier in sessionStorage and the exchange endpoint optionally accepts `code_verifier`/`state` echo check (design decision → document in `cline.md` before coding, per repo protocol).
- Test with real KU realm whether scope `basic openid` returns `email`/`email_verified` via userinfo (flagged in flow JSON); decide find-or-create key (`sub` recommended, fallback `email`).
- Pass id_token opaquely as `id_token_hint` to `END_SESSION` on logout — never parse it.

### Sources
- https://openid.net/specs/openid-connect-core-1_0.html (§3.1.3.7 ID Token Validation)
- https://security.stackexchange.com/questions/273579/does-an-oidc-id-token-need-validation-in-authorization-code-flow
- https://www.keycloak.org/securing-apps/oidc-layers
- https://forum.keycloak.org/t/should-lightweight-access-tokens-always-be-introspected/31363
- https://packagist.org/packages/laravel/socialite
- https://packagist.org/packages/socialiteproviders/keycloak
- https://packagist.org/packages/league/oauth2-client
- https://github.com/thephpleague/oauth2-client/blob/master/CHANGELOG.md
- https://packagist.org/packages/robsontenorio/laravel-keycloak-guard
