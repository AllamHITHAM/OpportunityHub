<?php

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Http\Requests\Public\IndexPublicOpportunityRequest;
use App\Models\Opportunity;
use Illuminate\Http\JsonResponse;

class OpportunityController extends Controller
{
    public function index(IndexPublicOpportunityRequest $request): JsonResponse
    {
        $opportunities = Opportunity::query()
            ->where('status', 'open')
            ->whereHas('organizationProfile', function ($query) {
                $query->where('approval_status', 'approved');
            })
            ->with(['organizationProfile', 'opportunitySkills.skill'])
            ->when($request->filled('opportunity_type'), function ($query) use ($request) {
                $query->where('opportunity_type', $request->input('opportunity_type'));
            })
            ->when($request->filled('employment_type'), function ($query) use ($request) {
                $query->where('employment_type', $request->input('employment_type'));
            })
            ->when($request->filled('work_mode'), function ($query) use ($request) {
                $query->where('work_mode', $request->input('work_mode'));
            })
            ->when($request->filled('experience_level'), function ($query) use ($request) {
                $query->where('experience_level', $request->input('experience_level'));
            })
            ->when($request->filled('location'), function ($query) use ($request) {
                $query->where('location', 'like', '%'.$request->input('location').'%');
            })
            ->when($request->filled('field_of_study'), function ($query) use ($request) {
                $query->where('field_of_study', 'like', '%'.$request->input('field_of_study').'%');
            })
            ->when($request->filled('keyword'), function ($query) use ($request) {
                $keyword = $request->input('keyword');
                $query->where(function ($inner) use ($keyword) {
                    $inner->where('title', 'like', '%'.$keyword.'%')
                        ->orWhere('description', 'like', '%'.$keyword.'%');
                });
            })
            ->latest()
            ->paginate($request->integer('per_page', 15));

        return response()->json([
            'success' => true,
            'message' => 'Opportunities retrieved successfully',
            'data' => $opportunities,
        ]);
    }

    public function show(Opportunity $opportunity): JsonResponse
    {
        $opportunity->loadMissing(['organizationProfile', 'opportunitySkills.skill']);

        if ($opportunity->status !== 'open' || $opportunity->organizationProfile?->approval_status !== 'approved') {
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
}
