<?php

namespace Tests\Feature\Organization;

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
 * GET /api/organization/dashboard.
 *
 * Regression coverage for the pre-existing bug discovered during Phase
 * 6C-1's regression review: `Interview::whereHas('application...', ...)`
 * 500ed on every call, because `Interview` has had no real `application()`
 * *relation* since the Phase 4A-1 Assessment retarget (only a read-only
 * `application` Attribute accessor, which `whereHas()` cannot use). Fixed
 * by querying the real chain, `interview -> assessment -> application ->
 * opportunity`. These tests call the real endpoint end-to-end -- a 500 here
 * must never be treated as an acceptable outcome again.
 */
class OrganizationDashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_approved_organization_receives_200_with_the_existing_envelope(): void
    {
        $org = $this->approvedOrganization();
        Sanctum::actingAs($org->user);

        $response = $this->getJson('/api/organization/dashboard');

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Dashboard statistics retrieved successfully');
    }

    public function test_response_contains_every_existing_dashboard_key(): void
    {
        $org = $this->approvedOrganization();
        Sanctum::actingAs($org->user);

        $response = $this->getJson('/api/organization/dashboard');

        $response->assertStatus(200)->assertJsonStructure(['data' => [
            'total_opportunities',
            'open_opportunities',
            'closed_opportunities',
            'draft_opportunities',
            'total_applications',
            'pending_applications',
            'shortlisted_applications',
            'offer_sent_applications',
            'accepted_applications',
            'rejected_applications',
            'total_interviews',
            'completed_interviews',
        ]]);
    }

    /**
     * Phase 6C-4: makes the final Offer funnel visible alongside the
     * existing accepted/rejected terminal counts (see
     * docs/BUSINESS_RULES.md section 5). `offer_sent_applications` counts
     * `status = offer_sent` rows the same way every other status count
     * here is a plain per-status count -- no Offer table join needed since
     * `Application.status` already carries this signal (see
     * `OfferService::sendOffer()`).
     */
    public function test_offer_sent_applications_count_is_correct_and_isolated_by_ownership(): void
    {
        $orgA = $this->approvedOrganization();
        $opportunityA = $this->opportunityFor($orgA);
        $this->applicationFor($opportunityA, 'offer_sent');
        $this->applicationFor($opportunityA, 'offer_sent');
        $this->applicationFor($opportunityA, 'in_assessment');

        $orgB = $this->approvedOrganization();
        $opportunityB = $this->opportunityFor($orgB);
        $this->applicationFor($opportunityB, 'offer_sent');

        Sanctum::actingAs($orgA->user);
        $responseA = $this->getJson('/api/organization/dashboard');
        $responseA->assertJsonPath('data.offer_sent_applications', 2);

        Sanctum::actingAs($orgB->user);
        $responseB = $this->getJson('/api/organization/dashboard');
        $responseB->assertJsonPath('data.offer_sent_applications', 1);
    }

    /**
     * Adding `offer_sent_applications` must never change what
     * `accepted_applications`/`rejected_applications` count -- each remains
     * a plain per-status count, unaffected by the new field alongside it.
     */
    public function test_accepted_and_rejected_counts_are_unchanged_by_the_new_field(): void
    {
        $org = $this->approvedOrganization();
        $opportunity = $this->opportunityFor($org);
        $this->applicationFor($opportunity, 'accepted');
        $this->applicationFor($opportunity, 'rejected');
        $this->applicationFor($opportunity, 'offer_sent');

        Sanctum::actingAs($org->user);

        $response = $this->getJson('/api/organization/dashboard');

        $response->assertJsonPath('data.accepted_applications', 1)
            ->assertJsonPath('data.rejected_applications', 1)
            ->assertJsonPath('data.offer_sent_applications', 1);
    }

    public function test_interview_count_is_correct(): void
    {
        $org = $this->approvedOrganization();
        $opportunity = $this->opportunityFor($org);
        $this->interviewFor($this->applicationFor($opportunity, 'in_assessment'));
        $this->interviewFor($this->applicationFor($opportunity, 'in_assessment'), status: 'completed');

        Sanctum::actingAs($org->user);

        $response = $this->getJson('/api/organization/dashboard');

        $response->assertJsonPath('data.total_interviews', 2)
            ->assertJsonPath('data.completed_interviews', 1);
    }

    public function test_only_interviews_from_this_organizations_own_opportunities_are_counted(): void
    {
        $orgA = $this->approvedOrganization();
        $opportunityA = $this->opportunityFor($orgA);
        $this->interviewFor($this->applicationFor($opportunityA, 'in_assessment'));
        $this->interviewFor($this->applicationFor($opportunityA, 'in_assessment'));

        $orgB = $this->approvedOrganization();
        $opportunityB = $this->opportunityFor($orgB);
        $this->interviewFor($this->applicationFor($opportunityB, 'in_assessment'));

        Sanctum::actingAs($orgA->user);
        $responseA = $this->getJson('/api/organization/dashboard');
        $responseA->assertJsonPath('data.total_interviews', 2);

        Sanctum::actingAs($orgB->user);
        $responseB = $this->getJson('/api/organization/dashboard');
        $responseB->assertJsonPath('data.total_interviews', 1);
    }

    public function test_unrelated_organizations_interviews_never_leak_into_a_zero_baseline(): void
    {
        $orgWithNoInterviews = $this->approvedOrganization();

        $orgWithInterviews = $this->approvedOrganization();
        $opportunity = $this->opportunityFor($orgWithInterviews);
        $this->interviewFor($this->applicationFor($opportunity, 'in_assessment'));

        Sanctum::actingAs($orgWithNoInterviews->user);

        $response = $this->getJson('/api/organization/dashboard');

        $response->assertJsonPath('data.total_interviews', 0)
            ->assertJsonPath('data.completed_interviews', 0);
    }

    public function test_other_dashboard_counts_remain_correct(): void
    {
        $org = $this->approvedOrganization();
        $this->opportunityFor($org, ['status' => 'open']);
        $this->opportunityFor($org, ['status' => 'closed']);
        $opportunity = $this->opportunityFor($org, ['status' => 'open']);
        $this->applicationFor($opportunity, 'pending');
        $this->applicationFor($opportunity, 'shortlisted');
        $this->applicationFor($opportunity, 'rejected');

        Sanctum::actingAs($org->user);

        $response = $this->getJson('/api/organization/dashboard');

        $response->assertJsonPath('data.total_opportunities', 3)
            ->assertJsonPath('data.open_opportunities', 2)
            ->assertJsonPath('data.closed_opportunities', 1)
            ->assertJsonPath('data.total_applications', 3)
            ->assertJsonPath('data.pending_applications', 1)
            ->assertJsonPath('data.shortlisted_applications', 1)
            ->assertJsonPath('data.rejected_applications', 1);
    }

    public function test_unauthenticated_request_is_rejected(): void
    {
        $response = $this->getJson('/api/organization/dashboard');

        $response->assertStatus(401);
    }

    public function test_a_student_cannot_access_the_organization_dashboard(): void
    {
        $studentUser = User::factory()->create(['role' => 'student', 'status' => 'active']);
        StudentProfile::create(['user_id' => $studentUser->id]);
        Sanctum::actingAs($studentUser);

        $response = $this->getJson('/api/organization/dashboard');

        $response->assertStatus(403)
            ->assertJsonPath('message', 'This action is unauthorized for your account type');
    }

    public function test_a_suspended_organization_is_rejected(): void
    {
        $user = User::factory()->create(['role' => 'organization', 'status' => 'suspended']);
        OrganizationProfile::create([
            'user_id' => $user->id,
            'organization_name' => 'Hiring Co',
            'organization_type' => 'company',
        ]);
        Sanctum::actingAs($user);

        $response = $this->getJson('/api/organization/dashboard');

        $response->assertStatus(403)->assertJsonPath('message', 'Account is not active');
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

    /**
     * `status` is deliberately not settable through the `create()` payload
     * -- it isn't in `Interview::$fillable` (only `Organization\InterviewController::complete()`
     * sets it, directly on the model, never via mass assignment), so it's
     * applied here the same way, after creation.
     */
    private function interviewFor(Application $application, array $overrides = [], string $status = 'scheduled'): Interview
    {
        $assessment = $application->assessment()->create([
            'type' => 'interview',
            'status' => 'scheduled',
            'result' => null,
        ]);

        $interview = $assessment->interview()->create(array_merge([
            'interview_type' => 'phone',
            'scheduled_at' => now()->addDays(3),
        ], $overrides));

        $interview->status = $status;
        $interview->save();

        return $interview;
    }
}
