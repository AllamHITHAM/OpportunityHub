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
 * Deliberately does not perform HTTP response construction, does not
 * return a JsonResponse, and does not perform organization-ownership
 * authorization -- all of that stays in the calling controllers, matching
 * every other controller/service pairing in this codebase (see
 * ApplicationAnalysisController + MatchingService).
 */
class AssessmentService
{
    private const ALLOWED_SOURCE_STATUSES = ['shortlisted', 'interview_scheduled'];

    /**
     * Creates an Assessment (`type=interview`) and its Interview detail
     * record in one transaction, then moves the application to
     * `interview_scheduled` (a temporary backward-compatibility status --
     * see docs/BUSINESS_RULES.md -- not the long-term assessment-state
     * design).
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
        $this->assertAllowedSourceStatus($application);
        $this->assertNoExistingAssessment($application);

        try {
            return DB::transaction(function () use ($application, $interviewData) {
                $assessment = $application->assessment()->create([
                    'type' => 'interview',
                    'status' => 'scheduled',
                    'result' => null,
                ]);

                $assessment->interview()->create($interviewData);

                $application->status = 'interview_scheduled';
                $application->reviewed_at = now();
                $application->save();

                return $assessment;
            });
        } catch (QueryException $e) {
            if ($this->isDuplicateAssessmentViolation($e)) {
                throw new AssessmentAlreadyExistsException();
            }

            throw $e;
        }
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
