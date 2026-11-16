<?php

namespace App\Http\Controllers\Dtr;

use App\Http\Controllers\Controller;
use App\Http\Requests\Dtr\StoreDtrNoteRequest;
use App\Http\Requests\Dtr\UpdateDtrNoteRequest;
use App\Models\DtrNote;
use App\Services\Dtr\DtrService;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

class DtrNoteController extends Controller
{
    public function __construct(private DtrService $dtr) {}

    public function store(StoreDtrNoteRequest $request): RedirectResponse
    {
        $this->flash($this->dtr->createNote($request->validated()));

        return back();
    }

    public function update(UpdateDtrNoteRequest $request, DtrNote $dtrNote): RedirectResponse
    {
        $this->flash($this->dtr->updateNote($dtrNote, $request->validated()));

        return back();
    }

    public function destroy(DtrNote $dtrNote): RedirectResponse
    {
        $this->flash($this->dtr->deleteNote($dtrNote));

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
