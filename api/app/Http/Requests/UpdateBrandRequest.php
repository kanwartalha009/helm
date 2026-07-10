<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateBrandRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('brand')) ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name'          => ['sometimes', 'string', 'max:120'],
            'timezone'      => ['sometimes', 'string', 'max:64', 'timezone:all'],
            'base_currency' => ['sometimes', 'string', 'size:3'],
            'group_tag'     => ['sometimes', 'nullable', 'string', 'max:60'],
            'status'        => ['sometimes', 'in:active,paused,archived'],
            // Phase 0 — margin-based rules stay off until set (spec §4 Phase 0).
            'gross_margin_pct' => ['sometimes', 'nullable', 'numeric', 'between:1,99'],
            'target_cpa'       => ['sometimes', 'nullable', 'numeric', 'min:0'],
        ];
    }
}
