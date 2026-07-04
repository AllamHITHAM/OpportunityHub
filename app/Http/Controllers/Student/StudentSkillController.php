<?php

namespace App\Http\Controllers\Student;

use App\Http\Controllers\Controller;
use App\Http\Requests\Student\StoreStudentSkillRequest;
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

    public function store(StoreStudentSkillRequest $request): JsonResponse
    {
        $studentProfile = $request->user()->studentProfile;

        $alreadyExists = $studentProfile->studentSkills()
            ->where('skill_id', $request->validated('skill_id'))
            ->exists();

        if ($alreadyExists) {
            return response()->json([
                'success' => false,
                'message' => 'You have already added this skill',
                'data' => null,
            ], 409);
        }

        try {
            $studentSkill = $studentProfile->studentSkills()->create($request->validated());
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
