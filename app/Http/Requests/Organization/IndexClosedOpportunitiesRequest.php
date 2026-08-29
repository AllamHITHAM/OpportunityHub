<?php

namespace App\Http\Requests\Organization;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Closed Opportunities Scalability Polish -- query-parameter validation for
 * `GET /organization/opportunities/closed`, mirroring
 * `Public\IndexPublicOpportunityRequest`'s own convention for the
 * project's one other paginated/filterable listing endpoint.
 */
class IndexClosedOpportunitiesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // A custom `closed_from`/`closed_to` range (when either is
            // sent) always takes priority over `date_preset` -- see
            // `OpportunityController::applyClosedDateFilter()`. Neither
            // is required to be sent together; either alone filters on
            // just that bound.
            'date_preset' => ['nullable', 'in:all,last_30_days,last_3_months,last_6_months,this_year,older'],
            'closed_from' => ['nullable', 'date'],
            'closed_to' => ['nullable', 'date', 'after_or_equal:closed_from'],
            'sort' => ['nullable', 'in:newest,oldest'],
            'per_page' => ['nullable', 'integer', 'between:1,50'],
            'page' => ['nullable', 'integer', 'min:1'],
        ];
    }
}
