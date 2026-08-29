<?php

namespace App\Http\Requests\Organization;

use Illuminate\Foundation\Http\FormRequest;

/**
 * `logo` is deliberately NOT accepted here (Company Profile Polish
 * phase) -- it used to be a freely-settable, unvalidated string with no
 * upload behind it (never used by any UI). It's now a managed image
 * path, settable only through the dedicated
 * `POST/DELETE /organization/profile/logo` endpoints
 * (`OrganizationProfileController::uploadLogo()`/`removeLogo()`), which
 * enforce real server-side file/MIME/size validation and generate their
 * own random filename -- accepting it as an arbitrary string here again
 * would let a client point it at anything.
 */
class UpdateOrganizationProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'organization_name' => ['required', 'string', 'max:255'],
            'organization_type' => ['required', 'in:company,university,ngo,training_center,government,other'],
            'industry' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'website' => ['nullable', 'url', 'max:255'],
            'phone' => ['nullable', 'string', 'max:30'],
            // Organization Public Profile phase: the same canonical
            // Location Catalog reference `opportunities.location_id` and
            // `student_profiles.current_location_id` already validate
            // against -- never a free-text location string.
            'location_id' => ['nullable', 'integer', 'exists:locations,id'],
        ];
    }
}
