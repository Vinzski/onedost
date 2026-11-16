<?php

namespace App\Http\Requests\Concerns;

use App\Enums\LeaveDetail;
use App\Enums\LeaveOutcome;
use App\Enums\LeaveType;
use Illuminate\Validation\Rule;

/** Validation shared by creating and updating a leave application. */
trait ValidatesLeave
{
    protected function leaveRules(): array
    {
        $type = LeaveType::tryFrom((string) $this->input('leave_type'));
        $details = array_map(fn (LeaveDetail $detail) => $detail->value, $type?->details() ?? []);

        return [
            'traveler_id' => ['nullable', 'integer', 'exists:travelers,id'],
            'office' => ['required', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],
            'first_name' => ['required', 'string', 'max:100'],
            'middle_name' => ['nullable', 'string', 'max:100'],
            'position' => ['required', 'string', 'max:100'],
            'salary' => ['nullable', 'numeric', 'min:0', 'max:9999999'],
            'filed_on' => ['required', 'date'],

            'leave_type' => ['required', Rule::enum(LeaveType::class)],
            'leave_type_other' => ['nullable', 'required_if:leave_type,'.LeaveType::Others->value, 'string', 'max:100'],
            // Only the 6.B boxes that go with this leave.
            'detail' => ['nullable', Rule::requiredIf((bool) $type?->needsDetail()), Rule::in($details)],
            'detail_text' => ['nullable', 'required_if:detail,'.LeaveDetail::Abroad->value, 'string', 'max:200'],
            'working_days' => ['required', 'numeric', 'min:0.5', 'max:365', 'multiple_of:0.5'],
            'start_date' => ['required', 'date'],
            'end_date' => ['required', 'date', 'after_or_equal:start_date'],
            'inclusive_dates' => ['nullable', 'string', 'max:100'],
            'commutation_requested' => ['boolean'],

            'signatories' => ['required', 'array'],
            'signatories.certifier_name' => ['required', 'string', 'max:255'],
            'signatories.certifier_title' => ['required', 'string', 'max:255'],
            'signatories.recommender_name' => ['required', 'string', 'max:255'],
            'signatories.recommender_title' => ['required', 'string', 'max:255'],
            'signatories.approver_name' => ['required', 'string', 'max:255'],
            'signatories.approver_title' => ['required', 'string', 'max:255'],

            'submitted_on' => ['nullable', 'date'],
            'outcome' => ['nullable', Rule::enum(LeaveOutcome::class)],
        ];
    }

    protected function leaveMessages(): array
    {
        return [
            'leave_type_other.required_if' => 'Say what kind of leave.',
            'detail.required' => 'Tick one of the boxes for this leave.',
            'detail.in' => 'This box doesn\'t go with the leave picked.',
            'detail_text.required_if' => 'Say which country.',
            'working_days.multiple_of' => 'Use whole or half days.',
            'end_date.after_or_equal' => 'The leave can\'t end before it starts.',
        ];
    }

    protected function leaveAttributes(): array
    {
        return [
            'leave_type' => 'type of leave',
            'detail' => 'details of leave',
            'working_days' => 'number of working days',
            'start_date' => 'first day',
            'end_date' => 'last day',
            'filed_on' => 'date of filing',
            'signatories.*' => 'signatory',
        ];
    }
}
