<?php

namespace App\Services\Liquidation;

use App\Models\Liquidation;
use Closure;
use RuntimeException;
use ZipArchive;

/** Builds a liquidation's downloads as temporary files. */
class LiquidationDocuments
{
    public const FORMS = 'forms';

    public const ACTIVITY_REPORT = 'activity-report';

    public const ALL = 'all';

    public const TYPES = [self::FORMS, self::ACTIVITY_REPORT, self::ALL];

    public function __construct(
        private LiquidationWorkbook $workbook,
        private ActivityReportDocument $activityReport,
    ) {}

    /**
     * @return array{path: string, name: string} A temp file (delete after sending) and its download name.
     */
    public function make(Liquidation $liquidation, string $type): array
    {
        $base = $this->baseName($liquidation);
        $kind = $liquidation->kind->label();   // "Liquidation" / "Reimbursement"

        return match ($type) {
            self::FORMS => $this->temp(fn (string $path) => $this->workbook->save($liquidation, $path), "{$base} - {$kind} Forms.xlsx"),
            self::ACTIVITY_REPORT => $this->temp(fn (string $path) => $this->activityReport->save($liquidation, $path), "{$base} - Activity Report.docx"),
            self::ALL => $this->zip($liquidation, "{$base} - {$kind}.zip"),
        };
    }

    private function zip(Liquidation $liquidation, string $name): array
    {
        $parts = array_map(fn (string $type) => $this->make($liquidation, $type), [self::FORMS, self::ACTIVITY_REPORT]);

        return $this->temp(function (string $path) use ($parts) {
            $zip = new ZipArchive;
            if ($zip->open($path, ZipArchive::OVERWRITE) !== true) {
                throw new RuntimeException('Could not create the zip file.');
            }
            foreach ($parts as $part) {
                $zip->addFile($part['path'], $part['name']);
            }
            $zip->close();
            foreach ($parts as $part) {
                @unlink($part['path']);
            }
        }, $name);
    }

    private function temp(Closure $write, string $name): array
    {
        $path = tempnam(sys_get_temp_dir(), 'liq');
        $write($path);

        return ['path' => $path, 'name' => $name];
    }

    /** "VCM 2026-5698" — the traveller's initials and travel order number, safe for a file name. */
    private function baseName(Liquidation $liquidation): string
    {
        return trim(preg_replace('/[\\\\\/:*?"<>|]+/', '-', $liquidation->initials().' '.$liquidation->travel_order_no));
    }
}
