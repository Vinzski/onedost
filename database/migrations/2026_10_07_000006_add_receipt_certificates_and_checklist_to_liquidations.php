<?php

use Carbon\CarbonImmutable;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * CENRR certificates become their own list (particulars + amounts, up to
     * ₱300 each) instead of a "no receipt" tick on itinerary legs. Also adds
     * the two papers staff get elsewhere: the Travel Order and the
     * Certificate of Appearance.
     */
    public function up(): void
    {
        Schema::table('liquidations', function (Blueprint $table) {
            $table->json('receipt_certificates')->nullable()->after('other_expenses_amount');
            $table->boolean('has_travel_order')->default(false)->after('submitted_on');
            $table->boolean('has_certificate_of_appearance')->default(false)->after('has_travel_order');
        });

        // Ticked legs printed one certificate each; keep exactly that.
        DB::table('liquidation_items')
            ->where('receipt_less', true)
            ->where('transport_cost', '>', 0)
            ->orderBy('liquidation_id')
            ->orderBy('position')
            ->get()
            ->groupBy('liquidation_id')
            ->each(function ($items, $liquidationId) {
                $certificates = $items->map(fn ($item) => ['lines' => [[
                    'particulars' => 'Payment for Transportation '.(str_contains(strtolower($item->place), ' to ') ? 'from ' : 'for ')
                        .$item->place.' on '.CarbonImmutable::parse($item->date)->format('j F Y'),
                    'amount' => (float) $item->transport_cost,
                ]]])->values()->all();

                DB::table('liquidations')->where('id', $liquidationId)->update(['receipt_certificates' => json_encode($certificates)]);
            });

        Schema::table('liquidation_items', function (Blueprint $table) {
            $table->dropColumn('receipt_less');
        });
    }

    public function down(): void
    {
        Schema::table('liquidation_items', function (Blueprint $table) {
            $table->boolean('receipt_less')->default(false);
        });

        Schema::table('liquidations', function (Blueprint $table) {
            $table->dropColumn(['receipt_certificates', 'has_travel_order', 'has_certificate_of_appearance']);
        });
    }
};
