<?php

namespace App\Http\Controllers\Organization;

use App\Http\Controllers\Controller;
use App\Http\Requests\Organization\StoreOpportunitySkillRequest;
use App\Http\Requests\Organization\SyncOpportunitySkillsRequest;
use App\Models\Opportunity;
use App\Models\OpportunitySkill;
use App\Models\Skill;
use App\Services\OpportunitySkillSyncService;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OpportunitySkillController extends Controller
{
    public function __construct(private readonly OpportunitySkillSyncService $skillSync)
    {
    }

    /**
     * Phase O8.2: the same canonical Skill Catalog
     * `Student\StudentSkillController::catalog()` already exposes -- an
     * Organization-side mirror so Create/Edit Opportunity's Required
     * Skills multi-select offers the exact same Skill IDs a Student picks
     * their own skills from. No role-specific filtering: it is the one
     * shared catalog.
     */
    public function catalog(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'message' => 'Skill catalog retrieved successfully',
            'data' => Skill::orderBy('name')->get(['id', 'name', 'category']),
        ]);
    }

    public function index(Opportunity $opportunity, Request $request): JsonResponse
    {
        if ($opportunity->organization_id !== $request->user()->organizationProfile->id) {
            return response()->json([
                'success' => false,
                'message' => 'Opportunity not found',
                'data' => null,
            ], 404);
        }

        $skills = $opportunity->opportunitySkills()->with('skill')->get();

        return response()->json([
            'success' => true,
            'message' => 'Opportunity skills retrieved successfully',
            'data' => $skills,
        ]);
    }

    public function store(StoreOpportunitySkillRequest $request, Opportunity $opportunity): JsonResponse
    {
        if ($opportunity->organization_id !== $request->user()->organizationProfile->id) {
            return response()->json([
                'success' => false,
                'message' => 'Opportunity not found',
                'data' => null,
            ], 404);
        }

        $alreadyExists = $opportunity->opportunitySkills()
            ->where('skill_id', $request->validated('skill_id'))
            ->exists();

        if ($alreadyExists) {
            return response()->json([
                'success' => false,
                'message' => 'This skill has already been added to this opportunity',
                'data' => null,
            ], 409);
        }

        try {
            $opportunitySkill = $opportunity->opportunitySkills()->create($request->validated());
        } catch (QueryException $e) {
            return response()->json([
                'success' => false,
                'message' => 'This skill has already been added to this opportunity',
                'data' => null,
            ], 409);
        }

        return response()->json([
            'success' => true,
            'message' => 'Skill added to opportunity successfully',
            'data' => $opportunitySkill->load('skill'),
        ], 201);
    }

    /**
     * Replaces [$opportunity]'s entire Required/Preferred Skill set in one
     * atomic call (Phase O8.2) -- delete-then-reinsert, the exact same
     * "sync the set cleanly" convention `OpportunityController::syncEligibleMajors()`
     * already established, so Create/Edit Opportunity's multi-select can
     * save its whole selection with one request. Every entry references a
     * real, canonical `skills.id` (`SyncOpportunitySkillsRequest` rejects
     * anything else) -- there is no free-text Skill input anywhere in this
     * flow. A duplicate `skill_id` within the same request keeps only its
     * last occurrence, mirroring how `syncEligibleMajors()` also collapses
     * duplicates rather than erroring on them.
     */
    public function sync(SyncOpportunitySkillsRequest $request, Opportunity $opportunity): JsonResponse
    {
        if ($opportunity->organization_id !== $request->user()->organizationProfile->id) {
            return response()->json([
                'success' => false,
                'message' => 'Opportunity not found',
                'data' => null,
            ], 404);
        }

        $this->skillSync->sync($opportunity, $request->validated('skills', []));

        return response()->json([
            'success' => true,
            'message' => 'Opportunity skills updated successfully',
            'data' => $opportunity->opportunitySkills()->with('skill')->get(),
        ]);
    }

    public function destroy(Opportunity $opportunity, OpportunitySkill $opportunitySkill, Request $request): JsonResponse
    {
        if ($opportunity->organization_id !== $request->user()->organizationProfile->id) {
            return response()->json([
                'success' => false,
                'message' => 'Opportunity not found',
                'data' => null,
            ], 404);
        }

        if ($opportunitySkill->opportunity_id !== $opportunity->id) {
            return response()->json([
                'success' => false,
                'message' => 'Opportunity skill not found',
                'data' => null,
            ], 404);
        }

        $opportunitySkill->delete();

        return response()->json([
            'success' => true,
            'message' => 'Skill removed from opportunity successfully',
            'data' => null,
        ]);
    }
}
