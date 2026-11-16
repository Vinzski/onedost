<?php

namespace App\Http\Requests\Dtr;

use App\Http\Requests\Concerns\ValidatesDtrNote;
use Illuminate\Foundation\Http\FormRequest;

class StoreDtrNoteRequest extends FormRequest
{
    use ValidatesDtrNote;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'traveler_id' => ['required', 'integer', 'exists:travelers,id'],
            ...$this->noteRules(),
        ];
    }

    public function messages(): array
    {
        return [
            'traveler_id.required' => 'Pick whose DTR this is.',
            ...$this->noteMessages(),
        ];
    }
}
