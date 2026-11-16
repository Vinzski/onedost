<?php

namespace App\Services\CashAdvance;

use App\Models\CashAdvance;
use App\Services\Travel\ItineraryOfTravelSheet;
use Closure;
use Illuminate\Support\Collection;
use PhpOffice\PhpSpreadsheet\IOFactory;
use RuntimeException;
use ZipArchive;

/** Builds a cash advance's downloads as temporary files. */
class CashAdvanceDocuments
{
    public const ITINERARY = 'itinerary';

    public const NO_PENDING = 'no-pending';

    public const ALL = 'all';

    /** Every traveller's Itinerary of Travel on the travel order, a sheet each. */
    public const TEAM_ITINERARIES = 'team-itineraries';

    public const TYPES = [self::ITINERARY, self::NO_PENDING, self::ALL, self::TEAM_ITINERARIES];

    /** The liquidation workbook holds the IOT sheet; a cash advance uses only that one. */
    private const WORKBOOK = 'templates/liquidation/forms.xlsx';

    public function __construct(
        private ItineraryOfTravelSheet $itinerary,
        private NoPendingCashAdvanceCertificate $certificate,
        private CashAdvanceService $cashAdvances,
    ) {}

    /**
     * @return array{path: string, name: string} A temp file (delete after sending) and its download name.
     */
    public function make(CashAdvance $cashAdvance, string $type): array
    {
        $cashAdvance->loadMissing('items');
        $base = $this->baseName($cashAdvance);

        return match ($type) {
            self::ITINERARY => $this->temp(fn (string $path) => $this->saveItineraries(collect([$cashAdvance]), $path), "{$base} - Itinerary of Travel.xlsx"),
            self::NO_PENDING => $this->temp(fn (string $path) => $this->certificate->save($cashAdvance, $path), "{$base} - No Pending Cash Advance.docx"),
            self::ALL => $this->zip($cashAdvance, "{$base} - Cash Advance.zip"),
            self::TEAM_ITINERARIES => $this->temp(
                fn (string $path) => $this->saveItineraries($this->cashAdvances->team($cashAdvance)->with('items')->get(), $path),
                $this->safe("TO {$cashAdvance->travel_order_no}").' - Itineraries of Travel.xlsx',
            ),
        };
    }

    /** One IOT sheet per cash advance ("IOT VCM", "IOT KDB"), in the order given. */
    private function saveItineraries(Collection $cashAdvances, string $path): void
    {
        $book = IOFactory::load(resource_path(self::WORKBOOK));
        foreach (array_reverse($book->getSheetNames()) as $name) {
            if ($name !== 'IOT') {
                $book->removeSheetByIndex($book->getIndex($book->getSheetByName($name)));
            }
        }
        $template = $book->getSheetByName('IOT');

        $titles = [];
        foreach ($cashAdvances as $cashAdvance) {
            $sheet = clone $template;
            $sheet->setTitle($titles[] = $this->sheetTitle($cashAdvance, $titles), false);
            $book->addSheet($sheet);
            $this->itinerary->fill($sheet, $cashAdvance);
            $sheet->setSelectedCells('A1');
        }

        $book->removeSheetByIndex($book->getIndex($template));
        $book->setActiveSheetIndex(0);

        IOFactory::createWriter($book, 'Xlsx')->save($path);
    }

    /** "IOT VCM"; a second traveller with the same initials gets "IOT VCM 2". */
    private function sheetTitle(CashAdvance $cashAdvance, array $taken): string
    {
        $base = mb_substr(trim('IOT '.$cashAdvance->initials()), 0, 27);
        $title = $base;
        for ($n = 2; in_array(mb_strtolower($title), array_map('mb_strtolower', $taken), true) || mb_strtolower($title) === 'iot'; $n++) {
            $title = "{$base} {$n}";
        }

        return $title;
    }

    private function zip(CashAdvance $cashAdvance, string $name): array
    {
        $parts = array_map(fn (string $type) => $this->make($cashAdvance, $type), [self::ITINERARY, self::NO_PENDING]);

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
        $path = tempnam(sys_get_temp_dir(), 'ca');
        $write($path);

        return ['path' => $path, 'name' => $name];
    }

    /** "VCM 2026-5698" — the traveller's initials and travel order number, safe for a file name. */
    private function baseName(CashAdvance $cashAdvance): string
    {
        return $this->safe($cashAdvance->initials().' '.$cashAdvance->travel_order_no);
    }

    private function safe(string $name): string
    {
        return trim(preg_replace('/[\\\\\/:*?"<>|]+/', '-', $name));
    }
}
