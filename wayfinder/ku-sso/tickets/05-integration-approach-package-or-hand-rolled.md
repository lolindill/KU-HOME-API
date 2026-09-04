# แนวทาง integrate: hand-rolled HTTP exchange หรือ package

- **label:** `wayfinder:research`
- **type:** AFK
- **status:** closed *(2026-09-01 — research subagent)*
- **blocked-by:** —
- **assignee:** (ว่าง)

## Question

Diagram card ยืนยันว่า "ไม่ต้องติดตั้ง package เพิ่ม — Http client + Service class พอ" — ตรวจสอบซ้ำจากมุมภายนอก (scrutinize pass):

- Hand-rolled: Laravel HTTP client เรียก token endpoint + userinfo ผ่าน HTTPS — ยืนยันว่า **ไม่จำเป็นต้อง verify JWT signature เอง** เมื่อไว้ใจ TLS + เรียก userinfo ด้วย access_token
- Package ที่ควรพิจารณา: `laravel/socialite` + `socialiteproviders/keycloak` · `league/oauth2-client` · keycloak guard packages — คุ้มไหมกับ API-only (ไม่มี server-side redirect), Laravel 13 + PHP 8.3, สถานะ maintenance, testability กับ `Http::fake`
- ข้อเสียของ package: ผูก lifecycle, config shape ไม่ตรง flow เรา (เราไม่ใช้ standard socialite redirect flow)
- สรุปเป็น recommendation 1 บรรทัด + เหตุผล

## Resolution (2026-09-01)

ดูรายงานเต็ม: [../research/integration-approach.md](../research/integration-approach.md)

**✅ Hand-rolled ชนะ — ยืนยัน claim ใน diagram card ("ไม่ต้องติดตั้ง package") ถูกต้อง**

- Repo: Laravel ^13.0 + PHP ^8.3 + Sanctum ^4.0, `expiration = null`, ไม่มี oauth/jwt/socialite package ใน composer.lock เลย
- **ไม่ต้อง verify JWT เอง** — backend ไม่ consume JWT: ส่ง access_token กลับไป userinfo บน HTTPS (อ้าง OIDC Core §3.1.3.7 อนุญาตให้ rely on TLS แทนการเช็ค signature); `email_verified` ได้จาก userinfo เหมือนกัน
- Socialite+keycloak driver: ออกแบบเพื่อ redirect flow ที่เราไม่ใช้ + driver เก่า (2023) · `league/oauth2-client`: abstract แค่ 2 HTTP calls · keycloak-guard packages: แก้ปัญหาต่างกัน (validate KC token ทุก request — ขัด Sanctum-only contract)
- **Testability ชี้ขาด:** package ใช้ Guzzle/PSR-18 ที่ `Http::fake()` ไม่ฝัง — hand-rolled ผ่าน `Http` facade ทดสอบได้น่าแท้
- เงื่อนไขติดตาม: lock TLS verify ไว้ (อย่าปิด `verify`) + `throttle:5,1` บน exchange endpoint
