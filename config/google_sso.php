<?php

/**
 * 🎫 Google SSO (Google Identity / OIDC) — wayfinder/google-integration
 *
 *    Design: wayfinder/google-integration/map.md (contract sign-off 2026-10-06, ticket 06)
 *    Sibling ของ config/ku_sso.php — hand-rolled Http facade ไม่ใช้ package (ticket 02)
 *    Flow: SPA เปิด authorization endpoint รับ code (state + PKCE S256 ฝั่ง SPA สร้างเอง)
 *          → POST /api/v1/auth/sso/google/exchange { code, code_verifier }
 *          → backend แลก code + client_secret (server-to-server, client_secret_post)
 *          → userinfo → find-or-create User (role `user` เสมอ — Google ไม่พิสูจน์สถานะ มก., ticket 06 ข้อ 1)
 *          → ออก Sanctum token (Google access token ใช้ครั้งเดียวแล้วทิ้ง ไม่เก็บ)
 */
return [

    // 🔐 confidential client — client_secret อยู่ฝั่ง server เท่านั้น ห้ามลง frontend
    'client_id' => env('GOOGLE_SSO_CLIENT_ID'),
    'client_secret' => env('GOOGLE_SSO_CLIENT_SECRET'),

    // redirect_uri ต้องตรงกับ Authorized redirect URI ใน Google Cloud Console ทุก byte
    // (กฎ: https บังคับยกเว้น localhost — research ticket 01 §8)
    'redirect_uri' => env('GOOGLE_SSO_REDIRECT_URI'),

    // 🌐 Endpoints — จาก discovery document live-verified 2026-09-11 (research ticket 01 §1)
    //    ค่าคงที่ของ Google ไม่รับ env override — เปลี่ยนได้แต่เมื่อ discovery เปลี่ยนจริง
    'authorize_endpoint' => 'https://accounts.google.com/o/oauth2/v2/auth',
    'token_endpoint' => 'https://oauth2.googleapis.com/token',
    'userinfo_endpoint' => 'https://openidconnect.googleapis.com/v1/userinfo',

    // scope ตาม contract (ticket 06) — basic OIDC non-sensitive ไม่ต้อง full verification
    'scope' => 'openid email profile',

    // 🕐 code อายุ ~10 นาที (RFC 6749 §4.1.2 RECOMMENDED) — exchange ต้องเร็วและไม่ retry
    'timeout' => (int) env('GOOGLE_SSO_TIMEOUT', 10),
    'connect_timeout' => (int) env('GOOGLE_SSO_CONNECT_TIMEOUT', 5),
];
