<?php

namespace App\Services\BookingRequest;

use App\Models\BookingRequest;
use PhpOffice\PhpSpreadsheet\IOFactory;

/** A booking request's download, as a temporary file. */
class BookingRequestDocuments
{
    public function __construct(private BookingRequestWorkbook $workbook) {}

    /**
     * @return array{path: string, name: string} A temp file (delete after sending) and its download name.
     */
    public function make(BookingRequest $booking): array
    {
        $path = tempnam(sys_get_temp_dir(), 'br');
        IOFactory::createWriter($this->workbook->build($booking), 'Xlsx')->save($path);

        return ['path' => $path, 'name' => $this->safe("TO {$booking->travel_order_no} - Airline Booking Request").'.xlsx'];
    }

    private function safe(string $name): string
    {
        return trim(preg_replace('/[\\\\\/:*?"<>|]+/', '-', $name));
    }
}
