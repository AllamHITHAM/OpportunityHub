<?php

namespace Tests\Feature\Applications;

use App\Models\Application;
use App\Models\Opportunity;
use App\Models\OrganizationProfile;
use App\Models\StudentProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Phase 6B-0: proves the `applications.status` enum accepts the new generic
 * `in_assessment` value while still accepting the legacy `interview_scheduled`
 * value, and that both read back and serialize correctly through the API --
 * `status` is never translated or hidden (see docs/BUSINESS_RULES.md).
 *
 * Phase 6C-0 extends this with the same coverage for the new `offer_sent`
 * value. `offer_sent`'s rollback-specific behavior (converting existing
 * `offer_sent` rows back to `in_assessment`) is covered separately in
 * OfferSentStatusMigrationTest, which needs `DatabaseMigrations` rather than
 * this file's `RefreshDatabase` to roll the migration itself back and forward.
 */
class ApplicationStatusMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_in_assessment_is_a_valid_application_status(): void
    {
        $application = $this->applicationFor($this->opportunityFor($this->approvedOrganization()));

        $application->status = 'in_assessment';
        $application->save();

        $this->assertDatabaseHas('applications', [
            'id' => $application->id,
            'status' => 'in_assessment',
        ]);
        $this->assertSame('in_assessment', $application->fresh()->status);
    }

    public function test_offer_sent_is_a_valid_application_status(): void
    {
        $application = $this->applicationFor($this->opportunityFor($this->approvedOrganization()));

        $application->status = 'offer_sent';
        $application->save();

        $this->assertDatabaseHas('applications', [
            'id' => $application->id,
            'status' => 'offer_sent',
        ]);
        $this->assertSame('offer_sent', $application->fresh()->status);
    }

    public function test_legacy_interview_scheduled_remains_a_valid_application_status(): void
    {
        $application = $this->applicationFor($this->opportunityFor($this->approvedOrganization()));

        $application->status = 'interview_scheduled';
        $application->save();

        $this->assertDatabaseHas('applications', [
            'id' => $application->id,
            'status' => 'interview_scheduled',
        ]);
        $this->assertSame('interview_scheduled', $application->fresh()->status);
    }

    public function test_an_in_assessment_application_serializes_correctly_via_the_api(): void
    {
        $org = $this->approvedOrganization();
        $opportunity = $this->opportunityFor($org);
        $application = $this->applicationFor($opportunity);
        $application->status = 'in_assessment';
        $application->save();

        Sanctum::actingAs($org->user);

        $response = $this->getJson("/api/organization/applications/{$application->id}");

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.status', 'in_assessment');
    }

    public function test_an_offer_sent_application_serializes_correctly_via_the_api(): void
    {
        $org = $this->approvedOrganization();
        $opportunity = $this->opportunityFor($org);
        $application = $this->applicationFor($opportunity);
        $application->status = 'offer_sent';
        $application->save();

        Sanctum::actingAs($org->user);

        $response = $this->getJson("/api/organization/applications/{$application->id}");

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.status', 'offer_sent');
    }

    /**
     * Group G: a legacy record already at `interview_scheduled` -- the
     * exact shape of a row that predates this migration, or one that was
     * hand-set before the manual-input gap was closed -- must still load
     * and serialize without error via every read path that touches it.
     */
    public function test_a_legacy_interview_scheduled_application_serializes_correctly_via_the_api(): void
    {
        $org = $this->approvedOrganization();
        $opportunity = $this->opportunityFor($org);
        $application = $this->applicationFor($opportunity);
        $application->status = 'interview_scheduled';
        $application->save();

        Sanctum::actingAs($org->user);

        $showResponse = $this->getJson("/api/organization/applications/{$application->id}");
        $showResponse->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.status', 'interview_scheduled');

        $listResponse = $this->getJson('/api/organization/applications');
        $listResponse->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.0.status', 'interview_scheduled');
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
