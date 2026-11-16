<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A day of someone's Daily Time Record, as imported from the DTR system.
        Schema::create('dtr_days', function (Blueprint $table) {
            $table->id();
            $table->foreignId('traveler_id')->constrained()->cascadeOnDelete();
            $table->date('date');
            // As printed ("06:43"); blank is null.
            $table->string('am_arrival', 5)->nullable();
            $table->string('am_departure', 5)->nullable();
            $table->string('pm_arrival', 5)->nullable();
            $table->string('pm_departure', 5)->nullable();
            $table->unsignedSmallInteger('undertime_hours')->nullable();
            $table->unsignedSmallInteger('undertime_minutes')->nullable();
            // "NON-WORKING DAY", "HALF DAY": printed across the times it covers.
            $table->string('remark', 60)->nullable();
            $table->json('remark_slots');
            $table->timestamps();

            $table->unique(['traveler_id', 'date']);
        });

        // Why a time (or a whole day) is blank, noted by the person whose DTR it is.
        Schema::create('dtr_notes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('traveler_id')->constrained()->cascadeOnDelete();
            $table->date('starts_on');
            $table->date('ends_on');
            // Which times it explains; empty means the whole day.
            $table->json('slots');
            $table->string('reason', 30);
            $table->string('details')->nullable();
            // The time it really was, e.g. when the biometric was down.
            $table->string('actual_time', 5)->nullable();
            $table->timestamps();

            $table->index(['traveler_id', 'starts_on', 'ends_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dtr_notes');
        Schema::dropIfExists('dtr_days');
    }
};
