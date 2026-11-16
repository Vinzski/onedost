<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The Deposit Slip and Cheque Tracker module was removed. Its data was
     * exported to storage/app/backups/cheque-tracker-2026-10-07.json first.
     */
    public function up(): void
    {
        Schema::dropIfExists('cheque_notifications');
        Schema::dropIfExists('cheques');
        Schema::dropIfExists('offices');
    }

    public function down(): void
    {
        // Not restorable from here; the rows are in the JSON backup named above.
    }
};
