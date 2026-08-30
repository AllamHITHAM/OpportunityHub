<?php

use App\Http\Controllers\Admin\DashboardController as AdminDashboardController;
use App\Http\Controllers\Admin\EducationVerificationController as AdminEducationVerificationController;
use App\Http\Controllers\Admin\OrganizationController as AdminOrganizationController;
use App\Http\Controllers\Admin\SkillController as AdminSkillController;
use App\Http\Controllers\Admin\SkillSuggestionController as AdminSkillSuggestionController;
use App\Http\Controllers\Admin\UserController as AdminUserController;
use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\Auth\EmailVerificationController;
use App\Http\Controllers\ConversationController;
use App\Http\Controllers\LocationController;
use App\Http\Controllers\MediaController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\Organization\ApplicationAnalysisController;
use App\Http\Controllers\Organization\ApplicationController as OrganizationApplicationController;
use App\Http\Controllers\Organization\AssessmentController as OrganizationAssessmentController;
use App\Http\Controllers\Organization\CandidateController;
use App\Http\Controllers\Organization\ConversationStartController;
use App\Http\Controllers\Organization\DashboardController as OrganizationDashboardController;
use App\Http\Controllers\Organization\InterviewController as OrganizationInterviewController;
use App\Http\Controllers\Organization\InvitationController as OrganizationInvitationController;
use App\Http\Controllers\Organization\OfferController as OrganizationOfferController;
use App\Http\Controllers\Organization\OpportunityController;
use App\Http\Controllers\Organization\OpportunityQuizController;
use App\Http\Controllers\Organization\OpportunityRecommendationController;
use App\Http\Controllers\Organization\OpportunitySkillController;
use App\Http\Controllers\Organization\OrganizationPostController;
use App\Http\Controllers\Organization\OrganizationProfileController;
use App\Http\Controllers\Organization\QuizController as OrganizationQuizController;
use App\Http\Controllers\Public\OpportunityController as PublicOpportunityController;
use App\Http\Controllers\Public\OrganizationController as PublicOrganizationController;
use App\Http\Controllers\Student\ApplicationController as StudentApplicationController;
use App\Http\Controllers\Student\AssessmentController as StudentAssessmentController;
use App\Http\Controllers\Student\CVController;
use App\Http\Controllers\Student\DashboardController as StudentDashboardController;
use App\Http\Controllers\Student\EducationVerificationController as StudentEducationVerificationController;
use App\Http\Controllers\Student\InterviewController as StudentInterviewController;
use App\Http\Controllers\Student\InvitationController as StudentInvitationController;
use App\Http\Controllers\Student\OfferController as StudentOfferController;
use App\Http\Controllers\Student\QuizController as StudentQuizController;
use App\Http\Controllers\Student\StudentProfileController;
use App\Http\Controllers\Student\StudentSkillController;
use Illuminate\Support\Facades\Route;

Route::post('/register/student', [AuthController::class, 'registerStudent']);
Route::post('/register/organization', [AuthController::class, 'registerOrganization']);
Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:5,1');

Route::get('/opportunities', [PublicOpportunityController::class, 'index']);
Route::get('/opportunities/{opportunity}', [PublicOpportunityController::class, 'show']);

// Final Company Profile Manual-E2E Bug Fix: serves every managed public
// image (Company Logo, post image) through Laravel itself -- reachable
// unauthenticated, exactly like the routes above -- so the CORS header the
// `api` middleware group already applies to every other response here also
// applies to these. See `MediaController`'s own doc comment for exactly
// why the raw `public/storage` symlink URL this replaces doesn't work in a
// real browser under `php artisan serve`.
Route::get('/media/{path}', [MediaController::class, 'show'])->where('path', '.*')->name('media.show');

// Organization Public Profile phase: reachable unauthenticated, exactly
// like the two Opportunity routes above -- see
// `Public\OrganizationController`'s own doc comment.
Route::get('/organizations/{organizationProfile}', [PublicOrganizationController::class, 'show']);
Route::get('/organizations/{organizationProfile}/posts', [PublicOrganizationController::class, 'posts']);

// Phase 8B-2: password recovery -- both unauthenticated and role-agnostic
// (a Student, Organization, or Admin account can all use them), so they
// live alongside login/register above rather than inside any
// role-specific group. Throttled the same as login (5 attempts/minute)
// against brute-forcing either endpoint.
Route::post('/forgot-password', [AuthController::class, 'forgotPassword'])->middleware('throttle:5,1');
Route::post('/reset-password', [AuthController::class, 'resetPassword'])->middleware('throttle:5,1');

// Phase 8B-2: the signed link a user clicks from their inbox -- see
// EmailVerificationController::verify()'s own doc comment for why this is
// deliberately not behind `auth:sanctum`. Named `verification.verify' so
// `URL::temporarySignedRoute()` (used by the framework's own
// `VerifyEmail` notification, which `VerifyEmailNotification` extends)
// can resolve it.
Route::get('/email/verify/{id}/{hash}', [EmailVerificationController::class, 'verify'])
    ->middleware('throttle:6,1')
    ->name('verification.verify');

Route::middleware('auth:sanctum')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/me', [AuthController::class, 'me']);
});

Route::middleware(['auth:sanctum', 'active'])->group(function () {
    // Phase O8.2: the canonical Location Catalog -- role-agnostic, like
    // notifications below, since both a Student and an Organization pick
    // from this exact same list.
    Route::get('/locations', [LocationController::class, 'index']);
    // Recommendation Accuracy Patch: typed-search-or-add -- resolves a
    // typed name to one canonical Location, reusing an existing
    // canonical/alias match before ever creating a new row.
    Route::post('/locations', [LocationController::class, 'store']);

    Route::get('/notifications', [NotificationController::class, 'index']);
    Route::put('/notifications/read-all', [NotificationController::class, 'markAllAsRead']);
    Route::put('/notifications/{notification}/read', [NotificationController::class, 'markAsRead']);
    // Phase 9.1: the static `/read` path must be registered before the
    // `{notification}` wildcard below -- otherwise Laravel would match
    // `DELETE /notifications/read` as `{notification} = "read"` and never
    // reach `clearRead()`.
    Route::delete('/notifications/read', [NotificationController::class, 'clearRead']);
    Route::delete('/notifications/{notification}', [NotificationController::class, 'destroy']);

    // Phase 8B-2: resend requires knowing *who* wants a new email, so
    // (unlike forgot-password) this is authenticated -- no email input,
    // always the current user. Same throttle as the verification link
    // itself.
    Route::post('/email/verification-notification', [EmailVerificationController::class, 'resend'])
        ->middleware('throttle:6,1');

    // Messaging MVP: one shared Conversation resource for both an
    // Organization and a Student -- role-agnostic middleware, ownership
    // checked in-controller (see ConversationController's own doc
    // comment). Starting a *new* conversation is a separate,
    // Organization-only route below.
    Route::get('/conversations', [ConversationController::class, 'index']);
    Route::get('/conversations/{conversation}', [ConversationController::class, 'show']);
    Route::post('/conversations/{conversation}/messages', [ConversationController::class, 'storeMessage']);
});

Route::middleware(['auth:sanctum', 'active', 'role:student'])->group(function () {
    Route::get('/student/profile', [StudentProfileController::class, 'show']);
    Route::post('/student/profile', [StudentProfileController::class, 'store']);
    Route::put('/student/profile', [StudentProfileController::class, 'update']);

    // Student Profile Photo: mirrors the Company Logo upload/remove
    // routes exactly (Organization\OrganizationProfileController above).
    Route::post('/student/profile/photo', [StudentProfileController::class, 'uploadPhoto']);
    Route::delete('/student/profile/photo', [StudentProfileController::class, 'removePhoto']);
});

Route::middleware(['auth:sanctum', 'active', 'role:student', 'profile.exists'])->group(function () {
    Route::get('/student/cvs', [CVController::class, 'index']);
    Route::post('/student/cvs', [CVController::class, 'store']);
    Route::patch('/student/cvs/{cv}', [CVController::class, 'update']);
    Route::get('/student/cvs/{cv}/download', [CVController::class, 'download']);
    Route::delete('/student/cvs/{cv}', [CVController::class, 'destroy']);
    Route::put('/student/cvs/{cv}/default', [CVController::class, 'setDefault']);
    Route::post('/student/cvs/{cv}/extract-skills', [CVController::class, 'extractSkills']);

    Route::get('/student/skills', [StudentSkillController::class, 'index']);
    Route::get('/student/skills/catalog', [StudentSkillController::class, 'catalog']);
    Route::post('/student/skills', [StudentSkillController::class, 'store']);
    Route::delete('/student/skills/{studentSkill}', [StudentSkillController::class, 'destroy']);

    Route::get('/student/education-verification', [StudentEducationVerificationController::class, 'show']);
    Route::post('/student/education-verification', [StudentEducationVerificationController::class, 'store']);
    Route::get('/student/education-verification/document', [StudentEducationVerificationController::class, 'document']);

    Route::post('/opportunities/{opportunity}/apply', [StudentApplicationController::class, 'store']);
    Route::get('/student/applications', [StudentApplicationController::class, 'index']);

    Route::get('/student/interviews', [StudentInterviewController::class, 'index']);

    Route::get('/student/assessments', [StudentAssessmentController::class, 'index']);
    Route::get('/student/assessments/{assessment}', [StudentAssessmentController::class, 'show']);

    Route::get('/student/assessments/{assessment}/quiz', [StudentQuizController::class, 'show']);
    Route::post('/student/quizzes/{quiz}/start', [StudentQuizController::class, 'start']);
    Route::post('/student/quizzes/{quiz}/submit', [StudentQuizController::class, 'submit']);

    Route::get('/student/applications/{application}/offer', [StudentOfferController::class, 'show']);
    Route::put('/student/offers/{offer}/accept', [StudentOfferController::class, 'accept']);
    Route::put('/student/offers/{offer}/decline', [StudentOfferController::class, 'decline']);

    Route::get('/student/invitations', [StudentInvitationController::class, 'index']);
    Route::put('/student/invitations/{invitation}/accept', [StudentInvitationController::class, 'accept']);
    Route::put('/student/invitations/{invitation}/decline', [StudentInvitationController::class, 'decline']);

    Route::get('/student/dashboard', [StudentDashboardController::class, 'index']);
});

Route::middleware(['auth:sanctum', 'active', 'role:organization'])->group(function () {
    Route::get('/organization/profile', [OrganizationProfileController::class, 'show']);
    Route::put('/organization/profile', [OrganizationProfileController::class, 'update']);

    // Company Profile Polish phase: real multipart Company Logo upload,
    // separate from the plain-JSON profile update above (see
    // `UpdateOrganizationProfileRequest`'s own doc comment on why `logo`
    // is no longer accepted there).
    Route::post('/organization/profile/logo', [OrganizationProfileController::class, 'uploadLogo']);
    Route::delete('/organization/profile/logo', [OrganizationProfileController::class, 'removeLogo']);

    // Organization Public Profile phase: "Updates & Achievements" post
    // management -- create/update/delete only, reading is covered by the
    // public `GET /organizations/{organizationProfile}/posts` route
    // above (used identically by the owner viewing their own profile).
    // `org.approved` on `store` only, mirroring
    // `POST /organization/opportunities`'s own gating immediately below.
    Route::post('/organization/posts', [OrganizationPostController::class, 'store'])->middleware('org.approved');
    Route::put('/organization/posts/{organizationPost}', [OrganizationPostController::class, 'update']);
    Route::delete('/organization/posts/{organizationPost}', [OrganizationPostController::class, 'destroy']);

    Route::post('/organization/opportunities', [OpportunityController::class, 'store'])->middleware('org.approved');
    Route::get('/organization/opportunities', [OpportunityController::class, 'index']);
    // Closed Opportunities Scalability Polish: registered BEFORE the
    // `{opportunity}` wildcard below -- Laravel matches routes in
    // registration order, and "closed" would otherwise bind to
    // `{opportunity}` as a literal (nonexistent) ID and 404 instead of
    // reaching this real endpoint.
    Route::get('/organization/opportunities/closed', [OpportunityController::class, 'closed']);
    Route::get('/organization/opportunities/{opportunity}', [OpportunityController::class, 'show']);
    Route::put('/organization/opportunities/{opportunity}', [OpportunityController::class, 'update']);
    Route::delete('/organization/opportunities/{opportunity}', [OpportunityController::class, 'destroy']);
    // Company Profile Polish phase: the Company Profile's own "Closed
    // Opportunities" cleanup action -- see `destroyClosed()`'s own doc
    // comment on why this is deliberately separate from the route above.
    Route::delete('/organization/opportunities/{opportunity}/closed', [OpportunityController::class, 'destroyClosed']);

    // Phase O8.2: the same canonical Skill Catalog Students already read
    // from `GET /student/skills/catalog` -- an Organization-side mirror of
    // that same endpoint (Skill is role-agnostic data, but this app keeps
    // one catalog route per role rather than a single shared one for
    // skills, unlike locations above), so Create/Edit Opportunity can
    // offer the exact same canonical Skill IDs.
    Route::get('/organization/skills/catalog', [OpportunitySkillController::class, 'catalog']);

    Route::get('/organization/opportunities/{opportunity}/skills', [OpportunitySkillController::class, 'index']);
    Route::post('/organization/opportunities/{opportunity}/skills', [OpportunitySkillController::class, 'store']);
    // Phase O8.2: replaces the Opportunity's entire Required/Preferred
    // Skill set in one atomic call -- the same "sync the set cleanly"
    // convention `eligible_majors` already established -- so Create/Edit
    // Opportunity's multi-select can save its selection with one request
    // instead of N individual add/remove calls. The single-skill
    // index/store/destroy endpoints above are unchanged and still work
    // independently (e.g. for a future one-off add/remove UI).
    Route::put('/organization/opportunities/{opportunity}/skills', [OpportunitySkillController::class, 'sync']);
    Route::delete('/organization/opportunities/{opportunity}/skills/{opportunitySkill}', [OpportunitySkillController::class, 'destroy']);

    Route::get('/organization/applications', [OrganizationApplicationController::class, 'index']);
    Route::get('/organization/opportunities/{opportunity}/applications', [OrganizationApplicationController::class, 'indexForOpportunity']);
    Route::get('/organization/applications/{application}', [OrganizationApplicationController::class, 'show']);
    Route::put('/organization/applications/{application}/status', [OrganizationApplicationController::class, 'updateStatus']);
    Route::get('/organization/applications/{application}/cv', [OrganizationApplicationController::class, 'downloadCv']);

    Route::post('/organization/applications/{application}/interview', [OrganizationInterviewController::class, 'store']);
    Route::get('/organization/interviews', [OrganizationInterviewController::class, 'index']);
    Route::get('/organization/interviews/{interview}', [OrganizationInterviewController::class, 'show']);
    Route::put('/organization/interviews/{interview}', [OrganizationInterviewController::class, 'update']);
    Route::put('/organization/interviews/{interview}/complete', [OrganizationInterviewController::class, 'complete']);
    Route::delete('/organization/interviews/{interview}', [OrganizationInterviewController::class, 'destroy']);

    Route::post('/organization/applications/{application}/assessments', [OrganizationAssessmentController::class, 'store']);
    Route::post('/organization/applications/{application}/quiz-assessment', [OrganizationAssessmentController::class, 'storeSharedQuizAssessment']);
    Route::get('/organization/applications/{application}/assessment', [OrganizationAssessmentController::class, 'showForApplication']);
    Route::get('/organization/assessments/{assessment}', [OrganizationAssessmentController::class, 'show']);

    // Phase 10A.4B — the Opportunity's shared Quiz template (as opposed to
    // the legacy ad-hoc per-candidate quiz routes below, keyed by
    // Assessment/Quiz id).
    Route::get('/organization/opportunities/{opportunity}/quiz', [OpportunityQuizController::class, 'show']);
    Route::post('/organization/opportunities/{opportunity}/quiz', [OpportunityQuizController::class, 'store']);
    Route::put('/organization/opportunities/{opportunity}/quiz', [OpportunityQuizController::class, 'update']);
    Route::put('/organization/opportunities/{opportunity}/quiz/publish', [OpportunityQuizController::class, 'publish']);
    Route::get('/organization/opportunities/{opportunity}/quiz/results', [OpportunityQuizController::class, 'results']);

    Route::get('/organization/assessments/{assessment}/quiz', [OrganizationQuizController::class, 'show']);
    Route::post('/organization/quizzes/{quiz}/questions', [OrganizationQuizController::class, 'storeQuestion']);
    Route::put('/organization/quizzes/{quiz}/questions/{question}', [OrganizationQuizController::class, 'updateQuestion']);
    Route::delete('/organization/quizzes/{quiz}/questions/{question}', [OrganizationQuizController::class, 'destroyQuestion']);
    Route::put('/organization/quizzes/{quiz}/publish', [OrganizationQuizController::class, 'publish']);
    Route::put('/organization/assessments/{assessment}/release-result', [OrganizationQuizController::class, 'releaseResult']);
    Route::post('/organization/assessments/{assessment}/next-action/interview', [OrganizationQuizController::class, 'setNextActionInterview']);
    Route::post('/organization/assessments/{assessment}/next-action/offer', [OrganizationQuizController::class, 'setNextActionOffer']);
    Route::post('/organization/assessments/{assessment}/next-action/reject', [OrganizationQuizController::class, 'setNextActionReject']);

    Route::post('/organization/applications/{application}/analyze', [ApplicationAnalysisController::class, 'analyze']);
    Route::get('/organization/applications/{application}/analysis', [ApplicationAnalysisController::class, 'show']);

    Route::post('/organization/applications/{application}/offer', [OrganizationOfferController::class, 'store']);
    Route::get('/organization/applications/{application}/offer', [OrganizationOfferController::class, 'show']);

    Route::get('/organization/candidates', [CandidateController::class, 'index']);
    Route::post('/organization/invitations', [OrganizationInvitationController::class, 'store']);

    // Messaging MVP: the one place a new Conversation can ever be created
    // -- Organization-only, a Student never initiates (see
    // ConversationStartController's own doc comment).
    Route::post('/organization/candidates/{student}/conversation', [ConversationStartController::class, 'start']);

    // Phase O8.1 — real candidates ranked for one of the Organization's own
    // Opportunities (never a second "choose an Opportunity" step, since
    // the Opportunity is already known from the URL).
    Route::get('/organization/opportunities/{opportunity}/recommended-candidates', [OpportunityRecommendationController::class, 'index']);

    Route::get('/organization/dashboard', [OrganizationDashboardController::class, 'index']);
});

Route::middleware(['auth:sanctum', 'active', 'role:admin'])->group(function () {
    Route::get('/admin/organizations', [AdminOrganizationController::class, 'index']);
    Route::get('/admin/organizations/{organizationProfile}', [AdminOrganizationController::class, 'show']);
    Route::put('/admin/organizations/{organizationProfile}/approval', [AdminOrganizationController::class, 'updateApproval']);

    Route::get('/admin/users', [AdminUserController::class, 'index']);
    Route::put('/admin/users/{user}/status', [AdminUserController::class, 'updateStatus']);

    Route::get('/admin/skills', [AdminSkillController::class, 'index']);
    Route::post('/admin/skills', [AdminSkillController::class, 'store']);
    Route::put('/admin/skills/{skill}', [AdminSkillController::class, 'update']);
    Route::delete('/admin/skills/{skill}', [AdminSkillController::class, 'destroy']);

    Route::get('/admin/skill-suggestions', [AdminSkillSuggestionController::class, 'index']);
    Route::put('/admin/skill-suggestions/{suggestion}/approve', [AdminSkillSuggestionController::class, 'approve']);
    Route::put('/admin/skill-suggestions/{suggestion}/reject', [AdminSkillSuggestionController::class, 'reject']);

    Route::get('/admin/education-verifications', [AdminEducationVerificationController::class, 'index']);
    Route::get('/admin/education-verifications/{verification}', [AdminEducationVerificationController::class, 'show']);
    Route::get('/admin/education-verifications/{verification}/document', [AdminEducationVerificationController::class, 'document']);
    Route::put('/admin/education-verifications/{verification}/verify', [AdminEducationVerificationController::class, 'verify']);
    Route::put('/admin/education-verifications/{verification}/reject', [AdminEducationVerificationController::class, 'reject']);

    Route::get('/admin/dashboard', [AdminDashboardController::class, 'index']);
});
