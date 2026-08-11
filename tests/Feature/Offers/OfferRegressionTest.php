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
 * Phase 6C-1, section I: proves introducing the Offer feature didn't
 * loosen the Phase 6C-0 generic-status-endpoint restrictions, and didn't
 * change the dashboard response contracts. The broader Assessment/
 * Interview/Quiz regression coverage is simply "the rest of the suite
 * still passes" -- nothing in this phase touches those controllers/
 * services/requests, so no new tests were added for them specifically.
 *
 * `GET /api/organization/dashboard`/`GET /api/student/dashboard` were both
 * discovered, while writing this phase's own dashboard-regression coverage,
 * to 500 on every call (a pre-existing, Offer-unrelated bug -- see the
 * now-fixed post-6C-1 dashboard bugfix phase for the root cause and the
 * real end-to-end coverage in `OrganizationDashboardTest`/
 * `StudentDashboardTest`). This class only re-confirms, now that both
 * endpoints work again, that an accepted Offer is reflected in
 * `accepted_applications` the same way any other `accepted` row already
 * was.
 *
 * `test_dashboards_now_expose_an_offer_sent_count()` below was updated in
 * Phase 6C-4, which added `offer_sent_applications` to both dashboards
 * (see `OrganizationDashboardTest`/`StudentDashboardTest` for the real
 * count-correctness/ownership-isolation coverage) -- superseding this
 * class's original Phase 6C-1 assertion that the field was absent.
 */
class OfferRegressionTest extends TestCase
{
    use RefreshDatabase;

    public function test_generic_status_endpoint_still_cannot_set_accepted(): void
    {
        $org = $this->approvedOrganization();
        $opportunity = $this->opportunityFor($org);
        $application = $this->applicationFor($opportunity, 'shortlisted');

        Sanctum::actingAs($org->user);

        $response = $this->putJson("/api/organization/applications/{$application->id}/status", [
            'status' => 'accepted',
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors(['status']);
        $this->assertDatabaseHas('applications', ['id' => $application->id, 'status' => 'shortlisted']);
    }

    public function test_generic_status_endpoint_still_cannot_set_offer_sent(): void
    {
        $org = $this->approvedOrganization();
        $opportunity = $this->opportunityFor($org);
        $application = $this->applicationFor($opportunity, 'in_assessment');

        Sanctum::actingAs($org->user);

        $response = $this->putJson("/api/organization/applications/{$application->id}/status", [
            'status' => 'offer_sent',
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors(['status']);
        $this->assertDatabaseHas('applications', ['id' => $application->id, 'status' => 'in_assessment']);
    }

    public function test_generic_status_endpoint_can_still_reject_a_candidate(): void
    {
        $org = $this->approvedOrganization();
        $opportunity = $this->opportunityFor($org);
        $application = $this->applicationFor($opportunity, 'shortlisted');

        Sanctum::actingAs($org->user);

        $response = $this->putJson("/api/organization/applications/{$application->id}/status", [
            'status' => 'rejected',
        ]);

        $response->assertStatus(200)->assertJsonPath('data.status', 'rejected');
    }

    /**
     * `accepted_applications` still counts raw `status = accepted` rows --
     * unchanged query, even though what a *new* `accepted` row means has
     * shifted (see docs/BUSINESS_RULES.md section 5) -- verified through
     * the real `GET /api/organization/dashboard` endpoint now that it
     * works again.
     */
    public function test_dashboard_accepted_count_reflects_an_accepted_offer(): void
    {
        $org = $this->approvedOrganization();
        $opportunity = $this->opportunityFor($org);
        $application = $this->applicationFor($opportunity, 'in_assessment');
        $application->assessment()->create([
            'type' => 'interview',
            'status' => 'completed',
            'result' => 'passed',
            'completed_at' => now(),
        ]);
        $offer = $application->offer()->create(['status' => 'sent', 'sent_at' => now()]);
        $application->status = 'offer_sent';
        $application->save();

        Sanctum::actingAs($application->studentProfile->user);
        $this->putJson("/api/student/offers/{$offer->id}/accept")->assertStatus(200);

        Sanctum::actingAs($org->user);
        $response = $this->getJson('/api/organization/dashboard');

        $response->assertStatus(200)->assertJsonPath('data.accepted_applications', 1);
    }

    /**
     * Phase 6C-4: `offer_sent_applications` was added to both dashboards
     * (see `Organization\DashboardController`/`Student\DashboardController`
     * and their own dedicated `OrganizationDashboardTest`/
     * `StudentDashboardTest` coverage for count-correctness/ownership-
     * isolation) -- this class only re-confirms the field now appears on
     * both, superseding the previous Phase 6C-1 assertion that it was
     * absent.
     */
    public function test_dashboards_now_expose_an_offer_sent_count(): void
    {
        $org = $this->approvedOrganization();
        Sanctum::actingAs($org->user);
        $orgResponse = $this->getJson('/api/organization/dashboard');
        $orgResponse->assertStatus(200);
        $this->assertArrayHasKey('offer_sent_applications', $orgResponse->json('data'));

        $application = $this->applicationFor($this->opportunityFor($org), 'pending');
        Sanctum::actingAs($application->studentProfile->user);
        $studentResponse = $this->getJson('/api/student/dashboard');
        $studentResponse->assertStatus(200);
        $this->assertArrayHasKey('offer_sent_applications', $studentResponse->json('data'));
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
}
