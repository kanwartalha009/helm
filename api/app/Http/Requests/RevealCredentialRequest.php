<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class RevealCredentialRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('reveal', $this->route('credential')) ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'password' => ['required', 'string', 'current_password'],
        ];
    }
}
