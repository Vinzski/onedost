<?php

namespace App\Http\Requests\CashAdvance;

use App\Http\Requests\Concerns\ValidatesTrip;
use Illuminate\Foundation\Http\FormRequest;

class StoreCashAdvanceRequest extends FormRequest
{
    use ValidatesTrip;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            ...$this->tripRules(),
            'requested_on' => ['required', 'date'],
        ];
    }

    public function messages(): array
    {
        return $this->tripMessages();
    }

    public function attributes(): array
    {
        return [
            ...$this->tripAttributes(),
            'requested_on' => 'date requested',
        ];
    }
}
