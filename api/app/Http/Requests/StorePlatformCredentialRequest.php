<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\PlatformCredential;
use Illuminate\Foundation\Http\FormRequest;

class StorePlatformCredentialRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', PlatformCredential::class) ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'platform' => ['required', 'string', 'in:shopify,meta,google,tiktok'],
            'key'      => ['required', 'string', 'max:60'],
            'value'    => ['required', 'string'],
            'label'    => ['nullable', 'string', 'max:120'],
            'metadata' => ['nullable', 'array'],
        ];
    }
}
