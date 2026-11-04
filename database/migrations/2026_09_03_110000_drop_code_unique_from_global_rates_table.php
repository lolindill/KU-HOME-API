<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('global_rates', function (Blueprint $table) {
            // Drop unique constraint on code so multiple room types can share group rate codes (min_5_rooms, min_10_rooms)
            $table->dropUnique('addon_rates_code_unique');
            $table->index(['room_type_id', 'rate_type', 'code'], 'global_rates_type_code_index');
        });
    }

    public function down(): void
    {
        Schema::table('global_rates', function (Blueprint $table) {
            $table->dropIndex('global_rates_type_code_index');
            $table->unique('code', 'addon_rates_code_unique');
        });
    }
};
