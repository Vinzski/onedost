<?php

namespace App\Services\BookingRequest;

use App\Enums\Airline;
use App\Enums\FlightTrip;
use App\Models\BookingPassenger;
use App\Models\BookingRequest;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Fills resources/templates/booking/booking-request.xlsx — the Supply Unit's
 * Request for Airline Booking. Each sheet holds three passengers (columns
 * G–H, J–K, M–N); more passengers get more sheets. The template's tick boxes
 * are printed ☐/☑ characters, rewritten here.
 */
class BookingRequestWorkbook
{
    public const TEMPLATE = 'templates/booking/booking-request.xlsx';

    /** First cell of each passenger column, and the cell holding its number. */
    private const COLUMNS = [['G', 'F'], ['J', 'I'], ['M', 'L']];

    private const ON = '☑';

    private const OFF = '☐';

    public function build(BookingRequest $booking): Spreadsheet
    {
        $booking->loadMissing('passengers');
        $book = IOFactory::load(resource_path(self::TEMPLATE));
        $template = $book->getSheet(0);

        $pages = $booking->passengers->values()->chunk(count(self::COLUMNS))->values();
        if ($pages->isEmpty()) {
            $pages = collect([collect()]);
        }

        // The template sheet is already called "Booking Request": rename it out of the way.
        $template->setTitle('Template', false);
        $perSheet = count(self::COLUMNS);

        foreach ($pages as $page => $passengers) {
            $sheet = clone $template;
            $from = $page * $perSheet + 1;
            $to = $from + $passengers->count() - 1;
            $sheet->setTitle(match (true) {
                $pages->count() === 1 => 'Booking Request',
                $from === $to => "Passenger {$from}",
                default => "Passengers {$from}-{$to}",
            }, false);
            $book->addSheet($sheet);
            $this->fill($sheet, $booking, $passengers->values(), $page * count(self::COLUMNS));
            $sheet->setSelectedCells('A1');
        }

        $book->removeSheetByIndex($book->getIndex($template));
        $book->setActiveSheetIndex(0);

        return $book;
    }

    /** @param Collection<int, BookingPassenger> $passengers */
    private function fill(Worksheet $sheet, BookingRequest $booking, Collection $passengers, int $numberFrom): void
    {
        foreach (self::COLUMNS as $i => [$first, $numberCell]) {
            $number = $numberFrom + $i + 1;
            $sheet->setCellValue("{$numberCell}7", $number);
            // Column headings, here and in the Supply Unit's part, follow the numbering.
            $sheet->setCellValue("{$first}5", "PASSENGER {$number}");
            $sheet->setCellValue("{$first}46", "PASSENGER {$number}");
            $passenger = $passengers->get($i);
            $passenger ? $this->passenger($sheet, $first, $booking, $passenger) : $this->blank($sheet, $first);
        }

        $sheet->setCellValue('G27', ' '.$booking->purpose);
        // Merged cells don't grow on their own: give a long purpose room.
        $sheet->getStyle('G27')->getAlignment()->setWrapText(true);
        $sheet->getRowDimension(27)->setRowHeight(max(15, 13 * (int) ceil(mb_strlen((string) $booking->purpose) / 95)));

        $justification = filled($booking->baggage_justification) ? ': '.$booking->baggage_justification : '';
        $sheet->setCellValue('C26', '  For baggage requirements in excess of the above, please justify'.$justification);
        $passes = $booking->boarding_passes_submitted;
        $sheet->setCellValue('C34', '  Boarding Passes from previous travel submitted:      '
            .$this->box($passes === true).' YES    '.$this->box($passes === false).' NO');

        $sheet->setCellValue('E38', $booking->requested_by);
        $sheet->setCellValue('J38', $booking->requested_on->format('j F Y'));
        $sheet->setCellValue('E41', $booking->signatory('noted_name'));
        $sheet->setCellValue('E42', $booking->signatory('noted_title'));
        $sheet->setCellValue('J41', $booking->signatory('approved_name'));
        $sheet->setCellValue('J42', $booking->signatory('approved_title'));
    }

    private function passenger(Worksheet $sheet, string $first, BookingRequest $booking, BookingPassenger $passenger): void
    {
        $last = $this->next($first);
        $return = $booking->trip->hasReturn();

        $sheet->setCellValue("{$first}6", $this->choices(['Ms.', 'Mr.'], $passenger->title, '    '));
        $sheet->setCellValue("{$first}7", mb_strtoupper((string) $passenger->first_name));
        $sheet->setCellValue("{$last}7", mb_strtoupper((string) $passenger->last_name));
        $sheet->setCellValue("{$first}9", $passenger->remarks);
        $sheet->setCellValueExplicit("{$first}10", (string) $passenger->id_number, 's');

        foreach (Airline::cases() as $row => $airline) {
            $sheet->setCellValue($first.(11 + $row), $this->box($booking->airline === $airline).' '.$airline->label());
        }
        $sheet->setCellValue("{$first}15", collect(FlightTrip::cases())
            ->map(fn (FlightTrip $trip) => $this->box($booking->trip === $trip).' '.$trip->label())->implode("\n"));

        $sheet->setCellValue("{$first}17", $booking->origin);
        $sheet->setCellValue("{$first}18", $this->longDate($booking->departure_date));
        $sheet->setCellValue("{$first}19", $booking->departure_etd);
        $sheet->setCellValue("{$first}20", $this->yesNo($passenger->going_baggage));
        $sheet->setCellValue("{$first}21", $booking->destination);
        $sheet->setCellValue("{$first}22", $return ? $this->longDate($booking->return_date) : null);
        $sheet->setCellValue("{$first}23", $return ? $booking->return_etd : null);
        $sheet->setCellValue("{$first}24", $return ? $this->yesNo($passenger->return_baggage) : null);

        // Text, so a number keeps its leading zero.
        $sheet->setCellValueExplicit("{$first}28", ' '.$booking->travel_order_no, 's');
        $sheet->setCellValueExplicit("{$first}29", (string) $passenger->contact_number, 's');
        $sheet->setCellValue("{$first}30", $passenger->email);
        $sheet->setCellValue("{$first}31", $this->longDate($passenger->birth_date));
        $sheet->setCellValue("{$first}32", $booking->fund_source);
        $sheet->setCellValue("{$first}33", $booking->project);
    }

    /** An unused passenger column: no boxes, nothing filled in. */
    private function blank(Worksheet $sheet, string $first): void
    {
        foreach ([6, 7, 9, 10, 11, 12, 13, 15, 17, 18, 19, 20, 21, 22, 23, 24, 28, 29, 30, 31, 32, 33] as $row) {
            $sheet->setCellValue("{$first}{$row}", null);
        }
        $sheet->setCellValue($this->next($first).'7', null);
    }

    private function yesNo(bool $yes): string
    {
        return $this->box($yes).' YES    '.$this->box(! $yes).' NO';
    }

    private function choices(array $options, ?string $picked, string $gap): string
    {
        return collect($options)->map(fn (string $option) => $this->box($option === $picked).' '.$option)->implode($gap);
    }

    private function box(bool $ticked): string
    {
        return $ticked ? self::ON : self::OFF;
    }

    /** "September 14, 2026", as the form is filled in by hand. */
    private function longDate(?CarbonInterface $date): ?string
    {
        return $date?->format('F j, Y');
    }

    private function next(string $column): string
    {
        return chr(ord($column) + 1);
    }
}
