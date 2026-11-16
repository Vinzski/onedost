<?php

namespace App\Services\Travel;

use App\Models\CashAdvance;
use App\Models\Liquidation;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Fills the "IOT" sheet of resources/templates/liquidation/forms.xlsx. The
 * same Itinerary of Travel goes with a cash advance (planned trip) and with
 * the liquidation (actual trip).
 */
class ItineraryOfTravelSheet
{
    use WritesCells;

    // Legs sit between the column header (row 18) and TOTAL (row 27).
    private const FIRST_ROW = 19;

    private const TEMPLATE_ROWS = 8;

    public function fill(Worksheet $sheet, CashAdvance|Liquidation $trip): void
    {
        $this->labelled($sheet, 'B10', 'Fund Cluster:  ', $trip->fund_cluster, 10);
        $this->labelled($sheet, 'K10', 'DOST TO No.: ', $trip->travel_order_no, 10);
        $sheet->setCellValue('D13', $trip->traveler_name);
        $this->labelled($sheet, 'I13', 'Date of Travel :  ', $trip->travelDates(), 9);
        $sheet->setCellValue('D14', $trip->position);
        $this->labelled($sheet, 'I14', "Purpose of Travel: \n", $trip->purpose, 9, underline: false);
        $sheet->setCellValue('D15', $trip->official_station);

        $items = $trip->items->values();
        $count = max($items->count(), 1);
        $first = self::FIRST_ROW;
        $templateLast = $first + self::TEMPLATE_ROWS - 1;
        $rowHeight = $sheet->getRowDimension($first)->getRowHeight();

        // Size the legs area to the trip, rebuilding its merges from scratch.
        $this->unmergeWithin($sheet, $first, $templateLast);
        if ($count > self::TEMPLATE_ROWS) {
            $sheet->insertNewRowBefore($templateLast, $count - self::TEMPLATE_ROWS);
        } elseif ($count < self::TEMPLATE_ROWS) {
            $sheet->removeRow($first + $count, self::TEMPLATE_ROWS - $count);
        }
        $shift = $count - self::TEMPLATE_ROWS;
        $last = $first + $count - 1;

        $dayStart = $first;
        $day = null;
        foreach ($items as $i => $item) {
            $row = $first + $i;
            // A leg with no date continues the day above; so does one on the same date.
            $newDay = $i === 0 || ($item->date !== null && ($day === null || ! $item->date->isSameDay($day)));
            $day = $item->date ?? $day;

            if ($newDay) {
                $this->mergeDay($sheet, $dayStart, $row - 1);
                $dayStart = $row;
                $this->date($sheet, "B{$row}", $item->date);
            } else {
                $sheet->setCellValue("B{$row}", null);
            }

            $sheet->setCellValue("C{$row}", $item->place);
            $sheet->mergeCells("C{$row}:F{$row}");
            $this->time($sheet, "G{$row}", $item->departure_time);
            $item->arrival_time ? $this->time($sheet, "H{$row}", $item->arrival_time) : $sheet->setCellValue("H{$row}", 'Onwards');
            $sheet->setCellValue("I{$row}", $item->transport_means);
            $this->amountOrDash($sheet, "J{$row}", $item->transport_cost);
            $this->amountOrDash($sheet, "K{$row}", $item->per_diem);
            $this->amountOrDash($sheet, "L{$row}", $item->other_cost);
            $sheet->setCellValue("M{$row}", $item->total() > 0 ? "=SUM(J{$row}:L{$row})" : null);
            $sheet->getStyle("M{$row}")->getNumberFormat()->setFormatCode(self::MONEY_FORMAT);

            // Merged cells don't auto-fit: give long place names room to wrap.
            $lines = max(1, (int) ceil(mb_strlen((string) $item->place) / 28));
            $sheet->getRowDimension($row)->setRowHeight(max($rowHeight, 12 * $lines + 4));
        }
        $this->mergeDay($sheet, $dayStart, $last);

        foreach (['J', 'K', 'L', 'M'] as $column) {
            $sheet->setCellValue("{$column}".($last + 1), "=SUM({$column}{$first}:{$column}{$last})");
        }

        $sheet->setCellValue('B'.(36 + $shift), $trip->signatory('supervisor_name'));
        $sheet->setCellValue('B'.(37 + $shift), $trip->signatory('supervisor_title'));
        $sheet->setCellValue('H'.(36 + $shift), $trip->signatory('director_name'));
        $sheet->setCellValue('H'.(37 + $shift), $trip->signatory('director_title'));
    }

    /** One date cell spans all of a day's legs. */
    private function mergeDay(Worksheet $sheet, int $from, int $to): void
    {
        if ($to > $from) {
            $sheet->mergeCells("B{$from}:B{$to}");
            $sheet->getStyle("B{$from}")->getAlignment()->setVertical(Alignment::VERTICAL_CENTER);
        }
    }
}
