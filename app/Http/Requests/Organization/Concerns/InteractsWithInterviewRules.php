<?php

namespace App\Http\Requests\Organization\Concerns;

/**
 * The single source of truth for Interview *creation* validation rules,
 * shared between the legacy `StoreInterviewRequest` (unprefixed, top-level
 * fields) and the generic `StoreAssessmentRequest` (prefixed under
 * `interview.*`, per the nested assessment request contract). Never copy
 * these rules by hand into a second place -- both requests call this.
 */
trait InteractsWithInterviewRules
{
    /**
     * @return array<string, array<int, string>>
     */
    protected function interviewCreationRules(string $prefix = ''): array
    {
        // The legacy (unprefixed) endpoint has no `type` field at all, so
        // `interview_type`/`scheduled_at` must stay unconditionally
        // required there -- exactly as before. The generic (prefixed)
        // endpoint also accepts `type=quiz`, so its interview.* fields
        // must only become required when `type=interview`, otherwise a
        // `type=quiz` request would be rejected by validation before it
        // ever reaches the explicit "Quiz assessments are not available
        // yet." business check.
        $required = $prefix === '' ? 'required' : 'required_if:type,interview';

        return [
            "{$prefix}interview_type" => [$required, 'in:onsite,online,phone'],
            "{$prefix}scheduled_at" => [$required, 'date'],
            "{$prefix}duration_minutes" => ['nullable', 'integer', 'min:1'],
            "{$prefix}meeting_link" => ["required_if:{$prefix}interview_type,online", 'nullable', 'string', 'max:2048'],
            "{$prefix}location" => ["required_if:{$prefix}interview_type,onsite", 'nullable', 'string', 'max:255'],
            "{$prefix}interviewer_name" => ['nullable', 'string', 'max:255'],
            "{$prefix}interviewer_email" => ['nullable', 'email', 'max:255'],
            "{$prefix}notes" => ['nullable', 'string', 'max:2000'],
        ];
    }
}
