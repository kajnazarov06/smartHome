<?php

namespace App\Http\Requests;

use App\Rules\LanHost;
use Illuminate\Foundation\Http\FormRequest;

class PairConfirmRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'host' => ['required', 'string', new LanHost],
            'code' => ['required', 'string', 'regex:/^\d{6}$/'],
            'name' => ['sometimes', 'nullable', 'string', 'max:64'],
        ];
    }
}
