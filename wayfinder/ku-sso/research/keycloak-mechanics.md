# Keycloak mechanics — realm `KU-Alllogin` (confidential client)

> Wayfinder research ticket `04-keycloak-mechanics-for-ku-alllogin.md`.
> Researched 2026-09-01.
>
> **Method note:** the live discovery document was fetched successfully with `curl`
> from the local network (HTTP 200 in ~0.37 s, TLS cert valid, no redirect).
> The `WebFetch` tool itself timed out against `sso-dev.ku.ac.th` (tool-side
> proxy issue) — everything below marked `[VERIFIED-LIVE]` came from raw
> `curl` responses against `https://sso-dev.ku.ac.th/realms/KU-Alllogin/...`,
> including deliberate no-credential error probes. No real client_id /
> client_secret was used or needed.

---

## 1. Discovery document — LIVE result

`GET https://sso-dev.ku.ac.th/realms/KU-Alllogin/.well-known/openid-configuration`
→ **HTTP 200, `application/json`** `[VERIFIED-LIVE]`

The legacy WildFly path `https://sso-dev.ku.ac.th/auth/realms/...` returns
**404** `[VERIFIED-LIVE]` — the server runs the modern Quarkus-based
distribution (Keycloak ≥ 17). Advertised DPoP support and `query.jwt`
response modes suggest a fairly recent build (likely ≥ 24). `[VERIFIED-LIVE observation; exact version UNVERIFIED-ASSUMPTION]`

### Endpoints (exact, verbatim from discovery)

| Field | Value |
|---|---|
| `issuer` | `https://sso-dev.ku.ac.th/realms/KU-Alllogin` |
| `authorization_endpoint` | `https://sso-dev.ku.ac.th/realms/KU-Alllogin/protocol/openid-connect/auth` |
| `token_endpoint` | `https://sso-dev.ku.ac.th/realms/KU-Alllogin/protocol/openid-connect/token` |
| `userinfo_endpoint` | `https://sso-dev.ku.ac.th/realms/KU-Alllogin/protocol/openid-connect/userinfo` |
| `end_session_endpoint` | `https://sso-dev.ku.ac.th/realms/KU-Alllogin/protocol/openid-connect/logout` |
| `jwks_uri` | `https://sso-dev.ku.ac.th/realms/KU-Alllogin/protocol/openid-connect/certs` |
| `introspection_endpoint` | `https://sso-dev.ku.ac.th/realms/KU-Alllogin/protocol/openid-connect/token/introspect` |
| `revocation_endpoint` | `https://sso-dev.ku.ac.th/realms/KU-Alllogin/protocol/openid-connect/revoke` |
| `device_authorization_endpoint` | `https://sso-dev.ku.ac.th/realms/KU-Alllogin/protocol/openid-connect/auth/device` |
| `check_session_iframe` | `https://sso-dev.ku.ac.th/realms/KU-Alllogin/protocol/openid-connect/login-status-iframe.html` |

All `[VERIFIED-LIVE]`.

### Capabilities advertised

- `response_types_supported` includes `code` (plus `id_token`, `token`, hybrids). `[VERIFIED-LIVE]`
- `grant_types_supported` includes `authorization_code` and `refresh_token` (also `client_credentials`, `implicit`, `password`, device code, token-exchange, CIBA). `[VERIFIED-LIVE]`
- `token_endpoint_auth_methods_supported` includes **`client_secret_post`** and **`client_secret_basic`** (also `private_key_jwt`, `client_secret_jwt`, `tls_client_auth`). So the Laravel backend can POST `client_id` + `client_secret` as form fields — no need for Basic auth header. `[VERIFIED-LIVE]`
- `code_challenge_methods_supported`: **`["plain", "S256"]`** → PKCE is supported, use `S256`. `[VERIFIED-LIVE]`
- `require_pushed_authorization_requests`: **`false`** → plain (non-PAR) authorization requests are allowed; the SPA can open the authorization URL directly. `[VERIFIED-LIVE]`
- `scopes_supported`: **`acr, microprofile-jwt, basic, openid, profile, phone, address, service_account, email, offline_access, roles, web-origins`** → **the KU-custom `basic` scope really exists**, alongside the standard `openid`, `profile`, `email`, `offline_access`, `roles`. `[VERIFIED-LIVE]`
- `claims_supported`: `iss, sub, aud, exp, iat, auth_time, name, given_name, family_name, preferred_username, email, acr, azp, nonce`. `[VERIFIED-LIVE]` (This is what the server *can* issue; what actually lands in userinfo depends on assigned client scopes — see §4.)
- `frontchannel_logout_supported: true`, `frontchannel_logout_session_supported: true`, `backchannel_logout_supported: true`, `backchannel_logout_session_supported: true`. `[VERIFIED-LIVE]`
- `authorization_response_iss_parameter_supported: true` → the authorization response will include an `iss` query parameter the SPA can additionally validate. `[VERIFIED-LIVE]`
- `response_modes_supported`: `query, fragment, form_post` (+ `*.jwt` variants). We use `query` (default for `response_type=code`). `[VERIFIED-LIVE]`
- `id_token_signing_alg_values_supported` includes `RS256`, `HS256`, `ES256`, `EdDSA`, etc. → expect **RS256** signed JWS; verify against `jwks_uri` if the API ever wants to validate the `id_token` locally (not required in the minimal flow — userinfo over TLS suffices). `[VERIFIED-LIVE]`

---

## 2. Authorization endpoint (browser redirect)

Standard OIDC authorization-code request, opened by the React SPA in the browser:

```
GET https://sso-dev.ku.ac.th/realms/KU-Alllogin/protocol/openid-connect/auth
    ?response_type=code
    &client_id=<CLIENT_ID>
    &redirect_uri=<SPA_CALLBACK_URL>
    &scope=openid%20basic        (exact scope string to test — see §4)
    &state=<random-non-reusable> [+ &nonce=<random>]
    [+ &code_challenge=<S256(verifier)>&code_challenge_method=S256]
```

- `response_type=code`, `client_id`, `redirect_uri`, `scope`, `state` are the mandatory/standard set per OIDC Core. `state` is REQUIRED in practice here — it is the only CSRF defense the SPA has before the backend gets involved. [KEYCLOAK-DOCS: https://www.keycloak.org/docs/latest/server_admin/index.html and OIDC Core https://openid.net/specs/openid-connect-core-1_0.html#AuthRequest]
- Calling the authorization endpoint with no/invalid params does **not** return a JSON error to a fetch-style client — it renders the Keycloak login **HTML page** (observed: `200`, `text/html`). `[VERIFIED-LIVE]` → the SPA must treat this step as a full browser navigation, never an AJAX call.
- `prompt_values_supported`: `none, login, consent`. `[VERIFIED-LIVE]`

### Is PKCE required or just recommended (confidential client)?

- Server-side support: **verified** (`code_challenge_methods_supported: [plain, S256]`). `[VERIFIED-LIVE]`
- Enforcement is **per-client** config in Keycloak: Clients → (client) → Advanced tab → "Proof Key for Code Exchange Code Challenge Method" — default is **off/empty**, i.e. PKCE is **recommended, not enforced by default** for confidential clients; it can be enforced per client (set to `S256`) or realm-wide via client policies. [KEYCLOAK-DOCS: https://www.keycloak.org/docs/latest/server_admin/index.html (client advanced settings / client policies); corroborated by https://developer.hashicorp.com/nomad/tutorials/archive/sso-oidc-keycloak]
- Whether the specific KU-Alllogin client has PKCE enforcement switched on: **`[UNVERIFIED-ASSUMPTION]`** — test with a real `client_id` before locking the SPA implementation. Design so the SPA *sends* `code_challenge` + `code_challenge_method=S256` regardless (harmless if optional, fatal only if enforcement expects it and it's missing).
- OAuth 2.1 / current IETF BCP recommend PKCE for **all** clients, including confidential ones. [KEYCLOAK-DOCS ecosystem: https://datatracker.ietf.org/doc/html/rfc7636]

### "Confidential client with browser redirect" — implications of this design

- The SPA builds/opens the authorization URL, but the **backend holds `client_secret`** and does the token exchange. This is a standard, legitimate confidential-client pattern; `client_id` is public information and safe to embed in the SPA config. `[KEYCLOAK-DOCS]`
- The authorization `code` transits the browser/SPA. With a confidential client this is acceptable: the code is **bound to `client_id`** and is useless at the token endpoint without the secret. Adding PKCE on top defends against code interception (network referrer, browser history, extension) — defense in depth. `[KEYCLOAK-DOCS: RFC 6749 §4.1.2, RFC 7636]`
- Because the token response never touches the browser, the SPA never sees `access_token`/`refresh_token` from KU — matches the proposed flow (`POST /api/v1/auth/sso/exchange { code }` → Sanctum token out).
- `redirect_uri` must be a route of the SPA (browser round-trip) and must be registered as a **Valid Redirect URI** on the client, else Keycloak shows an error page (HTML, not JSON) before any login happens. `[KEYCLOAK-DOCS]`

---

## 3. Token endpoint exchange (server-to-server)

```
POST https://sso-dev.ku.ac.th/realms/KU-Alllogin/protocol/openid-connect/token
Content-Type: application/x-www-form-urlencoded

grant_type=authorization_code
&code=<code from SPA>
&client_id=<CLIENT_ID>
&client_secret=<CLIENT_SECRET>     (client_secret_post — advertised [VERIFIED-LIVE])
&redirect_uri=<EXACT same string as step 2>
```

- **`redirect_uri` must be present and byte-identical** to the one used in the authorization request (scheme, host, port, path, query). This is required by RFC 6749 §4.1.3 whenever `redirect_uri` was included in the authorization request. [KEYCLOAK-DOCS: https://datatracker.ietf.org/doc/html/rfc6749#section-4.1.3] → practical rule: keep the callback URL in **one** shared config constant used by both SPA and backend; a mismatch yields `invalid_grant`-class failure (§6).
- Client auth: `client_secret_post` (fields in body) and `client_secret_basic` (HTTP Basic header) are both supported. `[VERIFIED-LIVE]`
- **Successful response** (`application/json`, `Cache-Control: no-store`) fields: `access_token`, `token_type: "Bearer"`, `expires_in` (seconds), `refresh_token` (only if refresh is enabled for the client), `id_token` (when `openid` scope was granted), plus Keycloak extras `scope`, `session_state`, `not_before`. [KEYCLOAK-DOCS: https://www.keycloak.org/docs/latest/server_admin/index.html — OIDC token endpoint; field set per RFC 6749 §4.1.4 / OIDC Core §3.1.3.3. Exact response for the KU client `[UNVERIFIED-ASSUMPTION]` until first live exchange.]
- **Authorization code is single-use.** Per RFC 6749 §4.1.2 the auth server MUST reject a code already redeemed; Keycloak enforces this — and as per §4.1.2 may also revoke tokens previously issued on that code. A replayed code → `invalid_grant` (§6). [KEYCLOAK-DOCS: https://datatracker.ietf.org/doc/html/rfc6749#section-4.1.2]
- **Code lifetime: default ≈ 60 seconds.** Keycloak bounds code validity with the realm setting **"Client login timeout"** (`Realm Settings → Tokens`), described as "the maximum time before clients must finish the Authorization Code Flow in OIDC"; default is **60 s / 1 minute**. [KEYCLOAK-DOCS: https://www.keycloak.org/docs/latest/server_admin/index.html; default value corroborated by Keycloak community https://github.com/keycloak/keycloak/discussions/19820. Exact KU realm value `[UNVERIFIED-ASSUMPTION]`.]
  → Consequence for the design: the window between "KU redirects to SPA with `?code=`" and "SPA POSTs it to `/auth/sso/exchange`" is seconds, not minutes. The exchange endpoint must exchange **immediately**, and a failure should tell the user to log in again — not retry.
- Since the Sanctum design never stores the KU `access_token` beyond the userinfo call, `refresh_token`/`offline_access` are unnecessary for v1 — do not request `offline_access` (it adds consent friction and a long-lived KU grant we'd have to manage).

---

## 4. Userinfo endpoint

```
GET https://sso-dev.ku.ac.th/realms/KU-Alllogin/protocol/openid-connect/userinfo
Authorization: Bearer <access_token>
```

- Auth method: standard **Bearer access token** header. Probed live **without** a token: **`401 Unauthorized`, empty body (`text/plain`), header `WWW-Authenticate: Bearer realm="KU-Alllogin"`**. `[VERIFIED-LIVE]`
  ⚠️ Laravel implication: check the HTTP **status code**, and do not blindly `->json()` — some failure modes return an **empty body**, others return `{"error":"invalid_token",...}`. Treat any non-200 as "re-authenticate".
- Standard OIDC scope→claim mapping: `sub` always present; `email` + `email_verified` come from the **`email`** scope; `name`, `given_name`, `family_name`, `preferred_username` come from the **`profile`** scope. [KEYCLOAK-DOCS: https://openid.net/specs/openid-connect-core-1_0.html#ScopeClaims and https://www.keycloak.org/docs/latest/server_admin/index.html]
- **KU's custom `basic` scope exists** (confirmed in `scopes_supported`). `[VERIFIED-LIVE]` **But which claims `basic` actually yields — especially whether it includes `email` — is `[UNVERIFIED-ASSUMPTION]`** and is exactly the thing the flow JSON flags for live testing ("ทดสอบ userinfo ว่า scope basic openid คืน email จริง"). Test matrix to run once credentials exist: `scope=openid`, `scope=openid basic`, `scope=openid basic profile email`, and diff the returned claims.
- Expected claim set per discovery: `sub`, `preferred_username`, `given_name`, `family_name`, `name`, `email` (subject to scopes above). `[VERIFIED-LIVE for capability / UNVERIFIED-ASSUMPTION for actual per-scope content]`
- Student/staff identifiers (student ID, personnel no., faculty): **not** listed in `claims_supported` → if KU exposes them, they'd come via the custom `basic` scope or protocol mappers configured on the client. `[UNVERIFIED-ASSUMPTION]` — do not design the `users` table around a student-ID claim before this is tested; the flow's `find-or-create by email` strategy already depends on `email` being present.
- Alternatives worth knowing: the `id_token` itself carries the same claims (no second HTTP call needed), and `POST token/introspect` (client-authenticated) is available if the API ever needs to validate a KU token. `[VERIFIED-LIVE capability]`

---

## 5. End-session (logout)

`end_session_endpoint` = `https://sso-dev.ku.ac.th/realms/KU-Alllogin/protocol/openid-connect/logout` `[VERIFIED-LIVE]`

- Parameters: `id_token_hint` (the ID token from the exchange) and `post_logout_redirect_uri`. Since **Keycloak 18+**, `post_logout_redirect_uri` is only honored when (a) a valid `id_token_hint` is provided and (b) the URI is registered on the client as a **"Valid post logout redirect URI"** (`post.logout.redirect.uris`). [KEYCLOAK-DOCS: https://www.keycloak.org/docs/latest/upgrading/index.html#new-behavior-of-the-logout-end-point] → registering the post-logout URI is a **Keycloak admin prerequisite** of the two-layer logout; if it isn't registered, the user just lands on the default Keycloak page (graceful, but breaks the UX contract).
- **Behavior without params** (probed live): bare `GET .../logout` returns **`200` `text/html`** and sets an `AUTH_SESSION_ID` cookie — i.e. it renders an interactive page (logout-confirm / login page), it is **not** an API call. `[VERIFIED-LIVE]` → conclusion: END_SESSION **must be a browser redirect from the SPA**, exactly as the flow JSON draws it (dashed "optional" arrow). The Laravel API cannot and should not call it server-side.
- Logout layering (already in the flow): revoking the Sanctum token (`delete currentAccessToken()`) is independent of the KU session; END_SESSION only ends the SSO session at KU. Skipping END_SESSION leaves the KU session alive — a subsequent redirect to the authorization endpoint may re-issue a code **without re-prompting for credentials** (SSO cookie). [KEYCLOAK-DOCS: https://www.keycloak.org/docs/latest/server_admin/index.html]
- Optional extra available: Keycloak also advertises front-channel and back-channel logout (`frontchannel_logout_supported` / `backchannel_logout_supported: true`) if a future phase wants KU-driven logouts to propagate. `[VERIFIED-LIVE capability]`

---

## 6. Error responses from the token endpoint

Shape is OAuth 2.0 standard: **JSON `{"error": "<code>", "error_description": "<human text>"}`** [KEYCLOAK-DOCS: https://datatracker.ietf.org/doc/html/rfc6749#section-5.2]. Verified live where marked:

| Situation | HTTP | `error` | `error_description` (as observed) | Status of fact |
|---|---|---|---|---|
| Wrong/missing client_id or client_secret (client auth is checked **before** the code) | **401** | `invalid_client` | `Invalid client or Invalid client credentials` | `[VERIFIED-LIVE]` |
| Bogus/expired/already-used `code` (with valid client auth) | **400** | `invalid_grant` | e.g. `Code not valid`, `Code already redeemed` etc. | `[KEYCLOAK-DOCS]` — HTTP status + error code standard; exact KU description text `[UNVERIFIED-ASSUMPTION]` |
| `redirect_uri` in token request differs from authorization request | **400** | `invalid_grant` (Keycloak treats it as code-validation failure) | "Parameter redirect_uri did not match…" | `[KEYCLOAK-DOCS/RFC 6749 §5.2 + Keycloak behavior; exact text UNVERIFIED-ASSUMPTION]` |
| Unknown `grant_type` | **400** | `unsupported_grant_type` | `Unsupported grant_type` | `[VERIFIED-LIVE]` body; HTTP 400 per RFC 6749 §5.2 |
| Missing `code` param | 400 | `invalid_request` / `invalid_grant` class | — | `[KEYCLOAK-DOCS/UNVERIFIED-ASSUMPTION]` |
| Unregistered `redirect_uri` at the **authorization** step | — | HTML error page (not JSON), before login | — | `[KEYCLOAK-DOCS]` |
| Expired/absent Bearer token at **userinfo** | **401** | body may be **empty** (or `{"error":"invalid_token",...}`) | `WWW-Authenticate: Bearer realm="KU-Alllogin"` | `[VERIFIED-LIVE]` (empty-body case) |

**Error-mapping guidance for `POST /api/v1/auth/sso/exchange`** (feeds the error-code design ticket):

- `invalid_grant` → the login attempt is **dead**. Do **not** retry the exchange (single-use code ⇒ a retry deterministically fails and can look like brute-forcing). Return 401/422 to the SPA with "please log in again", which restarts the whole browser flow with a fresh `state`. Never leak KU's `error_description` to the end user.
- `invalid_client` → our misconfiguration (`.env`), not a user error. Log it, alert, return generic 500-class message.
- `unsupported_grant_type` / malformed request → our bug; log, generic message.
- Keycloak 5xx / connection timeout → transient; **a retry is only safe if we never received a definitive (4xx) response** — since the code may or may not have been consumed, prefer surfacing "login failed, try again" (fresh flow) over blind retry for v1. `[UNVERIFIED-ASSUMPTION for KU's 5xx rate/frequency]`

**Timeout/retry numbers:** the discovery call took **0.37 s** from the local (Thai university) network; a KU-hosted token endpoint should be expected to answer in well under 1 s. Recommend Laravel `Http::timeout(10)->connectTimeout(5)` for the exchange + userinfo calls, **no automatic retry** of the code exchange, single attempt with a clear user-facing restart path. `[VERIFIED-LIVE latency sample / guidance = recommendation]`

---

## 7. TLS / hostname / network notes

- TLS certificate for `sso-dev.ku.ac.th` validates cleanly (curl exit OK, `ssl_verify_result=0`); **no HTTP→HTTPS redirect** on the discovery path; served over HTTP/1.1 keep-alive. `[VERIFIED-LIVE]`
- Resolves to `158.108.216.22` (KU address space). `[VERIFIED-LIVE]`
- Fronted by **nginx** (`Server: nginx`); sends `Strict-Transport-Security: max-age=31536000; includeSubDomains`, `X-Content-Type-Options: nosniff`, `X-Robots-Tag: none`, `Cache-Control: no-store` on token-class endpoints. `[VERIFIED-LIVE]`
- Server-side (Laravel) HTTP client must keep default TLS verification **on** — the cert is public and valid, no `verify => false` hacks needed.
- Cookie observed: `AUTH_SESSION_ID` is `Secure; HttpOnly; SameSite=None` (Path `/realms/KU-Alllogin/`) → consistent with cross-site browser redirect flows from a different-origin SPA. `[VERIFIED-LIVE]`
- The `WebFetch` research tool could not reach the host (timeout at its proxy layer) while plain curl succeeded from this machine — if a future automated test "can't connect", verify from a shell before concluding the KU server is down.

---

## Facts the other tickets depend on

- All four endpoints are **live and confirmed** at `https://sso-dev.ku.ac.th/realms/KU-Alllogin/protocol/openid-connect/{auth,token,userinfo,logout}` (issuer `https://sso-dev.ku.ac.th/realms/KU-Alllogin`); legacy `/auth/realms/...` path is 404. `[VERIFIED-LIVE]`
- Token endpoint accepts **`client_secret_post`** — Laravel can send `client_id` + `client_secret` as form fields; the SPA never needs the secret. `[VERIFIED-LIVE]`
- PKCE is **supported (`S256`) but likely not enforced** for confidential clients by default — the SPA should send `code_challenge` anyway; confirm per-client enforcement in the Keycloak admin when credentials exist. `[VERIFIED-LIVE support / UNVERIFIED enforcement]`
- The auth **code is single-use with ~60 s validity** (realm "Client login timeout" default 1 min) → the exchange endpoint must redeem immediately and must never retry on `invalid_grant`; failure path = fresh browser login. `[KEYCLOAK-DOCS]`
- Token request must repeat the **exact same `redirect_uri`** used at authorization (one shared config constant for SPA + backend). `[KEYCLOAK-DOCS/RFC]`
- Token endpoint errors are JSON `{"error","error_description"}`; client-auth failure is **401 `invalid_client`**, code failure is **400 `invalid_grant`** — map only these two, restart login on `invalid_grant`. `[VERIFIED-LIVE / KEYCLOAK-DOCS]`
- Userinfo needs `Authorization: Bearer` and can return **401 with an empty body** — validate by status code. The KU-custom **`basic` scope exists**, but whether `basic` yields `email` (vs needing standard `email`/`profile` scopes) is **unverified** — run the 3-scope test matrix before finalizing the find-or-create-by-email user mapping. `[VERIFIED-LIVE / UNVERIFIED]`
- END_SESSION is a **browser-interactive page** (bare GET returns HTML 200) — logout must be SPA redirect with `id_token_hint`, and `post_logout_redirect_uri` must be registered on the client (Keycloak ≥18 rule); Sanctum token revocation and KU session logout remain independent layers. `[VERIFIED-LIVE / KEYCLOAK-DOCS]`
