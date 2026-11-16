<?php

namespace App\Http\Controllers\BookingRequest;

use App\Http\Controllers\Controller;
use App\Models\BookingRequest;
use App\Services\BookingRequest\BookingRequestDocuments;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class BookingRequestDocumentController extends Controller
{
    public function __construct(private BookingRequestDocuments $documents) {}

    public function show(BookingRequest $bookingRequest): BinaryFileResponse
    {
        $file = $this->documents->make($bookingRequest->load('passengers'));

        return response()->download($file['path'], $file['name'])->deleteFileAfterSend();
    }
}
