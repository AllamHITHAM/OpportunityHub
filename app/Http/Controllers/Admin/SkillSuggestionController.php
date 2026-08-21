<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Skill;
use App\Models\SkillSuggestion;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;

/**
 * Phase 8A-6.1: Admin review of AI-derived pending skill suggestions.
 * Approving one grows the Admin-owned Skill catalog; rejecting one never
 * creates anything. Kept minimal on purpose -- no delete endpoint, no
 * bulk actions, no listing of already-reviewed suggestions.
 */
class SkillSuggestionController extends Controller
{
    /**
     * Only ever pending suggestions -- once approved/rejected there's
     * nothing further for an Admin to act on here.
     */
    public function index(): JsonResponse
    {
        $suggestions = SkillSuggestion::where('status', 'pending')
            ->orderBy('created_at')
            ->get();

        return response()->json([
            'success' => true,
            'message' => 'Skill suggestions retrieved successfully',
            'data' => $suggestions,
        ]);
    }

    /**
     * Creates a real Skill from the suggestion's canonical name, or --
     * safely handling the race where an equivalent Skill appeared after
     * the suggestion was created (the baseline seeder, another Admin, or
     * a second approval request) -- reuses that existing Skill instead of
     * creating a duplicate. Never lets the AI approve its own suggestion;
     * this is the only path that can move a suggestion to `approved`.
     */
    public function approve(SkillSuggestion $suggestion): JsonResponse
    {
        if ($suggestion->status !== 'pending') {
            return response()->json([
                'success' => false,
                'message' => 'This suggestion has already been reviewed.',
                'data' => $suggestion,
            ], 409);
        }

        $skill = Skill::findByNormalizedName($suggestion->name);

        if ($skill === null) {
            try {
                $skill = Skill::create(['name' => $suggestion->name]);
            } catch (QueryException) {
                // Another request created an equivalent Skill in the gap
                // between the lookup above and this insert -- reuse it
                // rather than failing the approval or creating a
                // duplicate the `skills.name` unique constraint would
                // otherwise have blocked anyway.
                $skill = Skill::findByNormalizedName($suggestion->name);
            }
        }

        $suggestion->update([
            'status' => 'approved',
            'approved_skill_id' => $skill?->id,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Skill suggestion approved successfully',
            'data' => $suggestion->fresh('approvedSkill'),
        ]);
    }

    public function reject(SkillSuggestion $suggestion): JsonResponse
    {
        if ($suggestion->status !== 'pending') {
            return response()->json([
                'success' => false,
                'message' => 'This suggestion has already been reviewed.',
                'data' => $suggestion,
            ], 409);
        }

        $suggestion->update(['status' => 'rejected']);

        return response()->json([
            'success' => true,
            'message' => 'Skill suggestion rejected successfully',
            'data' => $suggestion,
        ]);
    }
}
