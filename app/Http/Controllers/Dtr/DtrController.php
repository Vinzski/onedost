<?php

namespace App\Http\Controllers\Dtr;

use App\Enums\DtrReason;
use App\Enums\DtrSlot;
use App\Http\Controllers\Controller;
use App\Http\Requests\Dtr\ShowDtrRequest;
use App\Models\Traveler;
use App\Services\Dtr\DtrService;
use Carbon\CarbonImmutable;
use Inertia\Inertia;
use Inertia\Response;

class DtrController extends Controller
{
    public function __construct(private DtrService $dtr) {}

    public function index(ShowDtrRequest $request): Response
    {
        [$person, $month] = $this->pick($request);

        return Inertia::render('Dtr/Index', [
            ...$this->page($person, $month),
            'people' => $this->dtr->people(),
            'elsewhere' => $person ? $this->dtr->monthsToExplain($person, except: $month) : [],
            'options' => [
                'slots' => DtrSlot::options(),
                'reasons' => DtrReason::options(),
            ],
        ]);
    }

    /** The month's blanks and their explanations, to print for the In-Charge. */
    public function print(ShowDtrRequest $request): Response
    {
        [$person, $month] = $this->pick($request);
        abort_if($person === null, 404);

        return Inertia::render('Dtr/Print', [
            ...$this->page($person, $month),
            'slots' => DtrSlot::options(),
            'reasons' => DtrReason::options(),
            'inCharge' => ['name' => config('onedost.dtr.in_charge_name'), 'title' => config('onedost.dtr.in_charge_title')],
        ]);
    }

    /** @return array{0: ?Traveler, 1: CarbonImmutable} */
    private function pick(ShowDtrRequest $request): array
    {
        return [
            $request->person() ?? $this->dtr->defaultPerson(),
            ($request->month() ?? $this->dtr->today())->startOfMonth(),
        ];
    }

    private function page(?Traveler $person, CarbonImmutable $month): array
    {
        return [
            'person' => $person?->only(['id', 'name']),
            'month' => [
                'key' => $month->format('Y-m'),
                'label' => $month->format('F Y'),
                'previous' => $month->subMonth()->format('Y-m'),
                'next' => $month->addMonth()->format('Y-m'),
            ],
            'today' => $this->dtr->today()->toDateString(),
            'record' => $person ? $this->dtr->month($person, $month) : null,
        ];
    }
}
