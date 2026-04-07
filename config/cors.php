<?php

return [
    // ให้อนุญาตเฉพาะเส้นทางที่ขึ้นต้นด้วย api/ และ sanctum
    'paths' => ['api/*', 'sanctum/csrf-cookie'],

    // อนุญาตทุก Method (GET, POST, PUT, DELETE)
    'allowed_methods' => ['*'],

    // อนุญาตทุกเว็บ (หรือถ้านายท่านอยากให้ปลอดภัย ใส่เป็น ['http://localhost:5173'] ได้ค่ะ)
    'allowed_origins' => ['*'],

    'allowed_origins_patterns' => [],

    // อนุญาตทุก Header (รวมถึง Authorization ที่เราแนบ Token มาด้วย)
    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => false,
];