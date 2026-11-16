<?php

namespace App\Enums;

/** "Preferred Airline" on the Request for Airline Booking. */
enum Airline: string
{
    case CebuPacific = 'cebu_pacific';
    case PalExpress = 'pal_express';
    case Any = 'any';

    public function label(): string
    {
        return match ($this) {
            self::CebuPacific => 'Cebu Pacific',
            self::PalExpress => 'PAL Express',
            self::Any => 'Any Available Airline',
        };
    }

    /** Free check-in baggage, as printed on the form. */
    public function baggageAllowance(): ?string
    {
        return match ($this) {
            self::CebuPacific => '20 kg',
            self::PalExpress => '10 kg',
            self::Any => null,
        };
    }

    /** @return list<array{value: string, label: string, baggage: ?string}> */
    public static function options(): array
    {
        return array_map(fn (self $case) => ['value' => $case->value, 'label' => $case->label(), 'baggage' => $case->baggageAllowance()], self::cases());
    }
}
