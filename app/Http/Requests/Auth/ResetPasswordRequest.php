<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

class ResetPasswordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Same password rule as registration (`min:8`, `confirmed`) -- see
     * `RegisterStudentRequest`. `token` is the opaque value from the
     * reset-link query string; Laravel's `Password::reset()` is what
     * actually validates it against the hashed row in
     * `password_reset_tokens`, not this request.
     */
    public function rules(): array
    {
        return [
            'email' => ['required', 'email'],
            'token' => ['required', 'string'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ];
    }
}
