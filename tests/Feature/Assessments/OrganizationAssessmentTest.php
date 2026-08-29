<?php

namespace Tests\Feature\Assessments;

use App\Models\Application;
use App\Models\Assessment;
use App\Models\Opportunity;
use App\Models\OrganizationProfile;
use App\Models\StudentProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class OrganizationAssessmentTest extends TestCase
{
    use RefreshDatabase;

    /**
     * **Phase 10A.3**: `data` is now the full Assessment *history* array
     * (see `Organization\AssessmentController::showForApplication()`'s own
     * doc comment) -- a breaking response-shape change from the single
     * nullable object this endpoint returned before. With exactly one
     * Assessment, `data` is a one-element array; `test_organization_sees_the_full_assessment_history_for_its_own_application`
     * below covers the multi-element case.
     */
    public function test_organization_sees_the_assessment_for_its_own_application(): void
    {
        $org = $this->approvedOrganization();
        $opportunity = $this->opportunityFor($org);
        $application = $this->applicationFor($opportunity, 'shortlisted');
        $assessment = $this->assessmentFor($application);

        Sanctum::actingAs($org->user);

        $response = $this->getJson("/api/organization/applications/{$application->id}/assessment");

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $assessment->id)
            ->assertJsonPath('data.0.type', 'interview')
            ->assertJsonPath('data.0.application.id', $application->id)
            ->assertJsonPath('data.0.application.cv.id', $application->cv_id)
            ->assertJsonPath('data.0.application.cv.file_path', 'cvs/my-cv.pdf')
            ->assertJsonPath('data.0.interview.id', $assessment->interview->id);
    }

    /**
     * The Phase 10A.3 case the single-element test above can't cover: a
     * completed Quiz followed by a real "Advance to Interview" Assessment,
     * both returned, in creation order, in the same `data` array -- neither
     * overwritten nor collapsed down to just the latest one.
     */
    public function test_organization_sees_the_full_assessment_history_for_its_own_application(): void
    {
        $org = $this->approvedOrganization();
        $opportunity = $this->opportunityFor($org);
        $application = $this->applicationFor($opportunity, 'shortlisted');

        $quiz = $application->assessment()->create([
            'type' => 'quiz',
            'status' => 'completed',
            'result' => 'passed',
            'completed_at' => now(),
            'result_released_at' => now(),
        ]);
        $application->update(['status' => 'in_assessment']);

        $interview = $application->assessments()->create([
            'type' => 'interview',
            'status' => 'scheduled',
            'result' => null,
        ]);
        $interview->interview()->create([
            'interview_type' => 'phone',
            'scheduled_at' => now()->addDays(3),
        ]);

        Sanctum::actingAs($org->user);

        $response = $this->getJson("/api/organization/applications/{$application->id}/assessment");

        $response->assertStatus(200)
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.id', $quiz->id)
            ->assertJsonPath('data.0.type', 'quiz')
            ->assertJsonPath('data.0.status', 'completed')
            ->assertJsonPath('data.1.id', $interview->id)
            ->assertJsonPath('data.1.type', 'interview')
            ->assertJsonPath('data.1.status', 'scheduled');
    }

    public function test_organization_gets_an_empty_array_for_an_owned_application_with_no_assessment(): void
    {
        $org = $this->approvedOrganization();
        $opportunity = $this->opportunityFor($org);
        $application = $this->applicationFor($opportunity, 'shortlisted');

        Sanctum::actingAs($org->user);

        $response = $this->getJson("/api/organization/applications/{$application->id}/assessment");

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'This application has no assessment yet')
            ->assertJsonPath('data', []);
    }

    /**
     * Regression guard for the Phase 6A-Backend student privacy hotfix:
     * hiding internal Interview fields from Student responses must not
     * affect the Organization's own contract, which still needs them.
     */
    public function test_organization_assessment_response_still_includes_internal_interview_fields(): void
    {
        $org = $this->approvedOrganization();
        $opportunity = $this->opportunityFor($org);
        $application = $this->applicationFor($opportunity, 'shortlisted');
        $assessment = $this->assessmentFor($application);

        $assessment->interview->interviewer_email = 'jane@hiring.example';
        $assessment->interview->rating = 4;
        $assessment->interview->company_feedback = 'Strong technical answers.';
        $assessment->interview->save();

        Sanctum::actingAs($org->user);

        $response = $this->getJson("/api/organization/applications/{$application->id}/assessment");

        $response->assertStatus(200)
            ->assertJsonPath('data.0.interview.interviewer_email', 'jane@hiring.example')
            ->assertJsonPath('data.0.interview.rating', 4)
            ->assertJsonPath('data.0.interview.company_feedback', 'Strong technical answers.')
            ->assertJsonPath('data.0.interview.decision', 'pending');

        $showResponse = $this->getJson("/api/organization/assessments/{$assessment->id}");

        $showResponse->assertStatus(200)
            ->assertJsonPath('data.interview.interviewer_email', 'jane@hiring.example')
            ->assertJsonPath('data.interview.rating', 4)
            ->assertJsonPath('data.interview.company_feedback', 'Strong technical answers.')
            ->assertJsonPath('data.interview.decision', 'pending');
    }

    public function test_organization_cannot_see_the_assessment_for_another_organizations_application(): void
    {
        $orgA = $this->approvedOrganization();
        $opportunity = $this->opportunityFor($orgA);
        $application = $this->applicationFor($opportunity, 'shortlisted');
        $this->assessmentFor($application);

        $orgB = $this->approvedOrganization();
        Sanctum::actingAs($orgB->user);

        $response = $this->getJson("/api/organization/applications/{$application->id}/assessment");

        $response->assertStatus(404)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Application not found');
    }

    public function test_organization_can_view_assessment_details_by_id(): void
    {
        $org = $this->approvedOrganization();
        $opportunity = $this->opportunityFor($org);
        $application = $this->applicationFor($opportunity, 'shortlisted');
        $assessment = $this->assessmentFor($application);

        Sanctum::actingAs($org->user);

        $response = $this->getJson("/api/organization/assessments/{$assessment->id}");

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.id', $assessment->id)
            ->assertJsonPath('data.application.id', $application->id)
            ->assertJsonPath('data.application.cv.id', $application->cv_id)
            ->assertJsonPath('data.application.cv.file_path', 'cvs/my-cv.pdf')
            ->assertJsonPath('data.interview.id', $assessment->interview->id);
    }

    public function test_organization_cannot_view_another_organizations_assessment(): void
    {
        $orgA = $this->approvedOrganization();
        $opportunity = $this->opportunityFor($orgA);
        $application = $this->applicationFor($opportunity, 'shortlisted');
        $assessment = $this->assessmentFor($application);

        $orgB = $this->approvedOrganization();
        Sanctum::actingAs($orgB->user);

        $response = $this->getJson("/api/organization/assessments/{$assessment->id}");

        $response->assertStatus(404)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Assessment not found');
    }

    public function test_guest_cannot_view_an_assessment(): void
    {
        $org = $this->approvedOrganization();
        $opportunity = $this->opportunityFor($org);
        $application = $this->applicationFor($opportunity, 'shortlisted');
        $assessment = $this->assessmentFor($application);

        $response = $this->getJson("/api/organization/assessments/{$assessment->id}");

        $response->assertStatus(401)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Unauthenticated');
    }

    public function test_student_role_cannot_access_organization_assessment_routes(): void
    {
        $org = $this->approvedOrganization();
        $opportunity = $this->opportunityFor($org);
        $application = $this->applicationFor($opportunity, 'shortlisted');

        $studentUser = User::factory()->create([
            'role' => 'student',
            'status' => 'active',
        ]);

        Sanctum::actingAs($studentUser);

        $response = $this->getJson("/api/organization/applications/{$application->id}/assessment");

        $response->assertStatus(403)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'This action is unauthorized for your account type');
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

    private function assessmentFor(Application $application): Assessment
    {
        $assessment = $application->assessment()->create([
            'type' => 'interview',
            'status' => 'scheduled',
            'result' => null,
        ]);

        $assessment->interview()->create([
            'interview_type' => 'phone',
            'scheduled_at' => now()->addDays(3),
        ]);

        return $assessment->fresh('interview');
    }
}
