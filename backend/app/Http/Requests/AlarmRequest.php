<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** Create (POST) requires time + days; update (PUT) accepts partial changes. */
class AlarmRequest extends FormRequest
{
    public function rules(): array
    {
        $required = $this->isMethod('post') ? 'required' : 'sometimes';

        return [
            'time' => [$required, 'string', 'regex:/^([01]\d|2[0-3]):[0-5]\d$/'],
            // `present`, not `required`: an empty list is valid and means a one-shot alarm.
            'days' => [$this->isMethod('post') ? 'present' : 'sometimes', 'array', 'max:7'],
            'days.*' => ['integer', 'between:1,7', 'distinct'], // ISO weekday, 1 = Monday
            'enabled' => ['sometimes', 'boolean'],
            'label' => ['sometimes', 'nullable', 'string', 'max:32'],
            'snooze_min' => ['sometimes', 'integer', 'between:1,30'],
            'sound' => ['sometimes', 'string', 'max:32'],
            'volume' => ['sometimes', 'integer', 'between:0,100'],
        ];
    }

    public function alarm(): array
    {
        $data = $this->validated();
        if ($this->isMethod('post')) {
            $data['enabled'] ??= true;
        }

        return $data;
    }
}
