<?php

namespace App\Http\Controllers;

use App\Enums\ClaimKind;
use App\Services\BookingRequest\BookingRequestService;
use App\Services\CashAdvance\CashAdvanceService;
use App\Services\Dtr\DtrService;
use App\Services\LeaveApplication\LeaveApplicationService;
use App\Services\Liquidation\LiquidationService;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function index(
        BookingRequestService $bookings,
        CashAdvanceService $cashAdvances,
        LiquidationService $liquidations,
        LeaveApplicationService $leaves,
        DtrService $dtr,
    ): Response {
        return Inertia::render('Dashboard', [
            'dtr' => $dtr->summary(),
            'leaveApplications' => $leaves->summary(),
            'bookingRequests' => $bookings->summary(),
            'cashAdvances' => $cashAdvances->summary(),
            'liquidations' => $liquidations->summary(ClaimKind::Liquidation),
            'reimbursements' => $liquidations->summary(ClaimKind::Reimbursement),
        ]);
    }
}
