<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * An itinerary leg may leave its date blank — it then continues the day
     * above, as on the paper IOT. A cash advance is always its itinerary
     * total, so the separate amount goes.
     */
    public function up(): void
    {
        Schema::table('liquidation_items', function (Blueprint $table) {
            $table->date('date')->nullable()->change();
        });

        Schema::table('cash_advance_items', function (Blueprint $table) {
            $table->date('date')->nullable()->change();
        });

        Schema::table('cash_advances', function (Blueprint $table) {
            $table->dropColumn('amount');
        });
    }

    public function down(): void
    {
        Schema::table('cash_advances', function (Blueprint $table) {
            $table->decimal('amount', 12, 2)->nullable()->after('fund_cluster');
        });

        Schema::table('cash_advance_items', function (Blueprint $table) {
            $table->date('date')->nullable(false)->change();
        });

        Schema::table('liquidation_items', function (Blueprint $table) {
            $table->date('date')->nullable(false)->change();
        });
    }
};
