<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Only the no-pending cash advance certification used it, and that is no longer generated. */
    public function up(): void
    {
        Schema::table('liquidations', function (Blueprint $table) {
            $table->dropColumn('cash_advance_date');
        });
    }

    public function down(): void
    {
        Schema::table('liquidations', function (Blueprint $table) {
            $table->date('cash_advance_date')->nullable()->after('cash_advance_amount');
        });
    }
};
