<?php

namespace App\Http\Requests\Organization;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Messaging MVP -- `opportunity_id` is the required recruiting context
 * every valid "who can message" reason in this MVP is anchored to (see
 * `MessagingService::canOrganizationMessageStudent()`). Ownership (does
 * this Opportunity belong to the authenticated Organization) and the
 * actual relationship check happen in the controller, not here -- this
 * request only validates shape.
 */
class StartConversationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'opportunity_id' => ['required', 'integer', 'exists:opportunities,id'],
        ];
    }
}
