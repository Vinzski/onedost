<?php

namespace App\Http\Requests\Liquidation;

use Illuminate\Support\Arr;

class UpdateLiquidationRequest extends StoreLiquidationRequest
{
    /** A saved liquidation's photos go through the photo upload instead. */
    public function rules(): array
    {
        return Arr::except(parent::rules(), ['photos', 'photos.*']);
    }
}
