<?php

namespace App\Http\Requests\Dtr;

use App\Models\Traveler;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;

/** Whose DTR, and which month (YYYY-MM). */
class ShowDtrRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'person' => ['nullable', 'integer', 'exists:travelers,id'],
            'month' => ['nullable', 'date_format:Y-m'],
        ];
    }

    public function person(): ?Traveler
    {
        return $this->validated('person') ? Traveler::find($this->validated('person')) : null;
    }

    public function month(): ?CarbonImmutable
    {
        return $this->validated('month') ? CarbonImmutable::createFromFormat('!Y-m', $this->validated('month')) : null;
    }
}
