# Keycloak mechanics ของ realm KU-Alllogin (confidential client)

- **label:** `wayfinder:research`
- **type:** AFK
- **status:** closed *(2026-09-01 — research subagent)*
- **blocked-by:** —
- **assignee:** (ว่าง)

## Question

ข้อเท็จจริงที่ decision อื่นรออยู่:

- Discovery document จริงของ realm (`https://sso-dev.ku.ac.th/realms/KU-Alllogin/.well-known/openid-configuration`) — endpoints จริง: authorization / token / userinfo / end_session
- PKCE จำเป็น/แนะนำแค่ไหนสำหรับ confidential client (SPA redirect + backend exchange)
- Token endpoint exchange: parameters ที่ต้องส่ง, response fields, code ใช้ได้ครั้งเดียว + อายุเท่าไร
- Userinfo: scope ไหนคืน `email` จริง (KU มี scope แบบ `basic`/`openid` — non-standard ต้องเช็คจริง), มี claims อะไร (`sub`, `preferred_username`, `given_name`, `family_name`)
- END_SESSION: parameters (`id_token_hint`, `post_logout_redirect_uri` — ต้อง register ไหม)
- Error shapes จาก Keycloak (`invalid_grant` ฯลฯ) เพื่อออกแบบ mapping error code ฝั่งเรา

## Resolution (2026-09-01)

ดูรายงานเต็ม: [../research/keycloak-mechanics.md](../research/keycloak-mechanics.md)

- **Discovery document จริง verify แล้ว [VERIFIED-LIVE]** — HTTP 200 จาก `https://sso-dev.ku.ac.th/realms/KU-Alllogin/.well-known/openid-configuration` (Keycloak Quarkus ≥17) — endpoints ครบ 4 + issuer = `https://sso-dev.ku.ac.th/realms/KU-Alllogin`
- **`basic` scope มีจริง** ใน `scopes_supported` (คู่กับ openid/email/profile) แต่คืน claim อะไร **ยัง [UNVERIFIED]** ต้องทดสอบเมื่อมี client + test account (ผูกกับ ticket 06)
- Token endpoint รับ `client_secret_post` (form fields) · **code ใช้ได้ครั้งเดียว + อายุ ~60 วิ** → exchange ต้องเร็วและห้าม retry เมื่อ `invalid_grant`
- PKCE S256 รองรับแต่คงไม่ enforce กับ confidential client — SPA ควรส่ง `code_challenge` ไปเลย
- Error จริง: `invalid_client` = **401** (ตรวจก่อน code), `unsupported_grant_type` = 400, userinfo ไม่มี token = **401 empty body** → ฝั่งเราเช็ค status code อย่างเดียว
- **END_SESSION เป็นหน้า HTML interactive** → logout ฝั่ง KU ต้องเป็น browser redirect จาก SPA + ต้องมี `id_token_hint` + `post_logout_redirect_uri` ต้อง register ใน client
