<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Request for Airline Booking: the flight, shared by everyone on it.
        Schema::create('booking_requests', function (Blueprint $table) {
            $table->id();
            $table->string('travel_order_no', 50)->index();
            $table->text('purpose');
            $table->string('fund_source', 60);
            $table->string('project')->nullable();

            $table->string('trip', 20);
            $table->string('airline', 20);
            $table->string('origin', 100);
            $table->string('destination', 100);
            $table->date('departure_date');
            $table->string('departure_etd', 60)->nullable();
            $table->date('return_date')->nullable();
            $table->string('return_etd', 60)->nullable();
            $table->string('baggage_justification')->nullable();
            $table->boolean('boarding_passes_submitted')->nullable();

            $table->string('requested_by');
            $table->date('requested_on');
            $table->json('signatories');

            // Tracking after it leaves: sent to the Supply Unit, then booked.
            $table->date('submitted_on')->nullable();
            $table->string('booking_reference', 100)->nullable();
            $table->timestamps();
        });

        // One per passenger column on the form.
        Schema::create('booking_passengers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('booking_request_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('position');
            $table->foreignId('traveler_id')->nullable()->constrained()->nullOnDelete();
            $table->string('title', 5)->nullable();
            $table->string('first_name', 100);
            $table->string('last_name', 100);
            $table->string('remarks', 100)->nullable();
            $table->string('id_number', 60)->nullable();
            $table->string('contact_number', 30)->nullable();
            $table->string('email')->nullable();
            $table->date('birth_date')->nullable();
            $table->boolean('going_baggage')->default(false);
            $table->boolean('return_baggage')->default(false);
            $table->timestamps();
        });

        // What a booking needs from a traveller, saved for their next one.
        Schema::table('travelers', function (Blueprint $table) {
            $table->string('title', 5)->nullable()->after('terminal_distance');
            $table->string('first_name', 100)->nullable()->after('title');
            $table->string('last_name', 100)->nullable()->after('first_name');
            $table->date('birth_date')->nullable()->after('last_name');
            $table->string('contact_number', 30)->nullable()->after('birth_date');
            $table->string('email')->nullable()->after('contact_number');
            $table->string('id_number', 60)->nullable()->after('email');
        });
    }

    public function down(): void
    {
        Schema::table('travelers', function (Blueprint $table) {
            $table->dropColumn(['title', 'first_name', 'last_name', 'birth_date', 'contact_number', 'email', 'id_number']);
        });
        Schema::dropIfExists('booking_passengers');
        Schema::dropIfExists('booking_requests');
    }
};
