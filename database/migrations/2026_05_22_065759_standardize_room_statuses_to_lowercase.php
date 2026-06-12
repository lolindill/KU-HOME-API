<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Standardize all room statuses to lowercase.
 * 
 * Before: 'Occupied', 'prep_checkIn', etc.
 * After:  'occupied', 'prep_checkin', etc.
 * 
 * Valid statuses (all lowercase):
 *   available, occupied, checkout_makeup, dirty, prep_checkin, maintenance, reserved_closed
 */
return new class extends Migration
{
    public function up(): void
    {
        // Update all room statuses to lowercase
        DB::table('rooms')->update([
            'status' => DB::raw('LOWER(status)')
        ]);
    }

    public function down(): void
    {
        // No rollback needed — lowercase is the new standard
    }
};