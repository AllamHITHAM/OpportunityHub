<?php

namespace Tests\Feature\Offers;

use App\Models\Application;
use App\Models\Offer;
use App\Models\Opportunity;
use App\Models\OrganizationProfile;
use App\Models\StudentProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Phase 6C-1: PUT /api/student/offers/{offer}/accept and
 * PUT /api/student/offers/{offer}/decline -- sections F, G, and H.
 */
class RespondToOfferTest extends TestCase
{
    use RefreshDatabase;

    // ---------------------------------------------------------------
    // F. Accept
    // ---------------------------------------------------------------

    public function test_a_sent_offer_can_be_accepted(): void
    {
        [$application, $offer] = $this->sentOffer();

        Sanctum::actingAs($application->studentProfile->user);

        $response = $this->putJson("/api/student/offers/{$offer->id}/accept");

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Offer accepted successfully')
            ->assertJsonPath('data.status', 'accepted');
        $this->assertNotNull($response->json('data.responded_at'));

        $this->assertDatabaseHas('offers', [
            'id' => $offer->id,
            'status' => 'accepted',
        ]);
    }

    public function test_accepting_sets_application_status_to_accepted(): void
    {
        [$application, $offer] = $this->sentOffer();

        Sanctum::actingAs($application->studentProfile->user);

        $this->putJson("/api/student/offers/{$offer->id}/accept")->assertStatus(200);

        $this->assertDatabaseHas('applications', [
            'id' => $application->id,
            'status' => 'accepted',
        ]);
    }

    public function test_a_second_accept_returns_409(): void
    {
        [$application, $offer] = $this->sentOffer();
        Sanctum::actingAs($application->studentProfile->user);
        $this->putJson("/api/student/offers/{$offer->id}/accept")->assertStatus(200);

        $response = $this->putJson("/api/student/offers/{$offer->id}/accept");

        $response->assertStatus(409)->assertJsonPath('success', false);
        $this->assertDatabaseHas('applications', ['id' => $application->id, 'status' => 'accepted']);
    }

    public function test_declining_after_accepting_returns_409(): void
    {
        [$application, $offer] = $this->sentOffer();
        Sanctum::actingAs($application->studentProfile->user);
        $this->putJson("/api/student/offers/{$offer->id}/accept")->assertStatus(200);

        $response = $this->putJson("/api/student/offers/{$offer->id}/decline");

        $response->assertStatus(409);
        // The winning (accept) response must never be overwritten by a
        // losing decline attempt.
        $this->assertDatabaseHas('offers', ['id' => $offer->id, 'status' => 'accepted']);
        $this->assertDatabaseHas('applications', ['id' => $application->id, 'status' => 'accepted']);
    }

    public function test_a_different_student_cannot_accept_the_offer(): void
    {
        [, $offer] = $this->sentOffer();

        $otherStudentUser = User::factory()->create(['role' => 'student', 'status' => 'active']);
        StudentProfile::create(['user_id' => $otherStudentUser->id]);
        Sanctum::actingAs($otherStudentUser);

        $response = $this->putJson("/api/student/offers/{$offer->id}/accept");

        $response->assertStatus(404)->assertJsonPath('message', 'Offer not found');
        $this->assertDatabaseHas('offers', ['id' => $offer->id, 'status' => 'sent']);
    }

    // ---------------------------------------------------------------
    // G. Decline
    // ---------------------------------------------------------------

    public function test_a_sent_offer_can_be_declined(): void
    {
        [$application, $offer] = $this->sentOffer();

        Sanctum::actingAs($application->studentProfile->user);

        $response = $this->putJson("/api/student/offers/{$offer->id}/decline");

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Offer declined successfully')
            ->assertJsonPath('data.status', 'declined');
        $this->assertNotNull($response->json('data.responded_at'));

        $this->assertDatabaseHas('offers', [
            'id' => $offer->id,
            'status' => 'declined',
        ]);
    }

    public function test_declining_sets_application_status_to_rejected(): void
    {
        [$application, $offer] = $this->sentOffer();

        Sanctum::actingAs($application->studentProfile->user);

        $this->putJson("/api/student/offers/{$offer->id}/decline")->assertStatus(200);

        $this->assertDatabaseHas('applications', [
            'id' => $application->id,
            'status' => 'rejected',
        ]);
    }

    public function test_a_second_decline_returns_409(): void
    {
        [$application, $offer] = $this->sentOffer();
        Sanctum::actingAs($application->studentProfile->user);
        $this->putJson("/api/student/offers/{$offer->id}/decline")->assertStatus(200);

        $response = $this->putJson("/api/student/offers/{$offer->id}/decline");

        $response->assertStatus(409)->assertJsonPath('success', false);
        $this->assertDatabaseHas('applications', ['id' => $application->id, 'status' => 'rejected']);
    }

    public function test_accepting_after_declining_returns_409(): void
    {
        [$application, $offer] = $this->sentOffer();
        Sanctum::actingAs($application->studentProfile->user);
        $this->putJson("/api/student/offers/{$offer->id}/decline")->assertStatus(200);

        $response = $this->putJson("/api/student/offers/{$offer->id}/accept");

        $response->assertStatus(409);
        $this->assertDatabaseHas('offers', ['id' => $offer->id, 'status' => 'declined']);
        $this->assertDatabaseHas('applications', ['id' => $application->id, 'status' => 'rejected']);
    }

    public function test_a_different_student_cannot_decline_the_offer(): void
    {
        [, $offer] = $this->sentOffer();

        $otherStudentUser = User::factory()->create(['role' => 'student', 'status' => 'active']);
        StudentProfile::create(['user_id' => $otherStudentUser->id]);
        Sanctum::actingAs($otherStudentUser);

        $response = $this->putJson("/api/student/offers/{$offer->id}/decline");

        $response->assertStatus(404)->assertJsonPath('message', 'Offer not found');
        $this->assertDatabaseHas('offers', ['id' => $offer->id, 'status' => 'sent']);
    }

    // ---------------------------------------------------------------
    // H. Race: only one terminal response ever wins, and Offer/Application
    // can never diverge into an impossible combination.
    // ---------------------------------------------------------------

    public function test_accept_and_decline_never_both_win_and_never_leave_a_mismatched_state(): void
    {
        [$application, $offer] = $this->sentOffer();
        Sanctum::actingAs($application->studentProfile->user);

        $first = $this->putJson("/api/student/offers/{$offer->id}/accept");
        $second = $this->putJson("/api/student/offers/{$offer->id}/decline");

        $first->assertStatus(200);
        $second->assertStatus(409);

        $offer->refresh();
        $application->refresh();

        // Exactly one of the two valid terminal combinations -- never a
        // third, impossible one (e.g. Offer accepted + Application
        // rejected, or Offer declined + Application accepted).
        $this->assertSame('accepted', $offer->status);
        $this->assertSame('accepted', $application->status);
    }

    public function test_service_level_double_response_only_lets_the_first_call_through(): void
    {
        [$application, $offer] = $this->sentOffer();
        $service = app(\App\Services\OfferService::class);

        $accepted = $service->acceptOffer($offer);
        $this->assertSame('accepted', $accepted->status);

        $this->expectException(\App\Exceptions\OfferAlreadyRespondedException::class);
        $service->declineOffer($offer);
    }

    /**
     * @return array{0: Application, 1: Offer}
     */
    private function sentOffer(): array
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
        $offer = $application->offer()->create([
            'status' => 'sent',
            'sent_at' => now(),
        ]);
        $application->status = 'offer_sent';
        $application->save();

        return [$application, $offer];
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
