<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class DeviceSettingsRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'min:1', 'max:64'],
            'tz' => ['sometimes', 'string', 'timezone:all'],
            'brightness' => ['sometimes', 'integer', 'between:0,100'],
            'snooze_min' => ['sometimes', 'integer', 'between:1,30'],
        ];
    }
}
