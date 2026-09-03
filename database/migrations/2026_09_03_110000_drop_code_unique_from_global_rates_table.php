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
        });
    }

    public function down(): void
    {
        Schema::table('global_rates', function (Blueprint $table) {
            $table->unique('code', 'addon_rates_code_unique');
        });
    }
};
