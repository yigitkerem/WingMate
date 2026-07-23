<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('availabilities', function (Blueprint $table) {
            $table->string('fare_type')->default('one_way')->after('class_letters')->index();
            $table->index(['flight_id', 'fare_type', 'base_price_usd']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('availabilities', function (Blueprint $table) {
            $table->dropIndex(['flight_id', 'fare_type', 'base_price_usd']);
            $table->dropColumn('fare_type');
        });
    }
};
