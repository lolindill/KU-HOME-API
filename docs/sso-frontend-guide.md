# 🎫 KU SSO Login — คู่มือฝั่ง Frontend (React + TypeScript)

> คู่มือการต่อ KU SSO (Keycloak realm `KU-Alllogin`) สำหรับ repo `ku-home` (React)
> อิง contract จริงจาก backend `POST /api/v1/auth/sso/exchange` (ดู `docs/api_guide.md` §SSO) · design อยู่ที่ `wayfinder/ku-sso/`
> ปรับปรุงล่าสุด: 2026-09-09

---

## ภาพรวม flow

```
┌─────────┐   1. redirect เปิดหน้า login     ┌──────────────┐
│   SPA   │ ──────────────────────────────▶ │    KU SSO    │
│ (React) │    (+ code_challenge S256)      │  (Keycloak)  │
│         │ ◀────────────────────────────── │              │
│         │   2. redirect กลับ ?code&state  └──────────────┘
│         │
│         │   3. POST /auth/sso/exchange    ┌──────────────┐
│         │ ──────────────────────────────▶ │   API (เรา)  │ ──▶ แลก code+secret กับ
│         │ ◀────────────────────────────── │              │      Keycloak (server-to-server)
│         │   4. access_token (Sanctum)     └──────────────┘      + ดึง userinfo + find-or-create
└─────────┘
```

- **SPA ทำเอง:** สร้าง PKCE (`code_verifier` + `code_challenge` S256) + `state` → เปิดหน้า login → รับ `code` กลับที่ callback route → ส่ง `code` + `code_verifier` มาแลก token
- **API ทำให้:** แลก code กับ Keycloak (ใช้ `client_secret` — **อยู่ฝั่ง server เท่านั้น**) → ดึง userinfo → หา/สร้าง user → ออก Sanctum token
- ผู้ใช้ใหม่ที่สร้างจาก SSO จะได้ **role `ku_member`** (อัตราค่าห้องสมาชิก ม.เกษตร ใช้ได้เลย)

---

## 0) Config ที่ SPA ต้องรู้ (ใส่เป็น Vite env)

```bash
# .env ของ React (Vite)
VITE_API_BASE_URL=http://localhost:8000/api/v1
VITE_KU_SSO_BASE_URL=https://sso-dev.ku.ac.th/realms/KU-Alllogin
VITE_KU_SSO_CLIENT_ID=bkn-ocs-inf-kuhome
VITE_KU_SSO_REDIRECT_URI=http://localhost:8000/sso-callback
VITE_KU_SSO_SCOPE=basic openid
```

> 🔐 `client_id` **ไม่ใช่ความลับ** — ใส่ frontend ได้ตามปกติของ PKCE flow
> 🚫 `CLIENT_SECRET` **ห้ามเด็ดขาด** — ใช้เฉพาะตอนแลก code ซึ่ง API ทำให้แล้ว  frontend ไม่ต้องมี

### ⚠️ เรื่อง `redirect_uri` (สำคัญที่สุด)

Keycloak ตรวจ `redirect_uri` กับที่ **จดทะเบียนกับ OCS แบบตรงตัวทุก byte** — ค่าที่อนุมัติ ณ วันนี้:

| สภาพแวดล้อม | URI ที่ OCS อนุมัติ | สถานะ |
|---|---|---|
| dev (ปัจจุบัน) | `http://localhost:8000/*` (พอร์ต API dev server — http) | ✅ ใช้ได้จริงแล้ว |
| React dev server (เช่น `localhost:5173`) | ยังไม่ได้จด | ⏳ ต้องขอ OCS เพิ่มก่อนทดสอบ browser flow จริง |
| production | route https จริงของ React (เช่น `https://<โดเมน>/sso-callback`) | ⏳ รอ frontend ตัดสิน path แล้วขอ OCS เปลี่ยน |

- callback ต้องเป็น **route ของ React เท่านั้น** — ห้ามชี้มาที่ API (API เป็น JSON-only จะ reject `Accept: text/html` ด้วย 406)
- production **ต้องเป็น https** ตามเงื่อนไขของ OCS

---

## 1) สร้าง PKCE + state แล้วเปิดหน้า login

RFC 7636 S256: `code_challenge = base64url(SHA-256(code_verifier))`
verifier ต้องยาว 43–128 ตัวอักษร ใช้ได้เฉพาะ `[A-Za-z0-9-._~]` — base64url ของ 32 bytes = 43 ตัวอักษรพอดี

```ts
// src/lib/sso.ts
const KU_SSO_BASE_URL = import.meta.env.VITE_KU_SSO_BASE_URL;
const CLIENT_ID = import.meta.env.VITE_KU_SSO_CLIENT_ID;
const REDIRECT_URI = import.meta.env.VITE_KU_SSO_REDIRECT_URI;
const SCOPE = import.meta.env.VITE_KU_SSO_SCOPE;

function base64UrlEncode(bytes: Uint8Array): string {
  let bin = "";
  for (const b of bytes) bin += String.fromCharCode(b);
  return btoa(bin).replace(/\+/g, "-").replace(/\//g, "_").replace(/=+$/, "");
}

async function sha256(input: string): Promise<Uint8Array> {
  return new Uint8Array(
    await crypto.subtle.digest("SHA-256", new TextEncoder().encode(input)),
  );
}

/** เริ่ม KU SSO login — เรียกจากปุ่ม "เข้าสู่ระบบด้วยบัญชี KU" */
export async function loginWithKuSso(): Promise<void> {
  const verifier = base64UrlEncode(crypto.getRandomValues(new Uint8Array(32)));
  const challenge = base64UrlEncode(await sha256(verifier));
  const state = base64UrlEncode(crypto.getRandomValues(new Uint8Array(16)));

  // sessionStorage = ต่อ tab — กัน tab อื่นทำ login พร้อมกันทับกัน
  sessionStorage.setItem("ku_sso_verifier", verifier);
  sessionStorage.setItem("ku_sso_state", state);

  const params = new URLSearchParams({
    response_type: "code",
    client_id: CLIENT_ID,
    redirect_uri: REDIRECT_URI,
    scope: SCOPE, // "basic openid" — server จะแถม profile email ให้เอง
    state,
    code_challenge: challenge,
    code_challenge_method: "S256", // ← ห้ามลืม! KU enforce PKCE — ไม่ส่งโดน error ทันที
  });

  window.location.href = `${KU_SSO_BASE_URL}/protocol/openid-connect/auth?${params}`;
}
```

---

## 2) Callback route — รับ `code` แล้วยิง exchange

ทำ route เช่น `/sso-callback` (path ต้องตรงกับ `redirect_uri` ที่จดทะเบียน):

```tsx
// src/pages/SsoCallbackPage.tsx
import { useEffect } from "react";
import { exchangeKuSsoCode } from "../lib/sso";

export function SsoCallbackPage() {
  useEffect(() => {
    void handleCallback();
  }, []);

  async function handleCallback(): Promise<void> {
    const url = new URL(window.location.href);

    // Keycloak error (เช่น access_denied — user กดยกเลิก)
    const kcError = url.searchParams.get("error");
    if (kcError) {
      // แสดงข้อความ + ปุ่มเริ่ม login ใหม่
      return;
    }

    const code = url.searchParams.get("code");
    const state = url.searchParams.get("state");

    // state ต้องตรงกับที่เก็บไว้ก่อน redirect (กัน CSRF)
    if (!code || !state || state !== sessionStorage.getItem("ku_sso_state")) {
      // invalid → เริ่ม login flow ใหม่
      return;
    }

    const verifier = sessionStorage.getItem("ku_sso_verifier");
    if (!verifier) {
      // verifier หาย (เช่น session ระเดื่อง) → เริ่ม login flow ใหม่
      return;
    }

    try {
      const result = await exchangeKuSsoCode(code, verifier);
      // ✅ success — result.access_token / result.user / result.id_token
      // เก็บ token → redirect เข้าแอป
    } catch (err) {
      // ❌ แสดง message จาก error (ดู error map ข้อ 4)
    } finally {
      sessionStorage.removeItem("ku_sso_verifier");
      sessionStorage.removeItem("ku_sso_state");
      // เก็บ state ไว้จนเคลียร์เสร็จ — อย่า remove ก่อนเทียบเสร็จ
    }
  }

  return <p>กำลังเข้าสู่ระบบด้วยบัญชี KU…</p>;
}
```

---

## 3) เรียก exchange API

```ts
// src/lib/sso.ts (ต่อ)
const API_BASE = import.meta.env.VITE_API_BASE_URL;

export interface SsoExchangeSuccess {
  status: "success";
  message: string;
  access_token: string; // Sanctum token — ไม่มีวันหมดอายุ
  token_type: "Bearer";
  user: { id: string; name: string; email: string; role: string; /* … */ };
  id_token: string; // JWT จาก KU — v1 ยังไม่ต้องใช้ (เก็บไว้เผื่อ logout อนาคต ห้าม parse เอง)
}

export class SsoExchangeError extends Error {
  constructor(
    public httpStatus: number,
    message: string,
  ) {
    super(message);
  }
}

export async function exchangeKuSsoCode(
  code: string,
  code_verifier: string,
): Promise<SsoExchangeSuccess> {
  const res = await fetch(`${API_BASE}/auth/sso/exchange`, {
    method: "POST",
    headers: {
      "Accept": "application/json", // ← บังคับ! API ปฏิเสธ non-JSON ด้วย 406 เสมอ
      "Content-Type": "application/json",
    },
    body: JSON.stringify({ code, code_verifier }),
  });

  const body = await res.json();
  if (!res.ok || body.status !== "success") {
    throw new SsoExchangeError(res.status, body.message ?? "เข้าสู่ระบบไม่สำเร็จ");
  }
  return body as SsoExchangeSuccess;
}
```

เก็บ token หลังสำเร็จ:

```ts
localStorage.setItem("ku_home_access_token", result.access_token);
localStorage.setItem("ku_home_user", JSON.stringify(result.user));
```

---

## 4) Error map — จัดการยังไง

| HTTP | สาเหตุ | SPA ต้องทำ |
|---|---|---|
| `422` | `code` หมดอายุ (~60 วิ) หรือถูกใช้ไปแล้ว (`invalid_grant`) | **เริ่ม login flow ใหม่เสมอ** — code ใช้ครั้งเดียว ห้าม retry ด้วย code เดิม |
| `422` | validation (`code_verifier` หาย/สั้น-ยาวเกิน/ตัวอักษรนอก charset) | bug ฝั่ง SPA — แก้การสร้าง verifier |
| `422` | บัญชี KU ไม่ส่ง email กลับมา (fail-closed) | แสดง `body.message` — ปุ่มลองใหม่ไม่ช่วย ให้แจ้งผู้ใช้ติดต่อผู้ดูแล |
| `500` | config ฝั่ง API พัง (`invalid_client`) | แสดงข้อความแจ้งผู้ดูแลระบบ (API log รายละเอียดไว้แล้ว) |
| `502` | Keycloak ของมหาวิทยาลัยล่ม/timeout | ข้อความ "ลองใหม่ภายหลัง" — กด login ใหม่ได้ |
| `429` | ยิงเกิน 5 ครั้ง/นาที (throttle เทียบเท่า login) | หยุดพัก — **ห้าม auto-retry วนลูป** |

ข้อความ `message` จาก API เป็นภาษาไทยพร้อม emoji — แสดงตรงๆ ให้ผู้ใช้ได้เลยค่ะ

---

## 5) ใช้ token หลัง login + logout

```ts
// fetch wrapper — ทุก request ต่อจากนี้
export async function apiFetch(path: string, init: RequestInit = {}): Promise<Response> {
  const token = localStorage.getItem("ku_home_access_token");
  return fetch(`${API_BASE}${path}`, {
    ...init,
    headers: {
      "Accept": "application/json",
      "Content-Type": "application/json",
      ...(token ? { Authorization: `Bearer ${token}` } : {}),
      ...init.headers,
    },
  });
}

export async function logout(): Promise<void> {
  try {
    await apiFetch("/logout", { method: "POST" });
  } finally {
    localStorage.removeItem("ku_home_access_token");
    localStorage.removeItem("ku_home_user");
  }
}
```

สิ่งที่ต้องรู้เรื่อง token/session (นโยบายเดียวกับ password login ทุกประการ):

- **Token ไม่มีวันหมดอายุ** — ไม่ต้องทำ refresh logic
- **Login ซ้ำ = ได้ token ใหม่ ไม่ทับ/ไม่ revoke เดิม** — multi-device/multi-tab ใช้พร้อมกันได้ by design
- **v1 ไม่มี single logout กับ KU** — `logout` แค่ revoke Sanctum token ฝั่งเรา session ที่ KU ยังค้าง → user กด "เข้าสู่ระบบด้วย KU" ใหม่จะผ่านเลยโดยไม่ถามรหัส (พฤติกรรมที่ยอมรับแล้ว ไม่ใช่ bug)
- `id_token` ที่ได้จาก exchange — v1 **ยังไม่ต้องใช้งาน** เก็บไว้ก็ได้เผื่อ END_SESSION อนาคต (ห้าม decode/parse เอง)

---

## 6) Checklist กับดักที่เจอบ่อย

- [ ] ลืม `code_challenge_method=S256` → Keycloak error ทันที (`Missing parameter: code_challenge_method`) — **KU enforce PKCE แล้ว**
- [ ] `redirect_uri` ไม่ตรงที่ register กับ OCS แม้แต่ตัวเดียว (`http` vs `https`, ตำสลัก path) → `error=invalid_redirect_uri`
- [ ] ลืม header `Accept: application/json` ที่ยิงหา API → **406 ทันที** (API-only project)
- [ ] เรียก exchange ช้าเกิน ~60 วิ หลังได้ code หรือเรียกซ้ำ → `422 invalid_grant` เสมอ — ยิงทันทีที่ callback มา
- [ ] verifier มีตัวอักษรนอก `[A-Za-z0-9-._~]` → `422` — ใช้ base64url อย่างในตัวอย่างจะไม่มีทางเพี้ยน
- [ ] เก็บ PKCE pair ใน `localStorage` → ต่าง tab ทับกัน — ใช้ `sessionStorage`
- [ ] ทดสอบ dev: React dev server ยังไม่ได้จด redirect กับ OCS — ขอเพิ่มก่อน (ดูตาราง §0) หรือระหว่างนั้น mock response ทดสอบ UI ไปก่อนได้

---

*หมายเหตุ: ค่า config ข้างบนเป็นของ playground sso-dev — production จะเปลี่ยน `VITE_KU_SSO_BASE_URL` + redirect เป็น domain จริง โดยโค้ดเหมือนเดิมทุกอย่าง (ดู fog "production realm endpoint" ใน `wayfinder/ku-sso/map.md`)*
