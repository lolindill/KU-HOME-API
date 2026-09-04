# Contract ของ token & session หลัง SSO exchange

- **label:** `wayfinder:grilling`
- **type:** HITL
- **status:** open
- **blocked-by:** — *(ปลดบล็อก 2026-09-01: ticket 04 ปิดแล้ว — ข้อเท็จจริงที่ต้องใช้อยู่ใน [../research/keycloak-mechanics.md](../research/keycloak-mechanics.md))*
- **assignee:** (ว่าง)

## Question

- หลัง exchange สำเร็จ: ทิ้ง KU `access_token`/`refresh_token` ทิ้งเลย หรือเก็บ `refresh_token` ไว้ renew บางอย่าง? (Sanctum token ของเราไม่หมดอายุอยู่แล้ว — มีเหตุผลอะไรจะเก็บไหม)
- Sanctum token ที่ออกให้ SSO user ใช้นโยบายเดียวกับ password login (`expiration: null`, ไม่ revoke ของเดิม) ใช่ไหม?
- END_SESSION ฝั่ง Keycloak เป็น **required หรือ optional** ใน v1? (diagram วาดไว้เป็น dashed = optional)
- Response shape ของ endpoint exchange — ควร mirror login เดิม (`access_token` + `token_type`) หรือคืน `user` ด้วย?
- Error semantics: code หมดอายุ/ใช้ไปแล้ว/userinfo ล้ม → 401 หรือ 422 หรือ 502 (Keycloak ล่ม)?
