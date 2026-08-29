<?php

namespace Tests\Feature\Public;

use App\Models\Location;
use App\Models\OrganizationProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Organization Public Profile phase: `GET /organizations/{organizationProfile}`
 * and `GET /organizations/{organizationProfile}/posts` -- reachable
 * unauthenticated, exactly like `Public\OpportunityController`'s own
 * routes. Never a duplicate opportunity-fetching test here; "Open
 * Opportunities" reuses `GET /opportunities?organization_id=X`, already
 * covered by `OrganizationOpportunityTest`'s public-endpoint tests --
 * this file only adds the one new `organization_id` filter behavior.
 */
class OrganizationPublicProfileTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_can_view_an_approved_organizations_profile(): void
    {
        $org = $this->organizationWithProfile('approved', [
            'organization_name' => 'Acme Corp',
            'industry' => 'Software',
            'description' => 'We build great things.',
            'website' => 'https://acme.example.com',
            'phone' => '555-1234',
        ]);

        $response = $this->getJson("/api/organizations/{$org->profile->id}");

        $response->assertStatus(200)
            ->assertJsonPath('data.id', $org->profile->id)
            ->assertJsonPath('data.organization_name', 'Acme Corp')
            ->assertJsonPath('data.industry', 'Software')
            ->assertJsonPath('data.description', 'We build great things.')
            ->assertJsonPath('data.website', 'https://acme.example.com');
    }

    public function test_public_profile_never_leaks_user_id_or_approval_status(): void
    {
        $org = $this->organizationWithProfile('approved');

        $response = $this->getJson("/api/organizations/{$org->profile->id}");

        $response->assertStatus(200);
        $data = $response->json('data');
        $this->assertArrayNotHasKey('user_id', $data);
        $this->assertArrayNotHasKey('approval_status', $data);
    }

    public function test_public_profile_exposes_the_real_canonical_location(): void
    {
        $location = Location::create(['canonical_name' => 'Ramallah']);
        $org = $this->organizationWithProfile('approved', ['location_id' => $location->id]);

        $response = $this->getJson("/api/organizations/{$org->profile->id}");

        $response->assertStatus(200)
            ->assertJsonPath('data.location.id', $location->id)
            ->assertJsonPath('data.location.canonical_name', 'Ramallah');
    }

    public function test_a_pending_organizations_profile_is_not_publicly_visible(): void
    {
        $org = $this->organizationWithProfile('pending');

        $this->getJson("/api/organizations/{$org->profile->id}")->assertStatus(404);
        $this->getJson("/api/organizations/{$org->profile->id}/posts")->assertStatus(404);
    }

    public function test_a_rejected_organizations_profile_is_not_publicly_visible(): void
    {
        $org = $this->organizationWithProfile('rejected');

        $this->getJson("/api/organizations/{$org->profile->id}")->assertStatus(404);
    }

    /**
     * The Flutter Company Profile screen reuses this exact public
     * endpoint for the Organization owner's own "view my profile" screen
     * (never a second, richer self-view endpoint) -- so a not-yet-approved
     * Organization must still be able to see its own profile/posts before
     * Admin approval, exactly like `GET /organization/profile` already
     * allows.
     */
    public function test_a_pending_organization_can_view_its_own_profile_and_posts(): void
    {
        $org = $this->organizationWithProfile('pending', [
            'organization_name' => 'Not Yet Approved Inc',
        ]);
        $org->profile->posts()->create(['title' => 'A post', 'body' => 'A body']);

        Sanctum::actingAs($org->user);

        $this->getJson("/api/organizations/{$org->profile->id}")
            ->assertStatus(200)
            ->assertJsonPath('data.organization_name', 'Not Yet Approved Inc');

        $this->getJson("/api/organizations/{$org->profile->id}/posts")
            ->assertStatus(200)
            ->assertJsonCount(1, 'data');
    }

    public function test_a_pending_organization_cannot_view_another_pending_organizations_profile(): void
    {
        $orgA = $this->organizationWithProfile('pending');
        $orgB = $this->organizationWithProfile('pending');

        Sanctum::actingAs($orgA->user);

        $this->getJson("/api/organizations/{$orgB->profile->id}")->assertStatus(404);
    }

    public function test_a_student_cannot_view_a_pending_organizations_profile_even_when_authenticated(): void
    {
        $org = $this->organizationWithProfile('pending');
        $student = User::factory()->create(['role' => 'student', 'status' => 'active']);

        Sanctum::actingAs($student);

        $this->getJson("/api/organizations/{$org->profile->id}")->assertStatus(404);
    }

    public function test_a_nonexistent_organization_returns_a_clean_404(): void
    {
        $this->getJson('/api/organizations/999999')->assertStatus(404);
    }

    public function test_public_can_list_an_organizations_posts_newest_first(): void
    {
        $org = $this->organizationWithProfile('approved');
        $first = $org->profile->posts()->create(['title' => 'First', 'body' => 'First body']);
        $first->created_at = now()->subMinute();
        $first->save();
        $second = $org->profile->posts()->create(['title' => 'Second', 'body' => 'Second body']);

        $response = $this->getJson("/api/organizations/{$org->profile->id}/posts");

        $response->assertStatus(200)->assertJsonCount(2, 'data');
        $ids = collect($response->json('data'))->pluck('id')->all();
        $this->assertSame([$second->id, $first->id], $ids);
    }

    public function test_posts_list_is_empty_for_an_organization_with_no_posts(): void
    {
        $org = $this->organizationWithProfile('approved');

        $response = $this->getJson("/api/organizations/{$org->profile->id}/posts");

        $response->assertStatus(200)->assertJsonCount(0, 'data');
    }

    public function test_organizations_posts_are_never_mixed_with_another_organizations(): void
    {
        $orgA = $this->organizationWithProfile('approved');
        $orgB = $this->organizationWithProfile('approved');
        $orgA->profile->posts()->create(['title' => 'Org A post', 'body' => 'Body']);
        $orgB->profile->posts()->create(['title' => 'Org B post', 'body' => 'Body']);

        $response = $this->getJson("/api/organizations/{$orgA->profile->id}/posts");

        $response->assertStatus(200)->assertJsonCount(1, 'data');
        $this->assertSame('Org A post', $response->json('data.0.title'));
    }

    /**
     * The "Open Opportunities" section reuses the existing public
     * Opportunity endpoint with the new `organization_id` filter -- one
     * real query, never a duplicate implementation.
     */
    public function test_open_opportunities_can_be_filtered_by_organization(): void
    {
        $orgA = $this->organizationWithProfile('approved');
        $orgB = $this->organizationWithProfile('approved');
        $orgA->profile->opportunities()->create([
            'title' => 'Org A Job',
            'description' => 'Great role.',
            'opportunity_type' => 'job',
            'employment_type' => 'full_time',
            'work_mode' => 'remote',
            'experience_level' => 'junior',
            'status' => 'open',
        ]);
        $orgB->profile->opportunities()->create([
            'title' => 'Org B Job',
            'description' => 'Great role.',
            'opportunity_type' => 'job',
            'employment_type' => 'full_time',
            'work_mode' => 'remote',
            'experience_level' => 'junior',
            'status' => 'open',
        ]);

        $response = $this->getJson("/api/opportunities?organization_id={$orgA->profile->id}");

        $response->assertStatus(200);
        $titles = collect($response->json('data.data'))->pluck('title')->all();
        $this->assertSame(['Org A Job'], $titles);
    }

    private function organizationWithProfile(string $approvalStatus, array $attributes = []): object
    {
        $user = User::factory()->create(['role' => 'organization', 'status' => 'active']);

        $profile = OrganizationProfile::create(array_merge([
            'user_id' => $user->id,
            'organization_name' => 'Test Organization',
            'organization_type' => 'company',
        ], $attributes));
        $profile->approval_status = $approvalStatus;
        $profile->save();

        return (object) ['user' => $user, 'profile' => $profile];
    }
}
