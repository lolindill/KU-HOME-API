<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 💰 (11/09/26) เปลี่ยนมาตรฐานเงินทั้งระบบ: integer satang → integer บาทล้วน (non-decimal)
 *
 *    มาตรฐานใหม่: ทุก amount/price/rate field = integer หน่วย "บาท" ไม่มีทศนิยม
 *    (เช่น ห้อง 1,200 บาท/คืน เก็บเป็น 1200 — ไม่ใช่ 120000 satang อีกต่อไป)
 *
 *    แปลงข้อมูลเดิม: satang → baht หาร 100 (integer division ตัดเศษสตางค์,
 *    ทั้ง SQLite และ PostgreSQL ให้ผลเหมือนกันสำหรับ int/int)
 *
 *    ⚠️ ข้อยกเว้น: discounts.value เฉพาะ type 'fixed' / 'set_room_price'
 *    ที่เคยเก็บ satang — 'percent' (1-100) ห้ามหาร!
 *
 *    หมายเหตุ: receipts.amount เป็น legacy (ตาราง FROZEN) แต่แปลงด้วย
 *    เพื่อให้ทุกยอดเงินในระบบอยู่หน่วยเดียวกัน
 */
return new class extends Migration
{
    private const MONEY_COLUMNS = [
        'bookings.total_amount' => 'total_amount',
        'booking_rooms.room_amount' => 'room_amount',
        'booking_rooms.discount_amount' => 'discount_amount',
        'booking_rooms.amount' => 'amount',
        'addons.extra_bed_price' => 'extra_bed_price',
        'addons.breakfast_price' => 'breakfast_price',
        'addons.early_checkIn_price' => 'early_checkIn_price',
        'addons.late_checkOut_price' => 'late_checkOut_price',
        'global_rates.default_price' => 'default_price',
        'payments.amount' => 'amount',
        'receipts.amount' => 'amount',
    ];

    public function up(): void
    {
        foreach (self::MONEY_COLUMNS as $tableColumn => $column) {
            [$table] = explode('.', $tableColumn);

            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, $column)) {
                continue;
            }

            DB::statement("UPDATE {$table} SET {$column} = {$column} / 100");
        }

        // 🎟️ discounts.value — เฉพาะ fixed/set_room_price เคยเป็น satang, percent ไม่แตะ
        if (Schema::hasTable('discounts')) {
            DB::statement("UPDATE discounts SET value = value / 100 WHERE type IN ('fixed', 'set_room_price')");
        }

        // 📝 รีเฟรช comment คอลัมน์ที่เคยเขียนว่า (satang)
        if (Schema::hasTable('payments')) {
            Schema::table('payments', function ($table) {
                $table->bigInteger('amount')->comment('ยอดเงินที่ชำระ (integer บาท)')->change();
            });
        }
        if (Schema::hasTable('receipts')) {
            Schema::table('receipts', function ($table) {
                $table->bigInteger('amount')->comment('ยอดเงินในใบเสร็จ (integer บาท)')->change();
            });
        }
    }

    public function down(): void
    {
        // ย้อนกลับ: baht → satang คูณ 100 (best-effort)
        foreach (self::MONEY_COLUMNS as $tableColumn => $column) {
            [$table] = explode('.', $tableColumn);

            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, $column)) {
                continue;
            }

            DB::statement("UPDATE {$table} SET {$column} = {$column} * 100");
        }

        if (Schema::hasTable('discounts')) {
            DB::statement("UPDATE discounts SET value = value * 100 WHERE type IN ('fixed', 'set_room_price')");
        }

        if (Schema::hasTable('payments')) {
            Schema::table('payments', function ($table) {
                $table->bigInteger('amount')->comment('ยอดเงินที่ชำระ (satang)')->change();
            });
        }
        if (Schema::hasTable('receipts')) {
            Schema::table('receipts', function ($table) {
                $table->bigInteger('amount')->comment('ยอดเงินในใบเสร็จ (satang)')->change();
            });
        }
    }
};
