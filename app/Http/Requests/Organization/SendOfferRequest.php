<?php

namespace App\Http\Requests\Organization;

use Illuminate\Foundation\Http\FormRequest;

class SendOfferRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Every field is optional -- an Offer with no compensation terms at all
     * is valid v1 data (e.g. a volunteer/unpaid role) -- except that
     * `salary_amount`/`salary_currency`/`salary_period` must be supplied
     * together or not at all. The `required_with` pair below is
     * deliberately bidirectional: `salary_amount` requires the other two
     * (so a bare amount with no currency/period is rejected), and each of
     * `salary_currency`/`salary_period` requires `salary_amount` (so either
     * being supplied alone, with no amount, is rejected too) -- strict
     * internal consistency rather than silently normalizing a partial
     * compensation shape away.
     */
    public function rules(): array
    {
        return [
            'title' => ['nullable', 'string', 'max:255'],
            'salary_amount' => ['nullable', 'numeric', 'min:0', 'required_with:salary_currency,salary_period'],
            'salary_currency' => ['nullable', 'string', 'max:10', 'required_with:salary_amount'],
            'salary_period' => ['nullable', 'in:hourly,monthly,yearly', 'required_with:salary_amount'],
            // `today` or any future date -- the same `after_or_equal:today`
            // convention `StoreOpportunityRequest` already uses for
            // `application_deadline`. A start date already in the past
            // would never make sense for a not-yet-accepted Offer.
            'start_date' => ['nullable', 'date', 'after_or_equal:today'],
            'message' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
