<?php

namespace App\Http\Controllers\Liquidation;

use App\Http\Controllers\Controller;
use App\Http\Requests\Liquidation\StoreLiquidationPhotosRequest;
use App\Models\Liquidation;
use App\Models\LiquidationPhoto;
use App\Services\Liquidation\LiquidationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\StreamedResponse;

class LiquidationPhotoController extends Controller
{
    public function __construct(private LiquidationService $liquidations) {}

    public function store(StoreLiquidationPhotosRequest $request, Liquidation $liquidation): RedirectResponse
    {
        $this->flash($this->liquidations->addPhotos($liquidation, $request->file('photos')));

        return back();
    }

    public function show(Liquidation $liquidation, LiquidationPhoto $photo): StreamedResponse
    {
        return Storage::disk('local')->response($photo->path, $photo->original_name);
    }

    public function destroy(Liquidation $liquidation, LiquidationPhoto $photo): RedirectResponse
    {
        $this->flash($this->liquidations->removePhoto($photo));

        return back();
    }

    private function flash(array $result): void
    {
        Inertia::flash([
            'message' => $result['message'],
            'info' => $result['info'],
            'status' => $result['status'],
        ]);
    }
}
