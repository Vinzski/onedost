<?php

namespace App\Enums;

/** Why a time on the DTR is blank: the quick picks offered when noting it. */
enum DtrReason: string
{
    case NoInternet = 'no_internet';
    case SystemDown = 'system_down';
    case PowerOutage = 'power_outage';
    case Forgot = 'forgot';
    case OfficialTravel = 'official_travel';
    case OfficialBusiness = 'official_business';
    case Leave = 'leave';
    case WorkFromHome = 'work_from_home';
    case Holiday = 'holiday';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::NoInternet => 'No internet connection',
            self::SystemDown => 'DTR system or biometric down',
            self::PowerOutage => 'Power outage',
            self::Forgot => 'Forgot to log',
            self::OfficialTravel => 'On official travel',
            self::OfficialBusiness => 'Official business outside the office',
            self::Leave => 'On leave',
            self::WorkFromHome => 'Work from home',
            self::Holiday => 'Holiday or work suspension',
            self::Other => 'Other',
        };
    }

    /** A short label for chips and the month table. */
    public function shortLabel(): string
    {
        return match ($this) {
            self::NoInternet => 'No internet',
            self::SystemDown => 'System down',
            self::PowerOutage => 'Power outage',
            self::Forgot => 'Forgot to log',
            self::OfficialTravel => 'Travel',
            self::OfficialBusiness => 'Official business',
            self::Leave => 'Leave',
            self::WorkFromHome => 'Work from home',
            self::Holiday => 'Holiday',
            self::Other => 'Other',
        };
    }

    /** Remix Icon class. */
    public function icon(): string
    {
        return match ($this) {
            self::NoInternet => 'ri-wifi-off-line',
            self::SystemDown => 'ri-fingerprint-line',
            self::PowerOutage => 'ri-flashlight-line',
            // Not a question mark: that's what an unexplained blank shows.
            self::Forgot => 'ri-history-line',
            self::OfficialTravel => 'ri-suitcase-line',
            self::OfficialBusiness => 'ri-briefcase-line',
            self::Leave => 'ri-calendar-check-line',
            self::WorkFromHome => 'ri-home-wifi-line',
            self::Holiday => 'ri-flag-line',
            self::Other => 'ri-chat-1-line',
        };
    }

    /** Bootstrap colour: the office's fault, being away on duty, or your own slip. */
    public function variant(): string
    {
        return match ($this) {
            self::NoInternet, self::SystemDown, self::PowerOutage => 'warning',
            self::OfficialTravel, self::OfficialBusiness, self::WorkFromHome => 'info',
            self::Leave, self::Holiday => 'secondary',
            self::Forgot => 'danger',
            self::Other => 'light',
        };
    }

    /** What the details box suggests. */
    public function hint(): string
    {
        return match ($this) {
            self::NoInternet => 'e.g. Office internet down until 10 AM',
            self::SystemDown => 'e.g. Biometric wouldn\'t read fingerprints',
            self::PowerOutage => 'e.g. Brownout in the morning',
            self::Forgot => 'e.g. Went straight to a meeting',
            self::OfficialTravel => 'e.g. TO 2026-5667, Cebu',
            self::OfficialBusiness => 'e.g. Site visit at a client\'s plant',
            self::Leave => 'e.g. Sick leave',
            self::WorkFromHome => 'e.g. Approved WFH arrangement',
            self::Holiday => 'e.g. Proclamation No. 123, s. 2026',
            self::Other => 'Say what happened',
        };
    }

    /** "Other" says nothing without details. */
    public function needsDetails(): bool
    {
        return $this === self::Other;
    }

    /** @return list<array{value: string, label: string, short: string, icon: string, variant: string, hint: string, needs_details: bool}> */
    public static function options(): array
    {
        return array_map(fn (self $case) => [
            'value' => $case->value,
            'label' => $case->label(),
            'short' => $case->shortLabel(),
            'icon' => $case->icon(),
            'variant' => $case->variant(),
            'hint' => $case->hint(),
            'needs_details' => $case->needsDetails(),
        ], self::cases());
    }
}
