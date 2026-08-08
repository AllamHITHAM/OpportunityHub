<?php

namespace Tests\Feature\Interviews;

use App\Models\Application;
use App\Models\Interview;
use App\Models\Opportunity;
use App\Models\OrganizationProfile;
use App\Models\StudentProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class StudentInterviewTest extends TestCase
{
    use RefreshDatabase;

    public function test_student_can_list_only_their_own_interviews(): void
    {
        $org = $this->approvedOrganization();
        $opportunity = $this->opportunityFor($org);

        $student = $this->studentWithProfileAndCv();
        $application = $this->applicationForStudent($opportunity, $student, 'shortlisted');
        $interview = $this->interviewFor($application);

        $otherStudent = $this->studentWithProfileAndCv();
        $otherApplication = $this->applicationForStudent($opportunity, $otherStudent, 'shortlisted');
        $this->interviewFor($otherApplication);

        Sanctum::actingAs($student->user);

        $response = $this->getJson('/api/student/interviews');

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $interview->id);
    }

    public function test_student_cannot_see_another_students_interviews(): void
    {
        $org = $this->approvedOrganization();
        $opportunity = $this->opportunityFor($org);

        $owner = $this->studentWithProfileAndCv();
        $ownerApplication = $this->applicationForStudent($opportunity, $owner, 'shortlisted');
        $this->interviewFor($ownerApplication);

        $otherStudent = $this->studentWithProfileAndCv();
        Sanctum::actingAs($otherStudent->user);

        $response = $this->getJson('/api/student/interviews');

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonCount(0, 'data');
    }

    public function test_student_interview_index_hides_internal_interview_fields(): void
    {
        $org = $this->approvedOrganization();
        $opportunity = $this->opportunityFor($org);

        $student = $this->studentWithProfileAndCv();
        $application = $this->applicationForStudent($opportunity, $student, 'shortlisted');
        $interview = $this->interviewFor($application, [
            'interview_type' => 'onsite',
            'location' => 'HQ, Room 4',
            'interviewer_name' => 'Jane Recruiter',
            'interviewer_email' => 'jane@hiring.example',
        ]);
        $interview->rating = 4;
        $interview->company_feedback = 'Strong technical answers.';
        $interview->save();

        Sanctum::actingAs($student->user);

        $response = $this->getJson('/api/student/interviews');

        $response->assertStatus(200);
        $data = $response->json('data.0');

        $this->assertArrayNotHasKey('interviewer_email', $data);
        $this->assertArrayNotHasKey('company_feedback', $data);
        $this->assertArrayNotHasKey('rating', $data);
        $this->assertArrayNotHasKey('decision', $data);

        // Safe scheduling fields the student needs to attend/understand the
        // interview are untouched.
        $this->assertSame('onsite', $data['interview_type']);
        $this->assertSame('HQ, Room 4', $data['location']);
        $this->assertSame('Jane Recruiter', $data['interviewer_name']);
        $this->assertSame('scheduled', $data['status']);
    }

    /**
     * Even once a real decision has been recorded (not just the DB default
     * `pending`), the raw `interview.decision` column must stay hidden from
     * students. `assessment.result` (nested via `data[].assessment`) is the
     * student-facing outcome instead.
     */
    public function test_student_never_sees_raw_interview_decision_even_once_recorded(): void
    {
        $org = $this->approvedOrganization();
        $opportunity = $this->opportunityFor($org);

        $student = $this->studentWithProfileAndCv();
        $application = $this->applicationForStudent($opportunity, $student, 'shortlisted');
        $interview = $this->interviewFor($application);
        $interview->decision = 'passed';
        $interview->save();
        $interview->assessment->status = 'completed';
        $interview->assessment->result = 'passed';
        $interview->assessment->save();

        Sanctum::actingAs($student->user);

        $response = $this->getJson('/api/student/interviews');

        $response->assertStatus(200)
            ->assertJsonPath('data.0.assessment.result', 'passed');
        $this->assertArrayNotHasKey('decision', $response->json('data.0'));
    }

    public function test_organization_cannot_access_student_interview_routes(): void
    {
        $organizationUser = User::factory()->create([
            'role' => 'organization',
            'status' => 'active',
        ]);

        Sanctum::actingAs($organizationUser);

        $response = $this->getJson('/api/student/interviews');

        $response->assertStatus(403)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'This action is unauthorized for your account type');
    }

    public function test_student_interview_response_includes_application_and_opportunity_details(): void
    {
        $org = $this->approvedOrganization();
        $opportunity = $this->opportunityFor($org, ['title' => 'Backend Developer']);

        $student = $this->studentWithProfileAndCv();
        $application = $this->applicationForStudent($opportunity, $student, 'shortlisted');
        $this->interviewFor($application);

        Sanctum::actingAs($student->user);

        $response = $this->getJson('/api/student/interviews');

        $response->assertStatus(200)
            ->assertJsonPath('data.0.assessment.application.id', $application->id)
            ->assertJsonPath('data.0.assessment.application.opportunity.title', 'Backend Developer');
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

    /**
     * Creates an Assessment (type=interview) and its Interview directly,
     * bypassing the HTTP endpoint -- the equivalent of the old
     * `$application->interview()->create(...)` shortcut, which no longer
     * works now that `interviews` has no direct `application_id` column.
     */
    private function interviewFor(Application $application, array $overrides = []): Interview
    {
        $assessment = $application->assessment()->create([
            'type' => 'interview',
            'status' => 'scheduled',
            'result' => null,
        ]);

        return $assessment->interview()->create($this->validInterviewPayload($overrides));
    }
}
