<?php

namespace App\Http\Controllers\Student;

use App\Http\Controllers\Controller;
use App\Http\Requests\Student\StoreStudentSkillRequest;
use App\Models\CvSkillEvidence;
use App\Models\StudentSkill;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StudentSkillController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $studentSkills = $request->user()->studentProfile->studentSkills()->with('skill')->get();

        return response()->json([
            'success' => true,
            'message' => 'Skills retrieved successfully',
            'data' => $studentSkills,
        ]);
    }

    /**
     * Phase 8A-6.1: `source` defaults to `manual` -- every pre-existing
     * caller of this endpoint keeps working unchanged. A `source: cv_ai`
     * claim is never trusted on its own: the request must also carry the
     * `cv_id` the AI extraction ran against, and this action verifies a
     * real CvSkillEvidence row exists for exactly (that CV, this skill,
     * this student) before the claim is ever stored. A student cannot
     * spoof `cv_ai` for an arbitrary skill, and cannot use another
     * student's CV/evidence -- the evidence lookup is scoped to the
     * authenticated student's own `studentProfile->id` in every case.
     */
    public function store(StoreStudentSkillRequest $request): JsonResponse
    {
        $studentProfile = $request->user()->studentProfile;
        $skillId = $request->validated('skill_id');
        $source = $request->validated('source') ?? 'manual';

        $alreadyExists = $studentProfile->studentSkills()
            ->where('skill_id', $skillId)
            ->exists();

        if ($alreadyExists) {
            return response()->json([
                'success' => false,
                'message' => 'You have already added this skill',
                'data' => null,
            ], 409);
        }

        if ($source === 'cv_ai') {
            $hasEvidence = CvSkillEvidence::where('cv_id', $request->validated('cv_id'))
                ->where('skill_id', $skillId)
                ->where('student_id', $studentProfile->id)
                ->exists();

            if (! $hasEvidence) {
                return response()->json([
                    'success' => false,
                    'message' => 'This skill could not be verified as CV-supported for the given CV.',
                    'data' => null,
                ], 422);
            }
        }

        try {
            $studentSkill = $studentProfile->studentSkills()->create([
                'skill_id' => $skillId,
                'level' => $request->validated('level'),
                'years_of_experience' => $request->validated('years_of_experience'),
                'source' => $source,
            ]);
        } catch (QueryException $e) {
            return response()->json([
                'success' => false,
                'message' => 'You have already added this skill',
                'data' => null,
            ], 409);
        }

        return response()->json([
            'success' => true,
            'message' => 'Skill added successfully',
            'data' => $studentSkill->load('skill'),
        ], 201);
    }

    public function destroy(StudentSkill $studentSkill, Request $request): JsonResponse
    {
        if ($studentSkill->student_id !== $request->user()->studentProfile->id) {
            return response()->json([
                'success' => false,
                'message' => 'Skill not found',
                'data' => null,
            ], 404);
        }

        $studentSkill->delete();

        return response()->json([
            'success' => true,
            'message' => 'Skill removed successfully',
            'data' => null,
        ]);
    }
}
