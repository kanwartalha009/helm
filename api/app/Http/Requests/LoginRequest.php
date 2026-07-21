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
            // MFA is a separate two-step challenge (mfa_required + pending_token
            // → POST /auth/mfa/verify), never an inline field on login.
        ];
    }
}
