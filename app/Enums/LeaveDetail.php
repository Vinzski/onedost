<?php

namespace App\Enums;

/** A 6.B "Details of leave" box on the Application for Leave; LeaveType::details() says which go with which leave. */
enum LeaveDetail: string
{
    case WithinPhilippines = 'within_philippines';
    case Abroad = 'abroad';
    case InHospital = 'in_hospital';
    case OutPatient = 'out_patient';
    case MastersDegree = 'masters_degree';
    case BarReview = 'bar_review';
    case Monetization = 'monetization';
    case TerminalLeave = 'terminal_leave';

    public function label(): string
    {
        return match ($this) {
            self::WithinPhilippines => 'Within the Philippines',
            self::Abroad => 'Abroad',
            self::InHospital => 'In Hospital',
            self::OutPatient => 'Out Patient',
            self::MastersDegree => 'Completion of Master\'s Degree',
            self::BarReview => 'BAR/Board Examination Review',
            self::Monetization => 'Monetization of Leave Credits',
            self::TerminalLeave => 'Terminal Leave',
        };
    }

    /** What the line beside the box asks for, if it has one. */
    public function specifies(): ?string
    {
        return match ($this) {
            self::WithinPhilippines => 'Where',
            self::Abroad => 'Country',
            self::InHospital, self::OutPatient => 'Illness',
            default => null,
        };
    }

    /** Abroad must say where. */
    public function needsText(): bool
    {
        return $this === self::Abroad;
    }

    /** @return list<array{value: string, label: string, specifies: ?string, needs_text: bool}> */
    public static function options(): array
    {
        return array_map(fn (self $case) => [
            'value' => $case->value,
            'label' => $case->label(),
            'specifies' => $case->specifies(),
            'needs_text' => $case->needsText(),
        ], self::cases());
    }
}
