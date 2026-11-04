<?php

namespace App\Services\Sso\Exceptions;

/**
 * 🛰️ Keycloak ล่ม / timeout / ตอบผิดปกติ — transient → HTTP 502
 */
class KuSsoUnavailableException extends KuSsoException {}
