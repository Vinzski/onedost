<?php

namespace App\Http\Controllers\Liquidation;

use App\Http\Controllers\Controller;
use App\Models\Liquidation;
use App\Services\Liquidation\LiquidationDocuments;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class LiquidationDocumentController extends Controller
{
    public function __construct(private LiquidationDocuments $documents) {}

    public function show(Liquidation $liquidation, string $document): BinaryFileResponse
    {
        $file = $this->documents->make($liquidation->load(['items', 'photos']), $document);

        return response()->download($file['path'], $file['name'])->deleteFileAfterSend();
    }
}
