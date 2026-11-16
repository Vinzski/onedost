<?php

namespace App\Http\Controllers;

use App\Services\Backup\BackupService;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class BackupController extends Controller
{
    public function __construct(private BackupService $backups) {}

    /** Everything saved, as one zip to keep. */
    public function show(): BinaryFileResponse
    {
        $file = $this->backups->make();

        return response()->download($file['path'], $file['name'])->deleteFileAfterSend();
    }
}
