<?php

namespace App\Services\LeaveApplication;

use App\Enums\LeaveType;
use App\Models\LeaveApplication;
use setasign\Fpdi\Fpdi;

/**
 * Fills resources/templates/leave/application-for-leave.pdf: the region's
 * Application for Leave (CSC Form No. 6, Revised 2020) with its sample
 * entries and signatories removed. The page is imported as is and the
 * application written over it, each value in the box the form left for it.
 *
 * Positions are PDF points from the page's bottom-left corner, measured off
 * the template. A slot is [left, width, baseline, alignment]; text that
 * doesn't fit is set smaller.
 */
class LeaveApplicationForm
{
    public const TEMPLATE = 'templates/leave/application-for-leave.pdf';

    /** Centres of the 6.A boxes. */
    private const TYPE_BOXES = [
        'vacation' => [49.10, 619.36],
        'mandatory' => [48.63, 604.06],
        'sick' => [49.14, 589.66],
        'maternity' => [49.14, 575.26],
        'paternity' => [49.14, 561.68],
        'special_privilege' => [49.14, 547.28],
        'solo_parent' => [49.10, 532.06],
        'study' => [49.10, 517.66],
        'vawc' => [49.10, 503.26],
        'rehabilitation' => [49.10, 488.86],
        'special_leave_women' => [49.14, 474.46],
        'calamity' => [49.10, 460.06],
        'adoption' => [49.10, 446.02],
    ];

    /** Centres of the 6.B boxes. */
    private const DETAIL_BOXES = [
        'within_philippines' => [339.42, 604.06],
        'abroad' => [339.42, 589.66],
        'in_hospital' => [339.42, 561.68],
        'out_patient' => [339.42, 547.28],
        'masters_degree' => [339.42, 460.06],
        'bar_review' => [339.42, 444.88],
        'monetization' => [339.42, 416.26],
        'terminal_leave' => [339.42, 402.02],
    ];

    /** Centres of the 6.D boxes. */
    private const COMMUTATION_BOXES = ['not_requested' => [339.42, 369.06], 'requested' => [339.42, 354.66]];

    /** The line beside a 6.B box (place, country, illness), and the spare line under it for the rest. */
    private const DETAIL_LINES = [
        'within_philippines' => [[428.0, 116.0, 601.6, 'L']],
        'abroad' => [[412.0, 132.0, 587.2, 'L']],
        'in_hospital' => [[447.1, 96.5, 558.4, 'L'], [337.3, 206.8, 529.4, 'L']],
        'out_patient' => [[449.3, 94.3, 544.0, 'L'], [337.3, 206.8, 529.4, 'L']],
        // Special Leave Benefits for Women: "(Specify Illness)", no box.
        'special_leave_women' => [[394.9, 146.7, 500.6, 'L'], [337.3, 206.3, 486.2, 'L']],
    ];

    private const SLOTS = [
        'office' => [54.2, 140.5, 692.6, 'C'],
        'last_name' => [256.0, 78.0, 692.6, 'C'],
        'first_name' => [340.0, 82.0, 692.6, 'C'],
        'middle_name' => [424.4, 70.0, 692.6, 'C'],
        'filed_on' => [119.5, 87.0, 673.3, 'L'],
        'position' => [272.3, 127.6, 673.3, 'C'],
        'salary' => [457.8, 66.1, 673.3, 'C'],
        'others' => [48.0, 228.0, 400.4, 'L'],
        'days' => [55.0, 178.0, 367.3, 'C'],
        'inclusive_dates' => [55.0, 178.0, 338.5, 'C'],
        'certifier_name' => [56.4, 236.9, 206.2, 'C'],
        'certifier_title' => [56.4, 236.9, 193.65, 'C'],
        'recommender_name' => [348.9, 191.4, 206.8, 'C'],
        'recommender_title' => [324.3, 229.3, 193.65, 'C'],
        'approver_name' => [185.9, 219.2, 93.3, 'C'],
        'approver_title' => [185.9, 219.2, 78.6, 'C'],
    ];

    /** The form's typed-in entries: Arial (Helvetica) Bold 8.8 pt; labels 8 pt. */
    private const VALUE_SIZE = 8.8;

    private const LABEL_SIZE = 8.0;

    /** A ✔ from ZapfDingbats, a little larger than the 6 pt boxes, as ticked by hand. */
    private const TICK = '4';

    private const TICK_SIZE = 9.0;

    /** @return string The filled-in PDF. */
    public function render(LeaveApplication $leave): string
    {
        $pdf = new Fpdi('P', 'pt');
        $pdf->SetTitle('Application for Leave - '.$leave->fullName(), true);
        $pdf->SetCreator('OneDOST');
        $pdf->SetAutoPageBreak(false);
        $pdf->setSourceFile(resource_path(self::TEMPLATE));
        $page = $pdf->importPage(1);
        $size = $pdf->getTemplateSize($page);
        $pdf->AddPage($size['orientation'], [$size['width'], $size['height']]);
        $pdf->useTemplate($page);
        $height = $size['height'];

        $value = fn (string $slot, ?string $text, string $style = 'B', float $size = self::VALUE_SIZE) => $this->write($pdf, $height, self::SLOTS[$slot], $text, $style, $size);

        // 1–5
        $value('office', $leave->office);
        $value('last_name', mb_strtoupper($leave->last_name).',');
        $value('first_name', mb_strtoupper($leave->first_name));
        $value('middle_name', mb_strtoupper((string) $leave->middle_name));
        $value('filed_on', $leave->filed_on->format('F j, Y'), 'B', 8.0);
        $value('position', $leave->position);
        $value('salary', $leave->salary !== null ? number_format((float) $leave->salary, 2) : null);

        // 6.A and 6.B
        if ($leave->leave_type === LeaveType::Others) {
            $value('others', $leave->leave_type_other);
        } else {
            $this->tick($pdf, $height, self::TYPE_BOXES[$leave->leave_type->value]);
        }
        if ($leave->detail) {
            $this->tick($pdf, $height, self::DETAIL_BOXES[$leave->detail->value]);
        }
        $lines = self::DETAIL_LINES[$leave->detail?->value ?? $leave->leave_type->value] ?? null;
        if ($lines && filled($leave->detail_text)) {
            $this->writeLines($pdf, $height, $lines, $leave->detail_text);
        }

        // 6.C and 6.D
        $value('days', $leave->daysApplied());
        $value('inclusive_dates', $leave->inclusiveDates());
        $this->tick($pdf, $height, self::COMMUTATION_BOXES[$leave->commutation_requested ? 'requested' : 'not_requested']);

        // 7: who signs; the rest is theirs to fill in.
        foreach (['certifier', 'recommender', 'approver'] as $role) {
            $value("{$role}_name", mb_strtoupper($leave->signatory("{$role}_name")));
            $value("{$role}_title", '('.$leave->signatory("{$role}_title").')', '', self::LABEL_SIZE);
        }

        return $pdf->Output('S');
    }

    /** "Leave - MONOPOLLO, JOHN CARLO W. - October 7-8, 2026.pdf" */
    public function fileName(LeaveApplication $leave): string
    {
        $name = trim(mb_strtoupper($leave->last_name).', '.mb_strtoupper($leave->first_name).' '.mb_strtoupper((string) $leave->middle_name));

        // Symfony's download header also refuses "%".
        return trim(preg_replace('/[\\\\\/:*?"<>|%]+/', '-', "Leave - {$name} - {$leave->inclusiveDates()}")).'.pdf';
    }

    /** Write $text in a slot, set smaller (down to 6 pt) if it doesn't fit, and cut short if even that doesn't. */
    private function write(Fpdi $pdf, float $height, array $slot, ?string $text, string $style, float $size): void
    {
        [$left, $width, $baseline, $align] = $slot;
        $text = $this->encode(trim((string) $text));
        if ($text === '') {
            return;
        }

        $pdf->SetFont('Helvetica', $style, $size);
        while ($pdf->GetStringWidth($text) > $width && $size > 6) {
            $size -= 0.2;
            $pdf->SetFontSize($size);
        }
        if ($pdf->GetStringWidth($text) > $width) {
            // Windows-1252: one byte a character, and "…" is 0x85.
            while (strlen($text) > 1 && $pdf->GetStringWidth($text."\x85") > $width) {
                $text = substr($text, 0, -1);
            }
            $text = rtrim($text)."\x85";
        }
        $x = $align === 'C' ? $left + ($width - $pdf->GetStringWidth($text)) / 2 : $left;
        $pdf->Text($x, $height - $baseline, $text);
    }

    /** Fill the line beside the box by whole words; the rest goes on the spare line, if there is one. */
    private function writeLines(Fpdi $pdf, float $height, array $lines, string $text): void
    {
        [$line, $spare] = [$lines[0], $lines[1] ?? null];
        if ($spare === null) {
            $this->write($pdf, $height, $line, $text, 'B', self::VALUE_SIZE);

            return;
        }

        $pdf->SetFont('Helvetica', 'B', self::VALUE_SIZE);
        $words = preg_split('/\s+/', trim($text));
        $first = [];
        while ($words && $pdf->GetStringWidth($this->encode(implode(' ', [...$first, $words[0]]))) <= $line[1]) {
            $first[] = array_shift($words);
        }
        $this->write($pdf, $height, $line, implode(' ', $first), 'B', self::VALUE_SIZE);
        $this->write($pdf, $height, $spare, implode(' ', $words), 'B', self::VALUE_SIZE);
    }

    /** A ✔ centred on a box. */
    private function tick(Fpdi $pdf, float $height, array $centre): void
    {
        [$x, $y] = $centre;
        $pdf->SetFont('ZapfDingbats', '', self::TICK_SIZE);
        // The ✔ glyph's ink spans 35–811 across and -14–705 up, per 1000 units.
        $pdf->Text($x - 0.423 * self::TICK_SIZE, $height - ($y - 0.3455 * self::TICK_SIZE), self::TICK);
    }

    /** The core fonts speak Windows-1252 ("Ñ" and "é" included). */
    private function encode(string $text): string
    {
        return mb_convert_encoding($text, 'Windows-1252', 'UTF-8');
    }
}
