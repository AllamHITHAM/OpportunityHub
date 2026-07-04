<?php

namespace App\Http\Controllers\Organization;

use App\Http\Controllers\Controller;
use App\Http\Requests\Organization\StoreOpportunitySkillRequest;
use App\Models\Opportunity;
use App\Models\OpportunitySkill;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OpportunitySkillController extends Controller
{
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
