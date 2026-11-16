<?php

namespace App\Services\CashAdvance;

use NumberFormatter;

/** Peso amounts written out the way the certification words them. */
class AmountInWords
{
    /** 7050 → "SEVEN THOUSAND FIFTY PESOS ONLY"; 7050.5 → "SEVEN THOUSAND FIFTY PESOS AND FIFTY CENTAVOS ONLY". */
    public static function pesos(float $amount): string
    {
        $cents = (int) round($amount * 100);
        $pesos = intdiv($cents, 100);
        $centavos = $cents % 100;

        $speller = new NumberFormatter('en', NumberFormatter::SPELLOUT);
        $words = $speller->format($pesos).' '.($pesos === 1 ? 'peso' : 'pesos');

        if ($centavos > 0) {
            $words .= ' and '.$speller->format($centavos).' '.($centavos === 1 ? 'centavo' : 'centavos');
        }

        return strtoupper($words.' only');
    }
}
