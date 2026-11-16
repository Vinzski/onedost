<?php

namespace App\Http\Requests\Liquidation;

use App\Enums\TravelCompliance;
use App\Http\Requests\Concerns\ValidatesTrip;
use App\Services\Liquidation\LiquidationService;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreLiquidationRequest extends FormRequest
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

            'persons_contacted' => ['nullable', 'array', 'max:10'],
            'persons_contacted.*.name' => ['required', 'string', 'max:255'],
            'persons_contacted.*.designation' => ['nullable', 'string', 'max:255'],
            'output_summary' => ['nullable', 'string', 'max:2000'],
            'follow_up' => ['nullable', 'string', 'max:2000'],

            'cash_advance_amount' => self::MONEY,
            'dv_no' => ['nullable', 'string', 'max:50'],
            'dv_date' => ['nullable', 'date'],
            'or_no' => ['nullable', 'string', 'max:50'],
            'or_date' => ['nullable', 'date'],
            'supplies_amount' => self::MONEY,
            'other_expenses_amount' => self::MONEY,

            'compliance' => ['required', Rule::enum(TravelCompliance::class)],
            'compliance_note' => [Rule::requiredIf(fn () => $this->input('compliance') !== TravelCompliance::Strict->value), 'nullable', 'string', 'max:2000'],
            'evidence' => ['nullable', 'array', 'max:5'],
            'evidence.*' => ['nullable', 'string', 'max:255'],

            'activity_title' => ['nullable', 'string', 'max:255'],
            'activity_body' => ['nullable', 'string', 'max:10000'],

            'submitted_on' => ['nullable', 'date'],
            'has_certificate_of_appearance' => ['boolean'],

            // Certifications of Expenses Not Requiring Receipts.
            'receipt_certificates' => ['nullable', 'array', 'max:20'],
            'receipt_certificates.*' => ['array', $this->certificateWithinLimit()],
            'receipt_certificates.*.lines' => ['required', 'array', 'min:1', 'max:'.config('onedost.liquidation.cenrr_max_lines')],
            'receipt_certificates.*.lines.*.particulars' => ['required', 'string', 'max:255'],
            'receipt_certificates.*.lines.*.amount' => ['required', 'numeric', 'min:0.01', 'max:'.config('onedost.liquidation.cenrr_max_amount')],

            // Photos picked before the first save travel with it.
            'photos' => ['nullable', 'array', 'max:'.LiquidationService::MAX_PHOTOS],
            'photos.*' => StoreLiquidationPhotosRequest::PHOTO,
        ];
    }

    /** A certificate's items may not add up to more than the per-certificate cap (₱300). */
    private function certificateWithinLimit(): Closure
    {
        return function (string $attribute, mixed $certificate, Closure $fail) {
            $max = (float) config('onedost.liquidation.cenrr_max_amount');
            $total = collect($certificate['lines'] ?? [])->sum(fn ($line) => is_numeric($line['amount'] ?? null) ? (float) $line['amount'] : 0);

            if (round($total, 2) > $max) {
                $fail('A certificate can total at most ₱'.number_format($max).'. Move an item to another certificate.');
            }
        };
    }

    public function messages(): array
    {
        $max = number_format((float) config('onedost.liquidation.cenrr_max_amount'));

        return [
            ...$this->tripMessages(),
            ...StoreLiquidationPhotosRequest::photoMessages(),
            'receipt_certificates.*.lines.max' => 'A certificate holds '.config('onedost.liquidation.cenrr_max_lines').' items at most.',
            'receipt_certificates.*.lines.required' => 'Add at least one item, or remove the certificate.',
            'receipt_certificates.*.lines.*.particulars.required' => 'Describe the expense.',
            'receipt_certificates.*.lines.*.amount.required' => 'Enter the amount.',
            'receipt_certificates.*.lines.*.amount.max' => "One item can't be more than ₱{$max}.",
            'receipt_certificates.*.lines.*.amount.min' => 'Enter the amount.',
            'compliance_note.required' => 'Explain how the trip differed from the itinerary.',
        ];
    }

    public function attributes(): array
    {
        return [
            ...$this->tripAttributes(),
            'persons_contacted.*.name' => 'name',
        ];
    }
}
