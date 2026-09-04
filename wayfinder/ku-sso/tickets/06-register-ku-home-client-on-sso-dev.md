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
- 📌 เพิ่มเติมจาก research (ticket 04): ให้ KU IT **register `post_logout_redirect_uri`** ด้วย (Keycloak ≥18 บังคับ) และเปิด **PKCE S256** ถ้าเลือกใช้ · SPA ต้องส่ง `code_challenge` ตอน redirect · ระวัง code อายุ ~60 วิ

**ผลลัพธ์ที่ต้องได้:** client_id/client_secret ใส่ `.env` (ชื่อ vars ตกลงตาม ticket 04/05) + รายชื่อ redirect URIs ที่อนุมัติ + บัญชีทดสอบ
