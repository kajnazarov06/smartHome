<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class DeviceRenameRequest extends FormRequest
{
    public function rules(): array
    {
        return ['name' => ['required', ...DeviceSettingsRequest::NAME_RULES]];
    }
}
