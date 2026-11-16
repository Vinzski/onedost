<?php

namespace App\Http\Controllers\CashAdvance;

use App\Http\Controllers\Controller;
use App\Models\CashAdvance;
use App\Services\CashAdvance\CashAdvanceDocuments;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class CashAdvanceDocumentController extends Controller
{
    public function __construct(private CashAdvanceDocuments $documents) {}

    public function show(CashAdvance $cashAdvance, string $document): BinaryFileResponse
    {
        $file = $this->documents->make($cashAdvance, $document);

        return response()->download($file['path'], $file['name'])->deleteFileAfterSend();
    }
}
