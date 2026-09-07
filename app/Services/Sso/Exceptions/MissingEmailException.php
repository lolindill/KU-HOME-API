<?php

namespace App\Services\Sso\Exceptions;

/**
 * 📧 userinfo ไม่คืน claim `email` — fail-closed ตาม decision ticket 02
 * (ไม่เดา identity จาก preferred_username — รอ live-verify claims จริงก่อนเปลี่ยนนโยบาย)
 */
class MissingEmailException extends KuSsoException {}
