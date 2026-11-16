<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Reimbursements reuse the liquidation record: same papers minus the
     * Liquidation Report, and never tied to a cash advance.
     */
    public function up(): void
    {
        Schema::table('liquidations', function (Blueprint $table) {
            $table->string('kind', 15)->default('liquidation')->after('id')->index();
        });
    }

    public function down(): void
    {
        Schema::table('liquidations', function (Blueprint $table) {
            $table->dropIndex(['kind']);
            $table->dropColumn('kind');
        });
    }
};
