<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Where the trip goes, as a PSGC code: its region sets the EO 77 per
     * diem. The venue stays free text for the forms.
     */
    public function up(): void
    {
        foreach (['cash_advances', 'liquidations'] as $table) {
            Schema::table($table, function (Blueprint $table) {
                $table->string('destination_code', 10)->nullable()->after('venue');
            });
        }
    }

    public function down(): void
    {
        foreach (['cash_advances', 'liquidations'] as $table) {
            Schema::table($table, function (Blueprint $table) {
                $table->dropColumn('destination_code');
            });
        }
    }
};
