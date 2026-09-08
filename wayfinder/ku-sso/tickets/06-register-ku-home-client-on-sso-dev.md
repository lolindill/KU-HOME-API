# จดทะเบียน KU HOME client บน sso-dev.ku.ac.th (realm KU-Alllogin)

- **label:** `wayfinder:task`
- **type:** HITL (owner/KU IT เป็นผู้ทำ)
- **status:** closed *(2026-09-08 — live-verify กับ sso-dev จริงผ่าน subagent session)*
- **blocked-by:** —
- **assignee:** (ว่าง)

## Question

งานเตรียมที่ไม่มีอะไรต้องตัดสินใจ แต่ implementation ทดสอบกับ endpoint จริงต้องรอสิ่งนี้:

- สร้าง **confidential client** ใน realm `KU-Alllogin` (client_id + client_secret)
- ตั้ง redirect URI = route ของ React app (`ku-home`) — ไม่ใช่ route ของ API
- ขอ test account สำหรับทดสอบ login บน sso-dev
- (ถ้าทำได้) ถาม KU IT เรื่อง scope ที่ client นี้ได้รับ — โดยเฉพาะการได้ `email` claim จาก userinfo (`basic` scope มีจริงแต่ claims ยัง unverified — ดู ticket 04)
- 📌 เพิ่มเติมจาก research (ticket 04): เปิด **PKCE S256** ถ้าเลือกใช้ · SPA ต้องส่ง `code_challenge` ตอน redirect · ระวัง code อายุ ~60 วิ · ~~ให้ KU IT register `post_logout_redirect_uri` ด้วย~~ **ไม่ต้องแล้วใน v1** — END_SESSION ถูก defer โดย [ticket 03](./03-sso-token-and-session-contract.md) (ปิด 2026-09-07) · ถ้า KU IT ยินดี register ไว้เลยก็ดีเผื่ออนาคต แต่ไม่ใช่ข้อบังคับ

**ผลลัพธ์ที่ต้องได้:** client_id/client_secret ใส่ `.env` (ชื่อ vars ตกลงตาม ticket 04/05) + รายชื่อ redirect URIs ที่อนุมัติ + บัญชีทดสอบ

## 🆕 ขั้นตอนจริง (จากคู่มือ OCS — ดู [../research/ku-playground-manual-notes.md](../research/ku-playground-manual-notes.md), 2026-09-07)

1. **ก่อนกรอกฟอร์ม:** ต้องได้ **REDIRECT_URI** (route ของ React, ต้อง https) จากทีม frontend ก่อน — และถ้าฟอร์มถาม LOGOUT_REDIRECT_URI ให้ใส่ไปพร้อมกันเลย (เผื่อ END_SESSION อนาคต แม้ v1 defer แล้ว)
2. กรอกฟอร์มขอใช้บริการ **https://kasets.art/AdDecy** ด้วยบัญชี `@ku.th` → เจ้าหน้าที่ตอบกลับทางอีเมล **ภายใน 3 วันทำการ**
3. ได้รับ **CLIENT_ID / CLIENT_SECRET / USER_SCOPE** (OCS กำหนดให้) → นำมาใส่ `.env`
4. **Test account ได้จากคู่มือเลย** (นิสิต/อาจารย์/บุคลากร อย่างละ 3 — credentials อยู่ในไฟล์ต้นฉบับ **ห้าม commit ลง repo**) — ข้อนี้ถือว่าปิดแล้ว
5. PKCE RFC 7636 รองรับชัดเจนตามคู่มือ · endpoints ยืนยันตรงกับ research แล้ว · ทดสอบได้ 1 ปี · สอบถาม: admin@ku.ac.th

## 📊 ความคืบหน้า (2026-09-07 — playground credentials จาก OCS มาถึงแล้ว)

- ✅ **ฟอร์มผ่านแล้ว** — OCS ตอบกลับ: scope = **`basic openid`** · REDIRECT_URI = **`http://localhost:8000/*`** · LOGOUT_REDIRECT_URI = **`http://localhost:8000/*`** (wildcard ทั้งคู่)
- ✅ Endpoints ที่ได้มา (auth/token/userinfo/end_session) ตรงกับ [../research/keycloak-mechanics.md](../research/keycloak-mechanics.md) ทุกตัว
- ✅ บล็อก `KU_SSO_*` ลง `.env` แล้ว (BASE_URL / SCOPE / REDIRECT_URI / LOGOUT_REDIRECT_URI ครบ)
- ⏳ **เหลือ: `CLIENT_ID` / `CLIENT_SECRET` ตัวจริง** — ค่าที่ส่งมายังว่าง รอค่าจากอีเมล OCS แล้วเติมแล้วถือว่าข้อ "ผลลัพธ์ที่ต้องได้" ครบ
- ⚠️ REDIRECT_URI ที่อนุมัติเป็น **API dev server (`localhost:8000`, http) — ไม่ใช่ React** (frontend ยังไม่ตัดสิน route) — พอสำหรับ manual test เพราะ `code` จะติดมาใน URL bar แม้ page 404 · production ต้องขอ OCS เปลี่ยนเป็น route https ของ React (ค้างอยู่ใน "Not yet specified" ข้อ 2 ของ [map](../map.md))
- END_SESSION endpoint + LOGOUT_REDIRECT_URI ที่ OCS ให้มา = เผื่ออนาคตตามหมายเหตุข้อ 5 ด้านบน — **ยังไม่ทำใน v1 ตาม decision ของ [ticket 03](./03-sso-token-and-session-contract.md)**

## ✅ Resolution (2026-09-08 — live-verify session)

ของที่ต้องได้ตาม "ผลลัพธ์ที่ต้องได้" ครบทั้ง 3 และ verify กับ endpoint จริงแล้ว:

- ✅ **CLIENT_ID / CLIENT_SECRET ตัวจริงมาถึงแล้ว** (อีเมล OCS) — ลง `.env` เรียบร้อย (ค่าลับอยู่ใน `.env` เท่านั้น ห้าม commit)
- ✅ **redirect_uri ที่ลงทะเบียน (wildcard `http://localhost:8000/*`) ใช้ได้จริง** — ทดสอบด้วย path ชัด `http://localhost:8000/sso-callback` (อยู่ใน wildcard) แล้ว Keycloak ยอม redirect กลับ URI นี้ (กระทั่งกรณี error) = พิสูจน์ว่า client ลงทะเบียนจริง
- ✅ **Test accounts ครบ** ตามคู่มือ OCS (นิสิต/อาจารย์/บุคลากร อย่างละ 3 — credentials ไม่ลง repo ตามกติกา)
- ✅ **auth request พร้อม PKCE → HTTP 200 หน้า login จริง** — title "Sign in to KU-Alllogin" + form username/password ไม่มี error → realm + client_id ถูกต้อง, ไม่มี WAF/TLS block, latency ~0.04s

**❗ Discovery สำคัญ — PKCE S256 ถูก ENFORCE ฝั่ง server:**

- auth request **ไม่มี** `code_challenge` + `code_challenge_method=S256` → Keycloak **302 กลับ redirect_uri ทันที** พร้อม `error=invalid_request&error_description=Missing parameter: code_challenge_method` — **ขัดคำคาดเดิมของ [ticket 04](./04-keycloak-mechanics-for-ku-alllogin.md)** ที่ว่า "คงไม่ enforce กับ confidential client" (แก้ไขแล้วใน ticket 04)
- **ผลกระทบต่อ spec:** หลักการเดิม "state/PKCE เป็นหน้าที่ฝั่ง SPA — backend ไม่ยุ่ง" (comment ใน `SsoController` / `config/ku_sso.php`) ยังยืนได้ แต่ **`POST /auth/sso/exchange` ต้องรับ `code_verifier` จาก SPA แล้ว relay ไป token endpoint ด้วย** — ไม่งั้น exchange ตายที่ `invalid_grant` ทุกครั้ง (amendment ผูกกับ contract ของ [ticket 03](./03-sso-token-and-session-contract.md) — **ยังไม่ได้แก้โค้ด ณ วันที่จด**)
- การทดสอบเชิงลึก (login จริงด้วย test account + ตรวจ claims `email`/ชื่อจาก userinfo — ยัง [UNVERIFIED] ตาม ticket 04) รอแก้ exchange รองรับ `code_verifier` ก่อน แล้ว manual test ตาม [ticket 07](./07-sso-test-strategy.md)
- หมายเหตุกระบวนการ: browser automation ใช้ใน subagent ไม่ได้ (`Browser is not available in subagent`) — หลักฐานรอบนี้มาจาก curl ตรง endpoint; ขั้น login จริงใน browser ต้องทำใน main session หรือมือเปล่า
