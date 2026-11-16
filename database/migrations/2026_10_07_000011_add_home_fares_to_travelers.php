<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Where each traveller lives and how far, as bands from the fares memo
     * (config onedost.liquidation.home_fares): sets what they claim for rides
     * from home to the office, airport, wharf or bus terminal.
     */
    public function up(): void
    {
        Schema::table('travelers', function (Blueprint $table) {
            $table->string('home_area', 20)->nullable()->after('division');
            $table->string('office_distance', 30)->nullable()->after('home_area');
            $table->string('terminal_distance', 30)->nullable()->after('office_distance');
        });
    }

    public function down(): void
    {
        Schema::table('travelers', function (Blueprint $table) {
            $table->dropColumn(['home_area', 'office_distance', 'terminal_distance']);
        });
    }
};
