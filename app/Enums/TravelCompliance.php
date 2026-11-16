<?php

namespace App\Enums;

/** How the trip went against the travel order — the boxes on the Certificate of Travel Completed. */
enum TravelCompliance: string
{
    case Strict = 'strict';
    case CutShort = 'cut_short';
    case Extended = 'extended';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Strict => 'Strictly in accordance with the approved itinerary',
            self::CutShort => 'Cut short',
            self::Extended => 'Extended',
            self::Other => 'Other deviations',
        };
    }

    /** @return list<array{value: string, label: string}> */
    public static function options(): array
    {
        return array_map(fn (self $case) => ['value' => $case->value, 'label' => $case->label()], self::cases());
    }
}
