<?php

namespace Tests\Feature\Opportunities;

use App\Console\Commands\CloseExpiredOpportunitiesCommand;
use App\Models\Opportunity;
use App\Models\OrganizationProfile;
use App\Models\StudentProfile;
use App\Models\User;
use App\Services\OpportunityExpirationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Final Company Profile Manual-E2E Bug Fix: an Opportunity whose
 * `application_deadline` has passed must stop being treated as genuinely
 * open everywhere -- Student Apply (already correctly rejected pre-existing
 * code, re-tested here for regression only), public/Student discovery and
 * details, the Organization's own dashboard counts, and Company Profile's
 * Open/Closed split (which reuses the public discovery endpoint and the
 * Organization's own list respectively -- no Company-Profile-specific test
 * needed here, since neither screen has its own deadline logic to
 * duplicate).
 *
 * Covers both halves of the fix: the real-time, always-correct
 * `Opportunity::isOpenForApplications()`/`scopeOpenForApplications()`
 * checks (never depend on a scheduler having run), and the persisted
 * `status: open -> closed` transition (`OpportunityExpirationService`,
 * triggered lazily by `Organization\OpportunityController` and by the
 * scheduled `opportunities:close-expired` command) that the Organization's
 * own dashboard counts and `destroyClosed()`'s literal `status === 'closed'`
 * gate both depend on.
 */
class OpportunityExpirationTest extends TestCase
{
    use RefreshDatabase;

    public function test_open_with_a_future_deadline_accepts_an_application(): void
    {
        $student = $this->studentWithProfileAndCv();
        $opportunity = $this->openOpportunity(['application_deadline' => today()->addDays(5)]);

        Sanctum::actingAs($student->user);

        $response = $this->postJson("/api/opportunities/{$opportunity->id}/apply", [
            'cv_id' => $student->cv->id,
        ]);

        $response->assertStatus(201);
    }

    public function test_open_with_a_null_deadline_behaves_exactly_as_before(): void
    {
        $student = $this->studentWithProfileAndCv();
        $opportunity = $this->openOpportunity(['application_deadline' => null]);

        Sanctum::actingAs($student->user);

        $response = $this->postJson("/api/opportunities/{$opportunity->id}/apply", [
            'cv_id' => $student->cv->id,
        ]);

        $response->assertStatus(201);
        $this->assertTrue($opportunity->fresh()->isOpenForApplications());
    }

    public function test_open_with_todays_deadline_still_accepts_an_application(): void
    {
        // Exact boundary: the deadline's own calendar day still counts as
        // open through its entire 24 hours (end-of-day semantics) -- only
        // the day *after* it is genuinely expired.
        $student = $this->studentWithProfileAndCv();
        $opportunity = $this->openOpportunity(['application_deadline' => today()]);

        Sanctum::actingAs($student->user);

        $response = $this->postJson("/api/opportunities/{$opportunity->id}/apply", [
            'cv_id' => $student->cv->id,
        ]);

        $response->assertStatus(201);
    }

    public function test_open_with_a_past_deadline_rejects_an_application(): void
    {
        $student = $this->studentWithProfileAndCv();
        $opportunity = $this->openOpportunity(['application_deadline' => today()->subDay()]);

        Sanctum::actingAs($student->user);

        $response = $this->postJson("/api/opportunities/{$opportunity->id}/apply", [
            'cv_id' => $student->cv->id,
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Application deadline has passed');

        $this->assertDatabaseCount('applications', 0);
    }

    public function test_draft_with_a_past_deadline_stays_draft_not_closed(): void
    {
        $opportunity = $this->openOpportunity([
            'status' => 'draft',
            'application_deadline' => today()->subWeek(),
        ]);

        app(OpportunityExpirationService::class)->closeExpired();

        $this->assertSame('draft', $opportunity->fresh()->status);
    }

    public function test_an_already_closed_opportunity_stays_closed_and_is_untouched(): void
    {
        $opportunity = $this->openOpportunity([
            'status' => 'closed',
            'application_deadline' => today()->subWeek(),
        ]);
        $updatedAt = $opportunity->updated_at;

        $affected = app(OpportunityExpirationService::class)->closeExpired();

        $this->assertSame(0, $affected);
        $this->assertSame('closed', $opportunity->fresh()->status);
        $this->assertEquals($updatedAt, $opportunity->fresh()->updated_at);
    }

    public function test_expiration_never_deletes_any_recruitment_history(): void
    {
        $student = $this->studentWithProfileAndCv();
        $opportunity = $this->openOpportunity(['application_deadline' => today()->addDay()]);

        $application = \App\Models\Application::create([
            'student_id' => $student->profile->id,
            'opportunity_id' => $opportunity->id,
            'cv_id' => $student->cv->id,
        ]);

        // The deadline passes *after* the Application already exists.
        $opportunity->forceFill(['application_deadline' => today()->subDay()])->save();

        app(OpportunityExpirationService::class)->closeExpired();

        $this->assertSame('closed', $opportunity->fresh()->status);
        $this->assertDatabaseHas('applications', ['id' => $application->id]);
        $this->assertDatabaseHas('opportunities', ['id' => $opportunity->id]);
    }

    public function test_an_expired_opportunity_is_omitted_from_public_student_discovery(): void
    {
        $org = $this->approvedOrganization();
        $opportunity = $this->openOpportunity(['application_deadline' => today()->subDay()], $org->profile);
        $stillOpen = $this->openOpportunity(['application_deadline' => today()->addDay()], $org->profile);

        $index = $this->getJson('/api/opportunities?per_page=50');
        $ids = collect($index->json('data.data'))->pluck('id');
        $this->assertFalse($ids->contains($opportunity->id));
        $this->assertTrue($ids->contains($stillOpen->id));

        // Direct show() is opaque about it too -- identical to an already-
        // `closed` Opportunity, never a special "expired" leak.
        $this->getJson("/api/opportunities/{$opportunity->id}")->assertStatus(404);
    }

    public function test_an_expired_opportunity_is_omitted_from_company_profile_open_section(): void
    {
        // Company Profile's "Open Opportunities" reuses this exact
        // endpoint with `organization_id` -- no separate logic to
        // duplicate/desync.
        $opportunity = $this->openOpportunity(['application_deadline' => today()->subDay()]);

        $response = $this->getJson("/api/opportunities?organization_id={$opportunity->organization_id}&per_page=50");

        $ids = collect($response->json('data.data'))->pluck('id');
        $this->assertFalse($ids->contains($opportunity->id));
    }

    public function test_organization_open_and_closed_counts_are_correct_after_visiting_the_dashboard(): void
    {
        $org = $this->approvedOrganization();
        $expired = $org->profile->opportunities()->create($this->opportunityPayload([
            'application_deadline' => today()->subDay(),
        ]));
        $stillOpen = $org->profile->opportunities()->create($this->opportunityPayload([
            'application_deadline' => today()->addDay(),
        ]));

        Sanctum::actingAs($org->user);

        // The bug report's exact repro: before this endpoint is even hit,
        // the DB still literally says `open` for the expired row.
        $this->assertSame('open', $expired->fresh()->status);

        $response = $this->getJson('/api/organization/opportunities');

        $statuses = collect($response->json('data'))->pluck('status', 'id');
        $this->assertSame('closed', $statuses[$expired->id]);
        $this->assertSame('open', $statuses[$stillOpen->id]);

        // Persisted, not just relabeled for this one response -- a
        // second, independent read confirms it stuck.
        $this->assertSame('closed', $expired->fresh()->status);
    }

    public function test_a_lazily_closed_expired_opportunity_becomes_permanently_deletable_via_closed_cleanup(): void
    {
        $org = $this->approvedOrganization();
        $expired = $org->profile->opportunities()->create($this->opportunityPayload([
            'application_deadline' => today()->subDay(),
        ]));

        Sanctum::actingAs($org->user);

        // Visiting the dashboard is what lazily persists status=closed --
        // matches the real Company Profile flow (owner opens Company
        // Profile, which loads their own Opportunities list).
        $this->getJson('/api/organization/opportunities');

        $this->deleteJson("/api/organization/opportunities/{$expired->id}/closed")
            ->assertStatus(200)
            ->assertJsonPath('success', true);

        $this->assertDatabaseMissing('opportunities', ['id' => $expired->id]);
    }

    public function test_the_expiration_service_is_idempotent(): void
    {
        $opportunity = $this->openOpportunity(['application_deadline' => today()->subDay()]);

        $first = app(OpportunityExpirationService::class)->closeExpired();
        $second = app(OpportunityExpirationService::class)->closeExpired();

        $this->assertSame(1, $first);
        $this->assertSame(0, $second);
        $this->assertSame('closed', $opportunity->fresh()->status);
    }

    public function test_the_scheduled_command_closes_expired_opportunities_and_is_idempotent(): void
    {
        $opportunity = $this->openOpportunity(['application_deadline' => today()->subDay()]);

        $this->artisan(CloseExpiredOpportunitiesCommand::class)->assertSuccessful();
        $this->assertSame('closed', $opportunity->fresh()->status);

        // Running it again (e.g. a second scheduler tick) is a safe no-op.
        $this->artisan(CloseExpiredOpportunitiesCommand::class)->assertSuccessful();
        $this->assertSame('closed', $opportunity->fresh()->status);
    }

    public function test_the_expiration_service_scoped_to_one_organization_never_touches_another(): void
    {
        $orgA = $this->approvedOrganization();
        $orgB = $this->approvedOrganization();
        $expiredA = $orgA->profile->opportunities()->create($this->opportunityPayload([
            'application_deadline' => today()->subDay(),
        ]));
        $expiredB = $orgB->profile->opportunities()->create($this->opportunityPayload([
            'application_deadline' => today()->subDay(),
        ]));

        app(OpportunityExpirationService::class)->closeExpired($orgA->profile->id);

        $this->assertSame('closed', $expiredA->fresh()->status);
        $this->assertSame('open', $expiredB->fresh()->status);
    }

    private function studentWithProfileAndCv(): object
    {
        $user = User::factory()->create(['role' => 'student', 'status' => 'active']);
        $profile = StudentProfile::create(['user_id' => $user->id]);
        $cv = $profile->cvs()->create(['title' => 'My CV', 'file_path' => 'cvs/my-cv.pdf']);

        return (object) ['user' => $user, 'profile' => $profile, 'cv' => $cv];
    }

    private function approvedOrganization(): object
    {
        $user = User::factory()->create(['role' => 'organization', 'status' => 'active']);

        $profile = OrganizationProfile::create([
            'user_id' => $user->id,
            'organization_name' => 'Test Organization',
            'organization_type' => 'company',
        ]);
        $profile->approval_status = 'approved';
        $profile->save();

        return (object) ['user' => $user, 'profile' => $profile];
    }

    private function opportunityPayload(array $overrides = []): array
    {
        return array_merge([
            'title' => 'Backend Developer',
            'description' => 'Great role.',
            'opportunity_type' => 'job',
            'employment_type' => 'full_time',
            'work_mode' => 'remote',
            'experience_level' => 'junior',
            'status' => 'open',
        ], $overrides);
    }

    private function openOpportunity(array $overrides = [], ?OrganizationProfile $organizationProfile = null): Opportunity
    {
        $profile = $organizationProfile ?? $this->approvedOrganization()->profile;

        return $profile->opportunities()->create($this->opportunityPayload($overrides));
    }
}
