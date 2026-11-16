<?php

namespace App\Services\Liquidation;

use App\Enums\ClaimKind;
use App\Models\Liquidation;
use App\Models\LiquidationPhoto;
use App\Services\Travel\HomeFares;
use App\Services\Travel\TripWriter;
use App\Traits\HandlesTransaction;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

class LiquidationService
{
    use HandlesTransaction;

    public const PER_PAGE = 20;

    public const MAX_PHOTOS = 6;

    /** Longest side of a stored photo, in pixels. */
    private const PHOTO_MAX_SIDE = 1600;

    public function __construct(private TripWriter $trips) {}

    /**
     * @param  array{search?: ?string, status?: ?string}  $filters
     */
    public function list(array $filters, ClaimKind $kind = ClaimKind::Liquidation): LengthAwarePaginator
    {
        return Liquidation::query()
            ->ofKind($kind)
            ->with('items')
            ->when($filters['search'] ?? null, fn (Builder $q, string $search) => $q->where(fn (Builder $q) => $q
                ->where('traveler_name', 'like', "%{$search}%")
                ->orWhere('travel_order_no', 'like', "%{$search}%")
                ->orWhere('purpose', 'like', "%{$search}%")
                ->orWhere('venue', 'like', "%{$search}%")))
            ->when($filters['status'] ?? null, fn (Builder $q, string $status) => match ($status) {
                'pending' => $q->whereNull('submitted_on'),
                'overdue' => $this->overdue($q),
                'submitted' => $q->whereNotNull('submitted_on'),
                default => $q,
            })
            ->orderByRaw('submitted_on is not null')
            ->orderByDesc('return_date')
            ->orderByDesc('id')
            ->paginate(self::PER_PAGE)
            ->withQueryString();
    }

    public function summary(ClaimKind $kind = ClaimKind::Liquidation): array
    {
        $claims = fn () => Liquidation::query()->ofKind($kind);

        return [
            'total' => $claims()->count(),
            'pending' => $claims()->whereNull('submitted_on')->count(),
            // Only liquidations have a deadline (30 days after the trip, for the cash advance).
            'overdue' => $kind === ClaimKind::Liquidation ? $this->overdue($claims())->count() : 0,
            'submitted' => $claims()->whereNotNull('submitted_on')->count(),
            // What the traveller is still owed: reimbursements not yet submitted, or spending past the cash advance.
            'amount_pending' => round($claims()->with('items')->whereNull('submitted_on')->get()
                ->sum(fn (Liquidation $claim) => $claim->reimbursement()), 2),
        ];
    }

    /** What a new liquidation starts with. */
    public function defaults(): array
    {
        $settings = config('onedost.liquidation');

        return [
            'official_station' => $settings['official_station'],
            'division' => $settings['division'],
            'fund_cluster' => $settings['fund_cluster'],
            'compliance' => 'strict',
            'evidence' => ['Certificate of Appearance', 'Travel Accomplishment Report'],
            'output_summary' => '*Please see attached Activity Report',
            'signatories' => $settings['signatories'],
        ];
    }

    /**
     * @param  list<UploadedFile>  $photos  Picked on the new form before it had a record to attach to.
     */
    public function create(array $data, array $photos = [], ClaimKind $kind = ClaimKind::Liquidation): array
    {
        $stored = [];

        $result = self::handleTransaction(function () use ($data, $photos, $kind, &$stored) {
            $liquidation = new Liquidation;
            $liquidation->kind = $kind;
            $this->fill($liquidation, $data);
            $this->storePhotos($liquidation, $photos, $stored);

            return ['data' => $liquidation, 'message' => $kind->label().' saved.', 'info' => null, 'status' => true];
        });

        if (! $result['status']) {
            Storage::disk('local')->delete($stored);
        }

        return $result;
    }

    public function update(Liquidation $liquidation, array $data): array
    {
        return self::handleTransaction(function () use ($liquidation, $data) {
            $this->fill($liquidation, $data);

            return ['data' => $liquidation, 'message' => $liquidation->kind->label().' saved.', 'info' => null, 'status' => true];
        });
    }

    public function delete(Liquidation $liquidation): array
    {
        $paths = $liquidation->photos()->pluck('path')->all();

        $result = self::handleTransaction(function () use ($liquidation) {
            $liquidation->delete();

            return ['data' => null, 'message' => $liquidation->kind->label().' deleted.', 'info' => null, 'status' => true];
        });

        if ($result['status']) {
            Storage::disk('local')->delete($paths);
        }

        return $result;
    }

    /**
     * A copy for another traveller on the same trip: itinerary, report and
     * photos carry over; anything personal to the first traveller does not.
     */
    /**
     * Others on the same travel order (group travel): one claim each, of the
     * same kind, with the itinerary, reports and photos, their own saved
     * details and their own fares from home. DV/OR numbers, the submission
     * date and any cash advance stay with the source.
     *
     * @param  list<string>  $names
     */
    public function addTravelers(Liquidation $source, array $names): array
    {
        $copiedPhotos = [];

        $result = self::handleTransaction(function () use ($source, $names, &$copiedPhotos) {
            $people = $this->trips->newcomers($names, $this->team($source)->pluck('traveler_name'));

            $copies = [];
            foreach ($people as $person) {
                $copy = $source->replicate(['cash_advance_id', 'dv_no', 'dv_date', 'or_no', 'or_date', 'submitted_on']);
                $copy->fill($this->trips->travelerColumns($person, $source))->save();

                foreach ($this->trips->itemsFor($source->items, $person['traveler']) as $item) {
                    $copy->items()->create($item);
                }

                foreach ($source->photos as $photo) {
                    $path = $this->photoPath($copy, pathinfo($photo->path, PATHINFO_EXTENSION) ?: 'jpg');
                    if (! Storage::disk('local')->copy($photo->path, $path)) {
                        throw new RuntimeException('Could not copy a photo.');
                    }
                    $copiedPhotos[] = $path;
                    $copy->photos()->create(['path' => $path, 'original_name' => $photo->original_name, 'position' => $photo->position]);
                }
                $copies[] = $copy;
            }

            return ['data' => $copies, ...$this->trips->addedMessage($people, $source->items, $source->travel_order_no), 'status' => true];
        });

        if (! $result['status']) {
            Storage::disk('local')->delete($copiedPhotos);
        }

        return $result;
    }

    /** Every claim of this kind on its travel order, this one included. */
    public function team(Liquidation $liquidation): Builder
    {
        return Liquidation::query()->ofKind($liquidation->kind)
            ->where('travel_order_no', $liquidation->travel_order_no)
            ->orderBy('traveler_name')->orderBy('id');
    }

    /**
     * @param  list<UploadedFile>  $files
     */
    public function addPhotos(Liquidation $liquidation, array $files): array
    {
        $stored = [];

        $result = self::handleTransaction(function () use ($liquidation, $files, &$stored) {
            $this->storePhotos($liquidation, $files, $stored);

            $count = count($files);

            return ['data' => null, 'message' => $count === 1 ? 'Photo added.' : "{$count} photos added.", 'info' => null, 'status' => true];
        });

        if (! $result['status']) {
            Storage::disk('local')->delete($stored);
        }

        return $result;
    }

    public function removePhoto(LiquidationPhoto $photo): array
    {
        $path = $photo->path;

        $result = self::handleTransaction(function () use ($photo) {
            $photo->delete();

            return ['data' => null, 'message' => 'Photo removed.', 'info' => null, 'status' => true];
        });

        if ($result['status']) {
            Storage::disk('local')->delete($path);
        }

        return $result;
    }

    /**
     * Save each photo (resized) after any already attached. Paths written are
     * appended to $stored so a failed transaction can remove the files.
     *
     * @param  list<UploadedFile>  $files
     * @param  list<string>  $stored
     */
    private function storePhotos(Liquidation $liquidation, array $files, array &$stored): void
    {
        $position = (int) $liquidation->photos()->max('position');

        foreach ($files as $file) {
            $path = $this->photoPath($liquidation, 'jpg');
            Storage::disk('local')->put($path, $this->resized($file));
            $stored[] = $path;
            $liquidation->photos()->create([
                'path' => $path,
                'original_name' => Str::limit($file->getClientOriginalName(), 250, ''),
                'position' => ++$position,
            ]);
        }
    }

    private function fill(Liquidation $liquidation, array $data): void
    {
        $traveler = $this->trips->rememberTraveler($data);

        $liquidation->fill([
            ...Arr::except($data, ['items', ...HomeFares::TRAVELER_FIELDS]),
            'traveler_id' => $traveler->id,
            'supplies_amount' => $data['supplies_amount'] ?? 0,
            'other_expenses_amount' => $data['other_expenses_amount'] ?? 0,
            'persons_contacted' => array_values($data['persons_contacted'] ?? []),
            'evidence' => array_values(array_filter($data['evidence'] ?? [], 'filled')),
            'receipt_certificates' => array_map(fn (array $certificate) => [
                'lines' => array_map(fn (array $line) => [
                    'particulars' => $line['particulars'],
                    'amount' => round((float) $line['amount'], 2),
                ], array_values($certificate['lines'])),
            ], array_values($data['receipt_certificates'] ?? [])) ?: null,
            'lunch_provided' => (bool) ($data['lunch_provided'] ?? false),
            'has_travel_order' => (bool) ($data['has_travel_order'] ?? false),
            'has_certificate_of_appearance' => (bool) ($data['has_certificate_of_appearance'] ?? false),
        ]);

        // A reimbursement settles no cash advance: nothing to refund or record against one.
        if ($liquidation->isReimbursement()) {
            $liquidation->forceFill([
                'cash_advance_id' => null,
                'cash_advance_amount' => null,
                'dv_no' => null,
                'dv_date' => null,
                'or_no' => null,
                'or_date' => null,
            ]);
        }

        $liquidation->save();

        $this->trips->syncItems($liquidation->items(), $data['items']);
        $liquidation->unsetRelation('items');
    }

    /** Not submitted, and past the 30 days allowed after the trip ended. */
    private function overdue(Builder $query): Builder
    {
        $cutoff = CarbonImmutable::today()->subDays(config('onedost.liquidation.deadline_days'));

        return $query->whereNull('submitted_on')->where('return_date', '<', $cutoff->toDateString());
    }

    private function photoPath(Liquidation $liquidation, string $extension): string
    {
        return "liquidations/{$liquidation->id}/".Str::uuid().'.'.$extension;
    }

    /** JPEG bytes, scaled down so the longest side is at most PHOTO_MAX_SIDE. */
    private function resized(UploadedFile $file): string
    {
        $image = @imagecreatefromstring((string) file_get_contents($file->getRealPath()));
        if ($image === false) {
            throw new RuntimeException("{$file->getClientOriginalName()} is not a readable image.");
        }

        // Phone photos are often stored sideways with an EXIF hint.
        $orientation = function_exists('exif_read_data') ? (@exif_read_data($file->getRealPath())['Orientation'] ?? 1) : 1;
        $image = match ((int) $orientation) {
            3 => imagerotate($image, 180, 0),
            6 => imagerotate($image, -90, 0),
            8 => imagerotate($image, 90, 0),
            default => $image,
        };

        $longest = max(imagesx($image), imagesy($image));
        if ($longest > self::PHOTO_MAX_SIDE) {
            $image = imagescale($image, (int) round(imagesx($image) * self::PHOTO_MAX_SIDE / $longest));
        }

        ob_start();
        imagejpeg($image, null, 82);

        return (string) ob_get_clean();
    }
}
