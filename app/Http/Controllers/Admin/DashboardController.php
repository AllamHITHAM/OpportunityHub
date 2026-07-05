<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Application;
use App\Models\Interview;
use App\Models\Opportunity;
use App\Models\OrganizationProfile;
use App\Models\User;
use Illuminate\Http\JsonResponse;

class DashboardController extends Controller
{
    public function index(): JsonResponse
    {
        $data = [
            'total_users' => User::count(),
            'total_students' => User::where('role', 'student')->count(),
            'total_organizations' => User::where('role', 'organization')->count(),
            'pending_organizations' => OrganizationProfile::where('approval_status', 'pending')->count(),
            'approved_organizations' => OrganizationProfile::where('approval_status', 'approved')->count(),
            'rejected_organizations' => OrganizationProfile::where('approval_status', 'rejected')->count(),
            'total_opportunities' => Opportunity::count(),
            'open_opportunities' => Opportunity::where('status', 'open')->count(),
            'closed_opportunities' => Opportunity::where('status', 'closed')->count(),
            'total_applications' => Application::count(),
            'total_interviews' => Interview::count(),
        ];

        return response()->json([
            'success' => true,
            'message' => 'Dashboard statistics retrieved successfully',
            'data' => $data,
        ]);
    }
}
