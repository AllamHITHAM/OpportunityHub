<?php

namespace Tests\Feature\Offers;

use App\Models\Application;
use App\Models\Opportunity;
use App\Models\OrganizationProfile;
use App\Models\StudentProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Phase 6C-1: GET /api/organization/applications/{application}/offer and
 * GET /api/student/applications/{application}/offer.
 */
class OfferShowTest extends TestCase
{
    use RefreshDatabase;

    // ---------------------------------------------------------------
    // D. Organization show
    // ---------------------------------------------------------------

    public function test_organization_can_view_its_own_offer(): void
    {
        $org = $this->approvedOrganization();
        $opportunity = $this->opportunityFor($org);
        $application = $this->eligibleApplication($opportunity);
        $offer = $application->offer()->create([
            'title' => 'Backend Engineer',
            'status' => 'sent',
            'sent_at' => now(),
        ]);

        Sanctum::actingAs($org->user);

        $response = $this->getJson("/api/organization/applications/{$application->id}/offer");

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.id', $offer->id)
            ->assertJsonPath('data.title', 'Backend Engineer');
    }

    public function test_organization_sees_404_when_no_offer_exists(): void
    {
        $org = $this->approvedOrganization();
        $opportunity = $this->opportunityFor($org);
        $application = $this->eligibleApplication($opportunity);

        Sanctum::actingAs($org->user);

        $response = $this->getJson("/api/organization/applications/{$application->id}/offer");

        $response->assertStatus(404)->assertJsonPath('success', false);
    }

    public function test_wrong_organization_cannot_view_another_organizations_offer(): void
    {
        $orgA = $this->approvedOrganization();
        $opportunity = $this->opportunityFor($orgA);
        $application = $this->eligibleApplication($opportunity);
        $application->offer()->create(['status' => 'sent', 'sent_at' => now()]);

        $orgB = $this->approvedOrganization();
        Sanctum::actingAs($orgB->user);

        $response = $this->getJson("/api/organization/applications/{$application->id}/offer");

        $response->assertStatus(404)->assertJsonPath('message', 'Application not found');
    }

    // ---------------------------------------------------------------
    // E. Student show
    // ---------------------------------------------------------------

    public function test_student_can_view_their_own_offer(): void
    {
        $org = $this->approvedOrganization();
        $opportunity = $this->opportunityFor($org);
        $application = $this->eligibleApplication($opportunity);
        $offer = $application->offer()->create([
            'title' => 'Backend Engineer',
            'salary_amount' => 90000,
            'salary_currency' => 'USD',
            'salary_period' => 'yearly',
            'status' => 'sent',
            'sent_at' => now(),
        ]);
        $application->status = 'offer_sent';
        $application->save();

        Sanctum::actingAs($application->studentProfile->user);

        $response = $this->getJson("/api/student/applications/{$application->id}/offer");

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.id', $offer->id)
            ->assertJsonPath('data.title', 'Backend Engineer')
            ->assertJsonPath('data.salary_amount', '90000.00')
            ->assertJsonPath('data.salary_currency', 'USD')
            ->assertJsonPath('data.salary_period', 'yearly')
            ->assertJsonPath('data.status', 'sent');
    }

    public function test_another_student_is_denied_with_a_404(): void
    {
        $org = $this->approvedOrganization();
        $opportunity = $this->opportunityFor($org);
        $application = $this->eligibleApplication($opportunity);
        $application->offer()->create(['status' => 'sent', 'sent_at' => now()]);

        $otherStudentUser = User::factory()->create(['role' => 'student', 'status' => 'active']);
        StudentProfile::create(['user_id' => $otherStudentUser->id]);
        Sanctum::actingAs($otherStudentUser);

        $response = $this->getJson("/api/student/applications/{$application->id}/offer");

        $response->assertStatus(404)->assertJsonPath('message', 'Offer not found');
    }

    public function test_student_sees_404_when_no_offer_exists(): void
    {
        $org = $this->approvedOrganization();
        $opportunity = $this->opportunityFor($org);
        $application = $this->eligibleApplication($opportunity);

        Sanctum::actingAs($application->studentProfile->user);

        $response = $this->getJson("/api/student/applications/{$application->id}/offer");

        $response->assertStatus(404)->assertJsonPath('message', 'Offer not found');
    }

    // ---------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------

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

    private function eligibleApplication(Opportunity $opportunity): Application
    {
        $application = $this->applicationFor($opportunity, 'in_assessment');
        $application->assessment()->create([
            'type' => 'interview',
            'status' => 'completed',
            'result' => 'passed',
            'completed_at' => now(),
        ]);

        return $application;
    }
}
