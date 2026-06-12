<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Step 1: Deduplicate existing confirmation numbers (if any duplicates exist)
        // เก็บ record แรก ต่อท้าย record ที่ซ้ำด้วย -DUP
        // รองรับทั้ง PostgreSQL (uuid) และ SQLite (text) ค่ะ
        $minIdExpr = DB::getDriverName() === 'pgsql'
            ? 'MIN(id::text) as keep_id'
            : 'MIN(id) as keep_id';

        $duplicates = DB::table('bookings')
            ->select('confirmation', DB::raw($minIdExpr))
            ->groupBy('confirmation')
            ->havingRaw('COUNT(*) > 1')
            ->get();

        foreach ($duplicates as $dup) {
            DB::table('bookings')
                ->where('confirmation', $dup->confirmation)
                ->where('id', '!=', $dup->keep_id)
                ->update(['confirmation' => $dup->confirmation . '-DUP']);
        }

        // Step 2: Add unique constraint
        Schema::table('bookings', function (Blueprint $table) {
            $table->unique('confirmation');
        });
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->dropUnique(['confirmation']);
        });
    }
};