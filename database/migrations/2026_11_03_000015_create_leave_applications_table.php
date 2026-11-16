<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Application for Leave (CSC Form No. 6): one per employee per leave.
        Schema::create('leave_applications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('traveler_id')->nullable()->constrained()->nullOnDelete();

            // 1–5: the employee, as on the form.
            $table->string('office', 100);
            $table->string('last_name', 100);
            $table->string('first_name', 100);
            $table->string('middle_name', 100)->nullable();
            $table->string('position', 100);
            $table->decimal('salary', 12, 2)->nullable();
            $table->date('filed_on');

            // 6.A–6.D: the leave.
            $table->string('leave_type', 30);
            $table->string('leave_type_other', 100)->nullable();
            $table->string('detail', 30)->nullable();
            $table->string('detail_text', 200)->nullable();
            $table->decimal('working_days', 5, 1);
            $table->date('start_date')->index();
            $table->date('end_date');
            // Blank: worded from the dates ("October 7-8, 2026").
            $table->string('inclusive_dates', 100)->nullable();
            $table->boolean('commutation_requested')->default(false);

            $table->json('signatories');

            // Tracking after it leaves: submitted, then approved or disapproved.
            $table->date('submitted_on')->nullable();
            $table->string('outcome', 20)->nullable();
            $table->timestamps();
        });

        // The leave form asks for the middle name.
        Schema::table('travelers', function (Blueprint $table) {
            $table->string('middle_name', 100)->nullable()->after('first_name');
        });
    }

    public function down(): void
    {
        Schema::table('travelers', function (Blueprint $table) {
            $table->dropColumn('middle_name');
        });
        Schema::dropIfExists('leave_applications');
    }
};
