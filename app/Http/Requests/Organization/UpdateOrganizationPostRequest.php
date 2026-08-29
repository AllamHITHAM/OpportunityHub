<?php

namespace App\Http\Requests\Organization;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Organization Public Profile phase: same validation shape as
 * [StoreOrganizationPostRequest] -- kept as a separate class rather than
 * reused directly, matching this app's existing Store/Update pairing
 * convention (e.g. `StoreOpportunityRequest`/`UpdateOpportunityRequest`).
 */
class UpdateOrganizationPostRequest extends FormRequest
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
            // Company Profile Polish phase: `image` present means
            // "replace with this file" (or "add one" if none existed);
            // `remove_image` (only consulted when `image` is absent)
            // means "clear the existing image"; neither present means
            // "keep whatever image already exists unchanged" -- the
            // same three-state "Keep existing / Replace / Remove" UX
            // the spec asks for.
            'image' => ['nullable', 'file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
            'remove_image' => ['nullable', 'boolean'],
        ];
    }

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
