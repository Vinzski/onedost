<?php

namespace App\Services\Travel;

use App\Models\Traveler;
use Illuminate\Support\Collection;

/** Settings and saved travellers that both travel forms (cash advance, claim) need. */
class TripFormOptions
{
    public function options(): array
    {
        $settings = config('onedost.liquidation');

        return [
            'fundClusters' => $settings['fund_clusters'],
            'transportMeans' => $settings['transport_means'],
            'perDiemRate' => $settings['per_diem_rate'],
            'dteRates' => $settings['dte_rates'],
            'lunchDeduction' => $settings['lunch_deduction'],
            'officialStationPlace' => $settings['official_station_place'],
            'commuteFare' => $settings['commute_fare'],
            'terminalFare' => $settings['terminal_fare'],
            'homeFares' => $settings['home_fares'],
            'homeArea' => $settings['home_area'],
            'terminals' => $settings['terminals'],
            'taxiWithoutReceipt' => $settings['taxi_without_receipt'],
            'cenrrMaxAmount' => $settings['cenrr_max_amount'],
            'deadlineDays' => $settings['deadline_days'],
            'vramsUrl' => $settings['vrams_url'],
        ];
    }

    /** Saved travellers: the name picker fills the rest from these, fares included. */
    public function travelers(): Collection
    {
        return Traveler::orderBy('name')->get(['name', 'position', 'employee_no', 'official_station', 'division', ...HomeFares::TRAVELER_FIELDS]);
    }
}
