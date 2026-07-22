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
            // Trusted-device token (Kanwar, 2026-07-22): an opaque token the
            // browser saved after a "Trust this device" MFA challenge. When it's
            // present and valid, an MFA-enrolled user skips the code on that
            // browser (the password is still required).
            'trusted_device_token' => ['sometimes', 'nullable', 'string', 'max:128'],
        ];
    }
}
