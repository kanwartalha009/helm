<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Hash;

class ChangePasswordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'current_password' => ['required', 'string'],
            'new_password'     => ['required', 'string', 'min:12', 'confirmed'],
            // new_password_confirmation is the matched field for `confirmed`
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($v) {
            $user = $this->user();
            if (! $user) {
                return;
            }
            if (! Hash::check($this->input('current_password'), $user->password)) {
                $v->errors()->add('current_password', 'Current password is incorrect.');
            }
            if ($this->input('current_password') === $this->input('new_password')) {
                $v->errors()->add('new_password', 'New password must differ from the current one.');
            }
        });
    }
}
