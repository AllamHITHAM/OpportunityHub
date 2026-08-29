<?php

namespace App\Http\Requests\Organization;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Organization Public Profile phase: "Updates & Achievements" --
 * `organization_id` is never accepted from the client (always the
 * authenticated Organization's own, set by the controller), matching
 * `SendMessageRequest`'s own "only the fields a client can actually set"
 * doctrine.
 */
class StoreOrganizationPostRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'title' => ['nullable', 'string', 'max:255'],
            'body' => ['required', 'string', 'max:5000'],
            // Company Profile Polish phase: ONE optional image, never a
            // gallery -- same real-image/MIME/size validation as
            // `UploadOrganizationLogoRequest`.
            'image' => ['nullable', 'file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
        ];
    }

    /**
     * A whitespace-only body passes `required`/`string` but isn't a real
     * post -- rejected the same way `SendMessageRequest` rejects a
     * whitespace-only message body.
     */
    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            $body = $this->input('body');
            if (is_string($body) && trim($body) === '') {
                $validator->errors()->add('body', 'The body field must not be blank.');
            }
        });
    }
}
