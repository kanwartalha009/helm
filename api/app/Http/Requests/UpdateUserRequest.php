<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('user')) ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name'        => ['sometimes', 'string', 'max:120'],
            'role'        => ['sometimes', 'in:master_admin,manager,team_member,brand_user'],
            'brand_ids'   => ['sometimes', 'array'],
            'brand_ids.*' => ['integer', 'exists:brands,id'],
            'status'      => ['sometimes', 'in:active,disabled'],
        ];
    }
}
