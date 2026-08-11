<?php

namespace Tests\Feature\Student;

use App\Models\Application;
use App\Models\Interview;
use App\Models\Opportunity;
use App\Models\OrganizationProfile;
use App\Models\StudentProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * GET /api/student/dashboard.
 *
 * Regression coverage for the pre-existing bug discovered during Phase
 * 6C-1's regression review: `Interview::whereHas('application...', ...)`
 * 500ed on every call, because `Interview` has had no real `application()`
 * *relation* since the Phase 4A-1 Assessment retarget (only a read-only
 * `application` Attribute accessor, which `whereHas()` cannot use). Fixed
 * by querying the real chain, `interview -> assessment -> application`.
 * These tests call the real endpoint end-to-end -- a 500 here must never be
 * treated as an acceptable outcome again.
 */
class StudentDashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_student_with_a_complete_profile_receives_200_with_the_existing_envelope(): void
    {
        $student = $this->studentWithProfile();
        Sanctum::actingAs($student->user);

        $response = $this->getJson('/api/student/dashboard');

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Dashboard statistics retrieved successfully');
    }

    public function test_response_contains_every_existing_dashboard_key(): void
    {
        $student = $this->studentWithProfile();
        Sanctum::actingAs($student->user);

        $response = $this->getJson('/api/student/dashboard');

        $response->assertStatus(200)->assertJsonStructure(['data' => [
            'total_applications',
            'pending_applications',
            'reviewed_applications',
            'shortlisted_applications',
            'offer_sent_applications',
            'accepted_applications',
            'rejected_applications',
            'total_cvs',
            'total_skills',
            'total_interviews',
        ]]);
    }

    /**
     * Phase 6C-4: makes the final Offer funnel visible alongside the
     * existing accepted/rejected terminal counts (see
     * docs/BUSINESS_RULES.md section 5) -- counts only this student's own
     * applications.
     */
    public function test_offer_sent_applications_count_is_correct_and_isolated_by_student(): void
    {
        $studentA = $this->studentWithProfile();
        $studentB = $this->studentWithProfile();
        $org = $this->approvedOrganization();
        $opportunity = $this->opportunityFor($org);

        $this->applicationFor($studentA, $opportunity, 'offer_sent');
        $this->applicationFor($studentA, $this->opportunityFor($org), 'offer_sent');
        $this->applicationFor($studentA, $this->opportunityFor($org), 'in_assessment');
        $this->applicationFor($studentB, $opportunity, 'offer_sent');

        Sanctum::actingAs($studentA->user);
        $responseA = $this->getJson('/api/student/dashboard');
        $responseA->assertJsonPath('data.offer_sent_applications', 2);

        Sanctum::actingAs($studentB->user);
        $responseB = $this->getJson('/api/student/dashboard');
        $responseB->assertJsonPath('data.offer_sent_applications', 1);
    }

    /**
     * Adding `offer_sent_applications` must never change what
     * `accepted_applications`/`rejected_applications` count.
     */
    public function test_accepted_and_rejected_counts_are_unchanged_by_the_new_field(): void
    {
        $student = $this->studentWithProfile();
        $org = $this->approvedOrganization();
        $this->applicationFor($student, $this->opportunityFor($org), 'accepted');
        $this->applicationFor($student, $this->opportunityFor($org), 'rejected');
        $this->applicationFor($student, $this->opportunityFor($org), 'offer_sent');

        Sanctum::actingAs($student->user);

        $response = $this->getJson('/api/student/dashboard');

        $response->assertJsonPath('data.accepted_applications', 1)
            ->assertJsonPath('data.rejected_applications', 1)
            ->assertJsonPath('data.offer_sent_applications', 1);
    }

    public function test_interview_count_is_correct(): void
    {
        $student = $this->studentWithProfile();
        $org = $this->approvedOrganization();
        $opportunityA = $this->opportunityFor($org);
        $opportunityB = $this->opportunityFor($org);
        $this->interviewFor($this->applicationFor($student, $opportunityA, 'in_assessment'));
        $this->interviewFor($this->applicationFor($student, $opportunityB, 'in_assessment'));

        Sanctum::actingAs($student->user);

        $response = $this->getJson('/api/student/dashboard');

        $response->assertJsonPath('data.total_interviews', 2);
    }

    public function test_only_this_students_own_interviews_are_counted(): void
    {
        $studentA = $this->studentWithProfile();
        $studentB = $this->studentWithProfile();
        $org = $this->approvedOrganization();
        $opportunity = $this->opportunityFor($org);

        $this->interviewFor($this->applicationFor($studentA, $opportunity, 'in_assessment'));
        $this->interviewFor($this->applicationFor($studentB, $opportunity, 'in_assessment'));
        $this->interviewFor($this->applicationFor($studentB, $this->opportunityFor($org), 'in_assessment'));

        Sanctum::actingAs($studentA->user);
        $responseA = $this->getJson('/api/student/dashboard');
        $responseA->assertJsonPath('data.total_interviews', 1);

        Sanctum::actingAs($studentB->user);
        $responseB = $this->getJson('/api/student/dashboard');
        $responseB->assertJsonPath('data.total_interviews', 2);
    }

    public function test_another_students_interviews_never_leak_into_a_zero_baseline(): void
    {
        $studentWithNoInterviews = $this->studentWithProfile();

        $studentWithInterview = $this->studentWithProfile();
        $org = $this->approvedOrganization();
        $opportunity = $this->opportunityFor($org);
        $this->interviewFor($this->applicationFor($studentWithInterview, $opportunity, 'in_assessment'));

        Sanctum::actingAs($studentWithNoInterviews->user);

        $response = $this->getJson('/api/student/dashboard');

        $response->assertJsonPath('data.total_interviews', 0);
    }

    public function test_other_dashboard_counts_remain_correct(): void
    {
        $student = $this->studentWithProfile();
        $org = $this->approvedOrganization();
        $opportunityA = $this->opportunityFor($org);
        $opportunityB = $this->opportunityFor($org);
        $opportunityC = $this->opportunityFor($org);
        $this->applicationFor($student, $opportunityA, 'pending');
        $this->applicationFor($student, $opportunityB, 'reviewed');
        $this->applicationFor($student, $opportunityC, 'shortlisted');
        $student->cvs()->create(['title' => 'Second CV', 'file_path' => 'cvs/second.pdf']);

        Sanctum::actingAs($student->user);

        $response = $this->getJson('/api/student/dashboard');

        $response->assertJsonPath('data.total_applications', 3)
            ->assertJsonPath('data.pending_applications', 1)
            ->assertJsonPath('data.reviewed_applications', 1)
            ->assertJsonPath('data.shortlisted_applications', 1)
            // The student's own default CV (created in studentWithProfile())
            // plus the extra one created above.
            ->assertJsonPath('data.total_cvs', 2);
    }

    public function test_unauthenticated_request_is_rejected(): void
    {
        $response = $this->getJson('/api/student/dashboard');

        $response->assertStatus(401);
    }

    public function test_an_organization_cannot_access_the_student_dashboard(): void
    {
        $org = $this->approvedOrganization();
        Sanctum::actingAs($org->user);

        $response = $this->getJson('/api/student/dashboard');

        $response->assertStatus(403)
            ->assertJsonPath('message', 'This action is unauthorized for your account type');
    }

    public function test_a_student_without_a_profile_is_blocked(): void
    {
        $studentUser = User::factory()->create(['role' => 'student', 'status' => 'active']);
        Sanctum::actingAs($studentUser);

        $response = $this->getJson('/api/student/dashboard');

        $response->assertStatus(404)
            ->assertJsonPath('message', 'You must create a student profile first');
    }

    public function test_a_suspended_student_is_rejected(): void
    {
        $user = User::factory()->create(['role' => 'student', 'status' => 'suspended']);
        StudentProfile::create(['user_id' => $user->id]);
        Sanctum::actingAs($user);

        $response = $this->getJson('/api/student/dashboard');

        $response->assertStatus(403)->assertJsonPath('message', 'Account is not active');
    }

    private function studentWithProfile(): StudentProfile
    {
        $user = User::factory()->create([
            'role' => 'student',
            'status' => 'active',
        ]);

        $profile = StudentProfile::create(['user_id' => $user->id]);
        $profile->cvs()->create(['title' => 'My CV', 'file_path' => 'cvs/my-cv.pdf']);

        return $profile;
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

    private function applicationFor(StudentProfile $student, Opportunity $opportunity, string $status = 'pending'): Application
    {
        $cv = $student->cvs()->first() ?? $student->cvs()->create([
            'title' => 'My CV',
            'file_path' => 'cvs/my-cv.pdf',
        ]);

        $application = Application::create([
            'student_id' => $student->id,
            'opportunity_id' => $opportunity->id,
            'cv_id' => $cv->id,
        ]);

        $application->status = $status;
        $application->save();

        return $application;
    }

    private function interviewFor(Application $application, array $overrides = []): Interview
    {
        $assessment = $application->assessment()->create([
            'type' => 'interview',
            'status' => 'scheduled',
            'result' => null,
        ]);

        return $assessment->interview()->create(array_merge([
            'interview_type' => 'phone',
            'scheduled_at' => now()->addDays(3),
        ], $overrides));
    }
}
