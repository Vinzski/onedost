<?php

namespace App\Http\Requests\BookingRequest;

use App\Http\Requests\Concerns\ValidatesBooking;
use Illuminate\Foundation\Http\FormRequest;

class UpdateBookingRequestRequest extends FormRequest
{
    use ValidatesBooking;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return $this->bookingRules();
    }

    public function messages(): array
    {
        return $this->bookingMessages();
    }

    public function attributes(): array
    {
        return $this->bookingAttributes();
    }
}
