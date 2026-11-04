<?php

namespace App\Services\Sso\Exceptions;

/**
 * 🔐 Keycloak ปฏิเสธ client_id/client_secret — config ฝั่งเราพัง (ไม่ใช่ความผิดของ user)
 * Log รายละเอียดไว้ใน service แล้ว → HTTP 500 แบบไม่ expose
 */
class InvalidClientException extends KuSsoException {}
