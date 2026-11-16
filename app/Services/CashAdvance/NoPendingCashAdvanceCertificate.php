<?php

namespace App\Services\CashAdvance;

use App\Models\CashAdvance;
use PhpOffice\PhpWord\Settings;
use PhpOffice\PhpWord\TemplateProcessor;

/**
 * The "no pending cash advance" certification and salary-deduction
 * authority, on the region's letterhead
 * (resources/templates/cash-advance/no-pending-cash-advance.docx).
 */
class NoPendingCashAdvanceCertificate
{
    private const TEMPLATE = 'templates/cash-advance/no-pending-cash-advance.docx';

    public function save(CashAdvance $cashAdvance, string $path): void
    {
        Settings::setOutputEscapingEnabled(true);

        // Dated the day the cash advance is requested.
        $date = $cashAdvance->requested_on->format('F d, Y');
        $amount = $cashAdvance->requestedAmount();

        $template = new TemplateProcessor(resource_path(self::TEMPLATE));
        $template->setValues([
            'name' => $cashAdvance->traveler_name,
            'as_of_date' => $date,
            'ca_date' => $date,
            'amount_words' => AmountInWords::pesos($amount),
            'amount' => number_format($amount, 2),
            'accountant_name' => $cashAdvance->signatory('accountant_name'),
            'accountant_title' => $cashAdvance->signatory('accountant_title'),
        ]);
        $template->saveAs($path);
    }
}
