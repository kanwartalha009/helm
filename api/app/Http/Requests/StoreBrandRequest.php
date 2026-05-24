<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreBrandRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', \App\Models\Brand::class) ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name'          => ['required', 'string', 'max:120'],
            'slug'          => ['nullable', 'string', 'max:120', 'regex:/^[a-z0-9-]+$/'],
            'timezone'      => ['required', 'string', 'max:64', 'timezone:all'],
            'base_currency' => ['required', 'string', 'size:3'],
            'group_tag'     => ['nullable', 'string', 'max:60'],
        ];
    }

    public function messages(): array
    {
        return [
            'slug.regex' => 'Slug must use only lowercase letters, numbers, and hyphens.',
        ];
    }
}
