<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Lunch provided at the activity: per diem is less on each day there,
     * but not on the days going and coming back.
     */
    public function up(): void
    {
        foreach (['cash_advances', 'liquidations'] as $table) {
            Schema::table($table, function (Blueprint $table) {
                $table->boolean('lunch_provided')->default(false)->after('destination_code');
            });
        }
    }

    public function down(): void
    {
        foreach (['cash_advances', 'liquidations'] as $table) {
            Schema::table($table, function (Blueprint $table) {
                $table->dropColumn('lunch_provided');
            });
        }
    }
};
