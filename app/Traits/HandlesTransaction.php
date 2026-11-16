<?php

namespace App\Traits;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

trait HandlesTransaction
{
    /**
     * Run the callback inside a DB transaction and normalise the outcome.
     *
     * The callback must return ['data', 'message', 'info', 'status'].
     */
    public static function handleTransaction(callable $callback): array
    {
        $data = null;
        $info = null;
        $status = false;

        try {
            $result = DB::transaction($callback);
            $data = $result['data'];
            $info = $result['info'];
            $message = $result['message'];
            $status = $result['status'] ?? true;
        } catch (ValidationException $e) {
            $info = collect($e->errors())->flatten()->first() ?? 'Validation failed.';
            $message = 'Validation failed';
        } catch (\Exception $e) {
            $info = 'An unexpected error occurred: '.$e->getMessage();
            $message = 'Error occurred';
        }

        return [
            'data' => $data,
            'message' => $message ?? 'Error occurred',
            'info' => $info,
            'status' => $status,
        ];
    }
}
