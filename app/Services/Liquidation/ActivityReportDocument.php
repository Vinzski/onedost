<?php

namespace App\Services\Liquidation;

use App\Models\Liquidation;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\PhpWord;
use PhpOffice\PhpWord\Settings;
use PhpOffice\PhpWord\Shared\Converter;
use PhpOffice\PhpWord\SimpleType\Jc;
use PhpOffice\PhpWord\SimpleType\JcTable;
use PhpOffice\PhpWord\SimpleType\TblWidth;

/**
 * The Activity Report: landscape A4, a DATE | ACTIVITIES/OUTPUT table holding
 * the narrative and photos, then "Prepared by / Noted by" — the layout of the
 * region's ACTIVITY-REPORT.docx.
 */
class ActivityReportDocument
{
    private const HEADER_FILL = '9CC2E5';

    private const DATE_COLUMN = 1164;

    private const BODY_COLUMN = 14234;

    private const PHOTO_WIDTH_CM = 8;

    public function save(Liquidation $liquidation, string $path): void
    {
        Settings::setOutputEscapingEnabled(true);
        $liquidation->loadMissing('photos');

        $word = new PhpWord;
        $word->setDefaultFontName('Arial');
        $word->setDefaultFontSize(12);

        $section = $word->addSection([
            'orientation' => 'landscape',
            'pageSizeW' => Converter::cmToTwip(29.7),
            'pageSizeH' => Converter::cmToTwip(21),
            'marginTop' => 720, 'marginBottom' => 720, 'marginLeft' => 720, 'marginRight' => 720,
        ]);

        $section->addText(
            $liquidation->activity_title ?: 'Activity Report',
            ['name' => 'Calibri', 'size' => 14, 'bold' => true],
            ['alignment' => Jc::CENTER, 'spaceAfter' => 240],
        );

        $table = $section->addTable([
            'borderSize' => 6,
            'borderColor' => '000000',
            'cellMargin' => 80,
            'alignment' => JcTable::CENTER,
            'unit' => TblWidth::TWIP,
            'width' => self::DATE_COLUMN + self::BODY_COLUMN,
        ]);
        $bold = ['bold' => true];
        $center = ['alignment' => Jc::CENTER, 'spaceAfter' => 0];

        $table->addRow(303, ['tblHeader' => true]);
        $table->addCell(self::DATE_COLUMN, ['bgColor' => self::HEADER_FILL, 'valign' => 'center'])->addText('DATE', $bold, $center);
        $table->addCell(self::BODY_COLUMN, ['bgColor' => self::HEADER_FILL, 'valign' => 'center'])->addText('ACTIVITIES/OUTPUT', $bold, $center);

        $table->addRow();
        $table->addCell(self::DATE_COLUMN, ['valign' => 'top'])->addText($liquidation->travelDatesMonthFirst(), $bold, $center);
        $body = $table->addCell(self::BODY_COLUMN, ['valign' => 'top']);

        $paragraphs = preg_split('/\R\s*\R/', trim((string) $liquidation->activity_body)) ?: [];
        foreach (array_filter($paragraphs, fn ($p) => trim($p) !== '') as $paragraph) {
            $body->addText(preg_replace('/\s*\R\s*/', ' ', trim($paragraph)), null, ['alignment' => Jc::BOTH, 'spaceAfter' => 240]);
        }

        if ($liquidation->photos->isNotEmpty()) {
            $photos = $body->addTextRun(['alignment' => Jc::CENTER, 'spaceAfter' => 120]);
            foreach ($liquidation->photos as $photo) {
                $file = Storage::disk('local')->path($photo->path);
                if (is_file($file)) {
                    $photos->addImage($file, ['width' => Converter::cmToPoint(self::PHOTO_WIDTH_CM)]);
                    $photos->addText(' ');
                }
            }
        }

        $section->addTextBreak();
        $signatures = $section->addTable(['unit' => TblWidth::TWIP, 'width' => self::DATE_COLUMN + self::BODY_COLUMN]);
        $signatures->addRow();
        $left = $signatures->addCell(7700);
        $right = $signatures->addCell(7698);
        $left->addText('Prepared by:', null, ['spaceAfter' => 480]);
        $left->addText($liquidation->traveler_name, $bold, ['spaceAfter' => 0]);
        $left->addText($liquidation->position);
        $right->addText('Noted by:', null, ['spaceAfter' => 480]);
        $right->addText($liquidation->signatory('supervisor_name'), $bold, ['spaceAfter' => 0]);
        $right->addText($liquidation->signatory('supervisor_title'));

        IOFactory::createWriter($word, 'Word2007')->save($path);
    }
}
