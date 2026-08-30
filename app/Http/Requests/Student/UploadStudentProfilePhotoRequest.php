<?php

namespace App\Http\Requests\Student;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Student Profile Photo: mirrors `UploadOrganizationLogoRequest` exactly
 * -- real multipart image upload. `image` (Laravel's own real-image-data
 * check, not just an extension match) plus `mimes:` narrows to the
 * standard web-safe raster formats -- never an arbitrary file, and never
 * trusted from the client's filename or declared MIME type alone
 * (`mimes:` inspects real file content). Same 2MB cap as the Company
 * Logo upload.
 */
class UploadStudentProfilePhotoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'photo' => ['required', 'file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
        ];
    }
}
