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
        Schema::create('flights', function (Blueprint $table) {
            $table->id();
            $table->date('date')->index();
            $table->time('hour');
            $table->foreignId('origin_airport_id')->constrained('airports')->cascadeOnDelete();
            $table->foreignId('destination_airport_id')->constrained('airports')->cascadeOnDelete();
            $table->string('flight_number')->index();
            $table->string('plane_model');
            $table->timestamps();

            $table->index(['origin_airport_id', 'destination_airport_id', 'date']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('flights');
    }
};
