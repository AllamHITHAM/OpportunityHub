<?php

namespace App\Http\Controllers\Organization;

use App\Http\Controllers\Controller;
use App\Models\Application;
use App\Models\Invitation;
use App\Models\Opportunity;
use App\Models\StudentProfile;
use App\Services\OpportunityEligibilityService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Candidate Search (Phase 8B-3, Flow B) -- lets an Organization discover
 * Student profiles to invite, rather than only ever seeing Students who
 * already applied. Deliberately filter/search-only over structured data
 * already on `student_profiles`/`student_skills`: no semantic search, no
 * AI ranking, and no `MatchingService` involvement (see
 * docs/ARCHITECTURE.md) -- a match score is only ever meaningful once a
 * real `Application` exists for a specific Opportunity.
 *
 * Every returned candidate is built as an explicit, deliberately narrow
 * array -- never the raw `StudentProfile`/`User` models -- so this
 * endpoint can never accidentally leak a field (email, phone, bio, raw CV
 * text, education document path) just because a future change adds one to
 * either model. See docs/BUSINESS_RULES.md for the exact safe-field list.
 *
 * **Phase 8B-3.2**: when scoped to a specific `opportunity_id`, results
 * are additionally filtered to Students eligible for that Opportunity's
 * accepted major(s) (`OpportunityEligibilityService`) -- the general,
 * unscoped search (no `opportunity_id`) is never filtered by major, since
 * there is no single Opportunity to be eligible for yet.
 */
class CandidateController extends Controller
{
    public function __construct(private readonly OpportunityEligibilityService $eligibility)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $organizationId = $request->user()->organizationProfile->id;

        $opportunity = null;
        if ($request->filled('opportunity_id')) {
            $opportunity = Opportunity::with('eligibleMajorRecords')
                ->where('id', $request->query('opportunity_id'))
                ->where('organization_id', $organizationId)
                ->first();

            if ($opportunity === null) {
                return response()->json([
                    'success' => false,
                    'message' => 'Opportunity not found',
                    'data' => null,
                ], 404);
            }
        }

        $query = StudentProfile::query()
            ->whereHas('user', function ($q) {
                $q->where('status', 'active');
            })
            ->with(['studentSkills.skill', 'educationVerification', 'user:id,name']);

        if ($request->filled('name')) {
            $name = $request->query('name');
            $query->whereHas('user', function ($q) use ($name) {
                $q->where('name', 'like', "%{$name}%");
            });
        }

        if ($request->filled('major')) {
            $query->where('major', 'like', '%'.$request->query('major').'%');
        }

        if ($request->filled('university')) {
            $query->where('university', 'like', '%'.$request->query('university').'%');
        }

        if ($request->filled('graduation_year')) {
            $query->where('graduation_year', (int) $request->query('graduation_year'));
        }

        if ($request->filled('skill')) {
            $skill = $request->query('skill');
            $query->whereHas('studentSkills.skill', function ($q) use ($skill) {
                $q->where('name', 'like', "%{$skill}%");
            });
        }

        $candidates = $query->orderBy('id')->get();

        if ($opportunity !== null) {
            $candidates = $candidates->filter(
                fn (StudentProfile $profile) => $this->eligibility->isStudentEligible($opportunity, $profile),
            )->values();
        }

        $appliedStudentIds = [];
        $invitedStudentIds = [];
        if ($opportunity !== null) {
            $appliedStudentIds = Application::where('opportunity_id', $opportunity->id)
                ->pluck('student_id')
                ->all();
            $invitedStudentIds = Invitation::where('opportunity_id', $opportunity->id)
                ->pluck('student_id')
                ->all();
        }

        $data = $candidates->map(function (StudentProfile $profile) use (
            $opportunity,
            $appliedStudentIds,
            $invitedStudentIds,
        ) {
            $candidate = [
                'id' => $profile->id,
                'name' => $profile->user->name,
                'university' => $profile->university,
                'major' => $profile->major,
                'graduation_year' => $profile->graduation_year,
                'education_verification_status' => $profile->education_verification_status,
                'skills' => $profile->studentSkills->map(fn ($studentSkill) => [
                    'name' => $studentSkill->skill->name,
                    'source' => $studentSkill->source,
                ])->values(),
            ];

            if ($opportunity !== null) {
                $candidate['already_applied'] = in_array($profile->id, $appliedStudentIds, true);
                $candidate['already_invited'] = in_array($profile->id, $invitedStudentIds, true);
            }

            return $candidate;
        });

        return response()->json([
            'success' => true,
            'message' => 'Candidates retrieved successfully',
            'data' => $data,
        ]);
    }
}
