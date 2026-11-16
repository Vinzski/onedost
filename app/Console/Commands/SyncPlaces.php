<?php

namespace App\Console\Commands;

use App\Services\Travel\DestinationListBuilder;
use App\Services\Travel\Destinations;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('travel:sync-places {--output= : Write the list here instead of resources/data/places.json}')]
#[Description('Refresh the Philippine provinces, cities and municipalities (PSGC) offered as travel destinations')]
class SyncPlaces extends Command
{
    public function handle(DestinationListBuilder $builder): int
    {
        $path = $this->option('output') ?: Destinations::path();
        $list = $builder->sync($path);

        $this->info('Saved '.count($list['places']).' places in '.count($list['regions'])." regions to {$path}.");

        // A region with no rate would quietly fall back to the default per diem.
        $rated = array_map('strval', array_keys(config('onedost.liquidation.dte_rates')));
        if ($unrated = array_diff(array_keys($list['regions']), $rated)) {
            $this->warn('Add an EO 77 rate to config/onedost.php (dte_rates) for region '.implode(', ', $unrated).'.');
        }

        return self::SUCCESS;
    }
}
