<?php

namespace App\Http\Controllers\Student;

use App\Http\Controllers\Controller;
use App\Http\Requests\Student\StoreCVRequest;
use App\Models\CV;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CVController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $cvs = $request->user()->studentProfile->cvs;

        return response()->json([
            'success' => true,
            'message' => 'CVs retrieved successfully',
            'data' => $cvs,
        ]);
    }

    public function store(StoreCVRequest $request): JsonResponse
    {
        $cv = $request->user()->studentProfile->cvs()->create($request->validated());

        return response()->json([
            'success' => true,
            'message' => 'CV created successfully',
            'data' => $cv,
        ], 201);
    }

    public function destroy(CV $cv, Request $request): JsonResponse
    {
        if ($cv->student_id !== $request->user()->studentProfile->id) {
            return response()->json([
                'success' => false,
                'message' => 'CV not found',
                'data' => null,
            ], 404);
        }

        if ($cv->applications()->exists()) {
            return response()->json([
                'success' => false,
                'message' => 'Cannot delete a CV that has been used in an application',
                'data' => null,
            ], 409);
        }

        $cv->delete();

        return response()->json([
            'success' => true,
            'message' => 'CV deleted successfully',
            'data' => null,
        ]);
    }

    public function setDefault(CV $cv, Request $request): JsonResponse
    {
        if ($cv->student_id !== $request->user()->studentProfile->id) {
            return response()->json([
                'success' => false,
                'message' => 'CV not found',
                'data' => null,
            ], 404);
        }

        DB::transaction(function () use ($cv) {
            $cv->studentProfile->cvs()->where('id', '!=', $cv->id)->update(['is_default' => false]);
            $cv->update(['is_default' => true]);
        });

        return response()->json([
            'success' => true,
            'message' => 'Default CV updated successfully',
            'data' => $cv->fresh(),
        ]);
    }
}
