<?php

namespace App\Enums;

/** 6.A "Type of leave to be availed of" on the Application for Leave (CSC Form No. 6). */
enum LeaveType: string
{
    case Vacation = 'vacation';
    case Mandatory = 'mandatory';
    case Sick = 'sick';
    case Maternity = 'maternity';
    case Paternity = 'paternity';
    case SpecialPrivilege = 'special_privilege';
    case SoloParent = 'solo_parent';
    case Study = 'study';
    case Vawc = 'vawc';
    case Rehabilitation = 'rehabilitation';
    case SpecialLeaveWomen = 'special_leave_women';
    case Calamity = 'calamity';
    case Adoption = 'adoption';
    case Others = 'others';

    public function label(): string
    {
        return match ($this) {
            self::Vacation => 'Vacation Leave',
            self::Mandatory => 'Mandatory/Forced Leave',
            self::Sick => 'Sick Leave',
            self::Maternity => 'Maternity Leave',
            self::Paternity => 'Paternity Leave',
            self::SpecialPrivilege => 'Special Privilege Leave',
            self::SoloParent => 'Solo Parent Leave',
            self::Study => 'Study Leave',
            self::Vawc => '10-Day VAWC Leave',
            self::Rehabilitation => 'Rehabilitation Privilege',
            self::SpecialLeaveWomen => 'Special Leave Benefits for Women',
            self::Calamity => 'Special Emergency (Calamity) Leave',
            self::Adoption => 'Adoption Leave',
            self::Others => 'Others',
        };
    }

    /** The law or rule printed beside it on the form. */
    public function basis(): ?string
    {
        return match ($this) {
            self::Vacation => 'Sec. 51, Rule XVI, Omnibus Rules Implementing E.O. No. 292',
            self::Mandatory => 'Sec. 25, Rule XVI, Omnibus Rules Implementing E.O. No. 292',
            self::Sick => 'Sec. 43, Rule XVI, Omnibus Rules Implementing E.O. No. 292',
            self::Maternity => 'R.A. No. 11210 / IRR issued by CSC, DOLE and SSS',
            self::Paternity => 'R.A. No. 8187 / CSC MC No. 71, s. 1998, as amended',
            self::SpecialPrivilege => 'Sec. 21, Rule XVI, Omnibus Rules Implementing E.O. No. 292',
            self::SoloParent => 'RA No. 8972 / CSC MC No. 8, s. 2004',
            self::Study => 'Sec. 68, Rule XVI, Omnibus Rules Implementing E.O. No. 292',
            self::Vawc => 'RA No. 9262 / CSC MC No. 15, s. 2005',
            self::Rehabilitation => 'Sec. 55, Rule XVI, Omnibus Rules Implementing E.O. No. 292',
            self::SpecialLeaveWomen => 'RA No. 9710 / CSC MC No. 25, s. 2010',
            self::Calamity => 'CSC MC No. 2, s. 2012, as amended',
            self::Adoption => 'R.A. No. 8552',
            self::Others => null,
        };
    }

    /**
     * The 6.B boxes that go with this leave ("In case of …").
     *
     * @return list<LeaveDetail>
     */
    public function details(): array
    {
        return match ($this) {
            self::Vacation, self::SpecialPrivilege => [LeaveDetail::WithinPhilippines, LeaveDetail::Abroad],
            self::Sick => [LeaveDetail::InHospital, LeaveDetail::OutPatient],
            self::Study => [LeaveDetail::MastersDegree, LeaveDetail::BarReview],
            self::Others => [LeaveDetail::Monetization, LeaveDetail::TerminalLeave],
            default => [],
        };
    }

    /** Whether one of its 6.B boxes must be ticked ("Other purpose" is optional). */
    public function needsDetail(): bool
    {
        return $this->details() !== [] && $this !== self::Others;
    }

    /** What 6.B asks to specify with no box to tick: the illness, for Special Leave Benefits for Women. */
    public function specifies(): ?string
    {
        return $this === self::SpecialLeaveWomen ? 'Illness' : null;
    }

    /** @return list<array{value: string, label: string, basis: ?string, details: list<string>, needs_detail: bool, specifies: ?string}> */
    public static function options(): array
    {
        return array_map(fn (self $case) => [
            'value' => $case->value,
            'label' => $case->label(),
            'basis' => $case->basis(),
            'details' => array_map(fn (LeaveDetail $detail) => $detail->value, $case->details()),
            'needs_detail' => $case->needsDetail(),
            'specifies' => $case->specifies(),
        ], self::cases());
    }
}
