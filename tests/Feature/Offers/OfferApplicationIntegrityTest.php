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
 * Phase 6C-4: proves the generic
 * `PUT /api/organization/applications/{application}/status` endpoint can
 * never independently move an Application once an Offer exists for it —
 * closing the gap where, e.g., a `sent` Offer could coexist with
 * `Application.status = rejected` (an impossible combination the Offer/
 * Assessment stack elsewhere goes to considerable lengths to prevent — see
 * `RespondToOfferTest`'s own row-locked race coverage). v1 has no Offer
 * cancel/rescind workflow, so once an Offer exists the block is
 * unconditional — regardless of the Offer's own status (`sent`,
 * `accepted`, or `declined`) and regardless of which status value the
 * organization requests. See
 * `Organization\ApplicationController::updateStatus()` and
 * docs/BUSINESS_RULES.md section 5/7b.
 */
class OfferApplicationIntegrityTest extends TestCase
{
    use RefreshDatabase;

    public function test_generic_reject_is_blocked_once_a_sent_offer_exists(): void
    {
        [$application] = $this->applicationWithOffer('sent', 'offer_sent');

        Sanctum::actingAs($application->opportunity->organizationProfile->user);

        $response = $this->putJson("/api/organization/applications/{$application->id}/status", [
            'status' => 'rejected',
        ]);

        $response->assertStatus(409)
            ->assertJsonPath('success', false)
            ->assertJsonPath(
                'message',
                'This application already has an offer; its status can only change through the offer accept/decline endpoints.'
            );

        // Neither side of the pairing moved -- the Offer stays `sent` and
        // the Application stays `offer_sent`, never the impossible
        // Offer=sent + Application=rejected combination.
        $this->assertDatabaseHas('offers', ['application_id' => $application->id, 'status' => 'sent']);
        $this->assertDatabaseHas('applications', ['id' => $application->id, 'status' => 'offer_sent']);
    }

    public function test_generic_status_update_is_blocked_once_an_accepted_offer_exists(): void
    {
        [$application] = $this->applicationWithOffer('accepted', 'accepted');

        Sanctum::actingAs($application->opportunity->organizationProfile->user);

        $response = $this->putJson("/api/organization/applications/{$application->id}/status", [
            'status' => 'rejected',
        ]);

        $response->assertStatus(409)->assertJsonPath('success', false);

        // The accepted terminal outcome is never rewritten through this
        // endpoint.
        $this->assertDatabaseHas('offers', ['application_id' => $application->id, 'status' => 'accepted']);
        $this->assertDatabaseHas('applications', ['id' => $application->id, 'status' => 'accepted']);
    }

    public function test_generic_status_update_is_blocked_once_a_declined_offer_exists(): void
    {
        [$application] = $this->applicationWithOffer('declined', 'rejected');

        Sanctum::actingAs($application->opportunity->organizationProfile->user);

        // Even a value that would otherwise be a no-op re-write must still
        // be blocked -- there is no partial exemption once an Offer
        // exists.
        $response = $this->putJson("/api/organization/applications/{$application->id}/status", [
            'status' => 'rejected',
        ]);

        $response->assertStatus(409)->assertJsonPath('success', false);
        $this->assertDatabaseHas('offers', ['application_id' => $application->id, 'status' => 'declined']);
        $this->assertDatabaseHas('applications', ['id' => $application->id, 'status' => 'rejected']);
    }

    public function test_generic_reviewed_and_shortlisted_are_also_blocked_once_an_offer_exists(): void
    {
        [$application] = $this->applicationWithOffer('sent', 'offer_sent');

        Sanctum::actingAs($application->opportunity->organizationProfile->user);

        foreach (['reviewed', 'shortlisted'] as $status) {
            $response = $this->putJson("/api/organization/applications/{$application->id}/status", [
                'status' => $status,
            ]);

            $response->assertStatus(409)->assertJsonPath('success', false);
        }

        $this->assertDatabaseHas('applications', ['id' => $application->id, 'status' => 'offer_sent']);
    }

    /**
     * Sanity control: before any Offer exists, the generic endpoint works
     * exactly as it always has -- this new rule only ever triggers once an
     * Offer is actually present.
     */
    public function test_generic_reject_still_works_normally_before_any_offer_exists(): void
    {
        $org = $this->approvedOrganization();
        $opportunity = $this->opportunityFor($org);
        $application = $this->applicationFor($opportunity, 'shortlisted');

        Sanctum::actingAs($org->user);

        $response = $this->putJson("/api/organization/applications/{$application->id}/status", [
            'status' => 'rejected',
        ]);

        $response->assertStatus(200)->assertJsonPath('data.status', 'rejected');
        $this->assertDatabaseHas('applications', ['id' => $application->id, 'status' => 'rejected']);
    }

    /**
     * Phase 6C-4, section H: Assessment data is a wholly separate concern
     * from the Offer response — neither accepting nor declining an Offer
     * touches `Assessment.status`/`Assessment.result` in any way.
     */
    public function test_assessment_data_is_unchanged_by_an_offer_response(): void
    {
        $org = $this->approvedOrganization();
        $opportunity = $this->opportunityFor($org);
        $application = $this->applicationFor($opportunity, 'in_assessment');
        $assessment = $application->assessment()->create([
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

        $assessment->refresh();
        $this->assertSame('completed', $assessment->status);
        $this->assertSame('passed', $assessment->result);
        $this->assertNotNull($assessment->completed_at);
    }

    /**
     * @return array{0: Application}
     */
    private function applicationWithOffer(string $offerStatus, string $applicationStatus): array
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
        $application->offer()->create([
            'status' => $offerStatus,
            'sent_at' => now(),
            'responded_at' => $offerStatus === 'sent' ? null : now(),
        ]);
        $application->status = $applicationStatus;
        $application->save();

        return [$application];
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
