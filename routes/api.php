<?php

use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\Student\CVController;
use App\Http\Controllers\Student\StudentProfileController;
use App\Http\Controllers\Student\StudentSkillController;
use Illuminate\Support\Facades\Route;

Route::post('/register/student', [AuthController::class, 'registerStudent']);
Route::post('/register/organization', [AuthController::class, 'registerOrganization']);
Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:5,1');

Route::middleware('auth:sanctum')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/me', [AuthController::class, 'me']);
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
});
