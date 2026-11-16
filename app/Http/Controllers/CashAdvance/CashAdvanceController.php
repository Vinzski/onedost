<?php

namespace App\Http\Controllers\CashAdvance;

use App\Http\Controllers\Controller;
use App\Http\Requests\CashAdvance\ListCashAdvancesRequest;
use App\Http\Requests\CashAdvance\StoreCashAdvanceRequest;
use App\Http\Requests\CashAdvance\UpdateCashAdvanceRequest;
use App\Http\Requests\Travel\AddTravelersRequest;
use App\Http\Resources\CashAdvance\CashAdvanceResource;
use App\Http\Resources\CashAdvance\CashAdvanceSummaryResource;
use App\Models\CashAdvance;
use App\Services\CashAdvance\CashAdvanceService;
use App\Services\Travel\TripFormOptions;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class CashAdvanceController extends Controller
{
    public function __construct(
        private CashAdvanceService $cashAdvances,
        private TripFormOptions $tripForms,
    ) {}

    public function index(ListCashAdvancesRequest $request): Response
    {
        $filters = $request->filters();

        return Inertia::render('CashAdvance/Index', [
            'cashAdvances' => CashAdvanceSummaryResource::collection($this->cashAdvances->list($filters)),
            'summary' => $this->cashAdvances->summary(),
            'filters' => $filters,
            'deadlineDays' => config('onedost.liquidation.deadline_days'),
        ]);
    }

    public function create(): Response
    {
        return $this->form(null);
    }

    public function store(StoreCashAdvanceRequest $request): RedirectResponse
    {
        $result = $this->cashAdvances->create($request->validated());
        $this->flash($result);

        return $result['status'] ? redirect()->route('cash-advances.edit', $result['data']) : back();
    }

    public function edit(CashAdvance $cashAdvance): Response
    {
        return $this->form($cashAdvance->load(['items', 'liquidation']));
    }

    public function update(UpdateCashAdvanceRequest $request, CashAdvance $cashAdvance): RedirectResponse
    {
        $this->flash($this->cashAdvances->update($cashAdvance, $request->validated()));

        return back();
    }

    public function destroy(CashAdvance $cashAdvance): RedirectResponse
    {
        $result = $this->cashAdvances->delete($cashAdvance);
        $this->flash($result);

        return $result['status'] ? redirect()->route('cash-advances.index') : back();
    }

    /** Others on the same travel order: a cash advance each, with their own fares. */
    public function addTravelers(AddTravelersRequest $request, CashAdvance $cashAdvance): RedirectResponse
    {
        $this->flash($this->cashAdvances->addTravelers($cashAdvance->load('items'), $request->validated('travelers')));

        return back();
    }

    /** Start (or open) the liquidation, copied from this cash advance. */
    public function liquidate(CashAdvance $cashAdvance): RedirectResponse
    {
        $result = $this->cashAdvances->liquidate($cashAdvance->load(['items', 'liquidation']));
        $this->flash($result);

        return $result['status'] ? redirect()->route('liquidations.edit', $result['data']) : back();
    }

    private function form(?CashAdvance $cashAdvance): Response
    {
        return Inertia::render('CashAdvance/Edit', [
            'cashAdvance' => $cashAdvance ? new CashAdvanceResource($cashAdvance) : null,
            'defaults' => $this->cashAdvances->defaults(),
            'travelers' => $this->tripForms->travelers(),
            // Everyone on this travel order, this one included.
            'team' => $cashAdvance ? $this->cashAdvances->team($cashAdvance)->with('items')->get()
                ->map(fn (CashAdvance $member) => [
                    'id' => $member->id,
                    'traveler_name' => $member->traveler_name,
                    'position' => $member->position,
                    'amount' => $member->requestedAmount(),
                ]) : [],
            'options' => $this->tripForms->options(),
        ]);
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
