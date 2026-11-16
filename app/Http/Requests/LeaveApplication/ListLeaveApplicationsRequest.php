<?php

namespace App\Http\Requests\LeaveApplication;

use App\Enums\LeaveType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ListLeaveApplicationsRequest extends FormRequest
{
    public const STATUSES = ['draft', 'submitted', 'approved', 'disapproved'];

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'search' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::in(self::STATUSES)],
            'type' => ['nullable', Rule::enum(LeaveType::class)],
            'page' => ['nullable', 'integer', 'min:1'],
        ];
    }

    /** @return array{search: ?string, status: ?string, type: ?string} */
    public function filters(): array
    {
        return [
            'search' => $this->validated('search'),
            'status' => $this->validated('status'),
            'type' => $this->validated('type'),
        ];
    }
}
