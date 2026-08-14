<?php

namespace Tests\Feature\Applications;

use App\Models\Application;
use App\Models\Opportunity;
use App\Models\OrganizationProfile;
use App\Models\StudentProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Phase 8A-1: proves `match_score` -- an organization-internal matching
 * aid (see `App\Services\MatchingService`) -- never reaches a student
 * through any student-facing response, direct or nested, regardless of
 * whether a real score has been calculated yet. Mirrors the exact
 * "per-instance `makeHidden()`, not model-level `$hidden`" convention
 * `HidesInternalInterviewFields`/`HidesInternalQuestionFields` already
 * established for the identical class of problem -- see
 * `App\Http\Controllers\Student\Concerns\HidesInternalApplicationFields`.
 *
 * There is no `GET /api/student/applications/{application}` details
 * endpoint in this project (`GET /api/student/applications` is list-only)
 * -- section C of this phase's own test spec is therefore covered by
 * confirming that endpoint doesn't exist, not by testing it.
 */
class ApplicationPrivacyTest extends TestCase
{
    use RefreshDatabase;

    // -----------------------------------------------------------------
    // A. GET /api/student/applications
    // -----------------------------------------------------------------

    public function test_student_applications_list_succeeds_and_returns_only_their_own(): void
    {
        $student = $this->studentWithProfileAndCv();
        $opportunity = $this->openOpportunity();
        $this->applicationFor($opportunity, $student);

        Sanctum::actingAs($student->user);

        $response = $this->getJson('/api/student/applications');

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.student_id', $student->profile->id);
    }

    public function test_student_applications_list_never_includes_match_score(): void
    {
        $student = $this->studentWithProfileAndCv();
        $opportunity = $this->openOpportunity();
        $this->applicationFor($opportunity, $student);

        Sanctum::actingAs($student->user);

        $response = $this->getJson('/api/student/applications');

        $response->assertStatus(200)->assertJsonMissingPath('data.0.match_score');
    }

    // -----------------------------------------------------------------
    // B. POST /api/opportunities/{opportunity}/apply
    // -----------------------------------------------------------------

    public function test_application_create_response_never_includes_match_score(): void
    {
        $student = $this->studentWithProfileAndCv();
        $opportunity = $this->openOpportunity();

        Sanctum::actingAs($student->user);

        $response = $this->postJson("/api/opportunities/{$opportunity->id}/apply", [
            'cv_id' => $student->cv->id,
        ]);

        $response->assertStatus(201)->assertJsonMissingPath('data.match_score');
    }

    // -----------------------------------------------------------------
    // C. Student application details -- no such endpoint exists (list-only).
    // -----------------------------------------------------------------

    // -----------------------------------------------------------------
    // D. A calculated (non-null) score must still never reach the student.
    // -----------------------------------------------------------------

    public function test_a_calculated_match_score_is_still_never_returned_to_the_student(): void
    {
        $student = $this->studentWithProfileAndCv();
        $opportunity = $this->openOpportunity();
        $application = $this->applicationFor($opportunity, $student);
        $application->match_score = 87.50;
        $application->save();

        Sanctum::actingAs($student->user);

        $response = $this->getJson('/api/student/applications');

        $response->assertStatus(200)->assertJsonMissingPath('data.0.match_score');
    }

    // -----------------------------------------------------------------
    // E. A zero score must still be omitted -- proves this is field-level
    // privacy, not merely "null values are omitted".
    // -----------------------------------------------------------------

    public function test_a_zero_match_score_is_still_omitted_not_merely_null_values(): void
    {
        $student = $this->studentWithProfileAndCv();
        $opportunity = $this->openOpportunity();
        $application = $this->applicationFor($opportunity, $student);
        $application->match_score = 0.00;
        $application->save();

        Sanctum::actingAs($student->user);

        $response = $this->getJson('/api/student/applications');

        $response->assertStatus(200)
            // Sanity check: the row itself is genuinely present and
            // correctly identified -- this isn't passing because the
            // application silently failed to load.
            ->assertJsonPath('data.0.id', $application->id)
            ->assertJsonMissingPath('data.0.match_score');
    }

    // -----------------------------------------------------------------
    // F. Other student-facing endpoints that nest Application.
    // -----------------------------------------------------------------

    public function test_student_interviews_list_never_includes_nested_match_score(): void
    {
        [$student, $application] = $this->applicationWithInterview();
        $application->match_score = 72.00;
        $application->save();

        Sanctum::actingAs($student->user);

        $response = $this->getJson('/api/student/interviews');

        $response->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonMissingPath('data.0.assessment.application.match_score')
            // The same Application instance is also appended at the
            // top level (`Interview::application()`, a backward-compat
            // accessor) -- both paths must be clean.
            ->assertJsonMissingPath('data.0.application.match_score');
    }

    public function test_student_assessments_list_never_includes_nested_match_score(): void
    {
        [$student, $application] = $this->applicationWithInterview();
        $application->match_score = 65.00;
        $application->save();

        Sanctum::actingAs($student->user);

        $response = $this->getJson('/api/student/assessments');

        $response->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonMissingPath('data.0.application.match_score');
    }

    public function test_student_assessment_details_never_includes_nested_match_score(): void
    {
        [$student, $application, $assessment] = $this->applicationWithInterview();
        $application->match_score = 40.00;
        $application->save();

        Sanctum::actingAs($student->user);

        $response = $this->getJson("/api/student/assessments/{$assessment->id}");

        $response->assertStatus(200)->assertJsonMissingPath('data.application.match_score');
    }

    // -----------------------------------------------------------------
    // Helpers -- mirror StudentApplicationTest's own conventions.
    // -----------------------------------------------------------------

    private function studentWithProfileAndCv(): object
    {
        $user = User::factory()->create([
            'role' => 'student',
            'status' => 'active',
        ]);

        $profile = StudentProfile::create(['user_id' => $user->id]);

        $cv = $profile->cvs()->create([
            'title' => 'My CV',
            'file_path' => 'cvs/my-cv.pdf',
        ]);

        return (object) ['user' => $user, 'profile' => $profile, 'cv' => $cv];
    }

    private function openOpportunity(array $overrides = []): Opportunity
    {
        $organizationUser = User::factory()->create([
            'role' => 'organization',
            'status' => 'active',
        ]);

        $organizationProfile = OrganizationProfile::create([
            'user_id' => $organizationUser->id,
            'organization_name' => 'Hiring Co',
            'organization_type' => 'company',
        ]);
        $organizationProfile->approval_status = 'approved';
        $organizationProfile->save();

        return $organizationProfile->opportunities()->create(array_merge([
            'title' => 'Backend Developer',
            'description' => 'Great role.',
            'opportunity_type' => 'job',
            'employment_type' => 'full_time',
            'work_mode' => 'remote',
            'experience_level' => 'junior',
            'status' => 'open',
        ], $overrides));
    }

    private function applicationFor(Opportunity $opportunity, object $student, string $status = 'pending'): Application
    {
        $application = Application::create([
            'student_id' => $student->profile->id,
            'opportunity_id' => $opportunity->id,
            'cv_id' => $student->cv->id,
        ]);

        $application->status = $status;
        $application->save();

        return $application;
    }

    /**
     * A student, their application (`in_assessment`), and a real scheduled
     * Interview on it -- the minimal shape needed to exercise the nested
     * `assessment.application`/`application` leak paths.
     *
     * @return array{0: object, 1: Application, 2: \App\Models\Assessment}
     */
    private function applicationWithInterview(): array
    {
        $student = $this->studentWithProfileAndCv();
        $opportunity = $this->openOpportunity();
        $application = $this->applicationFor($opportunity, $student, 'in_assessment');

        $assessment = $application->assessment()->create([
            'type' => 'interview',
            'status' => 'scheduled',
            'result' => null,
        ]);

        $assessment->interview()->create([
            'interview_type' => 'online',
            'scheduled_at' => now()->addDays(3),
            'meeting_link' => 'https://meet.example.com/room',
        ]);

        return [$student, $application, $assessment];
    }
}
