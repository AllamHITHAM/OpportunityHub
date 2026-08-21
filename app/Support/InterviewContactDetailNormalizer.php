<?php

namespace App\Support;

/**
 * Phase Final-QA-1: keeps only the attendance detail relevant to an
 * Interview's current `interview_type` populated — `contact_phone`
 * (phone), `meeting_link` (online), `location` (onsite). Applied at
 * persistence time, after validation, from the one place each of
 * `AssessmentService::createInterviewAssessment()` (create) and
 * `Organization\InterviewController::update()` (full-replace update) writes
 * an Interview, so stale data from a previous type is never possible to
 * leak — a type change (e.g. phone -> online) clears the now-irrelevant
 * `contact_phone` even if the request never mentioned it at all, which a
 * plain `$interview->update($request->validated())` would otherwise leave
 * untouched (validated() only ever contains keys actually present in the
 * request).
 */
class InterviewContactDetailNormalizer
{
    /**
     * @param  array<string, mixed>  $data  Already-validated Interview fields.
     * @return array<string, mixed>
     */
    public static function normalize(array $data): array
    {
        $type = $data['interview_type'] ?? null;

        $data['contact_phone'] = $type === 'phone' ? ($data['contact_phone'] ?? null) : null;
        $data['meeting_link'] = $type === 'online' ? ($data['meeting_link'] ?? null) : null;
        $data['location'] = $type === 'onsite' ? ($data['location'] ?? null) : null;

        return $data;
    }
}
