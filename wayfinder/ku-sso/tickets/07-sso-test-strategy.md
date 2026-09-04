# กลยุทธ์ทดสอบ SSO โดยไม่มี browser

- **label:** `wayfinder:grilling`
- **type:** HITL
- **status:** open
- **blocked-by:** — *(ปลดบล็อก 2026-09-01: ticket 04 ปิดแล้ว — error shapes/params จริงสำหรับ fake อยู่ใน [../research/keycloak-mechanics.md](../research/keycloak-mechanics.md))*
- **assignee:** (ว่าง)

## Question

- PHPUnit feature tests ใช้ `Http::fake` จำลอง Keycloak (token endpoint + userinfo) — contract ที่ fake ต้องครอบ case ไหนบ้าง (สำเร็จ, code หมดอายุ, userinfo ไม่มี email, Keycloak 500)
- ต้องมี remote integration script ยิง sso-dev จริงไหม (pattern `test_scripts/test_*_remote.php` + env `KUHOME_*`) — หรือ fake พอ?
- ทดสอบ END_SESSION/logout 2 ชั้นยังไงโดยไม่มี browser session จริง
- ทดสอบ user-linkage (email ชนกัน, สร้างใหม่) ที่ระดับ feature test ได้เลยไหม
