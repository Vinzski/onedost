<?php

namespace App\Enums;

/** Which document has gone out for the project, as tracked in the sheet's "Doc Stat" column. */
enum DocStatus: string
{
    case RrfSent = 'RRF sent';
    case RfwAuthorizationSent = 'RFW and Authorization Sent';
    case ResultLetterSent = 'Result letter sent';

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
