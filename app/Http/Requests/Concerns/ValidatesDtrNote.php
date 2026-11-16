<?php

namespace App\Http\Requests\Concerns;

use App\Enums\DtrReason;
use App\Enums\DtrSlot;
use Illuminate\Validation\Rule;

/** Validation shared by adding and changing a DTR note. */
trait ValidatesDtrNote
{
    protected function noteRules(): array
    {
        return [
            'starts_on' => ['required', 'date'],
            // A run of days: a trip, a week without internet. Two months at most.
            'ends_on' => ['nullable', 'date', 'after_or_equal:starts_on', 'before_or_equal:'.$this->latestEnd()],
            // Empty: the whole day.
            'slots' => ['present', 'array'],
            'slots.*' => ['distinct', Rule::in(DtrSlot::values())],
            'reason' => ['required', Rule::enum(DtrReason::class)],
            'details' => ['nullable', 'required_if:reason,'.DtrReason::Other->value, 'string', 'max:255'],
            'actual_time' => ['nullable', 'date_format:H:i'],
        ];
    }

    protected function noteMessages(): array
    {
        return [
            'reason.required' => 'Pick why.',
            'details.required_if' => 'Say what happened.',
            'ends_on.after_or_equal' => 'The last day can\'t be before the first.',
            'ends_on.before_or_equal' => 'One note covers two months at most.',
        ];
    }

    private function latestEnd(): string
    {
        $start = strtotime((string) $this->input('starts_on'));

        return date('Y-m-d', strtotime('+62 days', $start ?: time()));
    }
}
