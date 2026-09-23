<?php

namespace App\Support;

use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;

/**
 * 🏨 Helper กลางสำหรับกฎการจอง (advance notice rule & room cap rule)
 *
 *    อ้างอิง: wayfinder/booking-create-rules/spec.md
 *    - นิยาม "จองก่อน N วัน": calendar days ตาม timezone Asia/Bangkok
 *    - Exemption: ตัดสินจาก Sanctum login role === 'admin' เท่านั้น (ห้ามใช้ field 'source')
 *    - Shared helper สำหรับทั้ง 4 write paths
 */
class BookingRule
{
    public const TIMEZONE = 'Asia/Bangkok';

    /**
     * ตรวจสอบว่าผู้ใช้มีสิทธิ์เป็น admin หรือไม่
     * ⚠️ ตัดสินจาก Sanctum login role เท่านั้น ห้ามใช้ field 'source' จาก request
     */
    public static function isAdmin(?User $user = null): bool
    {
        $user = $user ?? Auth::guard('sanctum')->user() ?? request()?->user('sanctum');

        return $user !== null && $user->role === 'admin';
    }

    /**
     * จำนวนวันล่วงหน้าขั้นต่ำตามปฏิทิน (admin = 0, non-admin = config min_advance_days)
     */
    public static function minAdvanceDays(?User $user = null): int
    {
        if (static::isAdmin($user)) {
            return 0;
        }

        return (int) config('booking.min_advance_days', 2);
    }

    /**
     * เพดานจำนวนห้องสูงสุดต่อ 1 การจอง (admin = PHP_INT_MAX, non-admin = config max_rooms_per_booking)
     */
    public static function maxRoomsPerBooking(?User $user = null): int
    {
        if (static::isAdmin($user)) {
            return PHP_INT_MAX;
        }

        return (int) config('booking.max_rooms_per_booking', 4);
    }

    /**
     * กฎ validation สำหรับ booking_rooms (max:N สำหรับ non-admin, null สำหรับ admin)
     */
    public static function roomCapRule(?User $user = null): ?string
    {
        if (static::isAdmin($user)) {
            return null;
        }

        $max = static::maxRoomsPerBooking($user);

        return 'max:'.$max;
    }

    /**
     * ข้อความแจ้งเตือนภาษาไทยเมื่อจำนวนห้องเกินเพดานที่กำหนด
     */
    public static function roomCapMessage(?User $user = null): string
    {
        $max = (int) config('booking.max_rooms_per_booking', 4);

        return "สามารถจองได้สูงสุด {$max} ห้องต่อการจอง หากต้องการจองมากกว่านี้ กรุณาติดต่อผู้ดูแลค่ะ";
    }

    /**
     * วันที่เช็คอินเร็วที่สุดที่อนุญาต (Asia/Bangkok calendar date)
     */
    public static function earliestCheckInDate(?User $user = null): Carbon
    {
        $days = static::minAdvanceDays($user);

        return Carbon::now(self::TIMEZONE)->startOfDay()->addDays($days);
    }

    /**
     * วันที่เช็คอินเร็วที่สุดในรูปแบบ string 'Y-m-d'
     */
    public static function earliestCheckInDateString(?User $user = null): string
    {
        return static::earliestCheckInDate($user)->toDateString();
    }

    /**
     * กฎ validation สำหรับ check_in (after_or_equal:YYYY-MM-DD)
     */
    public static function checkInRule(?User $user = null): string
    {
        return 'after_or_equal:'.static::earliestCheckInDateString($user);
    }

    /**
     * ข้อความแจ้งเตือนภาษาไทยเมื่อ check_in ไม่ผ่านเงื่อนไข
     */
    public static function checkInMessage(?User $user = null): string
    {
        $days = static::minAdvanceDays($user);

        if ($days > 0) {
            return "วันที่เช็คอินต้องจองล่วงหน้าอย่างน้อย {$days} วันค่ะ";
        }

        return 'วันที่เช็คอินต้องไม่เป็นวันในอดีต';
    }
}
