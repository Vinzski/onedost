<?php

namespace App\Enums;

/** One-way, return or multi-city — the trip boxes on the Request for Airline Booking. */
enum FlightTrip: string
{
    case OneWay = 'one_way';
    case Return = 'return';
    case MultiCity = 'multi_city';

    public function label(): string
    {
        return match ($this) {
            self::OneWay => 'One-way',
            self::Return => 'Return Flight',
            self::MultiCity => 'Multi City',
        };
    }

    /** Whether the form's return flight (date, ETD, baggage) applies. */
    public function hasReturn(): bool
    {
        return $this !== self::OneWay;
    }

    /** @return list<array{value: string, label: string}> */
    public static function options(): array
    {
        return array_map(fn (self $case) => ['value' => $case->value, 'label' => $case->label()], self::cases());
    }
}
