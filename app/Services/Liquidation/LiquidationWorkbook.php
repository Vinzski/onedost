<?php

namespace App\Services\Liquidation;

use App\Enums\TravelCompliance;
use App\Models\Liquidation;
use App\Services\Travel\ItineraryOfTravelSheet;
use App\Services\Travel\WritesCells;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\RichText\RichText;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Fills the region's liquidation workbook (resources/templates/liquidation/forms.xlsx):
 * Itinerary of Travel, Travel Accomplishment Report, Certificate of Travel
 * Completed, CENRR and Liquidation Report — the same five sheets staff
 * used to fill by hand.
 */
class LiquidationWorkbook
{
    use WritesCells;

    private const TEMPLATE = 'templates/liquidation/forms.xlsx';

    // Accomplishment report: two "persons contacted" rows before section E.
    private const TAR_PERSON_ROW = 21;

    private const TAR_PERSON_ROWS = 2;

    // CENRR: one block holds two certificates side by side; blocks repeat every 32 rows.
    private const CENRR_FIRST = 6;

    private const CENRR_LAST = 29;

    private const CENRR_STRIDE = 32;

    /** Column letters for each half of a CENRR block. */
    private const CENRR_SIDES = [
        ['name' => 'D', 'number' => 'K', 'particulars' => 'B', 'amount' => 'K', 'sumTo' => 'S', 'supervisor' => 'K', 'from' => 'A', 'to' => 'S'],
        ['name' => 'X', 'number' => 'AE', 'particulars' => 'V', 'amount' => 'AE', 'sumTo' => 'AM', 'supervisor' => 'AE', 'from' => 'V', 'to' => 'AN'],
    ];

    public function __construct(private ItineraryOfTravelSheet $itinerary) {}

    public function build(Liquidation $liquidation): Spreadsheet
    {
        $liquidation->loadMissing('items');
        $book = IOFactory::load(resource_path(self::TEMPLATE));

        $this->itinerary->fill($book->getSheetByName('IOT'), $liquidation);
        $this->accomplishment($book->getSheetByName('TAR'), $liquidation);
        $this->completion($book->getSheetByName('CTC'), $liquidation);
        $this->receiptLess($book, $book->getSheetByName('CENRR'), $liquidation);

        // A reimbursement settles no cash advance, so it has no Liquidation Report.
        $report = $book->getSheetByName('LIQ REPORT');
        $liquidation->isReimbursement()
            ? $book->removeSheetByIndex($book->getIndex($report))
            : $this->liquidationReport($report, $liquidation);

        // Sheets are named per traveller, e.g. "IOT VCM", as staff already do.
        $initials = $liquidation->initials();
        foreach ($book->getAllSheets() as $sheet) {
            $sheet->setTitle(trim($sheet->getTitle().' '.$initials));
            $sheet->setSelectedCells('A1');
        }
        $book->setActiveSheetIndex(0);

        return $book;
    }

    public function save(Liquidation $liquidation, string $path): void
    {
        IOFactory::createWriter($this->build($liquidation), 'Xlsx')->save($path);
    }

    // ── Travel Accomplishment Report ──────────────────────────────────

    private function accomplishment(Worksheet $sheet, Liquidation $l): void
    {
        $sheet->setCellValue('D9', $l->purpose);
        $sheet->setCellValue('D13', $l->venue);
        $this->date($sheet, 'I16', $l->departure_date);
        $this->date($sheet, 'I17', $l->return_date);

        $persons = collect($l->persons_contacted ?? [])->filter(fn ($p) => filled($p['name'] ?? null))->values();
        $extra = max($persons->count() - self::TAR_PERSON_ROWS, 0);
        if ($extra > 0) {
            $before = self::TAR_PERSON_ROW + self::TAR_PERSON_ROWS;
            $height = $sheet->getRowDimension($before - 1)->getRowHeight();
            $sheet->insertNewRowBefore($before, $extra);
            for ($row = $before; $row < $before + $extra; $row++) {
                $sheet->mergeCells("D{$row}:I{$row}");
                $sheet->mergeCells("J{$row}:K{$row}");
                $sheet->getRowDimension($row)->setRowHeight($height);
            }
        }
        foreach ($persons as $i => $person) {
            $row = self::TAR_PERSON_ROW + $i;
            $sheet->setCellValue("C{$row}", '-');
            $sheet->setCellValue("D{$row}", $person['name']);
            $sheet->setCellValue("J{$row}", $person['designation'] ?? null);
        }

        $sheet->setCellValue('D'.(24 + $extra), $l->output_summary);
        $sheet->setCellValue('J'.(27 + $extra), $l->travelExpenses());
        $sheet->setCellValue('J'.(28 + $extra), (float) $l->supplies_amount ?: null);
        $sheet->setCellValue('J'.(29 + $extra), (float) $l->other_expenses_amount ?: null);
        $sheet->setCellValue('J'.(30 + $extra), '=SUM(J'.(27 + $extra).':J'.(29 + $extra).')');
        $sheet->getStyle('J'.(27 + $extra).':J'.(30 + $extra))->getNumberFormat()->setFormatCode(self::MONEY_FORMAT);

        $sheet->setCellValue('C'.(33 + $extra), filled($l->follow_up) ? '-' : null);
        $sheet->setCellValue('D'.(33 + $extra), $l->follow_up);

        $sheet->setCellValue('D'.(37 + $extra), $l->traveler_name);
        $sheet->setCellValue('K'.(37 + $extra), $l->signatory('supervisor_name'));
        $sheet->setCellValue('K'.(38 + $extra), $l->signatory('supervisor_title'));
    }

    // ── Certificate of Travel Completed ───────────────────────────────

    private function completion(Worksheet $sheet, Liquidation $l): void
    {
        $sheet->setCellValue('K9', $l->fund_cluster);
        $sheet->setCellValue('C12', $l->signatory('director_name'));
        $sheet->setCellValue('C13', $l->signatory('director_title'));
        $sheet->setCellValue('C16', 'I HEREBY  CERTIFY  THAT  I have completed the travel as authorized in the Travel Order/Itinerary of Travel No. '
            .$l->travel_order_no.' dated '.($l->travel_order_date?->format('F d, Y') ?? '______________').' under conditions indicated below:');

        $box = match ($l->compliance) {
            TravelCompliance::Strict => 'D19',
            TravelCompliance::CutShort => 'D20',
            TravelCompliance::Extended => 'D22',
            TravelCompliance::Other => 'D23',
        };
        $sheet->setCellValue($box, '      X');

        if ($l->compliance === TravelCompliance::CutShort) {
            $sheet->setCellValue('F20', 'Cut short as explained below. Excess payment in the amount of ₱ '.number_format($l->refund(), 2));
            $sheet->setCellValue('F21', 'was refunded on O.R. No. '.($l->or_no ?: '___________').' dated '.($l->or_date?->format('F d, Y') ?? '______________'));
        }

        // One wrapped box under "Explanation or justifications:".
        foreach (['H26:K26', 'D28:H28', 'J28:K28'] as $range) {
            $sheet->unmergeCells($range);
        }
        $sheet->mergeCells('D26:K28');
        $sheet->setCellValue('D26', $l->compliance_note);
        $sheet->getStyle('D26')->getAlignment()->setWrapText(true)->setVertical(Alignment::VERTICAL_TOP);

        foreach (array_slice(array_values($l->evidence ?? []), 0, 5) as $i => $evidence) {
            $sheet->setCellValue('D'.(30 + $i), $i + 1);
            $sheet->setCellValue('G'.(30 + $i), $evidence);
        }

        $sheet->setCellValue('K38', $l->traveler_name);
        $sheet->setCellValue('K44', $l->signatory('director_name'));
        $sheet->setCellValue('K45', $l->signatory('director_title'));
    }

    // ── Certification of Expenses Not Requiring Receipts ──────────────

    private function receiptLess(Spreadsheet $book, Worksheet $sheet, Liquidation $l): void
    {
        $certificates = $l->receiptCertificates();
        if ($certificates === []) {
            $book->removeSheetByIndex($book->getIndex($sheet));

            return;
        }

        $blocks = (int) ceil(count($certificates) / 2);
        for ($block = 1; $block < $blocks; $block++) {
            $start = self::CENRR_FIRST + $block * self::CENRR_STRIDE;
            $this->copyRows($sheet, self::CENRR_FIRST, self::CENRR_LAST, $start);
            $sheet->setBreak('A'.($start - 1), Worksheet::BREAK_ROW);
        }

        foreach ($certificates as $i => $certificate) {
            $offset = intdiv($i, 2) * self::CENRR_STRIDE;
            $this->certificate($sheet, self::CENRR_SIDES[$i % 2], $offset, $l, $certificate['lines']);
        }

        // An odd count leaves the right half of the last block unused: blank it.
        if (count($certificates) % 2 === 1) {
            $offset = ($blocks - 1) * self::CENRR_STRIDE;
            $this->clearArea($sheet, self::CENRR_SIDES[1]['from'], self::CENRR_SIDES[1]['to'], self::CENRR_FIRST + $offset, self::CENRR_LAST + $offset);
        }
    }

    /**
     * One certificate: up to three particulars (rows 14–16) and their total.
     *
     * @param  list<array{particulars: string, amount: float}>  $lines
     */
    private function certificate(Worksheet $sheet, array $c, int $o, Liquidation $l, array $lines): void
    {
        $office = config('onedost.liquidation.office');

        $sheet->setCellValue($c['name'].(10 + $o), $l->traveler_name);
        $sheet->setCellValue($c['number'].(10 + $o), $l->employee_no);
        $sheet->setCellValue($c['name'].(11 + $o), $office);
        $sheet->setCellValue($c['name'].(12 + $o), $l->division);

        foreach (array_slice($lines, 0, 3) as $i => $line) {
            $row = 14 + $o + $i;
            $sheet->setCellValue($c['particulars'].$row, $line['particulars']);
            $sheet->setCellValue($c['amount'].$row, $line['amount']);
            $sheet->getStyle($c['amount'].$row)->getNumberFormat()->setFormatCode(self::MONEY_FORMAT);
            $sheet->getStyle($c['particulars'].$row)->getAlignment()->setWrapText(true)->setVertical(Alignment::VERTICAL_CENTER);

            // Merged cells don't auto-fit: room for the particulars to wrap (~55 characters a line).
            $needed = 13 * max(1, (int) ceil(mb_strlen($line['particulars']) / 55)) + 4;
            $dimension = $sheet->getRowDimension($row);
            $dimension->setRowHeight(max($dimension->getRowHeight(), $needed));
        }

        $sheet->setCellValue($c['amount'].(17 + $o), '=SUM('.$c['amount'].(14 + $o).':'.$c['sumTo'].(16 + $o).')');
        $sheet->getStyle($c['amount'].(17 + $o))->getNumberFormat()->setFormatCode(self::MONEY_FORMAT);

        $purpose = new RichText;
        $purpose->createTextRun("Purpose\n")->getFont()->setName('Calibri')->setSize(11)->setBold(true);
        $purpose->createTextRun($l->purpose)->getFont()->setName('Calibri')->setSize(10);
        $sheet->getCell($c['particulars'].(18 + $o))->setValue($purpose);

        $sheet->setCellValue($c['name'].(27 + $o), $l->traveler_name);
        $sheet->setCellValue($c['supervisor'].(27 + $o), $l->signatory('supervisor_name'));
    }

    // ── Liquidation Report (two copies on one page) ───────────────────

    private function liquidationReport(Worksheet $sheet, Liquidation $l): void
    {
        $spent = $l->totalSpent();
        $dv = 'AMOUNT OF CASH ADVANCE PER DV No.'.($l->dv_no ?: '_____________')
            .'    DTD  '.($l->dv_date?->format('M d, Y') ?? '______________________').' ';
        $or = 'AMOUNT REFUNDED PER OR No. '.($l->or_no ?: '____________________')
            .'    DTD  '.($l->or_date?->format('M d, Y') ?? '______________________');

        $sheet->setCellValue('H11', $spent);
        $sheet->setCellValue('C12', 'while on official travel re: '.$l->purpose);
        $sheet->setCellValue('H15', $spent);
        $sheet->setCellValue('C16', $dv);
        $sheet->setCellValue('H16', (float) $l->cash_advance_amount);
        $sheet->setCellValue('C17', $or);
        $sheet->setCellValue('H17', $l->refund());
        $sheet->setCellValue('H18', $l->reimbursement());
        $sheet->getStyle('H11:H18')->getNumberFormat()->setFormatCode(self::MONEY_FORMAT);

        $sheet->setCellValue('C22', $l->traveler_name);
        $sheet->setCellValue('D22', $l->signatory('supervisor_name'));
        $sheet->setCellValue('G22', $l->signatory('accountant_name'));
        $sheet->setCellValue('G23', $l->signatory('accountant_title'));

        // The lower copy mostly points at the upper one by formula; these are typed.
        $sheet->setCellValue('C41', $dv);
        $sheet->setCellValue('C42', $or);
        $sheet->setCellValue('G47', $l->signatory('accountant_name'));
        $sheet->setCellValue('G48', $l->signatory('accountant_title'));
    }
}
