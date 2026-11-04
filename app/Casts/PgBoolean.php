<?php

namespace App\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/**
 * 🌟 Fix PostgreSQL strict boolean typing (03/07/26)
 *
 * ปัญหา: PostgreSQL strict typing ไม่ยอมรับ integer 0/1 ใน boolean column
 *        แต่ Eloquent boolean cast ส่ง PHP false/true ผ่าน PDO → กลายเป็น integer 0/1
 *        ทำให้เกิด "Datatype mismatch" error บน PostgreSQL
 *
 * ทางแก้เดิม: ใช้ DB::raw('TRUE'/'FALSE') หรือ string 'true'/'false' กระจัดกระจาย
 * ทางแก้ใหม่: Custom Cast นี้แปลง PHP bool → DB::raw('TRUE'/'FALSE') ตอน write
 *            และแปลงค่าจาก DB → PHP bool ตอน read (รองรับ t/f/true/false/1/0)
 *
 * Portable: ทำงานได้ทั้ง PostgreSQL (strict) และ MySQL/SQLite (lenient)
 *
 * การใช้งาน: ใน model protected $casts = ['is_paid' => PgBoolean::class];
 */
class PgBoolean implements CastsAttributes
{
    /**
     * Cast ค่าจาก DB → PHP bool (ตอน read)
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): ?bool
    {
        if ($value === null) {
            return null;
        }

        // รองรับหลายรูปแบบ: true/false (PHP/DB), 't'/'f' (PostgreSQL), 1/0, 'true'/'false'
        return filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? false;
    }

    /**
     * Cast ค่าจาก PHP → DB (ตอน write)
     * ใช้ DB::raw('TRUE'/'FALSE') เพื่อให้ PostgreSQL รับเป็น SQL boolean literal
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): \Illuminate\Database\Query\Expression
    {
        // แปลงค่าเป็น PHP bool ก่อน (รองรับ string 'true'/'false', int 0/1, PHP bool)
        $boolValue = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? false;

        // ส่งเป็น SQL boolean literal — PostgreSQL รับได้, MySQL/SQLite ก็รับได้
        return \Illuminate\Support\Facades\DB::raw($boolValue ? 'TRUE' : 'FALSE');
    }
}
