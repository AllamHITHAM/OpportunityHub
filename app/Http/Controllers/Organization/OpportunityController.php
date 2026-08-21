<?php

namespace App\Http\Controllers\Organization;

use App\Http\Controllers\Controller;
use App\Http\Requests\Organization\StoreOpportunityRequest;
use App\Http\Requests\Organization\UpdateOpportunityRequest;
use App\Models\Opportunity;
use App\Support\MajorNormalizer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class OpportunityController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $opportunities = $request->user()->organizationProfile->opportunities()->with('eligibleMajorRecords')->get();

        return response()->json([
            'success' => true,
            'message' => 'Opportunities retrieved successfully',
            'data' => $opportunities,
        ]);
    }

    public function store(StoreOpportunityRequest $request): JsonResponse
    {
        $data = $request->validated();
        $eligibleMajors = $data['eligible_majors'] ?? null;
        unset($data['eligible_majors']);

        $opportunity = DB::transaction(function () use ($request, $data, $eligibleMajors) {
            $created = $request->user()->organizationProfile->opportunities()->create($data);

            if ($eligibleMajors !== null) {
                $this->syncEligibleMajors($created, $eligibleMajors);
            }

            return $created;
        });

        return response()->json([
            'success' => true,
            'message' => 'Opportunity created successfully',
            'data' => $opportunity->fresh('eligibleMajorRecords'),
        ], 201);
    }

    public function show(int $opportunity, Request $request): JsonResponse
    {
        $opportunity = Opportunity::with('eligibleMajorRecords')->find($opportunity);

        if (! $opportunity || $opportunity->organization_id !== $request->user()->organizationProfile->id) {
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

    public function update(UpdateOpportunityRequest $request, int $opportunity): JsonResponse
    {
        $opportunity = Opportunity::find($opportunity);

        if (! $opportunity || $opportunity->organization_id !== $request->user()->organizationProfile->id) {
            return response()->json([
                'success' => false,
                'message' => 'Opportunity not found',
                'data' => null,
            ], 404);
        }

        $data = $request->validated();
        // `array_key_exists`, not `isset`/`??` -- an explicitly-sent empty
        // array (`eligible_majors: []`) is a real "clear the list"
        // instruction and must be distinguished from the key being absent
        // entirely (which leaves the existing set untouched).
        $hasEligibleMajors = array_key_exists('eligible_majors', $data);
        $eligibleMajors = $data['eligible_majors'] ?? null;
        unset($data['eligible_majors']);

        DB::transaction(function () use ($opportunity, $data, $hasEligibleMajors, $eligibleMajors) {
            $opportunity->update($data);

            if ($hasEligibleMajors) {
                $this->syncEligibleMajors($opportunity, $eligibleMajors);
            }
        });

        return response()->json([
            'success' => true,
            'message' => 'Opportunity updated successfully',
            'data' => $opportunity->fresh('eligibleMajorRecords'),
        ]);
    }

    public function destroy(int $opportunity, Request $request): JsonResponse
    {
        $opportunity = Opportunity::find($opportunity);

        if (! $opportunity || $opportunity->organization_id !== $request->user()->organizationProfile->id) {
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

    /**
     * Replaces [$opportunity]'s entire `eligibleMajors` set with
     * [$majorNames] (Phase 8B-3.2) -- delete-then-reinsert is the
     * simplest correct "sync", and this table is never large enough
     * (max:10, enforced by the request) for that to be a real cost.
     * Deduplicates by normalized value (first occurrence's original
     * casing wins), so two entries differing only in
     * case/whitespace collapse to one row, matching the table's own
     * `unique(opportunity_id, normalized_major_name)` constraint.
     *
     * @param  list<string>  $majorNames
     */
    private function syncEligibleMajors(Opportunity $opportunity, array $majorNames): void
    {
        $opportunity->eligibleMajorRecords()->delete();

        $seen = [];
        foreach ($majorNames as $majorName) {
            $trimmed = trim($majorName);
            $normalized = MajorNormalizer::normalize($trimmed);
            if (isset($seen[$normalized])) {
                continue;
            }
            $seen[$normalized] = true;

            $opportunity->eligibleMajorRecords()->create([
                'major_name' => $trimmed,
                'normalized_major_name' => $normalized,
            ]);
        }
    }
}
