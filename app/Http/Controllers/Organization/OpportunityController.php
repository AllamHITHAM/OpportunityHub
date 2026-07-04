<?php

namespace App\Http\Controllers\Organization;

use App\Http\Controllers\Controller;
use App\Http\Requests\Organization\StoreOpportunityRequest;
use App\Http\Requests\Organization\UpdateOpportunityRequest;
use App\Models\Opportunity;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OpportunityController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $opportunities = $request->user()->organizationProfile->opportunities;

        return response()->json([
            'success' => true,
            'message' => 'Opportunities retrieved successfully',
            'data' => $opportunities,
        ]);
    }

    public function store(StoreOpportunityRequest $request): JsonResponse
    {
        $opportunity = $request->user()->organizationProfile->opportunities()->create($request->validated());

        return response()->json([
            'success' => true,
            'message' => 'Opportunity created successfully',
            'data' => $opportunity,
        ], 201);
    }

    public function show(Opportunity $opportunity, Request $request): JsonResponse
    {
        if ($opportunity->organization_id !== $request->user()->organizationProfile->id) {
            return response()->json([
                'success' => false,
                'message' => 'Opportunity not found',
                'data' => null,
            ], 404);
        }

        return response()->json([
            'success' => true,
            'message' => 'Opportunity retrieved successfully',
            'data' => $opportunity,
        ]);
    }

    public function update(UpdateOpportunityRequest $request, Opportunity $opportunity): JsonResponse
    {
        if ($opportunity->organization_id !== $request->user()->organizationProfile->id) {
            return response()->json([
                'success' => false,
                'message' => 'Opportunity not found',
                'data' => null,
            ], 404);
        }

        $opportunity->update($request->validated());

        return response()->json([
            'success' => true,
            'message' => 'Opportunity updated successfully',
            'data' => $opportunity,
        ]);
    }

    public function destroy(Opportunity $opportunity, Request $request): JsonResponse
    {
        if ($opportunity->organization_id !== $request->user()->organizationProfile->id) {
            return response()->json([
                'success' => false,
                'message' => 'Opportunity not found',
                'data' => null,
            ], 404);
        }

        if ($opportunity->applications()->exists()) {
            return response()->json([
                'success' => false,
                'message' => 'Cannot delete an opportunity that has applications',
                'data' => null,
            ], 409);
        }

        $opportunity->delete();

        return response()->json([
            'success' => true,
            'message' => 'Opportunity deleted successfully',
            'data' => null,
        ]);
    }
}
