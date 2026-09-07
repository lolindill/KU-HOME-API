# จดทะเบียน KU HOME client บน sso-dev.ku.ac.th (realm KU-Alllogin)

- **label:** `wayfinder:task`
- **type:** HITL (owner/KU IT เป็นผู้ทำ)
- **status:** open
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
