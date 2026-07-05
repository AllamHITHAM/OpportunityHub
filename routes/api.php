<?php

use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\Organization\ApplicationAnalysisController;
use App\Http\Controllers\Organization\ApplicationController as OrganizationApplicationController;
use App\Http\Controllers\Organization\InterviewController as OrganizationInterviewController;
use App\Http\Controllers\Organization\OpportunityController;
use App\Http\Controllers\Organization\OpportunitySkillController;
use App\Http\Controllers\Organization\OrganizationProfileController;
use App\Http\Controllers\Public\OpportunityController as PublicOpportunityController;
use App\Http\Controllers\Student\ApplicationController as StudentApplicationController;
use App\Http\Controllers\Student\CVController;
use App\Http\Controllers\Student\InterviewController as StudentInterviewController;
use App\Http\Controllers\Student\StudentProfileController;
use App\Http\Controllers\Student\StudentSkillController;
use Illuminate\Support\Facades\Route;

Route::post('/register/student', [AuthController::class, 'registerStudent']);
Route::post('/register/organization', [AuthController::class, 'registerOrganization']);
Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:5,1');

Route::get('/opportunities', [PublicOpportunityController::class, 'index']);
Route::get('/opportunities/{opportunity}', [PublicOpportunityController::class, 'show']);

Route::middleware('auth:sanctum')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/me', [AuthController::class, 'me']);
});

Route::middleware(['auth:sanctum', 'active'])->group(function () {
    Route::get('/notifications', [NotificationController::class, 'index']);
    Route::put('/notifications/read-all', [NotificationController::class, 'markAllAsRead']);
    Route::put('/notifications/{notification}/read', [NotificationController::class, 'markAsRead']);
});

Route::middleware(['auth:sanctum', 'active', 'role:student'])->group(function () {
    Route::get('/student/profile', [StudentProfileController::class, 'show']);
    Route::post('/student/profile', [StudentProfileController::class, 'store']);
    Route::put('/student/profile', [StudentProfileController::class, 'update']);
});

Route::middleware(['auth:sanctum', 'active', 'role:student', 'profile.exists'])->group(function () {
    Route::get('/student/cvs', [CVController::class, 'index']);
    Route::post('/student/cvs', [CVController::class, 'store']);
    Route::delete('/student/cvs/{cv}', [CVController::class, 'destroy']);
    Route::put('/student/cvs/{cv}/default', [CVController::class, 'setDefault']);

    Route::get('/student/skills', [StudentSkillController::class, 'index']);
    Route::post('/student/skills', [StudentSkillController::class, 'store']);
    Route::delete('/student/skills/{studentSkill}', [StudentSkillController::class, 'destroy']);

    Route::post('/opportunities/{opportunity}/apply', [StudentApplicationController::class, 'store']);
    Route::get('/student/applications', [StudentApplicationController::class, 'index']);

    Route::get('/student/interviews', [StudentInterviewController::class, 'index']);
});

Route::middleware(['auth:sanctum', 'active', 'role:organization'])->group(function () {
    Route::get('/organization/profile', [OrganizationProfileController::class, 'show']);
    Route::put('/organization/profile', [OrganizationProfileController::class, 'update']);

    Route::post('/organization/opportunities', [OpportunityController::class, 'store'])->middleware('org.approved');
    Route::get('/organization/opportunities', [OpportunityController::class, 'index']);
    Route::get('/organization/opportunities/{opportunity}', [OpportunityController::class, 'show']);
    Route::put('/organization/opportunities/{opportunity}', [OpportunityController::class, 'update']);
    Route::delete('/organization/opportunities/{opportunity}', [OpportunityController::class, 'destroy']);

    Route::get('/organization/opportunities/{opportunity}/skills', [OpportunitySkillController::class, 'index']);
    Route::post('/organization/opportunities/{opportunity}/skills', [OpportunitySkillController::class, 'store']);
    Route::delete('/organization/opportunities/{opportunity}/skills/{opportunitySkill}', [OpportunitySkillController::class, 'destroy']);

    Route::get('/organization/applications', [OrganizationApplicationController::class, 'index']);
    Route::get('/organization/opportunities/{opportunity}/applications', [OrganizationApplicationController::class, 'indexForOpportunity']);
    Route::get('/organization/applications/{application}', [OrganizationApplicationController::class, 'show']);
    Route::put('/organization/applications/{application}/status', [OrganizationApplicationController::class, 'updateStatus']);

    Route::post('/organization/applications/{application}/interview', [OrganizationInterviewController::class, 'store']);
    Route::get('/organization/interviews', [OrganizationInterviewController::class, 'index']);
    Route::get('/organization/interviews/{interview}', [OrganizationInterviewController::class, 'show']);
    Route::put('/organization/interviews/{interview}', [OrganizationInterviewController::class, 'update']);
    Route::put('/organization/interviews/{interview}/complete', [OrganizationInterviewController::class, 'complete']);
    Route::delete('/organization/interviews/{interview}', [OrganizationInterviewController::class, 'destroy']);

    Route::post('/organization/applications/{application}/analyze', [ApplicationAnalysisController::class, 'analyze']);
    Route::get('/organization/applications/{application}/analysis', [ApplicationAnalysisController::class, 'show']);
});
