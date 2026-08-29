<?php

namespace App\Http\Requests\Organization;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Company Profile Polish phase: real multipart image upload for the
 * Company Logo. `image` (Laravel's own real-image-data check, not just
 * an extension match) plus `mimes:` narrows to the standard web-safe
 * raster formats -- never an arbitrary file, and never trusted from the
 * client's filename or declared MIME type alone (`mimes:` inspects real
 * file content). 2MB cap -- generous for a logo, small enough to keep
 * Company Profile fast to load.
 */
class UploadOrganizationLogoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'logo' => ['required', 'file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
        ];
    }
}
