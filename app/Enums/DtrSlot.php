<?php

namespace App\Enums;

/** One of the four times a day on the Daily Time Record (CSC Form No. 48). */
enum DtrSlot: string
{
    case AmArrival = 'am_arrival';
    case AmDeparture = 'am_departure';
    case PmArrival = 'pm_arrival';
    case PmDeparture = 'pm_departure';

    /** What people call it. */
    public function label(): string
    {
        return match ($this) {
            self::AmArrival => 'Time in',
            self::AmDeparture => 'Lunch out',
            self::PmArrival => 'Lunch in',
            self::PmDeparture => 'Time out',
        };
    }

    /** What the form calls it, for the printed explanations. */
    public function formLabel(): string
    {
        return match ($this) {
            self::AmArrival => 'A.M. arrival',
            self::AmDeparture => 'A.M. departure',
            self::PmArrival => 'P.M. arrival',
            self::PmDeparture => 'P.M. departure',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $case) => $case->value, self::cases());
    }

    /** @return list<array{value: string, label: string, form_label: string}> */
    public static function options(): array
    {
        return array_map(fn (self $case) => ['value' => $case->value, 'label' => $case->label(), 'form_label' => $case->formLabel()], self::cases());
    }
}
