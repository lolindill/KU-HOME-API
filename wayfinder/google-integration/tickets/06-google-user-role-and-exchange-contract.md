# Role ของ Google-born user + contract ของ exchange endpoint

- **label:** `wayfinder:grilling`
- **type:** HITL
- **status:** open
- **blocked-by:** —
- **assignee:** (ว่าง)

## Question

Owner sign-off 2 ข้อก่อน implementation (ข้อมูลครบจาก research 01/02 แล้ว — ไม่ต้อง research เพิ่ม):

- **Role ของ user ที่เกิดจาก Google login ครั้งแรก:** research ticket 02 แนะ **`user`** ไม่ใช่ `ku_member` — เหตุผล: Google ไม่พิสูจน์สถานะสมาชิก KU และ role `ku_member` ผูกกับส่วนลดรายวัน `daily_ku` (ต่างจาก KU SSO ที่ first login ได้ `ku_member` ตาม ku-sso ticket 08) — ยืนยันไหม
- **Contract ของ `POST /api/v1/auth/sso/google/exchange`:** ตามที่ research 02 เสนอ — body รับ `code` + `code_verifier` (+ `redirect_uri` ตรงกับที่ register หรือไม่) · response mirror ku-sso ticket 03 (`{status, message, access_token, token_type, user, id_token}`) · error map `invalid_grant`→422 · `invalid_client`→500 · `redirect_uri_mismatch`→500 · Google ล่ม→502 · **`email_verified=false`/ไม่มี email → 422 fail-closed** · default role จากข้อแรก · sign-off ให้ implementation session เขียนได้โดยไม่ถามซ้ำ

ผลตัดสินจะ append เป็น amendment ที่ ticket นี้ แล้ว update บรรทัด Decisions so far ของ map
