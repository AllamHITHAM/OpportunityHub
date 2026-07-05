<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreSkillRequest;
use App\Http\Requests\Admin\UpdateSkillRequest;
use App\Models\Skill;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;

class SkillController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'message' => 'Skills retrieved successfully',
            'data' => Skill::all(),
        ]);
    }

    public function store(StoreSkillRequest $request): JsonResponse
    {
        try {
            $skill = Skill::create($request->validated());
        } catch (QueryException $e) {
            return response()->json([
                'success' => false,
                'message' => 'A skill with this name already exists',
                'data' => null,
            ], 409);
        }

        return response()->json([
            'success' => true,
            'message' => 'Skill created successfully',
            'data' => $skill,
        ], 201);
    }

    public function update(UpdateSkillRequest $request, Skill $skill): JsonResponse
    {
        try {
            $skill->update($request->validated());
        } catch (QueryException $e) {
            return response()->json([
                'success' => false,
                'message' => 'A skill with this name already exists',
                'data' => null,
            ], 409);
        }

        return response()->json([
            'success' => true,
            'message' => 'Skill updated successfully',
            'data' => $skill->fresh(),
        ]);
    }

    public function destroy(Skill $skill): JsonResponse
    {
        if ($skill->studentSkills()->exists() || $skill->opportunitySkills()->exists()) {
            return response()->json([
                'success' => false,
                'message' => 'Cannot delete a skill that is currently in use',
                'data' => null,
            ], 409);
        }

        $skill->delete();

        return response()->json([
            'success' => true,
            'message' => 'Skill deleted successfully',
            'data' => null,
        ]);
    }
}
