<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('airports', function (Blueprint $table): void {
            $table->id();
            $table->string('iata_code', 3)->unique();
            $table->string('icao_code', 4)->nullable()->unique();
            $table->string('name');
            $table->string('city');
            $table->string('country', 2);
            $table->string('timezone');
            $table->timestamps();
        });

        Schema::create('flights', function (Blueprint $table): void {
            $table->id();
            $table->string('flight_number')->index();
            $table->foreignId('origin_airport_id')->constrained('airports')->cascadeOnDelete();
            $table->foreignId('destination_airport_id')->constrained('airports')->cascadeOnDelete();
            $table->dateTime('departure_at')->index();
            $table->dateTime('arrival_at')->index();
            $table->unsignedSmallInteger('duration_minutes');
            $table->string('aircraft_type');
            $table->string('status')->default('scheduled')->index();
            $table->timestamps();

            $table->index(['origin_airport_id', 'destination_airport_id', 'departure_at'], 'flights_route_departure_idx');
        });

        Schema::create('cabins', function (Blueprint $table): void {
            $table->id();
            $table->string('code')->unique();
            $table->string('name');
            $table->unsignedSmallInteger('display_order')->default(0);
            $table->timestamps();
        });

        Schema::create('booking_classes', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 4)->unique();
            $table->foreignId('cabin_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('priority')->default(0)->index();
            $table->timestamps();
        });

        Schema::create('flight_inventories', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('flight_id')->constrained()->cascadeOnDelete();
            $table->foreignId('booking_class_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('capacity');
            $table->unsignedSmallInteger('available');
            $table->timestamps();

            $table->unique(['flight_id', 'booking_class_id']);
        });

        Schema::create('products', function (Blueprint $table): void {
            $table->id();
            $table->string('code')->unique();
            $table->string('name');
            $table->text('description')->nullable();
            $table->boolean('active')->default(true)->index();
            $table->timestamps();
        });

        Schema::create('bundles', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->string('code')->unique();
            $table->string('name');
            $table->text('description')->nullable();
            $table->boolean('active')->default(true)->index();
            $table->boolean('public')->default(true)->index();
            $table->unsignedSmallInteger('display_order')->default(0)->index();
            $table->timestamps();
        });

        Schema::create('services', function (Blueprint $table): void {
            $table->id();
            $table->string('code')->unique();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('category')->index();
            $table->string('value_type')->default('boolean');
            $table->string('default_unit')->nullable();
            $table->boolean('active')->default(true)->index();
            $table->timestamps();
        });

        Schema::create('bundle_services', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('bundle_id')->constrained()->cascadeOnDelete();
            $table->foreignId('service_id')->constrained()->cascadeOnDelete();
            $table->json('included_value')->nullable();
            $table->boolean('included')->default(true);
            $table->timestamps();

            $table->unique(['bundle_id', 'service_id']);
        });

        Schema::create('service_prices', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('service_id')->constrained()->cascadeOnDelete();
            $table->foreignId('bundle_id')->nullable()->constrained()->nullOnDelete();
            $table->string('currency', 3)->default('USD');
            $table->decimal('unit_price', 10, 2)->default(0);
            $table->unsignedSmallInteger('min_quantity')->default(0);
            $table->unsignedSmallInteger('max_quantity')->default(1);
            $table->dateTime('valid_from')->nullable()->index();
            $table->dateTime('valid_until')->nullable()->index();
            $table->boolean('active')->default(true)->index();
            $table->timestamps();
        });

        Schema::create('service_constraints', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('service_id')->constrained()->cascadeOnDelete();
            $table->string('type')->index();
            $table->foreignId('related_service_id')->nullable()->constrained('services')->nullOnDelete();
            $table->json('parameters')->nullable();
            $table->string('message')->nullable();
            $table->boolean('active')->default(true)->index();
            $table->timestamps();
        });

        Schema::create('base_fares', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('flight_id')->constrained()->cascadeOnDelete();
            $table->foreignId('booking_class_id')->constrained()->cascadeOnDelete();
            $table->foreignId('bundle_id')->constrained()->cascadeOnDelete();
            $table->string('trip_type')->default('one_way')->index();
            $table->unsignedTinyInteger('leg_index')->default(1);
            $table->string('currency', 3)->default('USD');
            $table->decimal('base_price', 10, 2);
            $table->decimal('taxes', 10, 2)->default(0);
            $table->decimal('fees', 10, 2)->default(0);
            $table->string('fare_basis_template');
            $table->string('class_letters');
            $table->dateTime('valid_from')->nullable()->index();
            $table->dateTime('valid_until')->nullable()->index();
            $table->boolean('active')->default(true)->index();
            $table->timestamps();

            $table->index(['flight_id', 'trip_type', 'active']);
            $table->index(['booking_class_id', 'bundle_id']);
        });

        Schema::create('pricing_rules', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('preset')->nullable()->index();
            $table->unsignedSmallInteger('priority')->default(100)->index();
            $table->boolean('active')->default(true)->index();
            $table->boolean('stackable')->default(true);
            $table->text('condition_expression');
            $table->json('actions');
            $table->dateTime('valid_from')->nullable()->index();
            $table->dateTime('valid_until')->nullable()->index();
            $table->timestamps();
        });

        Schema::create('price_import_batches', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('source')->default('thy_mcp')->index();
            $table->string('status')->default('draft')->index();
            $table->json('search_parameters');
            $table->json('raw_response')->nullable();
            $table->unsignedInteger('imported_count')->default(0);
            $table->text('message')->nullable();
            $table->timestamps();
        });

        Schema::create('price_import_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('price_import_batch_id')->constrained()->cascadeOnDelete();
            $table->foreignId('flight_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('base_fare_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status')->default('preview')->index();
            $table->json('mapped_payload');
            $table->json('raw_payload')->nullable();
            $table->timestamps();
        });

        Schema::create('offers', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('flight_id')->constrained()->cascadeOnDelete();
            $table->foreignId('booking_class_id')->constrained()->cascadeOnDelete();
            $table->foreignId('bundle_id')->constrained()->cascadeOnDelete();
            $table->foreignId('base_fare_id')->constrained()->cascadeOnDelete();
            $table->string('trip_type')->default('one_way')->index();
            $table->unsignedTinyInteger('leg_index')->default(1)->index();
            $table->string('currency', 3)->default('USD');
            $table->decimal('base_price', 10, 2);
            $table->decimal('taxes', 10, 2)->default(0);
            $table->decimal('fees', 10, 2)->default(0);
            $table->decimal('services_total', 10, 2)->default(0);
            $table->decimal('discount', 10, 2)->default(0);
            $table->decimal('total_price', 10, 2);
            $table->string('fare_basis_code');
            $table->string('class_letters');
            $table->unsignedTinyInteger('adults')->default(1);
            $table->unsignedTinyInteger('children')->default(0);
            $table->unsignedTinyInteger('infants')->default(0);
            $table->json('context')->nullable();
            $table->dateTime('expires_at')->index();
            $table->timestamps();

            $table->index(['flight_id', 'bundle_id', 'expires_at']);
        });

        Schema::create('offer_services', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('offer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('service_id')->constrained()->cascadeOnDelete();
            $table->json('value')->nullable();
            $table->unsignedSmallInteger('quantity')->default(1);
            $table->boolean('included')->default(true);
            $table->decimal('price', 10, 2)->default(0);
            $table->string('source')->default('bundle')->index();
            $table->timestamps();
        });

        Schema::create('price_components', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('offer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('pricing_rule_id')->nullable()->constrained()->nullOnDelete();
            $table->string('code')->index();
            $table->string('label');
            $table->string('type')->index();
            $table->decimal('amount', 10, 2);
            $table->json('meta')->nullable();
            $table->timestamps();
        });

        Schema::create('orders', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('booking_reference')->unique();
            $table->string('status')->default('confirmed')->index();
            $table->string('first_name');
            $table->string('last_name');
            $table->string('email')->nullable()->index();
            $table->string('passport_number')->nullable()->index();
            $table->string('currency', 3)->default('USD');
            $table->decimal('total_price', 10, 2)->default(0);
            $table->json('passengers')->nullable();
            $table->timestamps();
        });

        Schema::create('tickets', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('offer_id')->constrained()->cascadeOnDelete();
            $table->string('ticket_number')->unique();
            $table->string('passenger_type')->default('ADT')->index();
            $table->string('status')->default('issued')->index();
            $table->dateTime('issued_at')->nullable()->index();
            $table->timestamps();
        });

        Schema::create('ticket_segments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('ticket_id')->constrained()->cascadeOnDelete();
            $table->foreignId('flight_id')->constrained()->cascadeOnDelete();
            $table->foreignId('booking_class_id')->constrained()->cascadeOnDelete();
            $table->string('coupon_status')->default('open')->index();
            $table->timestamps();
        });

        Schema::create('rule_execution_logs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('offer_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('pricing_rule_id')->constrained()->cascadeOnDelete();
            $table->boolean('matched')->default(false)->index();
            $table->json('context')->nullable();
            $table->json('actions_applied')->nullable();
            $table->text('message')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rule_execution_logs');
        Schema::dropIfExists('ticket_segments');
        Schema::dropIfExists('tickets');
        Schema::dropIfExists('orders');
        Schema::dropIfExists('price_components');
        Schema::dropIfExists('offer_services');
        Schema::dropIfExists('offers');
        Schema::dropIfExists('price_import_items');
        Schema::dropIfExists('price_import_batches');
        Schema::dropIfExists('pricing_rules');
        Schema::dropIfExists('base_fares');
        Schema::dropIfExists('service_constraints');
        Schema::dropIfExists('service_prices');
        Schema::dropIfExists('bundle_services');
        Schema::dropIfExists('services');
        Schema::dropIfExists('bundles');
        Schema::dropIfExists('products');
        Schema::dropIfExists('flight_inventories');
        Schema::dropIfExists('booking_classes');
        Schema::dropIfExists('cabins');
        Schema::dropIfExists('flights');
        Schema::dropIfExists('airports');
    }
};
