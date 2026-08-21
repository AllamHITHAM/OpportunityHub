<?php

namespace App\Http\Controllers\Organization;

use App\Exceptions\AssessmentAlreadyExistsException;
use App\Exceptions\InvalidAssessmentSourceStatusException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Organization\CompleteInterviewRequest;
use App\Http\Requests\Organization\StoreInterviewRequest;
use App\Http\Requests\Organization\UpdateInterviewRequest;
use App\Models\Application;
use App\Models\Interview;
use App\Services\AssessmentService;
use App\Services\NotificationService;
use App\Support\InterviewContactDetailNormalizer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class InterviewController extends Controller
{
    public function __construct(
        private readonly AssessmentService $assessments,
        private readonly NotificationService $notifications,
    ) {
    }

    public function store(StoreInterviewRequest $request, Application $application): JsonResponse
    {
        if ($application->opportunity->organization_id !== $request->user()->organizationProfile->id) {
            return response()->json([
                'success' => false,
                'message' => 'Application not found',
                'data' => null,
            ], 404);
        }

        try {
            $assessment = $this->assessments->createInterviewAssessment(
                $application,
                $request->validated(),
            );
        } catch (InvalidAssessmentSourceStatusException) {
            return response()->json([
                'success' => false,
                'message' => 'An interview can only be scheduled for shortlisted applications',
                'data' => null,
            ], 422);
        } catch (AssessmentAlreadyExistsException) {
            return response()->json([
                'success' => false,
                'message' => 'An interview already exists for this application',
                'data' => null,
            ], 409);
        }

        return response()->json([
            'success' => true,
            'message' => 'Interview scheduled successfully',
            'data' => $assessment->interview->load('assessment.application'),
        ], 201);
    }

    public function index(Request $request): JsonResponse
    {
        $organizationId = $request->user()->organizationProfile->id;

        $interviews = Interview::whereHas('assessment.application.opportunity', function ($query) use ($organizationId) {
            $query->where('organization_id', $organizationId);
        })->with('assessment.application')->get();

        return response()->json([
            'success' => true,
            'message' => 'Interviews retrieved successfully',
            'data' => $interviews,
        ]);
    }

    public function show(Interview $interview, Request $request): JsonResponse
    {
        if ($interview->assessment->application->opportunity->organization_id !== $request->user()->organizationProfile->id) {
            return response()->json([
                'success' => false,
                'message' => 'Interview not found',
                'data' => null,
            ], 404);
        }

        return response()->json([
            'success' => true,
            'message' => 'Interview retrieved successfully',
            'data' => $interview->load('assessment.application'),
        ]);
    }

    public function update(UpdateInterviewRequest $request, Interview $interview): JsonResponse
    {
        if ($interview->assessment->application->opportunity->organization_id !== $request->user()->organizationProfile->id) {
            return response()->json([
                'success' => false,
                'message' => 'Interview not found',
                'data' => null,
            ], 404);
        }

        // Captured before the mutation -- `UpdateInterviewRequest` requires
        // `interview_type`/`scheduled_at` on every call (full-replace PUT
        // semantics), so an idempotent re-submit of the same values must
        // not read as a reschedule. `scheduled_at` is compared with
        // Carbon's own `equalTo()`, not `!==` (two different Carbon
        // instances for the same instant are never `===`/`!==`-equal by
        // object identity).
        $previousScheduledAt = $interview->scheduled_at;
        $previousInterviewType = $interview->interview_type;

        DB::transaction(function () use ($request, $interview, $previousScheduledAt, $previousInterviewType) {
            // Phase Final-QA-1: a full-replace PUT still only carries
            // whatever the client actually sent -- if the interview type
            // changed and the client (correctly) omitted the now-irrelevant
            // old detail field, `$request->validated()` simply won't
            // contain that key, and a plain `update()` would leave the
            // stale value in place. Normalizing here guarantees only the
            // current type's detail field survives.
            $interview->update(InterviewContactDetailNormalizer::normalize($request->validated()));

            // Phase 7A-2: "rescheduled" means the date/time or the
            // interview format itself changed -- not a logistics-only edit
            // (meeting_link/location/interviewer_name/notes) for the same
            // slot, which doesn't meaningfully change what the student
            // already knows to expect.
            $scheduledAtChanged = $previousScheduledAt === null
                || ! $previousScheduledAt->equalTo($interview->scheduled_at);
            $typeChanged = $previousInterviewType !== $interview->interview_type;

            if (! $scheduledAtChanged && ! $typeChanged) {
                return;
            }

            $application = $interview->assessment->application;
            $this->notifications->notifyInterviewRescheduled(
                $application->studentProfile->user,
                $application->opportunity->title,
                $application->id,
                $interview,
            );
        });

        return response()->json([
            'success' => true,
            'message' => 'Interview updated successfully',
            'data' => $interview->fresh('assessment.application'),
        ]);
    }

    public function complete(CompleteInterviewRequest $request, Interview $interview): JsonResponse
    {
        if ($interview->assessment->application->opportunity->organization_id !== $request->user()->organizationProfile->id) {
            return response()->json([
                'success' => false,
                'message' => 'Interview not found',
                'data' => null,
            ], 404);
        }

        DB::transaction(function () use ($request, $interview) {
            $interview->status = 'completed';
            $interview->completed_at = now();

            if ($request->filled('decision')) {
                $interview->decision = $request->validated('decision');
            }

            if ($request->filled('rating')) {
                $interview->rating = $request->validated('rating');
            }

            if ($request->filled('company_feedback')) {
                $interview->company_feedback = $request->validated('company_feedback');
            }

            $interview->save();

            // The assessment mirrors the interview's shared lifecycle
            // state -- it never drives the application's recruitment
            // status, which stays under the organization's manual control.
            $assessment = $interview->assessment;
            $assessment->status = 'completed';
            $assessment->completed_at = $interview->completed_at;
            $assessment->result = $this->assessmentResultFor($interview->decision);
            $assessment->save();
        });

        return response()->json([
            'success' => true,
            'message' => 'Interview marked as completed',
            'data' => $interview->fresh('assessment.application'),
        ]);
    }

    public function destroy(Interview $interview, Request $request): JsonResponse
    {
        if ($interview->assessment->application->opportunity->organization_id !== $request->user()->organizationProfile->id) {
            return response()->json([
                'success' => false,
                'message' => 'Interview not found',
                'data' => null,
            ], 404);
        }

        if ($interview->status === 'completed') {
            return response()->json([
                'success' => false,
                'message' => 'Completed interviews cannot be deleted',
                'data' => null,
            ], 409);
        }

        DB::transaction(function () use ($interview) {
            $assessment = $interview->assessment;
            $application = $assessment->application;

            // Cascades to the Interview row via assessments.id -> interviews.assessment_id.
            $assessment->delete();

            // Never leave an application at `in_assessment` (or, for legacy
            // rows, `interview_scheduled`) once its only assessment is gone.
            // Any other status (accepted/rejected/withdrawn/...) was set
            // independently by the organization and must not be touched.
            if (in_array($application->status, ['in_assessment', 'interview_scheduled'], true)) {
                $application->status = 'shortlisted';
                $application->save();
            }
        });

        return response()->json([
            'success' => true,
            'message' => 'Interview deleted successfully',
            'data' => null,
        ]);
    }

    /**
     * interviews.decision -> assessments.result. No decision recorded yet
     * (`null`/`pending`) maps to `null` -- matching the convention
     * documented on the assessments migration/model.
     */
    private function assessmentResultFor(?string $decision): ?string
    {
        return match ($decision) {
            'passed', 'failed', 'waiting' => $decision,
            default => null,
        };
    }
}
