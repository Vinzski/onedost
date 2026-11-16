<?php

namespace App\Enums;

/** The DOST programme a project is funded under. */
enum Program: string
{
    case Setup = 'SETUP';
    case Gia = 'GIA';
    case Cest = 'CEST';
    case Sscp = 'SSCP';

    public function label(): string
    {
        return $this->value;
    }

    /** @return list<array{value: string, label: string}> */
    public static function options(): array
    {
        return array_map(fn (self $case) => ['value' => $case->value, 'label' => $case->label()], self::cases());
    }
}
