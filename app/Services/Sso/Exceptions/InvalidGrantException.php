<?php

namespace App\Services\Sso\Exceptions;

/**
 * ⏱️ Authorization code หมดอายุ/ถูกใช้ไปแล้ว (single-use, ~60 วิ) — ห้าม retry
 * SPA ต้องเริ่ม login flow ใหม่จาก browser → HTTP 422
 */
class InvalidGrantException extends KuSsoException {}
