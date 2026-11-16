<?php

namespace App\Http\Requests\LeaveApplication;

use App\Http\Requests\Concerns\ValidatesLeave;
use Illuminate\Foundation\Http\FormRequest;

class StoreLeaveApplicationRequest extends FormRequest
{
    use ValidatesLeave;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return $this->leaveRules();
    }

    public function messages(): array
    {
        return $this->leaveMessages();
    }

    public function attributes(): array
    {
        return $this->leaveAttributes();
    }
}
