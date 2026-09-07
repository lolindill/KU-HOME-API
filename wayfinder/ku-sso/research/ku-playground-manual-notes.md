# โน้ตจากคู่มือ KU ALL-Login Playground (OCS ม.เกษตรศาสตร์)

> แหล่งที่มา: คู่มือฉบับเจ้าหน้าที่ `SSO_Programer_Manual_DEV.docx` (5 ก.ค. 2566) ที่ KU IT ให้มา — **ไฟล์ต้นฉบับ/extract เก็บไว้นอก repo เท่านั้น เพราะมีรหัสผ่าน test account ฝังอยู่ ห้าม commit**
> วันที่จด: 2026-09-07

## ขั้นตอนขอใช้บริการ (ticket 06)

1. กรอกแบบฟอร์มขอใช้บริการออนไลน์ **https://kasets.art/AdDecy** ด้วยบัญชี `@ku.th`
2. เจ้าหน้าที่ตรวจสอบ + ตอบกลับทางอีเมล **ภายใน 3 วันทำการ** (อีเมลที่กรอกบนฟอร์ม)
3. ได้รับ **CLIENT_ID / CLIENT_SECRET / USER_SCOPE** — OCS เป็นผู้กำหนดให้ทั้งหมด
4. ค่าที่ผู้พัฒนาต้องกำหนดเอง: **REDIRECT_URI** (ต้องเป็น https) และ **LOGOUT_REDIRECT_URI** (ต้องเป็น https)

## Endpoints (ยืนยันตรงกับ research/keycloak-mechanics.md แล้ว)

- AUTHORIZATION: `https://sso-dev.ku.ac.th/realms/KU-Alllogin/protocol/openid-connect/auth`
- TOKEN: `.../openid-connect/token` · USER_INFO: `.../openid-connect/userinfo` · END_SESSION: `.../openid-connect/logout`

## คุณสมบัติ/เงื่อนไข Playground

- รองรับ OAuth 2.0 + **PKCE (RFC 7636)** ชัดเจน — คู่มือแนะนำให้ใช้กัน CSRF
- รองรับ LOGOUT_REDIRECT_URI (redirect กลับหลัง logout ฝั่ง KU)
- เว็บทดสอบรองรับ http และ https — แต่คำอธิบาย REDIRECT_URI ระบุ "ต้องเป็น https" (ถ้ามี dev domain https ใช้อันนั้น)
- ระยะเวลาทดสอบ **1 ปี** · เงื่อนไข: ระบบต้องสนับสนุนการเรียนการสอนของมหาวิทยาลัย (KU HOME เข้าเกณฑ์)
- **Test accounts มีให้ในคู่มือเลย** — นิสิต 3 / อาจารย์ 3 / บุคลากร 3 (username+password ในไฟล์ต้นฉบับ ไม่ commit ลง repo)
- ตัวอย่าง library: PHP (PKCE + logout redirect, PHP ≥ 5.3.3) `https://oauth2-php-ocs.ku.ac.th/KUoAuth2-dev/KUoAuth2.zip` · Next.js + NextAuth `https://git-ocs.ku.ac.th/ku/alllogin/example-nextjs` · สอบถาม: admin@ku.ac.th

## ⚠️ Attribute Values ของ scope `basic` (ข้อเท็จจริงที่กระทบ ticket 02)

- ตาราง attribute ของคู่มือ**ไม่มี claim `email` ตรง ๆ** — ตัวที่เป็นอีเมล:
  - `mail` = KU Mail รวม alias (**เฉพาะบุคลากร**)
  - `google-mail` = KU-Google เช่น `maharat.t@ku.th`
  - `office365-mail` = KU-Office เช่น `maharat.t@live.ku.th`
- ชื่อ claim **ไม่ตาม OIDC standard**: `thainame`, `first-name`, `last-name`, `cn` (ชื่อเต็ม EN), `givenname`, `surname`, `thaiprename` — ไม่ใช่ `given_name`/`family_name`/`name`
- มี attribute จำแนกกลุ่ม: **`type-person`** (1=teacher, 2=staff, 3=student, 4=alumni, 5=guest, 6=emailfac, 7=kol, 8=nondegree + 10x โรงเรียนสาธิต), `jobtype`, `position`, `campus`, `faculty-id`, `idcode` (รหัสนิสิต), `degree`, `uid` (login id เดิม, อายุ 3 ปี), `userprincipalname` (login id ใหม่)
- ⚠️ ยังเป็นข้อมูลจากเอกสาร — ต้อง live-verify ด้วย test account หลังได้ client (ticket 06) ก่อนปรับ resolution ของ ticket 02 จริง
