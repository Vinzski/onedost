<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A cash advance request before travel: the planned Itinerary of Travel
     * and the no-pending cash advance certification. Its liquidation, once
     * started, is copied from it and points back here.
     */
    public function up(): void
    {
        Schema::create('cash_advances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('traveler_id')->nullable()->constrained()->nullOnDelete();

            $table->string('traveler_name');
            $table->string('position');
            $table->string('employee_no', 30)->nullable();
            $table->string('official_station');
            $table->string('division');

            $table->string('travel_order_no', 50);
            $table->date('travel_order_date')->nullable();
            $table->date('departure_date');
            $table->date('return_date');
            $table->string('venue');
            $table->text('purpose');
            $table->string('fund_cluster', 60);

            // Blank means "the itinerary total".
            $table->decimal('amount', 12, 2)->nullable();
            $table->date('requested_on');

            $table->json('signatories');
            $table->boolean('has_travel_order')->default(false);
            $table->timestamps();

            $table->index('return_date');
        });

        Schema::create('cash_advance_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cash_advance_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('position');
            $table->date('date');
            $table->string('place');
            $table->string('departure_time', 5)->nullable();
            $table->string('arrival_time', 5)->nullable();
            $table->string('transport_means', 40)->nullable();
            $table->decimal('transport_cost', 10, 2)->default(0);
            $table->decimal('per_diem', 10, 2)->default(0);
            $table->decimal('other_cost', 10, 2)->default(0);
        });

        Schema::table('liquidations', function (Blueprint $table) {
            $table->foreignId('cash_advance_id')->nullable()->unique()->after('traveler_id')
                ->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('liquidations', function (Blueprint $table) {
            $table->dropConstrainedForeignId('cash_advance_id');
        });
        Schema::dropIfExists('cash_advance_items');
        Schema::dropIfExists('cash_advances');
    }
};
