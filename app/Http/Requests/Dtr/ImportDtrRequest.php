<?php

namespace App\Http\Requests\Dtr;

use Illuminate\Foundation\Http\FormRequest;

class ImportDtrRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'file' => ['required', 'file', 'mimes:pdf', 'max:5120'],
        ];
    }

    public function messages(): array
    {
        return [
            'file.mimes' => 'Upload the DTR as a PDF.',
            'file.max' => 'That PDF is too big for a DTR (5 MB at most).',
        ];
    }
}
