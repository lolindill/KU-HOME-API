# Role ของ Google-born user + contract ของ exchange endpoint

- **label:** `wayfinder:grilling`
- **type:** HITL
- **status:** open
- **blocked-by:** —
- **assignee:** antigravity (2026-09-16, prep only — grilling รอ owner)

## Question

Owner sign-off 2 ข้อก่อน implementation (ข้อมูลครบจาก research 01/02 แล้ว — ไม่ต้อง research เพิ่ม):

- **Role ของ user ที่เกิดจาก Google login ครั้งแรก:** research ticket 02 แนะ **`user`** ไม่ใช่ `ku_member` — เหตุผล: Google ไม่พิสูจน์สถานะสมาชิก KU และ role `ku_member` ผูกกับส่วนลดรายวัน `daily_ku` (ต่างจาก KU SSO ที่ first login ได้ `ku_member` ตาม ku-sso ticket 08) — ยืนยันไหม
- **Contract ของ `POST /api/v1/auth/sso/google/exchange`:** ตามที่ research 02 เสนอ — body รับ `code` + `code_verifier` (+ `redirect_uri` ตรงกับที่ register หรือไม่) · response mirror ku-sso ticket 03 (`{status, message, access_token, token_type, user, id_token}`) · error map `invalid_grant`→422 · `invalid_client`→500 · `redirect_uri_mismatch`→500 · Google ล่ม→502 · **`email_verified=false`/ไม่มี email → 422 fail-closed** · default role จากข้อแรก · sign-off ให้ implementation session เขียนได้โดยไม่ถามซ้ำ

ผลตัดสินจะ append เป็น amendment ที่ ticket นี้ แล้ว update บรรทัด Decisions so far ของ map

---

## Grilling prep (2026-09-16 — antigravity)

> 🎀 **เอกสารเตรียมการสำหรับ HITL Grilling Session ระหว่างนายท่าน (Owner) และน้องเมด (Agent)**
> รวบรวมข้อเท็จจริง ตัวเลือก ผลกระทบทางธุรกิจ/เทคนิค และคำแนะนำที่ชัดเจน เพื่อให้นายท่านสามารถตัดสินใจและลงนาม (Sign-off) ปิดตั๋วนี้ได้ภายในครั้งเดียวค่ะ! ✨

---

### 1. 🔒 สิ่งที่ Research & Standing Decisions ล็อกไว้แล้ว (ไม่ต้องถาม Owner ซ้ำ)

เพื่อความกระชับในการ Grilling ประเด็นด้านล่างนี้ถือเป็นข้อยุติที่มีข้อสรุปจากงานวิจัยและ Standing Decisions เดิมแล้ว ไม่ต้องนำมาถกซ้ำใน session นี้ค่ะ:

1. **Split-User Rule (เด็ดขาด):** ผู้ใช้แต่ละ provider (`password`, `ku_sso`, `google`) แยกกันคนละ row ในฐานข้อมูลอย่างสิ้นเชิง ไม่มีการ merge/link identity — schema พร้อมรองรับด้วย composite unique `(email, auth_provider)` และ `auth_provider = 'google'`
2. **Sanctum Token Contract:** ออก token ด้วย `createToken('ku_home_auth_token')` นโยบายเดียวกับ password login (stateless bearer token, `expiration: null`, ไม่ revoke ของเดิม รองรับ multi-device, `logout` ลบเฉพาะ `currentAccessToken()`)
3. **Stateless Disposability ของ Google Tokens:** Google access token ถูกใช้ยิง userinfo ครั้งเดียวแล้วทิ้งทันที ไม่มีการบันทึก token หรือ `sub` ลงฐานข้อมูล และไม่ขอ `access_type=offline` (ไม่มี refresh token และไม่ติดข้อจำกัดอายุ 7 วัน)
4. **Logout Semantics (Purely Local):** Google OIDC ไม่มี `end_session_endpoint` สำหรับ browser redirect (ต่างจาก Keycloak) ดังนั้นการ logout ฝั่งเราคือการลบ Sanctum token ภายในระบบ KU HOME เท่านั้น
5. **Architecture Approach:** สร้าง sibling service `GoogleSsoService` คู่ขนานกับ `KuSsoService` (0 new packages, 0 blast radius กับ KU SSO ที่ผ่าน live-verify แล้ว) โดยใช้ `Illuminate\Support\Facades\Http` และ reuse exception 4 ตัวเดิมใน `App\Services\Sso\Exceptions\`
6. **PKCE Enforcement:** ใช้ PKCE S256 โดย SPA เป็นผู้สร้าง `code_verifier` (RFC 7636) ส่งมาพร้อม `code` ให้ backend relay ต่อไปยัง Google token endpoint
7. **Merge Dependency:** โค้ด Google SSO จะต้องเริ่มเขียนบนฐานของ branch `feature/ku-sso-login` (ซึ่งมี migration `auth_provider` และ SSO plumbing ครบถ้วนแล้ว)

---

### 2. 📋 Open Decision Questions สำหรับ Owner Sign-off

---

#### ❓ ข้อที่ 1: Role ของผู้ใช้ที่เกิดจาก Google login ครั้งแรก (Default Role for Google-born Users)

* **บริบท & Precedent:**
  * ใน KU SSO ตั๋ว `ku-sso/tickets/08-ku-member-role-or-tag.md` นายท่านเคยตัดสินให้ผู้ใช้ที่เข้าสู่ระบบผ่าน KU SSO ครั้งแรกได้รับ role **`ku_member`** ทันที เพราะ Keycloak realm `KU-Alllogin` ของมหาวิทยาลัยถือเป็นการพิสูจน์สถานะความเป็นนิสิต/บุคลากรของ มก.
  * Role `ku_member` มีผลกระทบทางการเงินโดยตรง! ตาม `GlobalRate::getEffectiveDailyRate()`, ผู้ใช้ที่มี `role === 'ku_member'` จะได้รับอัตราค่าห้องพิเศษประเภท `daily_ku` โดยอัตโนมัติ
  * แต่การล็อกอินผ่าน Google (Google Identity) เป็น public consumer identity ไม่ได้พิสูจน์ว่าผู้ใช้มีความผูกพันใด ๆ กับมหาวิทยาลัยเกษตรศาสตร์ แม้จะล็อกอินด้วยอีเมลองค์กรก็ตาม
* **ตัวเลือก (Options):**
  * **Option 1A (✨ น้องเมดแนะนำ): กำหนดเป็น role `user` เสมอ (เหมือนการ register สมาชิกทั่วไป)**
    * *ข้อดี:* ปลอดภัยสูงสุด ป้องกันไม่ให้บุคคลภายนอกหรือผู้ใช้ทั่วไปได้สิทธิ์ส่วนลดสมาชิก KU (`daily_ku`) โดยพลการ; สอดคล้องกับเจตนารมณ์ของระบบสมาชิก
    * *ข้อเสีย:* หากบุคลากร/นิสิต มก. เผลอล็อกอินผ่าน Google ด้วยอีเมล `@ku.th` จะไม่ได้สิทธิ์ `ku_member` อัตโนมัติ (แต่พวกเขาสามารถเลือกเข้าสู่ระบบผ่านปุ่ม KU SSO ซึ่งเป็นช่องทางหลักเพื่อรับสิทธิ์ `ku_member` ได้อยู่แล้ว)
  * **Option 1B: กำหนดเป็น role `ku_member` เหมือน KU SSO**
    * *ข้อดี:* พฤติกรรม SSO เหมือนกันทุกช่องทาง
    * *ข้อเสีย:* **อันตรายอย่างยิ่งต่อรายได้ของโรงแรม!** ผู้ใช้ทุกคนในโลกที่มี Gmail จะกลายเป็นสมาชิก KU และได้สิทธิ์ลดราคาห้องพักทันที
  * **Option 1C: Derive role จากโดเมนของอีเมล (เช่น ลงท้าย `@ku.th` / `@ku.ac.th` ให้เป็น `ku_member`, อื่น ๆ เป็น `user`)**
    * *ข้อดี:* อำนวยความสะดวกให้นิสิต/บุคลากรที่สะดวกกด Google login
    * *ข้อเสีย:* ละเมิด precedent ของตั๋ว ku-sso 08 ("ku_member ยืนเป็น role ไม่ใช่ derive"); เสี่ยงต่อข้อผิดพลาดเรื่องการ maintain domain list/subdomain; แย่งบทบาทของช่องทาง KU SSO แท้จริง
  * **Option 1D: Config-driven (`config('google_sso.default_role', 'user')`)**
    * *ข้อดี:* ปรับเปลี่ยนได้ในอนาคตผ่าน config โดยไม่ต้องแก้ code
    * *ข้อเสีย:* เพิ่ม config key เล็กน้อย แต่ถ้า default เป็น `'user'` ก็ปลอดภัย
* **💡 คำแนะนำของน้องเมด (Recommendation):**
  * **เลือก Option 1A (Role `user`)** (อาจใส่ไว้ใน config `config('google_sso.default_role', 'user')` ได้) เพราะ Google login ในบริบทของ KU HOME คือการอำนวยความสะดวกให้แขกทั่วไป (Guests/Public Users) ได้เข้าสู่ระบบสะดวกรวดเร็ว ไม่ใช่ช่องทางยืนยันความเป็นคน มก. ค่ะ! 🏨✨

---

#### ❓ ข้อที่ 2: Endpoint Path และ Architecture Routing สำหรับ Exchange

* **บริบท & Precedent:**
  * KU SSO ใช้ `POST /api/v1/auth/sso/exchange` (public route, `throttle:5,1`)
  * Research ตั๋ว 02 ได้วิเคราะห์เปรียบเทียบระหว่าง "แยก Route" กับ "รวม Route เดิมโดยเพิ่ม parameter provider"
* **ตัวเลือก (Options):**
  * **Option 2A (✨ น้องเมดแนะนำ): แยก Route เป็น `POST /api/v1/auth/sso/google/exchange`**
    * Controller: เพิ่ม method `exchangeGoogle()` ใน `SsoController` (หรือแยก `GoogleSsoController`)
    * Middleware: `throttle:5,1`
    * *ข้อดี:* Blast radius ต่อ route KU SSO เดิมเป็น 0%; สัญญา API ใน `docs/api_guide.md` และโค้ดฝั่ง React ของ KU SSO ไม่ถูกกระทบ; validation และ error handling ของแต่ละ provider แยกกันเด็ดขาด ไม่อุดอู้ด้วย if-else
    * *ข้อเสีย:* เพิ่มเส้นทาง route 1 บรรทัด
  * **Option 2B: รวม Route เดิม `POST /api/v1/auth/sso/exchange` โดยรับ body `provider: 'google'`**
    * *ข้อดี:* Endpoint การแลก token มีจุดเดียว
    * *ข้อเสีย:* เสี่ยงสร้าง regression ต่อ KU SSO; ทำให้ controller เดิมซับซ้อนขึ้น; ขัดกับ contract ที่ระบุไว้ใน api_guide
* **💡 คำแนะนำของน้องเมด (Recommendation):**
  * **เลือก Option 2A (`POST /api/v1/auth/sso/google/exchange`)** เพื่อความสะอาด ชัดเจน และลดความเสี่ยง 0 blast radius ตามแนวคิดของระบบค่ะ! ✌️

---

#### ❓ ข้อที่ 3: Request Payload และการจัดการ `redirect_uri`

* **บริบท & Precedent:**
  * KU SSO รับ request payload เฉพาะ `{ "code": "...", "code_verifier": "..." }` โดย backend ดึง `redirect_uri` มาจาก config (`config('ku_sso.redirect_uri')`)
  * RFC 6749 กำหนดว่า `redirect_uri` ตอน exchange ต้องตรงกับตอนที่ส่งใน authorization request ทุกตัวอักษร
* **ตัวเลือก (Options):**
  * **Option 3A (✨ น้องเมดแนะนำ): รับเฉพาะ `code` + `code_verifier` (เหมือน KU SSO), backend อ่าน `redirect_uri` จาก `config('google_sso.redirect_uri')`**
    * Payload: `{ "code": "string", "code_verifier": "string" }` (ตรวจสอบ regex RFC 7636 §4.1 min:43, max:128)
    * *ข้อดี:* เลียนแบบรูปแบบของ KU SSO 100%; คุมค่าความปลอดภัยจาก server config (.env); ป้องกัน open-redirect attack
    * *ข้อเสีย:* SPA dev และ prod ต้องกำหนดค่า redirect_uri ให้ตรงกับ .env ของ backend ในแต่ละ environment
  * **Option 3B: อนุญาตให้ SPA ส่ง `redirect_uri` มาใน body ด้วย**
    * Payload: `{ "code": "...", "code_verifier": "...", "redirect_uri": "..." }`
    * *ข้อดี:* ยืดหยุ่นถ้า frontend มีหลาย domain
    * *ข้อเสีย:* Backend ต้องเขียน logic ทำ whitelist validation ตรวจสอบความถูกต้อง; ต่างจากมาตรฐานที่ทำไว้ใน KU SSO
* **💡 คำแนะนำของน้องเมด (Recommendation):**
  * **เลือก Option 3A** เพื่อรักษาความสม่ำเสมอกับ KU SSO และให้ `.env` เป็น Single Source of Truth ของ URL ประจำแต่ละ environment ค่ะ!

---

#### ❓ ข้อที่ 4: Response Contract & Shape (`id_token` Key Parity)

* **บริบท & Precedent:**
  * KU SSO ส่งกลับ (HTTP 200):
    ```json
    {
      "status": "success",
      "message": "Google login successful",
      "access_token": "<sanctum_token>",
      "token_type": "Bearer",
      "user": { ...User model... },
      "id_token": "<jwt_string_or_null>"
    }
    ```
  * ใน KU SSO ค่า `id_token` ถูกส่งกลับเพื่อให้ SPA เก็บไว้ใช้ทำ `id_token_hint` ตอน END_SESSION แต่ Google ไม่มี END_SESSION endpoint
* **ตัวเลือก (Options):**
  * **Option 4A (✨ น้องเมดแนะนำ): คืนครบ 6 keys รวม `id_token` (Mirror KU SSO 100%)**
    * ส่งต่อค่า `id_token` ที่ได้จาก Google กลับไปเป็น opaque string (backend ไม่ต้องแกะ parse)
    * *ข้อดี:* Parity สมบูรณ์ 100% กับ KU SSO; ฝั่ง React ใช้ TypeScript Interface เดียวกัน (`SsoExchangeResponse`) จัดการผลลัพธ์จากทั้งสองปุ่มได้ทันทีโดยไม่ต้องแยก type; เผื่ออนาคตหาก frontend ต้องการนำไปใช้แสดงผล
    * *ข้อเสีย:* ส่งข้อมูล string เพิ่มขึ้นเล็กน้อย (~1KB)
  * **Option 4B: ตัด `id_token` ออก คืนเฉพาะ 5 keys หลัก**
    * *ข้อดี:* คืนเฉพาะข้อมูลที่จำเป็นจริง ๆ
    * *ข้อเสีย:* Response shape ไม่ตรงกันระหว่าง provider ทำให้ frontend ต้องสร้าง interface แยก
* **💡 คำแนะนำของน้องเมด (Recommendation):**
  * **เลือก Option 4A** เพื่อให้ Client-side integration ง่ายที่สุดและเป็นรูปแบบเดียวกันหมดค่ะ!

---

#### ❓ ข้อที่ 5: นโยบายตรวจสอบอีเมล (`email_verified` & Fail-Closed Policy)

* **บริบท & Precedent:**
  * ใน KU SSO ไม่สามารถตรวจ `email_verified` ได้เพราะเซิร์ฟเวอร์มหาลัยส่งคืน `false` เสมอ (จึงใช้ fallback chain `email -> google-mail -> office365-mail`)
  * แต่สำหรับ Google Claim `email_verified` มีความหมายแท้จริงและเชื่อถือได้ตามมาตรฐาน OIDC
* **ตัวเลือก (Options):**
  * **Option 5A (✨ น้องเมดแนะนำ): Strict Fail-Closed — ต้องมี `email` และ `email_verified === true` เท่านั้น**
    * กฎ: บัญชี Google ต้องมี claim `email` ที่ถูกต้องตามรูปแบบ RFC และค่า `email_verified` ต้องเป็น boolean `true` (รองรับ string `"true"` แบบ tolerant ด้วย `filter_var`)
    * หากไม่ผ่าน: โยน Exception ตอบกลับ HTTP `422 Unprocessable Content` ทันที โดยไม่สร้าง user row
    * *ข้อดี:* ป้องกันการสวมรอยหรือการใช้ unverified Google account; ปลอดภัยตามมาตรฐานความปลอดภัยสูงสุด สอดคล้องกับ standing decision "ไม่เดา identity"
    * *ข้อเสีย:* ผู้ใช้ที่ยังไม่ได้กดยืนยันอีเมลใน Google จะเข้าสู่ระบบไม่ได้ (ซึ่งปกติบัญชี Gmail แท้จะ verified อยู่แล้ว)
  * **Option 5B: ตรวจเฉพาะการมี `email` แต่ยอมรับแม้ `email_verified === false`**
    * *ข้อดี:* ยืดหยุ่นสูงสุด
    * *ข้อเสีย:* เสี่ยงต่อความปลอดภัยหากมีคนสร้างบัญชี Google ปลอมโดยแอบอ้างอีเมลคนอื่น
* **💡 คำแนะนำของน้องเมด (Recommendation):**
  * **เลือก Option 5A (Strict Fail-Closed)** เพื่อความปลอดภัยของระบบโรงแรมค่ะ! 🛡️✨

---

#### ❓ ข้อที่ 6: การจับคู่ข้อผิดพลาด (Error Mapping Table)

* **สรุปตาราง Error Mapping ที่นำเสนอเพื่อ Sign-off:**

| สถานการณ์ | Error / Exception | HTTP Status | Response Payload & ความประพฤติ |
|---|---|---|---|
| ข้อมูล request ไม่ครบ (`code` ขาด / `code_verifier` ไม่ตรงตาม RFC 7636) | `ValidationException` | **422** | `{"status":"error","message":"...validation error...", "errors":{...}}` ไม่ยิงต่อไปยัง Google |
| Code หมดอายุ หรือถูกใช้ซ้ำ (replay attack) | Google ตอบ HTTP 400 `error: "invalid_grant"` | **422** | `{"status":"error","message":"รหัสยืนยันหมดอายุหรือถูกใช้งานแล้ว กรุณาเข้าสู่ระบบผ่าน Google อีกครั้งค่ะ 🔄"}` (SPA ต้องเริ่ม flow ใหม่) |
| อีเมลไม่มี หรือ `email_verified !== true` | `MissingEmailException` (หรือ `UnverifiedEmailException`) | **422** | `{"status":"error","message":"บัญชี Google ไม่ได้รับการยืนยันอีเมล หรือไม่ส่งข้อมูลอีเมลกลับมา จึงเข้าสู่ระบบไม่ได้ค่ะ 📧"}` |
| Client ID หรือ Client Secret ผิดพลาด | Google ตอบ HTTP 401 `error: "invalid_client"` | **500** | Log error รายละเอียดใน server · คืน generic 500 ปลอดภัย: `{"status":"error","message":"เกิดข้อผิดพลาดในการยืนยันตัวตนกับผู้ให้บริการ กรุณาแจ้งผู้ดูแลระบบค่ะ"}` |
| `redirect_uri_mismatch` / `invalid_request` จาก Google Token Endpoint | Google ตอบ HTTP 400 `error: "redirect_uri_mismatch"` | **500** | ถือเป็น Server Configuration Bug — Log error ใน server · คืน generic 500 (ผู้ใช้แก้ไขเองไม่ได้) |
| Google ระบบล่ม, ปฏิเสธการเชื่อมต่อ, หรือ Request Timeout | `ConnectionException` / Google 5xx | **502** | `{"status":"error","message":"ระบบ Google Authentication ขัดข้องชั่วคราว กรุณาลองใหม่อีกครั้งค่ะ 🛰️"}` |
| ข้อผิดพลาดอื่น ๆ ที่อยู่นอกเหนือความคาดหมาย | `Throwable` | **500** | Log::error พร้อม Class และ Message · คืน generic 500 ไม่ leak stack trace |

* **💡 คำแนะนำของน้องเมด (Recommendation):**
  * แนะนำให้นายท่านลงนามรับรอง Error Mapping นี้ทั้งชุด เพราะถอดแบบมาจาก KU SSO Contract อย่างเที่ยงตรง และมีการจัดการกรณี `redirect_uri_mismatch` เข้ากลุ่ม 500 อย่างถูกต้องตามหลักการค่ะ!

---

### 3. 🎯 Proposed Sign-off Checklist สำหรับนายท่าน (Owner Checklist)

เมื่อนายท่านตรวจสอบและเห็นชอบ สามารถติ๊กถูก `[x]` ใน Checklist ด้านล่างนี้เพื่อปิดตั๋วได้ใน sitting เดียวเลยค่ะ:

- [ ] **1. Default Role:** ผู้ใช้ที่เกิดจาก Google login ครั้งแรก ได้รับ role **`user`** (ไม่ใช่ `ku_member` เพื่อคุ้มครองอัตราค่าห้อง `daily_ku`)
- [ ] **2. Endpoint Shape:** กำหนดเส้นทางเป็น **`POST /api/v1/auth/sso/google/exchange`** นอก auth group พร้อม middleware **`throttle:5,1`**
- [ ] **3. Request Contract:** Body รับ **`{ "code": "...", "code_verifier": "..." }`** โดยค่า `redirect_uri` อ่านจาก backend config (`config('google_sso.redirect_uri')`)
- [ ] **4. Response Contract:** คืน HTTP 200 พร้อม JSON ครบ 6 keys: **`status`**, **`message`**, **`access_token`**, **`token_type`**, **`user`**, **`id_token`** (Mirror KU SSO)
- [ ] **5. Fail-Closed Email Policy:** ตรวจสอบทั้งรูปแบบ `email` และ **`email_verified === true`** (tolerant boolean) มิฉะนั้นปฏิเสธด้วย HTTP 422
- [ ] **6. Error Mapping:** รับรองตารางจับคู่ข้อผิดพลาด (422 สำหรับ grant/email, 500 สำหรับ client/redirect mismatch, 502 สำหรับ service unavailable)
- [ ] **7. Standing Decisions Ratification:** รับรอง standing decisions ทั้ง 7 ข้อ (Split-user, 0-package Sibling service, Purely local logout, Sanctum policy)

