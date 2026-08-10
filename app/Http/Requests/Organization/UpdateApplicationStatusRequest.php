<?php

namespace App\Http\Requests\Organization;

use Illuminate\Foundation\Http\FormRequest;

class UpdateApplicationStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        // `in_assessment` is never accepted here -- it must only ever be
        // reached through a real Assessment-creation workflow (see
        // AssessmentService::transitionToInAssessment()). `interview_scheduled`
        // is also excluded: allowing it as manual input let an organization
        // fabricate an "assessment exists" status with no Assessment row
        // behind it. `offer_sent` is excluded the same way -- it must only
        // ever be reached through the future `OfferService::sendOffer()`
        // (Phase 6C-1). `accepted` is excluded as of Phase 6C-0: it now
        // means specifically "the student accepted the Offer", so only the
        // future `Student\OfferController::accept()` may write it -- an
        // organization can no longer set it directly. Existing rows may
        // still legitimately hold any of these values (legacy data /
        // assessment-driven / (eventually) offer-driven writes) -- this
        // rule only governs what a client may request here, not what the
        // column may contain.
        return [
            'status' => ['required', 'in:reviewed,shortlisted,rejected'],
        ];
    }
}
