<?php

namespace Tests\Feature\Interviews;

use App\Models\Application;
use App\Models\Opportunity;
use App\Models\OrganizationProfile;
use App\Models\StudentProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Phase 4A-1 changed Interview responses from a top-level `data.application`
 * to `data.assessment.application`, which was a breaking change for any
 * existing consumer of the legacy shape. These tests prove the fix: every
 * Interview-returning endpoint now serializes BOTH the legacy top-level
 * `application` (via `Interview::$appends`, resolved through `assessment`)
 * AND the new `assessment` object, with identical application data in both,
 * and without `assessment` recursing back into `interview`.
 */
class InterviewResponseShapeTest extends TestCase
{
    use RefreshDatabase;

    public function test_creating_an_interview_returns_both_application_and_assessment(): void
    {
        $org = $this->approvedOrganization();
        $opportunity = $this->opportunityFor($org);
        $application = $this->applicationFor($opportunity, 'shortlisted');

        Sanctum::actingAs($org->user);

        $response = $this->postJson(
            "/api/organization/applications/{$application->id}/interview",
            $this->validInterviewPayload()
        );

        $response->assertStatus(201);
        $this->assertInterviewResponseShape($response->json('data'), $application->id);
    }

    public function test_interview_index_returns_both_application_and_assessment(): void
    {
        $org = $this->approvedOrganization();
        $opportunity = $this->opportunityFor($org);
        $application = $this->applicationFor($opportunity, 'shortlisted');
        $this->interviewFor($application);

        Sanctum::actingAs($org->user);

        $response = $this->getJson('/api/organization/interviews');

        $response->assertStatus(200);
        $this->assertInterviewResponseShape($response->json('data.0'), $application->id);
    }

    public function test_interview_show_returns_both_application_and_assessment(): void
    {
        $org = $this->approvedOrganization();
        $opportunity = $this->opportunityFor($org);
        $application = $this->applicationFor($opportunity, 'shortlisted');
        $interview = $this->interviewFor($application);

        Sanctum::actingAs($org->user);

        $response = $this->getJson("/api/organization/interviews/{$interview->id}");

        $response->assertStatus(200);
        $this->assertInterviewResponseShape($response->json('data'), $application->id);
    }

    public function test_interview_update_returns_both_application_and_assessment(): void
    {
        $org = $this->approvedOrganization();
        $opportunity = $this->opportunityFor($org);
        $application = $this->applicationFor($opportunity, 'shortlisted');
        $interview = $this->interviewFor($application);

        Sanctum::actingAs($org->user);

        $response = $this->putJson(
            "/api/organization/interviews/{$interview->id}",
            $this->validInterviewPayload(['interviewer_name' => 'Jane Recruiter'])
        );

        $response->assertStatus(200);
        $this->assertInterviewResponseShape($response->json('data'), $application->id);
        $this->assertSame('Jane Recruiter', $response->json('data.interviewer_name'));
    }

    public function test_interview_complete_returns_both_application_and_assessment(): void
    {
        $org = $this->approvedOrganization();
        $opportunity = $this->opportunityFor($org);
        $application = $this->applicationFor($opportunity, 'shortlisted');
        $interview = $this->interviewFor($application);

        Sanctum::actingAs($org->user);

        $response = $this->putJson("/api/organization/interviews/{$interview->id}/complete", [
            'decision' => 'passed',
        ]);

        $response->assertStatus(200);
        $this->assertInterviewResponseShape($response->json('data'), $application->id);
        $this->assertSame('passed', $response->json('data.decision'));
    }

    public function test_student_interview_index_returns_both_application_and_assessment(): void
    {
        $org = $this->approvedOrganization();
        $opportunity = $this->opportunityFor($org, ['title' => 'Backend Developer']);

        $student = $this->studentWithProfileAndCv();
        $application = $this->applicationForStudent($opportunity, $student, 'shortlisted');
        $this->interviewFor($application);

        Sanctum::actingAs($student->user);

        $response = $this->getJson('/api/student/interviews');

        $response->assertStatus(200);
        $this->assertInterviewResponseShape($response->json('data.0'), $application->id);

        // The pre-existing nested opportunity detail is preserved too.
        $this->assertSame(
            'Backend Developer',
            $response->json('data.0.application.opportunity.title')
        );
        $this->assertSame(
            'Backend Developer',
            $response->json('data.0.assessment.application.opportunity.title')
        );
    }

    /**
     * All pre-existing Interview fields (not just `application`) are still
     * present and unchanged after the fix -- the legacy shape wasn't
     * partially preserved, it's fully preserved, with `assessment` as a
     * pure addition.
     */
    public function test_existing_interview_fields_are_unchanged(): void
    {
        $org = $this->approvedOrganization();
        $opportunity = $this->opportunityFor($org);
        $application = $this->applicationFor($opportunity, 'shortlisted');
        $interview = $this->interviewFor($application, ['interviewer_name' => 'Jane Recruiter']);

        Sanctum::actingAs($org->user);

        $data = $this->getJson("/api/organization/interviews/{$interview->id}")
            ->assertStatus(200)
            ->json('data');

        $this->assertSame($interview->id, $data['id']);
        $this->assertSame($interview->assessment_id, $data['assessment_id']);
        $this->assertSame('phone', $data['interview_type']);
        $this->assertSame('scheduled', $data['status']);
        $this->assertSame('pending', $data['decision']);
        $this->assertSame('Jane Recruiter', $data['interviewer_name']);
        $this->assertArrayNotHasKey('application_id', $data);
    }

    /**
     * Asserts the required dual shape for a single Interview response:
     * - `application` exists at the top level (legacy path).
     * - `assessment` exists (new, additive).
     * - the application ID is identical through both paths.
     * - `assessment` does not recursively carry `interview` back
     *   (Interview -> Assessment -> Interview would be circular).
     */
    private function assertInterviewResponseShape(array $data, int $expectedApplicationId): void
    {
        $this->assertArrayHasKey('application', $data);
        $this->assertNotNull($data['application']);
        $this->assertSame($expectedApplicationId, $data['application']['id']);

        $this->assertArrayHasKey('assessment', $data);
        $this->assertNotNull($data['assessment']);
        $this->assertArrayHasKey('application', $data['assessment']);
        $this->assertSame($expectedApplicationId, $data['assessment']['application']['id']);

        $this->assertSame(
            $data['application']['id'],
            $data['assessment']['application']['id'],
            'application ID must be identical through both the legacy and new representations'
        );

        $this->assertArrayNotHasKey(
            'interview',
            $data['assessment'],
            'assessment must not recursively serialize the interview that owns it'
        );
    }

    private function approvedOrganization(): object
    {
        $user = User::factory()->create([
            'role' => 'organization',
            'status' => 'active',
        ]);

        $profile = OrganizationProfile::create([
            'user_id' => $user->id,
            'organization_name' => 'Hiring Co',
            'organization_type' => 'company',
        ]);
        $profile->approval_status = 'approved';
        $profile->save();

        return (object) ['user' => $user, 'profile' => $profile];
    }

    private function opportunityFor(object $org, array $overrides = []): Opportunity
    {
        return $org->profile->opportunities()->create(array_merge([
            'title' => 'Backend Developer',
            'description' => 'Great role.',
            'opportunity_type' => 'job',
            'employment_type' => 'full_time',
            'work_mode' => 'remote',
            'experience_level' => 'junior',
            'status' => 'open',
        ], $overrides));
    }

    private function applicationFor(Opportunity $opportunity, string $status = 'pending'): Application
    {
        $studentUser = User::factory()->create([
            'role' => 'student',
            'status' => 'active',
        ]);

        $studentProfile = StudentProfile::create(['user_id' => $studentUser->id]);

        $cv = $studentProfile->cvs()->create([
            'title' => 'My CV',
            'file_path' => 'cvs/my-cv.pdf',
        ]);

        $application = Application::create([
            'student_id' => $studentProfile->id,
            'opportunity_id' => $opportunity->id,
            'cv_id' => $cv->id,
        ]);

        $application->status = $status;
        $application->save();

        return $application;
    }

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

    private function applicationForStudent(Opportunity $opportunity, object $student, string $status = 'pending'): Application
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

    private function validInterviewPayload(array $overrides = []): array
    {
        return array_merge([
            'interview_type' => 'phone',
            'scheduled_at' => now()->addDays(3)->toDateTimeString(),
        ], $overrides);
    }

    private function interviewFor(Application $application, array $overrides = [])
    {
        $assessment = $application->assessment()->create([
            'type' => 'interview',
            'status' => 'scheduled',
            'result' => null,
        ]);

        return $assessment->interview()->create($this->validInterviewPayload($overrides));
    }
}
