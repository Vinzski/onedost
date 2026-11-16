<?php

namespace App\Http\Requests\BookingRequest;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ListBookingRequestsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'search' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::in(['draft', 'submitted', 'booked'])],
            'page' => ['nullable', 'integer', 'min:1'],
        ];
    }

    /** @return array{search: ?string, status: ?string} */
    public function filters(): array
    {
        return [
            'search' => $this->validated('search'),
            'status' => $this->validated('status'),
        ];
    }
}
