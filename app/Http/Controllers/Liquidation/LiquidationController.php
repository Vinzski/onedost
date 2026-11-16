<?php

namespace App\Http\Controllers\Liquidation;

use App\Enums\ClaimKind;
use App\Enums\TravelCompliance;
use App\Http\Controllers\Controller;
use App\Http\Requests\Liquidation\ListLiquidationsRequest;
use App\Http\Requests\Liquidation\StoreLiquidationRequest;
use App\Http\Requests\Liquidation\UpdateLiquidationRequest;
use App\Http\Requests\Travel\AddTravelersRequest;
use App\Http\Resources\Liquidation\LiquidationResource;
use App\Http\Resources\Liquidation\LiquidationSummaryResource;
use App\Models\CashAdvance;
use App\Models\Liquidation;
use App\Services\Liquidation\LiquidationService;
use App\Services\Travel\TripFormOptions;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Travel claims after the trip — served under /liquidations and
 * /reimbursements; the route name decides which kind (ClaimKind::fromRoute).
 */
class LiquidationController extends Controller
{
    public function __construct(
        private LiquidationService $liquidations,
        private TripFormOptions $tripForms,
    ) {}

    public function index(ListLiquidationsRequest $request): Response
    {
        $kind = ClaimKind::fromRoute($request->route());
        $filters = $request->filters();

        return Inertia::render('Liquidation/Index', [
            'liquidations' => LiquidationSummaryResource::collection($this->liquidations->list($filters, $kind)),
            'summary' => $this->liquidations->summary($kind),
            'filters' => $filters,
            'deadlineDays' => config('onedost.liquidation.deadline_days'),
            ...$this->kindProps($kind),
        ]);
    }

    public function create(): Response
    {
        return $this->form(null, ClaimKind::fromRoute(request()->route()));
    }

    public function store(StoreLiquidationRequest $request): RedirectResponse
    {
        $kind = ClaimKind::fromRoute($request->route());
        $result = $this->liquidations->create($request->safe()->except('photos'), $request->file('photos', []), $kind);
        $this->flash($result);

        return $result['status']
            ? redirect()->route($kind->routePrefix().'.edit', $result['data'])
            : back();
    }

    public function edit(Liquidation $liquidation): Response
    {
        return $this->form($liquidation->load(['items', 'photos', 'cashAdvance']), $liquidation->kind);
    }

    public function update(UpdateLiquidationRequest $request, Liquidation $liquidation): RedirectResponse
    {
        $this->flash($this->liquidations->update($liquidation, $request->validated()));

        return back();
    }

    public function destroy(Liquidation $liquidation): RedirectResponse
    {
        $result = $this->liquidations->delete($liquidation);
        $this->flash($result);

        return $result['status'] ? redirect()->route($liquidation->kind->routePrefix().'.index') : back();
    }

    /** Others on the same travel order: a claim each, with their own fares. */
    public function addTravelers(AddTravelersRequest $request, Liquidation $liquidation): RedirectResponse
    {
        $this->flash($this->liquidations->addTravelers($liquidation->load(['items', 'photos']), $request->validated('travelers')));

        return back();
    }

    private function form(?Liquidation $liquidation, ClaimKind $kind): Response
    {
        return Inertia::render('Liquidation/Edit', [
            'liquidation' => $liquidation ? new LiquidationResource($liquidation) : null,
            'defaults' => $this->liquidations->defaults(),
            // A new liquidation can start from a cash advance that has none yet; reimbursements never do.
            'cashAdvances' => $liquidation || $kind === ClaimKind::Reimbursement ? [] : CashAdvance::with('items')->doesntHave('liquidation')
                ->orderByDesc('departure_date')->limit(20)->get()
                ->map(fn (CashAdvance $ca) => [
                    'id' => $ca->id,
                    'traveler_name' => $ca->traveler_name,
                    'travel_order_no' => $ca->travel_order_no,
                    'travel_dates' => $ca->travelDates(),
                    'venue' => $ca->venue,
                    'amount' => $ca->requestedAmount(),
                ]),
            'travelers' => $this->tripForms->travelers(),
            // Everyone of this kind on this travel order, this one included.
            'team' => $liquidation ? $this->liquidations->team($liquidation)->get()
                ->map(fn (Liquidation $member) => [
                    'id' => $member->id,
                    'traveler_name' => $member->traveler_name,
                    'position' => $member->position,
                    'submitted' => $member->submitted_on !== null,
                ]) : [],
            'options' => [
                ...$this->tripForms->options(),
                'compliance' => TravelCompliance::options(),
                'maxPhotos' => LiquidationService::MAX_PHOTOS,
                'cenrrMaxLines' => config('onedost.liquidation.cenrr_max_lines'),
            ],
            ...$this->kindProps($kind),
        ]);
    }

    /** Which kind the page shows, and the route names it links with. */
    private function kindProps(ClaimKind $kind): array
    {
        return ['kind' => $kind->value, 'routePrefix' => $kind->routePrefix()];
    }

    private function flash(array $result): void
    {
        Inertia::flash([
            'message' => $result['message'],
            'info' => $result['info'],
            'status' => $result['status'],
        ]);
    }
}
