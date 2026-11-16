<?php

namespace App\Http\Controllers\Dtr;

use App\Http\Controllers\Controller;
use App\Http\Requests\Dtr\ImportDtrRequest;
use App\Services\Dtr\DtrService;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

class DtrImportController extends Controller
{
    public function __construct(private DtrService $dtr) {}

    /** Read an uploaded DTR, then show its month. */
    public function store(ImportDtrRequest $request): RedirectResponse
    {
        $result = $this->dtr->import($request->file('file'));
        Inertia::flash([
            'message' => $result['message'],
            'info' => $result['info'],
            'status' => $result['status'],
        ]);

        return $result['status']
            ? redirect()->route('dtr.index', ['person' => $result['data']['person']->id, 'month' => $result['data']['month']->format('Y-m')])
            : back();
    }
}
