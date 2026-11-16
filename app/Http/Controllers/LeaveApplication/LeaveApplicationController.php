<?php

namespace App\Http\Controllers\LeaveApplication;

use App\Enums\LeaveDetail;
use App\Enums\LeaveOutcome;
use App\Enums\LeaveType;
use App\Http\Controllers\Controller;
use App\Http\Requests\LeaveApplication\ListLeaveApplicationsRequest;
use App\Http\Requests\LeaveApplication\StoreLeaveApplicationRequest;
use App\Http\Requests\LeaveApplication\UpdateLeaveApplicationRequest;
use App\Http\Resources\LeaveApplication\LeaveApplicationResource;
use App\Http\Resources\LeaveApplication\LeaveApplicationSummaryResource;
use App\Models\LeaveApplication;
use App\Models\Traveler;
use App\Services\LeaveApplication\LeaveApplicationService;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class LeaveApplicationController extends Controller
{
    public function __construct(private LeaveApplicationService $leaves) {}

    public function index(ListLeaveApplicationsRequest $request): Response
    {
        $filters = $request->filters();

        return Inertia::render('LeaveApplication/Index', [
            'leaveApplications' => LeaveApplicationSummaryResource::collection($this->leaves->list($filters)),
            'summary' => $this->leaves->summary(),
            'filters' => $filters,
            'types' => LeaveType::options(),
        ]);
    }

    public function create(): Response
    {
        return $this->form(null);
    }

    public function store(StoreLeaveApplicationRequest $request): RedirectResponse
    {
        $result = $this->leaves->create($request->validated());
        $this->flash($result);

        return $result['status'] ? redirect()->route('leave-applications.edit', $result['data']) : back();
    }

    public function edit(LeaveApplication $leaveApplication): Response
    {
        return $this->form($leaveApplication);
    }

    public function update(UpdateLeaveApplicationRequest $request, LeaveApplication $leaveApplication): RedirectResponse
    {
        $this->flash($this->leaves->update($leaveApplication, $request->validated()));

        return back();
    }

    public function destroy(LeaveApplication $leaveApplication): RedirectResponse
    {
        $result = $this->leaves->delete($leaveApplication);
        $this->flash($result);

        return $result['status'] ? redirect()->route('leave-applications.index') : back();
    }

    private function form(?LeaveApplication $leave): Response
    {
        return Inertia::render('LeaveApplication/Edit', [
            'leaveApplication' => $leave ? new LeaveApplicationResource($leave) : null,
            'defaults' => $this->leaves->defaults(),
            // Saved people: picking one fills in their name and position.
            'travelers' => Traveler::orderBy('name')->get(['id', 'name', 'position', 'first_name', 'middle_name', 'last_name']),
            'options' => [
                'types' => LeaveType::options(),
                'details' => LeaveDetail::options(),
                'outcomes' => LeaveOutcome::options(),
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
