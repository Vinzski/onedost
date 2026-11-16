<?php

namespace App\Http\Requests\Travel;

use Illuminate\Foundation\Http\FormRequest;

/** Others to put on a cash advance's or claim's travel order, by name. */
class AddTravelersRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'travelers' => ['required', 'array', 'min:1', 'max:30'],
            'travelers.*' => ['required', 'string', 'max:255'],
        ];
    }

    public function messages(): array
    {
        return [
            'travelers.required' => 'Pick at least one traveller.',
            'travelers.*.required' => 'A name is blank.',
        ];
    }
}
