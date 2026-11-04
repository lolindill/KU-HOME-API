<?php

/**
 * 🎫 KU SSO (Keycloak) — realm KU-Alllogin บน sso-dev.ku.ac.th
 *
 *    Design: wayfinder/ku-sso/map.md (decision-lock ครบ 2026-09-07) · hand-rolled Http facade ไม่ใช้ package
 *    Flow: SPA เปิด authorization endpoint รับ code (state ฝั่ง SPA · PKCE: SPA สร้าง verifier/challenge
 *          — backend relay code_verifier ตอน exchange; live-verify 2026-09-08: KU enforce S256, ticket 10)
 *          → POST /api/v1/auth/sso/exchange { code, code_verifier }
 *          → backend แลก code + client_secret (server-to-server) → userinfo → find-or-create User
 *          → ออก Sanctum token (KU tokens ทิ้งหมด ไม่เก็บ — decision ticket 03)
 */
return [

    'base_url' => env('KU_SSO_BASE_URL', 'https://sso-dev.ku.ac.th/realms/KU-Alllogin'),

    // 🔐 confidential client — client_secret อยู่ฝั่ง server เท่านั้น ห้ามลง frontend
    'client_id' => env('KU_SSO_CLIENT_ID'),
    'client_secret' => env('KU_SSO_CLIENT_SECRET'),

    // scope ที่ OCS อนุมัติ (2026-09-07) — claims จริงของ `basic` ยังต้อง live-verify (amendment ticket 02)
    'scope' => env('KU_SSO_SCOPE', 'basic openid'),

    // redirect_uri ต้องตรงกับที่ register กับ OCS ทุก byte — ฝั่ง SPA กับ exchange ใช้ค่าเดียวกัน
    'redirect_uri' => env('KU_SSO_REDIRECT_URI'),

    // END_SESSION ยังไม่ทำใน v1 (decision ticket 03) — เก็บไว้เผื่ออนาคต
    'logout_redirect_uri' => env('KU_SSO_LOGOUT_REDIRECT_URI'),

    // 🕐 code อายุ ~60 วิ — exchange ต้องเร็วและไม่ retry (research: KU latency < 1s จากเน็ตไทย)
    'timeout' => (int) env('KU_SSO_TIMEOUT', 10),
    'connect_timeout' => (int) env('KU_SSO_CONNECT_TIMEOUT', 5),
];
