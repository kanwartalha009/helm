<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;

class InviteUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('invite', User::class) ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'email'       => ['required', 'email', 'max:190'],
            // master_admin cannot be invited — only seeded.
            'role'        => ['required', 'in:manager,team_member,brand_user'],
            'note'        => ['nullable', 'string', 'max:2000'],
            'brand_ids'   => ['nullable', 'array'],
            'brand_ids.*' => ['integer', 'exists:brands,id'],
        ];
    }
}
