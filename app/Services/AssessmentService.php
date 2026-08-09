<?php

namespace App\Services;

use App\Exceptions\AssessmentAlreadyExistsException;
use App\Exceptions\InvalidAssessmentSourceStatusException;
use App\Models\Application;
use App\Models\Assessment;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Owns the shared workflow for turning a shortlisted (or already
 * interview_scheduled) Application into an Assessment plus its
 * type-specific detail record. Both the legacy
 * `POST .../applications/{application}/interview` endpoint and the generic
 * `POST .../applications/{application}/assessments` endpoint call this
 * directly, so the status precondition, duplicate-assessment guard,
 * transaction boundary, and application-status side effect are never
 * duplicated between them.
 *
 * `Application.status` only ever moves to the generic `in_assessment` value
 * from here (see `transitionToInAssessment()`) -- never to a type-specific
 * value -- so a future `createQuizAssessment()` can reuse the exact same
 * transition without introducing another Application status. `Assessment.type`
 * still distinguishes interview vs. quiz; `Assessment.status`/`Interview.status`
 * own their own type-specific lifecycles. See docs/BUSINESS_RULES.md.
 *
 * Deliberately does not perform HTTP response construction, does not
 * return a JsonResponse, and does not perform organization-ownership
 * authorization -- all of that stays in the calling controllers, matching
 * every other controller/service pairing in this codebase (see
 * ApplicationAnalysisController + MatchingService).
 */
class AssessmentService
{
    /**
     * `interview_scheduled` stays allowed here purely for legacy rows that
     * reached that status without a real Assessment ever being created
     * (e.g. set directly through the generic status endpoint back when that
     * input was still allowed) -- it lets such an application still receive
     * its first real Assessment. `in_assessment` is deliberately absent: by
     * construction it only exists once a real Assessment already does, so
     * `assertNoExistingAssessment()` below is the guard for that case, not
     * this list -- adding it here would only weaken that guard's coverage.
     */
    private const ALLOWED_SOURCE_STATUSES = ['shortlisted', 'interview_scheduled'];

    /**
     * Creates an Assessment (`type=interview`) and its Interview detail
     * record in one transaction, then moves the application to the generic
     * `in_assessment` status (see `transitionToInAssessment()`).
     *
     * @param  array<string, mixed>  $interviewData  Already-validated Interview fields
     *                                                (interview_type, scheduled_at, ...).
     *
     * @throws InvalidAssessmentSourceStatusException  When the application's status
     *                                                  is not `shortlisted`/`interview_scheduled`.
     * @throws AssessmentAlreadyExistsException         When the application already has an
     *                                                   assessment (pre-check or a translated
     *                                                   unique-constraint violation).
     */
    public function createInterviewAssessment(Application $application, array $interviewData): Assessment
    {
        // Existing-assessment is checked first so that a genuine duplicate
        // always reports as "already exists" (409), even for an
        // `in_assessment` application (whose status alone would otherwise
        // -- and redundantly -- also fail the source-status check below).
        $this->assertNoExistingAssessment($application);
        $this->assertAllowedSourceStatus($application);

        try {
            return DB::transaction(function () use ($application, $interviewData) {
                $assessment = $application->assessment()->create([
                    'type' => 'interview',
                    'status' => 'scheduled',
                    'result' => null,
                ]);

                $assessment->interview()->create($interviewData);

                $this->transitionToInAssessment($application);

                return $assessment;
            });
        } catch (QueryException $e) {
            if ($this->isDuplicateAssessmentViolation($e)) {
                throw new AssessmentAlreadyExistsException();
            }

            throw $e;
        }
    }

    /**
     * The single place `Application.status` moves to the generic
     * `in_assessment` state. Every Assessment-creation path -- interview
     * today, quiz once it exists -- must route through here instead of
     * assigning the status literal itself, keeping this the one source of
     * truth for the transition.
     */
    private function transitionToInAssessment(Application $application): void
    {
        $application->status = 'in_assessment';
        $application->reviewed_at = now();
        $application->save();
    }

    private function assertAllowedSourceStatus(Application $application): void
    {
        if (! in_array($application->status, self::ALLOWED_SOURCE_STATUSES, true)) {
            throw new InvalidAssessmentSourceStatusException();
        }
    }

    private function assertNoExistingAssessment(Application $application): void
    {
        if ($application->assessment()->exists()) {
            throw new AssessmentAlreadyExistsException();
        }
    }

    /**
     * Narrowly confirms a QueryException is the `assessments.application_id`
     * unique-constraint violation (the concurrency-race counterpart to the
     * pre-check above) before treating it as a clean duplicate outcome --
     * any other integrity-constraint failure is rethrown untouched so it
     * surfaces as a genuine 500, never silently mislabeled as "duplicate".
     */
    private function isDuplicateAssessmentViolation(QueryException $e): bool
    {
        if ($e->getCode() !== '23000') {
            return false;
        }

        $message = $e->getMessage();

        return str_contains($message, 'assessments_application_id_unique')
            || str_contains($message, 'assessments.application_id');
    }
}
