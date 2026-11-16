<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Saved traveller details, so the next liquidation fills them in.
        Schema::create('travelers', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->string('position');
            $table->string('employee_no', 30)->nullable();
            $table->string('official_station');
            $table->string('division');
            $table->timestamps();
        });

        Schema::create('liquidations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('traveler_id')->nullable()->constrained()->nullOnDelete();

            // Traveller as printed on this liquidation's forms.
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

            $table->decimal('cash_advance_amount', 12, 2)->nullable();
            $table->date('cash_advance_date')->nullable();
            $table->string('dv_no', 50)->nullable();
            $table->date('dv_date')->nullable();
            $table->string('or_no', 50)->nullable();
            $table->date('or_date')->nullable();
            $table->decimal('supplies_amount', 12, 2)->default(0);
            $table->decimal('other_expenses_amount', 12, 2)->default(0);

            $table->json('persons_contacted')->nullable();
            $table->text('output_summary')->nullable();
            $table->text('follow_up')->nullable();

            $table->string('compliance', 15)->default('strict');
            $table->text('compliance_note')->nullable();
            $table->json('evidence')->nullable();

            $table->string('activity_title')->nullable();
            $table->text('activity_body')->nullable();

            $table->json('signatories');
            $table->date('submitted_on')->nullable();
            $table->timestamps();

            $table->index('return_date');
        });

        Schema::create('liquidation_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('liquidation_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('position');
            $table->date('date');
            $table->string('place');
            $table->string('departure_time', 5)->nullable();
            $table->string('arrival_time', 5)->nullable();
            $table->string('transport_means', 40)->nullable();
            $table->decimal('transport_cost', 10, 2)->default(0);
            $table->decimal('per_diem', 10, 2)->default(0);
            $table->decimal('other_cost', 10, 2)->default(0);
            // A fare with no receipt gets its own certificate (CENRR).
            $table->boolean('receipt_less')->default(false);
        });

        Schema::create('liquidation_photos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('liquidation_id')->constrained()->cascadeOnDelete();
            $table->string('path');
            $table->string('original_name');
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('liquidation_photos');
        Schema::dropIfExists('liquidation_items');
        Schema::dropIfExists('liquidations');
        Schema::dropIfExists('travelers');
    }
};
