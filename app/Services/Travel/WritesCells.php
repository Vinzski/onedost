<?php

namespace App\Services\Travel;

use DateTimeInterface;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\RichText\RichText;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PhpOffice\PhpSpreadsheet\Style\Font;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/** Cell-writing helpers shared by the travel form generators. */
trait WritesCells
{
    protected const DATE_FORMAT = 'dd-mmm-yy';

    protected const MONEY_FORMAT = '#,##0.00';

    /** "Label: " in plain text followed by a bold (underlined) value, as the form prints it. */
    protected function labelled(Worksheet $sheet, string $cell, string $label, string $value, int $size, bool $underline = true): void
    {
        $text = new RichText;
        $text->createTextRun($label)->getFont()->setName('Arial')->setSize($size);
        $font = $text->createTextRun($value)->getFont()->setName('Arial')->setSize($size)->setBold(true);
        if ($underline) {
            $font->setUnderline(Font::UNDERLINE_SINGLE);
        }
        $sheet->getCell($cell)->setValue($text);
    }

    protected function date(Worksheet $sheet, string $cell, ?DateTimeInterface $date): void
    {
        $sheet->setCellValue($cell, $date ? ExcelDate::dateTimeToExcel($date) : null);
        $sheet->getStyle($cell)->getNumberFormat()->setFormatCode(self::DATE_FORMAT);
    }

    /** "13:30" → an Excel time showing "1:30 PM". */
    protected function time(Worksheet $sheet, string $cell, ?string $time): void
    {
        if (! $time) {
            $sheet->setCellValue($cell, null);

            return;
        }
        [$h, $m] = array_map('intval', explode(':', $time));
        $sheet->setCellValue($cell, ($h * 60 + $m) / 1440);
        $sheet->getStyle($cell)->getNumberFormat()->setFormatCode('h:mm AM/PM');
    }

    protected function amountOrDash(Worksheet $sheet, string $cell, mixed $amount): void
    {
        $amount = (float) $amount;
        $sheet->setCellValue($cell, $amount > 0 ? $amount : '-');
        $sheet->getStyle($cell)->getNumberFormat()->setFormatCode(self::MONEY_FORMAT);
    }

    protected function unmergeWithin(Worksheet $sheet, int $fromRow, int $toRow): void
    {
        foreach ($sheet->getMergeCells() as $range) {
            [[, $top], [, $bottom]] = array_map(Coordinate::coordinateFromString(...), explode(':', $range));
            if ((int) $top >= $fromRow && (int) $bottom <= $toRow) {
                $sheet->unmergeCells($range);
            }
        }
    }

    /** Copy rows (values, styles, heights, merges) to another place on the same sheet. Formulas are left out. */
    protected function copyRows(Worksheet $sheet, int $from, int $to, int $destination): void
    {
        $offset = $destination - $from;
        $lastColumn = Coordinate::columnIndexFromString($sheet->getHighestColumn());

        for ($row = $from; $row <= $to; $row++) {
            $sheet->getRowDimension($row + $offset)->setRowHeight($sheet->getRowDimension($row)->getRowHeight());
            for ($col = 1; $col <= $lastColumn; $col++) {
                $source = $sheet->getCell([$col, $row]);
                $value = $source->getValue();
                $target = $sheet->getCell([$col, $row + $offset]);
                $target->setValue(match (true) {
                    $value instanceof RichText => clone $value,
                    is_string($value) && str_starts_with($value, '=') => null,
                    default => $value,
                });
                $target->setXfIndex($source->getXfIndex());
            }
        }

        foreach ($sheet->getMergeCells() as $range) {
            [[$left, $top], [$right, $bottom]] = array_map(Coordinate::coordinateFromString(...), explode(':', $range));
            if ((int) $top >= $from && (int) $bottom <= $to) {
                $sheet->mergeCells($left.($top + $offset).':'.$right.($bottom + $offset));
            }
        }
    }

    /** Empty a rectangle completely: values, merges and formatting. */
    protected function clearArea(Worksheet $sheet, string $fromColumn, string $toColumn, int $fromRow, int $toRow): void
    {
        $first = Coordinate::columnIndexFromString($fromColumn);
        $last = Coordinate::columnIndexFromString($toColumn);

        foreach ($sheet->getMergeCells() as $range) {
            [[$left, $top], [$right, $bottom]] = array_map(Coordinate::coordinateFromString(...), explode(':', $range));
            if ((int) $top >= $fromRow && (int) $bottom <= $toRow
                && Coordinate::columnIndexFromString($left) >= $first && Coordinate::columnIndexFromString($right) <= $last) {
                $sheet->unmergeCells($range);
            }
        }

        for ($row = $fromRow; $row <= $toRow; $row++) {
            for ($col = $first; $col <= $last; $col++) {
                $cell = $sheet->getCell([$col, $row]);
                $cell->setValue(null);
                $cell->setXfIndex(0);
            }
        }
    }
}
