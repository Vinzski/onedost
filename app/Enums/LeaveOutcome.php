<?php

namespace App\Enums;

/** What became of a submitted leave application. */
enum LeaveOutcome: string
{
    case Approved = 'approved';
    case Disapproved = 'disapproved';

    public function label(): string
    {
        return match ($this) {
            self::Approved => 'Approved',
            self::Disapproved => 'Disapproved',
        };
    }

    /** Bootstrap colour of its badge. */
    public function variant(): string
    {
        return match ($this) {
            self::Approved => 'success',
            self::Disapproved => 'danger',
        };
    }

    /** @return list<array{value: string, label: string, variant: string}> */
    public static function options(): array
    {
        return array_map(fn (self $case) => ['value' => $case->value, 'label' => $case->label(), 'variant' => $case->variant()], self::cases());
    }
}
