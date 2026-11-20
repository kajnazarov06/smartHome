<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class LoginRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'login' => ['required', 'string', 'max:255'], // email or username
            'password' => ['required', 'string'],
            'remember' => ['sometimes', 'boolean'],
        ];
    }

    /** @return array{email?: string, username?: string, password: string} */
    public function credentials(): array
    {
        $login = trim($this->string('login'));
        $field = filter_var($login, FILTER_VALIDATE_EMAIL) ? 'email' : 'username';

        return [$field => $login, 'password' => (string) $this->input('password')];
    }

    public function throttleKey(): string
    {
        return mb_strtolower(trim($this->string('login'))).'|'.$this->ip();
    }
}
