<?php

namespace App\Http\Requests\Liquidation;

use App\Services\Liquidation\LiquidationService;
use Illuminate\Foundation\Http\FormRequest;

class StoreLiquidationPhotosRequest extends FormRequest
{
    /**
     * Rules for one photo, shared with a new liquidation's first save. The
     * browser scales photos down before sending them; the size cap stops an
     * image that would take more memory to open than a small server has.
     */
    public const PHOTO = ['image', 'mimes:jpg,jpeg,png,webp', 'max:15360', 'dimensions:max_width=10000,max_height=10000'];

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $room = LiquidationService::MAX_PHOTOS - $this->route('liquidation')->photos()->count();

        return [
            'photos' => ['required', 'array', 'min:1', 'max:'.max($room, 0)],
            'photos.*' => self::PHOTO,
        ];
    }

    public function messages(): array
    {
        return self::photoMessages();
    }

    public static function photoMessages(): array
    {
        return [
            'photos.max' => 'An activity report holds up to '.LiquidationService::MAX_PHOTOS.' photos.',
            'photos.*.max' => 'Each photo must be under 15 MB.',
            'photos.*.image' => 'Only photos can be added.',
            'photos.*.mimes' => 'Photos must be JPG, PNG or WebP.',
            'photos.*.dimensions' => 'That photo is too large to open: under 10,000 pixels a side, please.',
        ];
    }
}
