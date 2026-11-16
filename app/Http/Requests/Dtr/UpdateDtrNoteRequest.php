<?php

namespace App\Http\Requests\Dtr;

use App\Http\Requests\Concerns\ValidatesDtrNote;
use Illuminate\Foundation\Http\FormRequest;

class UpdateDtrNoteRequest extends FormRequest
{
    use ValidatesDtrNote;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return $this->noteRules();
    }

    public function messages(): array
    {
        return $this->noteMessages();
    }
}
