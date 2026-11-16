<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A traveller's saved details, offered when the next trip or booking is
 * started — including where they live and how far, which sets their fares
 * from home (see App\Services\Travel\HomeFares), what an airline
 * booking asks for, and the middle name a leave application asks for.
 */
class Traveler extends Model
{
    /** Saved from a booking request's passenger, for their next one. */
    public const BOOKING_FIELDS = ['title', 'first_name', 'last_name', 'birth_date', 'contact_number', 'email', 'id_number'];

    protected $fillable = [
        'name',
        'position',
        'employee_no',
        'official_station',
        'division',
        'home_area',
        'office_distance',
        'terminal_distance',
        'title',
        'first_name',
        'middle_name',
        'last_name',
        'birth_date',
        'contact_number',
        'email',
        'id_number',
    ];

    protected function casts(): array
    {
        return [
            // Y-m-d, as the forms' date inputs take it.
            'birth_date' => 'date:Y-m-d',
        ];
    }

    /**
     * The saved person a form names: by id, then first and last name, then
     * full name ("VINCE BRADLEY MULOC", or with the middle name given).
     */
    public static function findPerson(?int $id, string $first, string $last, ?string $middle = null): ?self
    {
        $names = array_values(array_unique(array_map('mb_strtolower', array_filter([
            "{$first} {$last}",
            filled($middle) ? "{$first} {$middle} {$last}" : null,
        ]))));

        return ($id ? self::find($id) : null)
            ?? self::whereRaw('lower(first_name) = ? and lower(last_name) = ?', [mb_strtolower($first), mb_strtolower($last)])->first()
            ?? self::whereRaw('lower(name) in ('.implode(', ', array_fill(0, count($names), '?')).')', $names)->first();
    }
}
