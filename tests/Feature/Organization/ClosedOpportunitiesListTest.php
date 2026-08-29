<?php

namespace Tests\Feature\Organization;

use App\Models\Application;
use App\Models\Opportunity;
use App\Models\OrganizationProfile;
use App\Models\StudentProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Closed Opportunities Scalability Polish -- the real, backend-paginated/
 * filtered/sorted `GET /organization/opportunities/closed` endpoint the
 * Company Profile's "Closed Opportunities" section now calls instead of
 * fetching every status and filtering client-side.
 */
class ClosedOpportunitiesListTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_closed_opportunities_are_returned_never_open_or_draft(): void
    {
        $org = $this->approvedOrganization();
        $closed = $this->closedOpportunity($org->profile, now()->subDay());
        $org->profile->opportunities()->create($this->opportunityPayload(['status' => 'open']));
        $org->profile->opportunities()->create($this->opportunityPayload(['status' => 'draft']));

        Sanctum::actingAs($org->user);

        $response = $this->getJson('/api/organization/opportunities/closed');

        $response->assertStatus(200);
        $ids = collect($response->json('data.data'))->pluck('id');
        $this->assertEquals([$closed->id], $ids->all());
        $this->assertSame(1, $response->json('total_closed'));
    }

    public function test_another_organizations_closed_opportunities_are_never_returned(): void
    {
        $orgA = $this->approvedOrganization();
        $orgB = $this->approvedOrganization();
        $this->closedOpportunity($orgA->profile, now()->subDay());
        $closedB = $this->closedOpportunity($orgB->profile, now()->subDay());

        Sanctum::actingAs($orgB->user);

        $response = $this->getJson('/api/organization/opportunities/closed');

        $ids = collect($response->json('data.data'))->pluck('id');
        $this->assertEquals([$closedB->id], $ids->all());
        $this->assertSame(1, $response->json('total_closed'));
    }

    public function test_a_student_cannot_access_the_closed_opportunities_endpoint(): void
    {
        $student = User::factory()->create(['role' => 'student', 'status' => 'active']);
        StudentProfile::create(['user_id' => $student->id]);
        Sanctum::actingAs($student);

        $this->getJson('/api/organization/opportunities/closed')->assertStatus(403);
    }

    public function test_an_unauthenticated_request_is_rejected(): void
    {
        $this->getJson('/api/organization/opportunities/closed')->assertStatus(401);
    }

    public function test_date_preset_last_30_days(): void
    {
        $org = $this->approvedOrganization();
        $recent = $this->closedOpportunity($org->profile, now()->subDays(5));
        $old = $this->closedOpportunity($org->profile, now()->subDays(45));
        Sanctum::actingAs($org->user);

        $response = $this->getJson('/api/organization/opportunities/closed?date_preset=last_30_days');

        $ids = collect($response->json('data.data'))->pluck('id');
        $this->assertTrue($ids->contains($recent->id));
        $this->assertFalse($ids->contains($old->id));
        // total_closed is always the real unfiltered total.
        $this->assertSame(2, $response->json('total_closed'));
    }

    public function test_date_preset_last_3_months(): void
    {
        $org = $this->approvedOrganization();
        $withinWindow = $this->closedOpportunity($org->profile, now()->subMonths(2));
        $outsideWindow = $this->closedOpportunity($org->profile, now()->subMonths(4));
        Sanctum::actingAs($org->user);

        $response = $this->getJson('/api/organization/opportunities/closed?date_preset=last_3_months');

        $ids = collect($response->json('data.data'))->pluck('id');
        $this->assertTrue($ids->contains($withinWindow->id));
        $this->assertFalse($ids->contains($outsideWindow->id));
    }

    public function test_date_preset_last_6_months(): void
    {
        $org = $this->approvedOrganization();
        $withinWindow = $this->closedOpportunity($org->profile, now()->subMonths(5));
        $outsideWindow = $this->closedOpportunity($org->profile, now()->subMonths(7));
        Sanctum::actingAs($org->user);

        $response = $this->getJson('/api/organization/opportunities/closed?date_preset=last_6_months');

        $ids = collect($response->json('data.data'))->pluck('id');
        $this->assertTrue($ids->contains($withinWindow->id));
        $this->assertFalse($ids->contains($outsideWindow->id));
    }

    public function test_date_preset_this_year(): void
    {
        $org = $this->approvedOrganization();
        $thisYear = $this->closedOpportunity($org->profile, now()->startOfYear()->addDay());
        $lastYear = $this->closedOpportunity($org->profile, now()->subYear());
        Sanctum::actingAs($org->user);

        $response = $this->getJson('/api/organization/opportunities/closed?date_preset=this_year');

        $ids = collect($response->json('data.data'))->pluck('id');
        $this->assertTrue($ids->contains($thisYear->id));
        $this->assertFalse($ids->contains($lastYear->id));
    }

    public function test_date_preset_older_includes_pre_this_year_and_unknown_legacy_dates(): void
    {
        $org = $this->approvedOrganization();
        $lastYear = $this->closedOpportunity($org->profile, now()->subYear());
        $thisYear = $this->closedOpportunity($org->profile, now());
        $legacyUnknown = $org->profile->opportunities()->create($this->opportunityPayload([
            'status' => 'closed',
            'closed_at' => null,
        ]));
        Sanctum::actingAs($org->user);

        $response = $this->getJson('/api/organization/opportunities/closed?date_preset=older');

        $ids = collect($response->json('data.data'))->pluck('id');
        $this->assertTrue($ids->contains($lastYear->id));
        $this->assertTrue($ids->contains($legacyUnknown->id));
        $this->assertFalse($ids->contains($thisYear->id));
    }

    public function test_date_preset_all_includes_unknown_legacy_dates(): void
    {
        $org = $this->approvedOrganization();
        $known = $this->closedOpportunity($org->profile, now()->subDay());
        $legacyUnknown = $org->profile->opportunities()->create($this->opportunityPayload([
            'status' => 'closed',
            'closed_at' => null,
        ]));
        Sanctum::actingAs($org->user);

        $response = $this->getJson('/api/organization/opportunities/closed?date_preset=all');

        $ids = collect($response->json('data.data'))->pluck('id');
        $this->assertTrue($ids->contains($known->id));
        $this->assertTrue($ids->contains($legacyUnknown->id));
    }

    public function test_unknown_legacy_dates_are_excluded_from_every_bounded_preset_except_all_and_older(): void
    {
        $org = $this->approvedOrganization();
        $org->profile->opportunities()->create($this->opportunityPayload([
            'status' => 'closed',
            'closed_at' => null,
        ]));
        Sanctum::actingAs($org->user);

        foreach (['last_30_days', 'last_3_months', 'last_6_months', 'this_year'] as $preset) {
            $response = $this->getJson("/api/organization/opportunities/closed?date_preset={$preset}");
            $this->assertSame(0, $response->json('data.total'), "preset={$preset} should exclude a null closed_at");
        }
    }

    public function test_custom_date_range_filters_against_closed_at_never_created_at(): void
    {
        $org = $this->approvedOrganization();
        $inRange = $this->closedOpportunity($org->profile, now()->subDays(10));
        $outOfRange = $this->closedOpportunity($org->profile, now()->subDays(60));
        Sanctum::actingAs($org->user);

        $response = $this->getJson(
            '/api/organization/opportunities/closed'
            .'?closed_from='.now()->subDays(20)->toDateString()
            .'&closed_to='.now()->toDateString(),
        );

        $ids = collect($response->json('data.data'))->pluck('id');
        $this->assertTrue($ids->contains($inRange->id));
        $this->assertFalse($ids->contains($outOfRange->id));
    }

    public function test_custom_date_range_takes_priority_over_a_simultaneously_sent_preset(): void
    {
        $org = $this->approvedOrganization();
        $recent = $this->closedOpportunity($org->profile, now()->subDays(2));
        Sanctum::actingAs($org->user);

        // A `last_30_days` preset would include this row too, but the
        // explicit custom range (deliberately excluding it) must win.
        $response = $this->getJson(
            '/api/organization/opportunities/closed?date_preset=last_30_days'
            .'&closed_from='.now()->subDays(10)->toDateString()
            .'&closed_to='.now()->subDays(5)->toDateString(),
        );

        $this->assertSame(0, $response->json('data.total'));
    }

    public function test_a_closed_from_with_no_closed_to_is_an_open_ended_lower_bound(): void
    {
        $org = $this->approvedOrganization();
        $recent = $this->closedOpportunity($org->profile, now()->subDays(2));
        $old = $this->closedOpportunity($org->profile, now()->subDays(60));
        Sanctum::actingAs($org->user);

        $response = $this->getJson(
            '/api/organization/opportunities/closed?closed_from='.now()->subDays(10)->toDateString(),
        );

        $ids = collect($response->json('data.data'))->pluck('id');
        $this->assertTrue($ids->contains($recent->id));
        $this->assertFalse($ids->contains($old->id));
    }

    public function test_sort_newest_first_is_the_default(): void
    {
        $org = $this->approvedOrganization();
        $older = $this->closedOpportunity($org->profile, now()->subDays(10));
        $newer = $this->closedOpportunity($org->profile, now()->subDay());
        Sanctum::actingAs($org->user);

        $response = $this->getJson('/api/organization/opportunities/closed');

        $ids = collect($response->json('data.data'))->pluck('id');
        $this->assertEquals([$newer->id, $older->id], $ids->all());
    }

    public function test_sort_oldest_first(): void
    {
        $org = $this->approvedOrganization();
        $older = $this->closedOpportunity($org->profile, now()->subDays(10));
        $newer = $this->closedOpportunity($org->profile, now()->subDay());
        Sanctum::actingAs($org->user);

        $response = $this->getJson('/api/organization/opportunities/closed?sort=oldest');

        $ids = collect($response->json('data.data'))->pluck('id');
        $this->assertEquals([$older->id, $newer->id], $ids->all());
    }

    public function test_unknown_legacy_dates_sort_last_regardless_of_direction(): void
    {
        $org = $this->approvedOrganization();
        $known = $this->closedOpportunity($org->profile, now()->subDay());
        $legacyUnknown = $org->profile->opportunities()->create($this->opportunityPayload([
            'status' => 'closed',
            'closed_at' => null,
        ]));
        Sanctum::actingAs($org->user);

        $newest = $this->getJson('/api/organization/opportunities/closed?sort=newest');
        $this->assertEquals(
            [$known->id, $legacyUnknown->id],
            collect($newest->json('data.data'))->pluck('id')->all(),
        );

        $oldest = $this->getJson('/api/organization/opportunities/closed?sort=oldest');
        $this->assertEquals(
            [$known->id, $legacyUnknown->id],
            collect($oldest->json('data.data'))->pluck('id')->all(),
        );
    }

    public function test_pagination_page_1_respects_per_page(): void
    {
        $org = $this->approvedOrganization();
        for ($i = 0; $i < 5; $i++) {
            $this->closedOpportunity($org->profile, now()->subDays($i));
        }
        Sanctum::actingAs($org->user);

        $response = $this->getJson('/api/organization/opportunities/closed?per_page=2');

        $response->assertStatus(200);
        $this->assertCount(2, $response->json('data.data'));
        $this->assertSame(1, $response->json('data.current_page'));
        $this->assertSame(3, $response->json('data.last_page'));
        $this->assertSame(5, $response->json('data.total'));
        $this->assertSame(5, $response->json('total_closed'));
    }

    public function test_pagination_next_page_returns_the_remaining_rows(): void
    {
        $org = $this->approvedOrganization();
        $opportunities = [];
        for ($i = 4; $i >= 0; $i--) {
            $opportunities[] = $this->closedOpportunity($org->profile, now()->subDays($i));
        }
        Sanctum::actingAs($org->user);

        $page1 = $this->getJson('/api/organization/opportunities/closed?per_page=2&page=1');
        $page2 = $this->getJson('/api/organization/opportunities/closed?per_page=2&page=2');

        $page1Ids = collect($page1->json('data.data'))->pluck('id');
        $page2Ids = collect($page2->json('data.data'))->pluck('id');
        $this->assertCount(2, $page1Ids);
        $this->assertCount(2, $page2Ids);
        $this->assertEmpty($page1Ids->intersect($page2Ids));
    }

    public function test_filter_and_pagination_combine_correctly(): void
    {
        $org = $this->approvedOrganization();
        for ($i = 0; $i < 4; $i++) {
            $this->closedOpportunity($org->profile, now()->subDays($i));
        }
        // Outside the last_30_days window -- must never appear or count
        // toward the filtered pagination here.
        $this->closedOpportunity($org->profile, now()->subDays(90));
        Sanctum::actingAs($org->user);

        $response = $this->getJson('/api/organization/opportunities/closed?date_preset=last_30_days&per_page=2');

        $this->assertSame(4, $response->json('data.total'));
        $this->assertSame(2, $response->json('data.last_page'));
        $this->assertSame(5, $response->json('total_closed'));
    }

    public function test_can_delete_reflects_real_recruitment_history_on_this_endpoint_too(): void
    {
        $org = $this->approvedOrganization();
        $safe = $this->closedOpportunity($org->profile, now()->subDay());
        $withHistory = $this->closedOpportunity($org->profile, now()->subDay());

        $student = User::factory()->create(['role' => 'student', 'status' => 'active']);
        $studentProfile = StudentProfile::create(['user_id' => $student->id]);
        $cv = $studentProfile->cvs()->create(['title' => 'CV', 'file_path' => 'cvs/x.pdf']);
        Application::create([
            'student_id' => $studentProfile->id,
            'opportunity_id' => $withHistory->id,
            'cv_id' => $cv->id,
        ]);

        Sanctum::actingAs($org->user);

        $response = $this->getJson('/api/organization/opportunities/closed');

        $byId = collect($response->json('data.data'))->keyBy('id');
        $this->assertTrue($byId[$safe->id]['can_delete']);
        $this->assertFalse($byId[$withHistory->id]['can_delete']);
    }

    public function test_a_legacy_null_closed_at_is_returned_as_null_never_a_fabricated_date(): void
    {
        $org = $this->approvedOrganization();
        $org->profile->opportunities()->create($this->opportunityPayload([
            'status' => 'closed',
            'closed_at' => null,
        ]));
        Sanctum::actingAs($org->user);

        $response = $this->getJson('/api/organization/opportunities/closed');

        $this->assertNull($response->json('data.data.0.closed_at'));
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

    private function closedOpportunity(OrganizationProfile $profile, \Illuminate\Support\Carbon $closedAt): Opportunity
    {
        return $profile->opportunities()->create($this->opportunityPayload([
            'status' => 'closed',
            'closed_at' => $closedAt,
        ]));
    }
}
