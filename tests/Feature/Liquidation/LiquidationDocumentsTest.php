<?php

use App\Models\Liquidation;
use App\Services\Liquidation\ActivityReportDocument;
use App\Services\Liquidation\LiquidationWorkbook;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\RichText\RichText;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

uses(RefreshDatabase::class);

beforeEach(fn () => Storage::fake('local'));

function sheet(Spreadsheet $book, string $prefix): Worksheet
{
    foreach ($book->getAllSheets() as $sheet) {
        if (str_starts_with($sheet->getTitle(), $prefix)) {
            return $sheet;
        }
    }
    throw new RuntimeException("No sheet {$prefix}");
}

function text(Worksheet $sheet, string $cell): string
{
    $value = $sheet->getCell($cell)->getValue();

    return $value instanceof RichText ? $value->getPlainText() : (string) $value;
}

/** A trip with $legs itinerary rows (₱100 fare each, ₱1,500 per diem on even legs) and one CENRR certificate. */
function tripWithLegs(int $legs, array $attributes = []): Liquidation
{
    $liquidation = Liquidation::factory()->create([
        'traveler_name' => 'VINCE BRADLEY C. MULOC', 'travel_order_no' => '2026-5698',
        'departure_date' => '2026-09-20', 'return_date' => '2026-09-30',
        'receipt_certificates' => [['lines' => [['particulars' => 'Payment for Transportation from Residence to DOST IX Office on 20 September 2026', 'amount' => 100]]]],
        ...$attributes,
    ]);
    foreach (range(0, $legs - 1) as $i) {
        $liquidation->items()->create([
            'position' => $i, 'date' => '2026-09-'.(20 + intdiv($i, 2)), 'place' => "Leg {$i} to somewhere",
            'departure_time' => '08:00', 'arrival_time' => '17:30', 'transport_means' => 'Public',
            'transport_cost' => 100, 'per_diem' => $i % 2 ? 0 : 1500,
        ]);
    }

    return $liquidation->fresh();
}

it('fills the itinerary of travel and names the sheets after the traveller', function () {
    $liquidation = tripWithLegs(4);
    $book = app(LiquidationWorkbook::class)->build($liquidation);
    $iot = sheet($book, 'IOT');

    expect(array_map(fn ($s) => $s->getTitle(), $book->getAllSheets()))->toBe(['IOT VCM', 'TAR VCM', 'CTC VCM', 'CENRR VCM', 'LIQ REPORT VCM'])
        ->and(text($iot, 'K10'))->toBe('DOST TO No.: 2026-5698')
        ->and(text($iot, 'D13'))->toBe('VINCE BRADLEY C. MULOC')
        ->and(text($iot, 'I13'))->toBe('Date of Travel :  20 - 30 September 2026')
        ->and(text($iot, 'C19'))->toBe('Leg 0 to somewhere')
        ->and($iot->getCell('B19')->getFormattedValue())->toBe('20-Sep-26')
        ->and($iot->getCell('G19')->getFormattedValue())->toBe('8:00 AM')
        ->and($iot->getCell('H22')->getFormattedValue())->toBe('5:30 PM')
        // Two legs a day: the date cell spans both.
        ->and($iot->getMergeCells())->toContain('B19:B20', 'B21:B22')
        // Totals sit right under the 4 legs; the template's 8 rows shrank.
        ->and(text($iot, 'B23'))->toBe('TOTAL')
        ->and($iot->getCell('M23')->getCalculatedValue())->toEqual(3400)
        ->and(text($iot, 'B32'))->toBe('RICARDO J. APOLINARIO III')
        ->and(text($iot, 'H32'))->toBe('ROSEMARIE S. SALAZAR');
});

it('grows the itinerary for long trips without losing the signature block', function () {
    $iot = sheet(app(LiquidationWorkbook::class)->build(tripWithLegs(12)), 'IOT');

    expect(text($iot, 'C30'))->toBe('Leg 11 to somewhere')
        ->and(text($iot, 'B31'))->toBe('TOTAL')
        ->and($iot->getCell('M31')->getCalculatedValue())->toEqual(10200)
        ->and(text($iot, 'B40'))->toBe('RICARDO J. APOLINARIO III')
        ->and(text($iot, 'B41'))->toBe('ARD for TOS');
});

it('fills the accomplishment report, adding rows for extra persons contacted', function () {
    $liquidation = tripWithLegs(2, [
        'persons_contacted' => [['name' => 'A'], ['name' => 'B', 'designation' => 'PSTD'], ['name' => 'C', 'designation' => 'LGU']],
        'supplies_amount' => 250,
        'follow_up' => 'Monitor the deployment',
    ]);
    $tar = sheet(app(LiquidationWorkbook::class)->build($liquidation), 'TAR');

    expect(text($tar, 'D21'))->toBe('A')
        ->and(text($tar, 'D23'))->toBe('C')
        ->and(text($tar, 'J23'))->toBe('LGU')
        ->and($tar->getMergeCells())->toContain('D23:I23')
        ->and($tar->getCell('J28')->getValue())->toEqual(1700)
        ->and($tar->getCell('J29')->getValue())->toEqual(250)
        ->and($tar->getCell('J31')->getCalculatedValue())->toEqual(1950)
        ->and(text($tar, 'D34'))->toBe('Monitor the deployment')
        ->and(text($tar, 'D38'))->toBe('VINCE BRADLEY C. MULOC');
});

it('ticks how the travel went on the certificate of travel completed', function () {
    $book = app(LiquidationWorkbook::class)->build(tripWithLegs(2, [
        'compliance' => 'cut_short', 'compliance_note' => 'Returned early.', 'cash_advance_amount' => 5000, 'or_no' => 'OR-77',
    ]));
    $ctc = sheet($book, 'CTC');

    expect(trim(text($ctc, 'D20')))->toBe('X')
        ->and(text($ctc, 'D19'))->toBe('')
        ->and(text($ctc, 'F20'))->toContain('₱ 3,300.00')
        ->and(text($ctc, 'F21'))->toContain('OR-77')
        ->and(text($ctc, 'D26'))->toBe('Returned early.')
        ->and(text($ctc, 'C16'))->toContain('No. 2026-5698 dated')
        ->and(text($ctc, 'G30'))->toBe('Certificate of Appearance');
});

it('prints each CENRR certificate with its particulars and amounts, two to a block', function () {
    $liquidation = tripWithLegs(2, ['receipt_certificates' => [
        ['lines' => [
            ['particulars' => 'Payment for Transportation from Residence to Zamboanga Airport on 12 October 2026', 'amount' => 150],
            ['particulars' => 'Payment for Transportation from Zamboanga Airport to Residence on 14 October 2026', 'amount' => 150],
        ]],
        ['lines' => [['particulars' => 'Porterage', 'amount' => 100]]],
        ['lines' => [['particulars' => 'Tricycle to the venue', 'amount' => 60], ['particulars' => 'Tricycle back', 'amount' => 60], ['particulars' => 'Jeepney', 'amount' => 20.5]]],
    ]]);
    $cenrr = sheet(app(LiquidationWorkbook::class)->build($liquidation), 'CENRR');

    // Certificate 1 (left): two items on rows 14–15, totalled on row 17.
    expect(text($cenrr, 'B14'))->toBe('Payment for Transportation from Residence to Zamboanga Airport on 12 October 2026')
        ->and(text($cenrr, 'B15'))->toBe('Payment for Transportation from Zamboanga Airport to Residence on 14 October 2026')
        ->and($cenrr->getCell('K15')->getValue())->toEqual(150)
        ->and($cenrr->getCell('K17')->getCalculatedValue())->toEqual(300)
        // Certificate 2 (right of the same block).
        ->and(text($cenrr, 'V14'))->toBe('Porterage')
        ->and($cenrr->getCell('AE17')->getCalculatedValue())->toEqual(100)
        // Certificate 3: a second block 32 rows down, all three rows used.
        ->and(text($cenrr, 'B40'))->toBe('CERTIFICATION OF EXPENSES NOT REQUIRING RECEIPTS')
        ->and(text($cenrr, 'B48'))->toBe('Jeepney')
        ->and($cenrr->getCell('K49')->getCalculatedValue())->toEqual(140.5)
        ->and(text($cenrr, 'D42'))->toBe('VINCE BRADLEY C. MULOC')
        // The unused right half of the last block is blank.
        ->and(text($cenrr, 'V40'))->toBe('')
        ->and(text($cenrr, 'V46'))->toBe('');
});

it('leaves out the CENRR when there are no expenses without receipts', function () {
    $liquidation = tripWithLegs(2, ['receipt_certificates' => null]);

    $titles = array_map(fn ($s) => $s->getTitle(), app(LiquidationWorkbook::class)->build($liquidation)->getAllSheets());

    expect($titles)->not->toContain('CENRR VCM')->toHaveCount(4);
});

it('fills both copies of the liquidation report', function () {
    $liq = sheet(app(LiquidationWorkbook::class)->build(tripWithLegs(2, ['cash_advance_amount' => 1000, 'dv_no' => 'DV-2026-09-123'])), 'LIQ');

    expect($liq->getCell('H15')->getValue())->toEqual(1700)
        ->and($liq->getCell('H16')->getValue())->toEqual(1000)
        ->and($liq->getCell('H17')->getValue())->toEqual(0)
        ->and($liq->getCell('H18')->getValue())->toEqual(700)
        ->and(text($liq, 'C16'))->toContain('DV No.DV-2026-09-123')
        ->and(text($liq, 'C12'))->toStartWith('while on official travel re: ')
        ->and($liq->getCell('H43')->getCalculatedValue())->toEqual(700)
        ->and(text($liq, 'G47'))->toBe('INGRID T. ABELLA-COLCOL');
});

it('writes the activity report with its narrative and photos', function () {
    $liquidation = tripWithLegs(2, [
        'activity_title' => 'Activity Report of R&D Week',
        'activity_body' => "First paragraph.\n\nSecond paragraph & more.",
    ]);
    $this->post(route('liquidations.photos.store', $liquidation), ['photos' => [UploadedFile::fake()->image('a.jpg', 900, 600)]]);

    $path = tempnam(sys_get_temp_dir(), 'test');
    app(ActivityReportDocument::class)->save($liquidation->fresh(), $path);

    $zip = new ZipArchive;
    $zip->open($path);
    $xml = $zip->getFromName('word/document.xml');
    $hasImage = collect(range(0, $zip->numFiles - 1))->contains(fn ($i) => str_starts_with($zip->getNameIndex($i), 'word/media/'));
    $zip->close();
    unlink($path);

    expect(simplexml_load_string($xml))->not->toBeFalse()
        ->and($xml)->toContain('Activity Report of R&amp;D Week', 'First paragraph.', 'Second paragraph &amp; more.', 'September 20-30, 2026', 'VINCE BRADLEY C. MULOC', 'ARD for TOS')
        ->and($hasImage)->toBeTrue();
});

it('prints the chosen fund cluster and means of transport, including typed-in ones', function () {
    $liquidation = tripWithLegs(2, ['fund_cluster' => 'MDS Trust Fund']);
    $liquidation->items()->where('position', 1)->update(['transport_means' => 'Habal-habal']);

    $book = app(LiquidationWorkbook::class)->build($liquidation->fresh());

    expect(text(sheet($book, 'IOT'), 'B10'))->toBe('Fund Cluster:  MDS Trust Fund')
        ->and(text(sheet($book, 'CTC'), 'K9'))->toBe('MDS Trust Fund')
        ->and(text(sheet($book, 'IOT'), 'I19'))->toBe('Public')
        ->and(text(sheet($book, 'IOT'), 'I20'))->toBe('Habal-habal');
});

it('downloads each document, or both as a zip', function () {
    $liquidation = Liquidation::factory()->withItinerary()->create(['traveler_name' => 'VINCE BRADLEY C. MULOC', 'travel_order_no' => '2026/5698']);

    $this->get(route('liquidations.download', [$liquidation, 'forms']))
        ->assertOk()
        ->assertDownload('VCM 2026-5698 - Liquidation Forms.xlsx');
    $this->get(route('liquidations.download', [$liquidation, 'activity-report']))->assertDownload('VCM 2026-5698 - Activity Report.docx');

    $response = $this->get(route('liquidations.download', [$liquidation, 'all']))->assertDownload('VCM 2026-5698 - Liquidation.zip');
    $zip = new ZipArchive;
    $zip->open($response->getFile()->getPathname());
    $names = array_map(fn ($i) => $zip->getNameIndex($i), range(0, $zip->numFiles - 1));
    $zip->close();

    expect($names)->toBe(['VCM 2026-5698 - Liquidation Forms.xlsx', 'VCM 2026-5698 - Activity Report.docx']);

    // The cash advance certification is no longer part of the package.
    $this->get('/liquidations/'.$liquidation->id.'/download/cash-advance')->assertNotFound();
});
