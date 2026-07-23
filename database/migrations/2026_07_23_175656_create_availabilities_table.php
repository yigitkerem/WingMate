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
        Schema::create('availabilities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('flight_id')->constrained()->cascadeOnDelete();
            $table->string('class');
            $table->unsignedSmallInteger('checked_baggage_kg');
            $table->unsignedSmallInteger('cabin_baggage_kg');
            $table->unsignedInteger('change_fee_usd');
            $table->unsignedInteger('refund_fee_usd');
            $table->unsignedSmallInteger('latest_refund_hours')->nullable();
            $table->unsignedSmallInteger('latest_change_hours')->nullable();
            $table->string('class_letters');
            $table->unsignedInteger('base_price_usd');
            $table->unsignedSmallInteger('count_available');
            $table->timestamps();

            $table->index(['flight_id', 'class']);
            $table->index(['flight_id', 'class_letters']);
            $table->index(['count_available', 'base_price_usd']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('availabilities');
    }
};
