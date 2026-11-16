<?php

namespace App\Http\Controllers\BookingRequest;

use App\Enums\Airline;
use App\Enums\FlightTrip;
use App\Http\Controllers\Controller;
use App\Http\Requests\BookingRequest\ListBookingRequestsRequest;
use App\Http\Requests\BookingRequest\StoreBookingRequestRequest;
use App\Http\Requests\BookingRequest\UpdateBookingRequestRequest;
use App\Http\Resources\BookingRequest\BookingRequestResource;
use App\Http\Resources\BookingRequest\BookingRequestSummaryResource;
use App\Models\BookingRequest;
use App\Models\Traveler;
use App\Services\BookingRequest\BookingRequestService;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class BookingRequestController extends Controller
{
    public function __construct(private BookingRequestService $bookings) {}

    public function index(ListBookingRequestsRequest $request): Response
    {
        $filters = $request->filters();

        return Inertia::render('BookingRequest/Index', [
            'bookingRequests' => BookingRequestSummaryResource::collection($this->bookings->list($filters)),
            'summary' => $this->bookings->summary(),
            'filters' => $filters,
        ]);
    }

    public function create(): Response
    {
        return $this->form(null);
    }

    public function store(StoreBookingRequestRequest $request): RedirectResponse
    {
        $result = $this->bookings->create($request->validated());
        $this->flash($result);

        return $result['status'] ? redirect()->route('booking-requests.edit', $result['data']) : back();
    }

    public function edit(BookingRequest $bookingRequest): Response
    {
        return $this->form($bookingRequest->load('passengers'));
    }

    public function update(UpdateBookingRequestRequest $request, BookingRequest $bookingRequest): RedirectResponse
    {
        $this->flash($this->bookings->update($bookingRequest, $request->validated()));

        return back();
    }

    public function destroy(BookingRequest $bookingRequest): RedirectResponse
    {
        $result = $this->bookings->delete($bookingRequest);
        $this->flash($result);

        return $result['status'] ? redirect()->route('booking-requests.index') : back();
    }

    private function form(?BookingRequest $booking): Response
    {
        return Inertia::render('BookingRequest/Edit', [
            'bookingRequest' => $booking ? new BookingRequestResource($booking) : null,
            'defaults' => $this->bookings->defaults(),
            // Saved travellers: picking one fills in their booking details.
            'travelers' => Traveler::orderBy('name')->get(['id', 'name', 'position', ...Traveler::BOOKING_FIELDS]),
            // A new request can start from a travel order, with everyone on it.
            'travelOrders' => $booking ? [] : $this->bookings->travelOrders(),
            'options' => [
                'airlines' => Airline::options(),
                'trips' => FlightTrip::options(),
                'fundClusters' => config('onedost.liquidation.fund_clusters'),
                'destinations' => config('onedost.booking.destinations'),
                'passengersPerForm' => config('onedost.booking.passengers_per_form'),
            ],
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
