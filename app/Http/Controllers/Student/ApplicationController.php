<?php

namespace App\Http\Controllers\Student;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Student\Concerns\HidesInternalApplicationFields;
use App\Http\Requests\Student\ApplyToOpportunityRequest;
use App\Models\Opportunity;
use App\Services\MatchingService;
use App\Services\NotificationService;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class ApplicationController extends Controller
{
    use HidesInternalApplicationFields;

    public function __construct(
        private readonly NotificationService $notifications,
        private readonly MatchingService $matching,
    ) {
    }

    public function store(ApplyToOpportunityRequest $request, Opportunity $opportunity): JsonResponse
    {
        $studentProfile = $request->user()->studentProfile;

        if ($opportunity->status !== 'open' || $opportunity->organizationProfile?->approval_status !== 'approved') {
            return response()->json([
                'success' => false,
                'message' => 'Opportunity not found',
                'data' => null,
            ], 404);
        }

        if (
            $opportunity->application_deadline &&
            now()->startOfDay()->gt(Carbon::parse($opportunity->application_deadline)->startOfDay())
        ) {
            return response()->json([
                'success' => false,
                'message' => 'Application deadline has passed',
                'data' => null,
            ], 422);
        }

        if ($studentProfile->cvs()->doesntExist()) {
            return response()->json([
                'success' => false,
                'message' => 'You must upload at least one CV before applying',
                'data' => null,
            ], 422);
        }

        $alreadyApplied = $studentProfile->applications()
            ->where('opportunity_id', $opportunity->id)
            ->exists();

        if ($alreadyApplied) {
            return response()->json([
                'success' => false,
                'message' => 'You have already applied to this opportunity',
                'data' => null,
            ], 409);
        }

        try {
            // Phase 7A-2: notifies the organization in the same transaction
            // as the Application insert -- a rolled-back duplicate-apply
            // race (see the QueryException catch below) must never leave a
            // stray "New Application" notification behind for an
            // application that doesn't actually exist.
            $application = DB::transaction(function () use ($request, $opportunity, $studentProfile) {
                $created = $studentProfile->applications()->create([
                    ...$request->validated(),
                    'opportunity_id' => $opportunity->id,
                ]);

                // Phase 8A-2: synchronous, deterministic, no external I/O --
                // safe to run inline before the transaction commits. Never
                // exposed to the student (see HidesInternalApplicationFields
                // below).
                $result = $this->matching->analyze($created);
                $created->match_score = $result['overall_match_score'];
                $created->save();

                $this->notifications->notifyApplicationSubmitted(
                    $opportunity->organizationProfile->user,
                    $opportunity->title,
                    $created->id,
                );

                return $created;
            });
        } catch (QueryException $e) {
            return response()->json([
                'success' => false,
                'message' => 'You have already applied to this opportunity',
                'data' => null,
            ], 409);
        }

        $application->load(['opportunity', 'cv']);
        $this->hideInternalApplicationFields($application);

        return response()->json([
            'success' => true,
            'message' => 'Application submitted successfully',
            'data' => $application,
        ], 201);
    }

    public function index(Request $request): JsonResponse
    {
        $applications = $request->user()->studentProfile->applications()->with(['opportunity', 'cv'])->get();
        $applications->each(fn ($application) => $this->hideInternalApplicationFields($application));

        return response()->json([
            'success' => true,
            'message' => 'Applications retrieved successfully',
            'data' => $applications,
        ]);
    }
}
