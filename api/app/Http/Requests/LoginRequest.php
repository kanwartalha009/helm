<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class LoginRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'email'    => ['required', 'email', 'max:190'],
            'password' => ['required', 'string', 'min:8'],
            'mfa_code' => ['nullable', 'string', 'size:6'],
        ];
    }
}
