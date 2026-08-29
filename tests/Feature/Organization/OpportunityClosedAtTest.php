<?php

namespace Tests\Feature\Organization;

use App\Models\Opportunity;
use App\Models\OrganizationProfile;
use App\Models\User;
use App\Services\OpportunityExpirationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Closed Opportunities Scalability Polish -- the `closed_at` lifecycle
 * itself: manual close/reopen (via the existing generic Edit Opportunity
 * form, `PUT /organization/opportunities/{id}`), automatic expiration
 * closure (`OpportunityExpirationService`), and direct create-as-closed.
 * See `ClosedOpportunitiesListTest` for the new filtered/paginated
 * `GET /organization/opportunities/closed` endpoint itself.
 */
class OpportunityClosedAtTest extends TestCase
{
    use RefreshDatabase;

    public function test_manually_closing_an_open_opportunity_sets_closed_at(): void
    {
        $org = $this->approvedOrganization();
        $opportunity = $org->profile->opportunities()->create($this->opportunityPayload(['status' => 'open']));
        Sanctum::actingAs($org->user);

        $this->assertNull($opportunity->closed_at);

        $response = $this->putJson(
            "/api/organization/opportunities/{$opportunity->id}",
            $this->opportunityPayload(['status' => 'closed']),
        );

        $response->assertStatus(200);
        $this->assertNotNull($response->json('data.closed_at'));
        $this->assertNotNull($opportunity->fresh()->closed_at);
        $this->assertTrue($opportunity->fresh()->closed_at->isToday());
    }

    public function test_reopening_a_closed_opportunity_clears_closed_at(): void
    {
        $org = $this->approvedOrganization();
        $opportunity = $org->profile->opportunities()->create($this->opportunityPayload([
            'status' => 'closed',
            'closed_at' => now()->subDays(5),
        ]));
        Sanctum::actingAs($org->user);

        $response = $this->putJson(
            "/api/organization/opportunities/{$opportunity->id}",
            $this->opportunityPayload(['status' => 'open']),
        );

        $response->assertStatus(200);
        $this->assertNull($response->json('data.closed_at'));
        $this->assertNull($opportunity->fresh()->closed_at);
    }

    public function test_re_saving_an_already_closed_opportunity_never_bumps_closed_at(): void
    {
        $org = $this->approvedOrganization();
        $originalClosedAt = now()->subWeek();
        $opportunity = $org->profile->opportunities()->create($this->opportunityPayload([
            'status' => 'closed',
            'closed_at' => $originalClosedAt,
        ]));
        Sanctum::actingAs($org->user);

        // Editing an unrelated field (description) while status stays
        // `closed` -- the client didn't even send `status` here at all.
        $payload = $this->opportunityPayload();
        unset($payload['status']);
        $payload['description'] = 'An updated description, nothing else.';

        $this->putJson("/api/organization/opportunities/{$opportunity->id}", $payload)
            ->assertStatus(200);

        $this->assertEquals(
            $originalClosedAt->toDateTimeString(),
            $opportunity->fresh()->closed_at->toDateTimeString(),
        );
    }

    public function test_explicitly_resending_status_closed_on_an_already_closed_opportunity_never_bumps_closed_at(): void
    {
        $org = $this->approvedOrganization();
        $originalClosedAt = now()->subWeek();
        $opportunity = $org->profile->opportunities()->create($this->opportunityPayload([
            'status' => 'closed',
            'closed_at' => $originalClosedAt,
        ]));
        Sanctum::actingAs($org->user);

        $this->putJson(
            "/api/organization/opportunities/{$opportunity->id}",
            $this->opportunityPayload(['status' => 'closed']),
        )->assertStatus(200);

        $this->assertEquals(
            $originalClosedAt->toDateTimeString(),
            $opportunity->fresh()->closed_at->toDateTimeString(),
        );
    }

    public function test_a_draft_opportunity_edited_without_changing_status_is_unaffected(): void
    {
        $org = $this->approvedOrganization();
        $opportunity = $org->profile->opportunities()->create($this->opportunityPayload(['status' => 'draft']));
        Sanctum::actingAs($org->user);

        $payload = $this->opportunityPayload();
        unset($payload['status']);

        $this->putJson("/api/organization/opportunities/{$opportunity->id}", $payload)
            ->assertStatus(200);

        $fresh = $opportunity->fresh();
        $this->assertSame('draft', $fresh->status);
        $this->assertNull($fresh->closed_at);
    }

    public function test_transitioning_draft_directly_to_closed_sets_closed_at(): void
    {
        $org = $this->approvedOrganization();
        $opportunity = $org->profile->opportunities()->create($this->opportunityPayload(['status' => 'draft']));
        Sanctum::actingAs($org->user);

        $this->putJson(
            "/api/organization/opportunities/{$opportunity->id}",
            $this->opportunityPayload(['status' => 'closed']),
        )->assertStatus(200);

        $this->assertNotNull($opportunity->fresh()->closed_at);
    }

    public function test_creating_an_opportunity_directly_as_closed_sets_closed_at_immediately(): void
    {
        $org = $this->approvedOrganization();
        Sanctum::actingAs($org->user);

        $response = $this->postJson(
            '/api/organization/opportunities',
            array_merge($this->opportunityPayload(['status' => 'closed']), $this->creationExtras()),
        );

        $response->assertStatus(201);
        $this->assertNotNull($response->json('data.closed_at'));
    }

    public function test_creating_an_opportunity_as_open_never_sets_closed_at(): void
    {
        $org = $this->approvedOrganization();
        Sanctum::actingAs($org->user);

        $response = $this->postJson(
            '/api/organization/opportunities',
            array_merge($this->opportunityPayload(['status' => 'open']), $this->creationExtras()),
        );

        $response->assertStatus(201);
        $this->assertNull($response->json('data.closed_at'));
    }

    public function test_automatic_expiration_closure_sets_closed_at_to_now(): void
    {
        $org = $this->approvedOrganization();
        $opportunity = $org->profile->opportunities()->create($this->opportunityPayload([
            'status' => 'open',
            'application_deadline' => today()->subDay(),
        ]));

        app(OpportunityExpirationService::class)->closeExpired();

        $fresh = $opportunity->fresh();
        $this->assertSame('closed', $fresh->status);
        $this->assertNotNull($fresh->closed_at);
        $this->assertTrue($fresh->closed_at->isToday());
    }

    public function test_automatic_expiration_closure_never_rewrites_closed_at_on_a_second_sweep(): void
    {
        $org = $this->approvedOrganization();
        $opportunity = $org->profile->opportunities()->create($this->opportunityPayload([
            'status' => 'open',
            'application_deadline' => today()->subDay(),
        ]));

        app(OpportunityExpirationService::class)->closeExpired();
        $firstClosedAt = $opportunity->fresh()->closed_at;

        // A later sweep run (e.g. the next day's scheduled command) never
        // matches this row again -- it's no longer `status='open'`.
        app(OpportunityExpirationService::class)->closeExpired();

        $this->assertEquals(
            $firstClosedAt->toDateTimeString(),
            $opportunity->fresh()->closed_at->toDateTimeString(),
        );
    }

    public function test_closed_at_transition_never_touches_recruitment_history(): void
    {
        $org = $this->approvedOrganization();
        $opportunity = $org->profile->opportunities()->create($this->opportunityPayload(['status' => 'open']));

        $studentUser = User::factory()->create(['role' => 'student', 'status' => 'active']);
        $studentProfile = \App\Models\StudentProfile::create(['user_id' => $studentUser->id]);
        $cv = $studentProfile->cvs()->create(['title' => 'CV', 'file_path' => 'cvs/x.pdf']);
        $application = \App\Models\Application::create([
            'student_id' => $studentProfile->id,
            'opportunity_id' => $opportunity->id,
            'cv_id' => $cv->id,
        ]);

        Sanctum::actingAs($org->user);
        $this->putJson(
            "/api/organization/opportunities/{$opportunity->id}",
            $this->opportunityPayload(['status' => 'closed']),
        )->assertStatus(200);

        $this->assertDatabaseHas('applications', ['id' => $application->id]);
        $this->assertNotNull($opportunity->fresh()->closed_at);
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

    /**
     * `StoreOpportunityRequest` (unlike the update request) requires at
     * least one entry each for `eligible_majors`/`skills` -- only
     * relevant for the two `postJson('/api/organization/opportunities')`
     * tests here, which exercise the real HTTP create endpoint (every
     * other test in this file creates Opportunities directly via
     * Eloquent, bypassing that validation entirely).
     */
    private function creationExtras(): array
    {
        $skillId = \App\Models\Skill::firstOrCreate(['name' => 'PHP'])->id;

        return [
            'eligible_majors' => ['Computer Science'],
            'skills' => [['skill_id' => $skillId, 'is_required' => true]],
        ];
    }
}
