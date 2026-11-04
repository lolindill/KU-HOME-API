<?php

namespace App\Services\Sso\Exceptions;

/**
 * 🎫 Base exception ของ KU SSO flow — SsoController map เป็น HTTP status ตาม contract
 * ของ wayfinder/ku-sso ticket 03 (invalid_grant→422 · invalid_client→500 · KU ล่ม→502)
 */
class KuSsoException extends \RuntimeException {}
