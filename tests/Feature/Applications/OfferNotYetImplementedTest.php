<?php

namespace Tests\Feature\Applications;

use App\Models\Application;
use App\Models\Opportunity;
use App\Models\OrganizationProfile;
use App\Models\StudentProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Phase 6C-0 deliberately only prepares `Application.status` to be
 * Offer-ready (the new `offer_sent` value, `accepted` no longer
 * organization-writable) -- it does not implement any part of the Offer
 * feature itself. No `offers` table, no `Offer` model, no `OfferService`,
 * no Offer controllers/requests, and no Offer routes exist yet; all of that
 * is Phase 6C-1.
 *
 * This file is a regression guard, not a feature test: it fails loudly if
 * any piece of the Offer feature is accidentally introduced early (e.g. by
 * a later phase's migration landing before its controller/routes are
 * wired), rather than testing behavior that doesn't exist yet.
 */
class OfferNotYetImplementedTest extends TestCase
{
    use RefreshDatabase;

    public function test_no_offers_table_exists_yet(): void
    {
        $this->assertFalse(Schema::hasTable('offers'));
    }

    public function test_no_offer_model_class_exists_yet(): void
    {
        $this->assertFalse(class_exists(\App\Models\Offer::class));
    }

    public function test_no_organization_send_offer_route_exists_yet(): void
    {
        $org = $this->approvedOrganization();
        $opportunity = $this->opportunityFor($org);
        $application = $this->applicationFor($opportunity);

        Sanctum::actingAs($org->user);

        $response = $this->postJson("/api/organization/applications/{$application->id}/offer");

        $response->assertStatus(404);
    }

    public function test_no_student_offer_show_route_exists_yet(): void
    {
        $org = $this->approvedOrganization();
        $opportunity = $this->opportunityFor($org);
        $application = $this->applicationFor($opportunity);

        Sanctum::actingAs($application->studentProfile->user);

        $response = $this->getJson("/api/student/applications/{$application->id}/offer");

        $response->assertStatus(404);
    }

    public function test_no_student_offer_accept_route_exists_yet(): void
    {
        $studentUser = User::factory()->create(['role' => 'student', 'status' => 'active']);
        Sanctum::actingAs($studentUser);

        $response = $this->putJson('/api/student/offers/1/accept');

        $response->assertStatus(404);
    }

    public function test_no_student_offer_decline_route_exists_yet(): void
    {
        $studentUser = User::factory()->create(['role' => 'student', 'status' => 'active']);
        Sanctum::actingAs($studentUser);

        $response = $this->putJson('/api/student/offers/1/decline');

        $response->assertStatus(404);
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

    private function opportunityFor(object $org): Opportunity
    {
        return $org->profile->opportunities()->create([
            'title' => 'Backend Developer',
            'description' => 'Great role.',
            'opportunity_type' => 'job',
            'employment_type' => 'full_time',
            'work_mode' => 'remote',
            'experience_level' => 'junior',
            'status' => 'open',
        ]);
    }

    private function applicationFor(Opportunity $opportunity): Application
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

        return Application::create([
            'student_id' => $studentProfile->id,
            'opportunity_id' => $opportunity->id,
            'cv_id' => $cv->id,
        ]);
    }
}
