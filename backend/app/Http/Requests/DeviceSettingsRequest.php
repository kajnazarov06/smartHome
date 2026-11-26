<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class DeviceSettingsRequest extends FormRequest
{
    public const NAME_RULES = ['string', 'min:1', 'max:64'];

    /** The clock's snooze range, shared by the device setting and per-alarm overrides. */
    public const SNOOZE_RULES = ['integer', 'between:1,30'];

    public function rules(): array
    {
        return [
            'name' => ['sometimes', ...self::NAME_RULES],
            'tz' => ['sometimes', 'string', 'timezone:all'],
            'brightness' => ['sometimes', 'integer', 'between:0,100'],
            'snooze_min' => ['sometimes', ...self::SNOOZE_RULES],
        ];
    }
}
