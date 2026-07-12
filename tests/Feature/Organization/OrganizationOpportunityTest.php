<?php

namespace Tests\Feature\Organization;

use App\Models\OrganizationProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class OrganizationOpportunityTest extends TestCase
{
    use RefreshDatabase;

    public function test_approved_organization_can_list_its_own_opportunities(): void
    {
        $org = $this->organizationWithProfile('approved');
        $org->profile->opportunities()->create($this->validOpportunityPayload());

        Sanctum::actingAs($org->user);

        $response = $this->getJson('/api/organization/opportunities');

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonCount(1, 'data');
    }

    public function test_approved_organization_can_create_an_opportunity(): void
    {
        $org = $this->organizationWithProfile('approved');

        Sanctum::actingAs($org->user);

        $response = $this->postJson('/api/organization/opportunities', $this->validOpportunityPayload());

        $response->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.title', 'Software Engineer');

        $this->assertDatabaseHas('opportunities', [
            'organization_id' => $org->profile->id,
            'title' => 'Software Engineer',
        ]);
    }

    public function test_pending_organization_cannot_create_an_opportunity(): void
    {
        $org = $this->organizationWithProfile('pending');

        Sanctum::actingAs($org->user);

        $response = $this->postJson('/api/organization/opportunities', $this->validOpportunityPayload());

        $response->assertStatus(403)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Organization is not approved to publish opportunities');

        $this->assertDatabaseCount('opportunities', 0);
    }

    public function test_rejected_organization_cannot_create_an_opportunity(): void
    {
        $org = $this->organizationWithProfile('rejected');

        Sanctum::actingAs($org->user);

        $response = $this->postJson('/api/organization/opportunities', $this->validOpportunityPayload());

        $response->assertStatus(403)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Organization is not approved to publish opportunities');

        $this->assertDatabaseCount('opportunities', 0);
    }

    public function test_organization_can_view_its_own_opportunity(): void
    {
        $org = $this->organizationWithProfile('approved');
        $opportunity = $org->profile->opportunities()->create($this->validOpportunityPayload());

        Sanctum::actingAs($org->user);

        $response = $this->getJson("/api/organization/opportunities/{$opportunity->id}");

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.id', $opportunity->id);
    }

    public function test_organization_can_update_its_own_opportunity(): void
    {
        $org = $this->organizationWithProfile('approved');
        $opportunity = $org->profile->opportunities()->create($this->validOpportunityPayload());

        Sanctum::actingAs($org->user);

        $response = $this->putJson(
            "/api/organization/opportunities/{$opportunity->id}",
            $this->validOpportunityPayload(['title' => 'Senior Software Engineer'])
        );

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.title', 'Senior Software Engineer');

        $this->assertDatabaseHas('opportunities', [
            'id' => $opportunity->id,
            'title' => 'Senior Software Engineer',
        ]);
    }

    public function test_organization_can_delete_its_own_opportunity(): void
    {
        $org = $this->organizationWithProfile('approved');
        $opportunity = $org->profile->opportunities()->create($this->validOpportunityPayload());

        Sanctum::actingAs($org->user);

        $response = $this->deleteJson("/api/organization/opportunities/{$opportunity->id}");

        $response->assertStatus(200)
            ->assertJsonPath('success', true);

        $this->assertDatabaseMissing('opportunities', ['id' => $opportunity->id]);
    }

    public function test_organization_cannot_view_another_organizations_opportunity_through_organization_routes(): void
    {
        $orgA = $this->organizationWithProfile('approved');
        $opportunity = $orgA->profile->opportunities()->create($this->validOpportunityPayload());

        $orgB = $this->organizationWithProfile('approved');
        Sanctum::actingAs($orgB->user);

        $response = $this->getJson("/api/organization/opportunities/{$opportunity->id}");

        $response->assertStatus(404)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Opportunity not found');
    }

    public function test_organization_cannot_update_another_organizations_opportunity(): void
    {
        $orgA = $this->organizationWithProfile('approved');
        $opportunity = $orgA->profile->opportunities()->create($this->validOpportunityPayload());

        $orgB = $this->organizationWithProfile('approved');
        Sanctum::actingAs($orgB->user);

        $response = $this->putJson(
            "/api/organization/opportunities/{$opportunity->id}",
            $this->validOpportunityPayload(['title' => 'Hijacked Title'])
        );

        $response->assertStatus(404)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Opportunity not found');

        $this->assertDatabaseHas('opportunities', [
            'id' => $opportunity->id,
            'title' => 'Software Engineer',
        ]);
    }

    public function test_organization_cannot_delete_another_organizations_opportunity(): void
    {
        $orgA = $this->organizationWithProfile('approved');
        $opportunity = $orgA->profile->opportunities()->create($this->validOpportunityPayload());

        $orgB = $this->organizationWithProfile('approved');
        Sanctum::actingAs($orgB->user);

        $response = $this->deleteJson("/api/organization/opportunities/{$opportunity->id}");

        $response->assertStatus(404)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Opportunity not found');

        $this->assertDatabaseHas('opportunities', ['id' => $opportunity->id]);
    }

    public function test_validation_rejects_invalid_opportunity_data(): void
    {
        $org = $this->organizationWithProfile('approved');

        Sanctum::actingAs($org->user);

        $response = $this->postJson('/api/organization/opportunities', [
            'title' => '',
            'opportunity_type' => 'not-a-real-type',
            'employment_type' => 'full_time',
            'work_mode' => 'remote',
            'experience_level' => 'junior',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['title', 'description', 'opportunity_type']);

        $this->assertDatabaseCount('opportunities', 0);
    }

    public function test_public_endpoint_returns_open_opportunities(): void
    {
        $org = $this->organizationWithProfile('approved');
        $opportunity = $org->profile->opportunities()->create($this->validOpportunityPayload(['status' => 'open']));

        $response = $this->getJson('/api/opportunities');

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonCount(1, 'data.data')
            ->assertJsonPath('data.data.0.id', $opportunity->id);
    }

    public function test_public_endpoint_does_not_return_draft_opportunities(): void
    {
        $org = $this->organizationWithProfile('approved');
        $org->profile->opportunities()->create($this->validOpportunityPayload(['status' => 'draft']));

        $response = $this->getJson('/api/opportunities');

        $response->assertStatus(200)
            ->assertJsonCount(0, 'data.data');
    }

    /**
     * The public index query explicitly filters `status = 'open'`, so closed
     * opportunities are excluded by design, same as drafts.
     */
    public function test_public_endpoint_does_not_return_closed_opportunities(): void
    {
        $org = $this->organizationWithProfile('approved');
        $org->profile->opportunities()->create($this->validOpportunityPayload(['status' => 'closed']));

        $response = $this->getJson('/api/opportunities');

        $response->assertStatus(200)
            ->assertJsonCount(0, 'data.data');
    }

    private function organizationWithProfile(string $approvalStatus = 'approved'): object
    {
        $user = User::factory()->create([
            'role' => 'organization',
            'status' => 'active',
        ]);

        $profile = OrganizationProfile::create([
            'user_id' => $user->id,
            'organization_name' => 'Test Organization',
            'organization_type' => 'company',
        ]);

        $profile->approval_status = $approvalStatus;
        $profile->save();

        return (object) ['user' => $user, 'profile' => $profile];
    }

    private function validOpportunityPayload(array $overrides = []): array
    {
        return array_merge([
            'title' => 'Software Engineer',
            'description' => 'A great opportunity.',
            'opportunity_type' => 'job',
            'employment_type' => 'full_time',
            'work_mode' => 'remote',
            'experience_level' => 'junior',
        ], $overrides);
    }
}
