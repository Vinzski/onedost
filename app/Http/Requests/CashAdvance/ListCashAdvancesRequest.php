<?php

namespace App\Http\Requests\CashAdvance;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ListCashAdvancesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'search' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::in(['pending', 'overdue', 'liquidated'])],
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
