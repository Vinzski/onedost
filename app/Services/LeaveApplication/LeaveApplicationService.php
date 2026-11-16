<?php

namespace App\Services\LeaveApplication;

use App\Enums\LeaveDetail;
use App\Enums\LeaveType;
use App\Models\LeaveApplication;
use App\Models\Traveler;
use App\Traits\HandlesTransaction;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

class LeaveApplicationService
{
    use HandlesTransaction;

    public const PER_PAGE = 20;

    /**
     * @param  array{search?: ?string, status?: ?string, type?: ?string}  $filters
     */
    public function list(array $filters): LengthAwarePaginator
    {
        return LeaveApplication::query()
            ->when($filters['search'] ?? null, fn (Builder $q, string $search) => $q->where(fn (Builder $q) => $q
                ->where('last_name', 'like', "%{$search}%")
                ->orWhere('first_name', 'like', "%{$search}%")
                ->orWhere('position', 'like', "%{$search}%")
                ->orWhere('leave_type_other', 'like', "%{$search}%")
                ->orWhere('detail_text', 'like', "%{$search}%")))
            ->when($filters['status'] ?? null, fn (Builder $q, string $status) => $this->withStatus($q, $status))
            ->when($filters['type'] ?? null, fn (Builder $q, string $type) => $q->where('leave_type', $type))
            ->orderByDesc('start_date')
            ->orderByDesc('id')
            ->paginate(self::PER_PAGE)
            ->withQueryString();
    }

    public function summary(): array
    {
        return [
            'total' => LeaveApplication::count(),
            'draft' => $this->withStatus(LeaveApplication::query(), 'draft')->count(),
            'submitted' => $this->withStatus(LeaveApplication::query(), 'submitted')->count(),
            'approved' => $this->withStatus(LeaveApplication::query(), 'approved')->count(),
        ];
    }

    /** What a new leave application starts with. */
    public function defaults(): array
    {
        return [
            'office' => config('onedost.leave.office'),
            'filed_on' => CarbonImmutable::today()->toDateString(),
            'leave_type' => LeaveType::Vacation->value,
            'detail' => LeaveDetail::WithinPhilippines->value,
            'commutation_requested' => false,
            'signatories' => config('onedost.leave.signatories'),
        ];
    }

    public function create(array $data): array
    {
        return self::handleTransaction(function () use ($data) {
            $leave = new LeaveApplication;
            $this->fill($leave, $data);

            return ['data' => $leave, 'message' => 'Leave application saved.', 'info' => null, 'status' => true];
        });
    }

    public function update(LeaveApplication $leave, array $data): array
    {
        return self::handleTransaction(function () use ($leave, $data) {
            $this->fill($leave, $data);

            return ['data' => $leave, 'message' => 'Leave application saved.', 'info' => null, 'status' => true];
        });
    }

    public function delete(LeaveApplication $leave): array
    {
        return self::handleTransaction(function () use ($leave) {
            $leave->delete();

            return ['data' => null, 'message' => 'Leave application deleted.', 'info' => null, 'status' => true];
        });
    }

    private function fill(LeaveApplication $leave, array $data): void
    {
        foreach (['office', 'last_name', 'first_name', 'middle_name', 'position', 'leave_type_other', 'detail_text', 'inclusive_dates'] as $field) {
            $data[$field] = filled($data[$field] ?? null) ? Str::squish($data[$field]) : null;
        }

        // Keep only what the form prints for this leave: "Others" text with
        // Others, and a specified place or illness only where a line asks.
        $type = LeaveType::from($data['leave_type']);
        $detail = LeaveDetail::tryFrom((string) ($data['detail'] ?? ''));
        if ($type !== LeaveType::Others) {
            $data['leave_type_other'] = null;
        }
        if (($detail?->specifies() ?? $type->specifies()) === null) {
            $data['detail_text'] = null;
        }

        $leave->fill([
            ...$data,
            'detail' => $detail,
            'commutation_requested' => (bool) ($data['commutation_requested'] ?? false),
            'traveler_id' => $this->rememberEmployee($data)->id,
        ])->save();
    }

    /**
     * Save the employee's name on their traveller profile, so the next form
     * (leave, trip or booking) fills it in. Someone new becomes a saved
     * person; their position fills only a blank, so the itinerary's wording
     * stays theirs.
     */
    private function rememberEmployee(array $data): Traveler
    {
        $first = $data['first_name'];
        $last = $data['last_name'];
        $middle = $data['middle_name'];

        $traveler = Traveler::findPerson(filled($data['traveler_id'] ?? null) ? (int) $data['traveler_id'] : null, $first, $last, $middle)
            ?? new Traveler([
                'name' => mb_strtoupper(trim(preg_replace('/\s+/', ' ', "{$first} {$middle} {$last}"))),
                'position' => '',
                'official_station' => '',
                'division' => '',
            ]);

        $traveler->fill(array_filter([
            'first_name' => $first,
            'middle_name' => $middle,
            'last_name' => $last,
            'position' => blank($traveler->position) ? $data['position'] : null,
        ], fn ($value) => filled($value)))->save();

        return $traveler;
    }

    private function withStatus(Builder $query, string $status): Builder
    {
        return match ($status) {
            'approved', 'disapproved' => $query->where('outcome', $status),
            'submitted' => $query->whereNull('outcome')->whereNotNull('submitted_on'),
            'draft' => $query->whereNull('outcome')->whereNull('submitted_on'),
            default => $query,
        };
    }
}
