<?php

return [

    // DOST IX's clock. The app stores times in UTC; "today" — for the DTR,
    // file names — is Manila's.
    'timezone' => 'Asia/Manila',

    /*
    |--------------------------------------------------------------------------
    | Airline Booking Request
    |--------------------------------------------------------------------------
    |
    | The Supply Unit's "Request for Airline Booking": three passengers per
    | form. Signatories are copied onto each request when it is created.
    |
    */

    'booking' => [
        'origin' => 'Zamboanga',

        // Offered as the destination is typed; any other place can be typed in.
        'destinations' => ['Manila', 'Cebu', 'Davao', 'Clark', 'Iloilo', 'Cagayan de Oro', 'Tawi-Tawi', 'Jolo', 'Bacolod', 'Puerto Princesa'],

        'passengers_per_form' => 3,

        'signatories' => [
            'noted_name' => 'RONNEL B. GUNDOY',
            'noted_title' => 'OIC-ARD-FASS',
            'approved_name' => 'ROSEMARIE S. SALAZAR',
            'approved_title' => 'Regional Director',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Application for Leave
    |--------------------------------------------------------------------------
    |
    | CSC Form No. 6 (Revised 2020) as TOS files it. Signatories are copied
    | onto each application when it is created.
    |
    */

    'leave' => [
        'office' => 'DOST IX - TOS',

        'signatories' => [
            // 7.A Certification of leave credits
            'certifier_name' => 'RONNEL B. GUNDOY',
            'certifier_title' => 'OIC-ARD FASS',
            // 7.B Recommendation
            'recommender_name' => 'RICARDO J. APOLINARIO III',
            'recommender_title' => 'ARD - TOS',
            // 7.C / 7.D Approval
            'approver_name' => 'ROSEMARIE S. SALAZAR',
            'approver_title' => 'Regional Director',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Daily Time Record
    |--------------------------------------------------------------------------
    |
    | Imported DTRs and the notes that explain their blanks. "Today" is
    | Manila's (timezone, above), whatever the server's clock says.
    |
    */

    'dtr' => [
        // Signs the printed explanations, as on the DTR's "Verified as to
        // the prescribed office hours".
        'in_charge_name' => 'RICARDO J. APOLINARIO III',
        'in_charge_title' => 'In Charge',
    ],

    /*
    |--------------------------------------------------------------------------
    | Travel Liquidation
    |--------------------------------------------------------------------------
    |
    | Fixed wording on the liquidation forms, and the defaults a new
    | liquidation starts from. Signatories are copied onto each liquidation
    | when it is created, so changing them here never rewrites old reports.
    |
    */

    'liquidation' => [
        'entity' => 'DOST RO IX',
        'station' => 'DOST REGION IX',
        'office' => 'Department of Science and Technology IX',
        'fund_cluster' => 'Regular Fund',

        // Dropdown choices. Both also offer "Others (indicate)" for free text.
        'fund_clusters' => ['Regular Fund', 'Scholarship Fund', 'Trust Fund', 'MDS Trust Fund'],
        'transport_means' => ['Public', 'Official Vehicle', 'Airplane', 'Ferry', 'Tricycle', 'Grab'],
        'official_station' => 'DOST- Zamboanga City',
        'division' => 'Technical Operations Services',

        // Starter itinerary amounts; every figure stays editable per trip.
        // The per diem follows the destination (dte_rates below) and fares
        // follow the traveller (home_fares); these apply only until those
        // are picked.
        'per_diem_rate' => 1500,
        'commute_fare' => 150,
        'terminal_fare' => 200,

        // Fares memo (taxi, TNVS and tricycle claims): the most a traveller
        // may claim per ride between home and the office, or between home
        // and the airport, wharf or bus terminal — by where they live and how
        // far. Each traveller's area and distances are saved with them, so
        // their residence legs fill themselves in. Rides once already at the
        // destination are entered by hand.
        'home_fares' => [
            'zamboanga' => [
                'label' => 'Zamboanga City',
                'office' => ['1–7 km' => 150, '8–15 km' => 250, 'Beyond 15 km' => 400],
                'terminal' => ['1–7 km' => 200, '8–15 km' => 300, 'Beyond 15 km' => 500],
            ],
            'pagadian' => [
                'label' => 'Pagadian City',
                'office' => ['1–7 km' => 100, 'Beyond 7 km' => 150],
                'terminal' => ['First 10 km' => 200, 'Beyond 10 km' => 300],
            ],
            'dipolog' => [
                'label' => 'Dipolog City',
                'office' => ['1–7 km' => 100, 'Beyond 7 km' => 150],
                'terminal' => ['First 10 km' => 200, 'Beyond 10 km' => 300],
            ],
            'ipil' => [
                'label' => 'Ipil',
                'office' => ['1–7 km' => 100, 'Beyond 7 km' => 150],
                'terminal' => ['First 10 km' => 100, 'Beyond 10 km' => 150],
            ],
        ],
        'home_area' => 'zamboanga',

        // Fares memo: outside Zamboanga Peninsula a taxi or TNVS ride is
        // claimed at cost with its receipt, or up to this on a CENRR.
        'taxi_without_receipt' => 300,

        // Where a trip that doesn't leave from the office starts out.
        'terminals' => [
            'airport' => 'Zamboanga International Airport',
            'port' => 'Zamboanga City Port',
            'bus' => 'Zamboanga City IBT',
        ],

        // EO 77 s. 2019 §5(b)(i): maximum Daily Travel Expense for local
        // travel, by destination region (PSGC region code). Full rate from
        // the day of arrival, half on the day of departure for home (§5(c)).
        'dte_rates' => [
            '13' => 2200, // NCR
            '04' => 2200, // Region IV-A (CALABARZON)
            '17' => 2200, // Region IV-B (MIMAROPA)
            '14' => 1800, // CAR
            '06' => 1800, // Region VI
            '07' => 1800, // Region VII
            '10' => 1800, // Region X
            '11' => 1800, // Region XI
            '18' => 1800, // Negros Island Region (2024) — its provinces were in VI and VII
            '01' => 1500, // Region I
            '02' => 1500, // Region II
            '03' => 1500, // Region III
            '05' => 1500, // Region V
            '08' => 1500, // Region VIII
            '09' => 1500, // Region IX
            '12' => 1500, // Region XII
            '16' => 1500, // Region XIII (Caraga)
            '19' => 1500, // BARMM (ARMM in EO 77)
        ],

        // Lunch provided at the activity: this much less per diem on each
        // day there. The days going and coming back keep their usual rate.
        'lunch_deduction' => 150,

        // Zamboanga City (PSGC). EO 77: within 50 km of the official
        // station, per diem only when staying overnight.
        'official_station_place' => '0931700000',

        // COA Circular 2012-001: liquidate within 30 days of the trip ending.
        'deadline_days' => 30,

        // Certification of Expenses Not Requiring Receipts: each certificate
        // holds up to this many lines and this much money.
        'cenrr_max_amount' => 300,
        'cenrr_max_lines' => 3,

        // Where staff download their Travel Order.
        'vrams_url' => 'https://vrams.dost9.ph/',

        'signatories' => [
            'supervisor_name' => 'RICARDO J. APOLINARIO III',
            'supervisor_title' => 'ARD for TOS',
            'director_name' => 'ROSEMARIE S. SALAZAR',
            'director_title' => 'Regional Director',
            'accountant_name' => 'INGRID T. ABELLA-COLCOL',
            'accountant_title' => 'Accountant III',
        ],
    ],

];
