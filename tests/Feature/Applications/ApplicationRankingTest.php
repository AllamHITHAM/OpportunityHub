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
 * Phase 8A-1: proves organization-facing applicant lists
 * (`GET /organization/applications`,
 * `GET /organization/opportunities/{opportunity}/applications`) are ranked
 * by `match_score` descending, null (not-yet-calculated) scores always
 * last, `applied_at` ascending as the deterministic tie-breaker within each
 * group -- using only the already-stored `match_score` column. Never
 * calls `MatchingService`, never mutates any Application. Also proves
 * organization visibility of `match_score` (null/zero/numeric) is
 * unchanged, ownership scoping is unaffected by the new ordering, and
 * student-facing ordering is untouched.
 */
class ApplicationRankingTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The exact scenario this phase's own spec prescribes: 92, 75, 75, 10,
     * 0, null, null -- created in a deliberately shuffled insertion order
     * (nulls first, ascending scores) so a passing test proves real
     * ordering is happening, not an accidental match with insertion order.
     * The two `75`s and the two `null`s are each given distinct, out-of-
     * insertion-order `applied_at` timestamps so the `applied_at ASC`
     * tie-breaker is genuinely exercised, not coincidentally satisfied.
     */
    public function test_organization_applications_list_is_ranked_by_score_then_applied_at(): void
    {
        $org = $this->approvedOrganization();
        $opportunity = $this->opportunityFor($org);

        // `appledDaysAgo` is "how many days in the past" -- a *larger*
        // value is *further* in the past, i.e. chronologically *earlier*.
        // "Earlier" below always gets the larger days-ago value.
        $nullLater = $this->applicationWithScore($opportunity, null, appledDaysAgo: 1);
        $nullEarlier = $this->applicationWithScore($opportunity, null, appledDaysAgo: 2);
        $zero = $this->applicationWithScore($opportunity, 0.00, appledDaysAgo: 7);
        $ten = $this->applicationWithScore($opportunity, 10.00, appledDaysAgo: 6);
        $seventyFiveLater = $this->applicationWithScore($opportunity, 75.00, appledDaysAgo: 3);
        $seventyFiveEarlier = $this->applicationWithScore($opportunity, 75.00, appledDaysAgo: 4);
        $ninetyTwo = $this->applicationWithScore($opportunity, 92.00, appledDaysAgo: 5);

        Sanctum::actingAs($org->user);

        $response = $this->getJson('/api/organization/applications');
        $response->assertStatus(200);

        $this->assertSame(
            [
                $ninetyTwo->id,
                $seventyFiveEarlier->id,
                $seventyFiveLater->id,
                $ten->id,
                $zero->id,
                $nullEarlier->id,
                $nullLater->id,
            ],
            array_column($response->json('data'), 'id'),
        );
    }

    public function test_opportunity_applicants_list_is_ranked_the_same_way(): void
    {
        $org = $this->approvedOrganization();
        $opportunity = $this->opportunityFor($org);

        $low = $this->applicationWithScore($opportunity, 20.00, appledDaysAgo: 2);
        $high = $this->applicationWithScore($opportunity, 88.00, appledDaysAgo: 1);
        $null = $this->applicationWithScore($opportunity, null, appledDaysAgo: 3);

        Sanctum::actingAs($org->user);

        $response = $this->getJson("/api/organization/opportunities/{$opportunity->id}/applications");

        $response->assertStatus(200)->assertJsonPath('data', function ($data) use ($high, $low, $null) {
            return array_column($data, 'id') === [$high->id, $low->id, $null->id];
        });
    }

    public function test_ranking_does_not_widen_ownership_scope(): void
    {
        $orgA = $this->approvedOrganization();
        $opportunityA = $this->opportunityFor($orgA);
        $applicationA = $this->applicationWithScore($opportunityA, 90.00);

        $orgB = $this->approvedOrganization();
        $opportunityB = $this->opportunityFor($orgB);
        $this->applicationWithScore($opportunityB, 99.00);

        Sanctum::actingAs($orgA->user);

        $response = $this->getJson('/api/organization/applications');

        $response->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $applicationA->id);
    }

    public function test_listing_applicants_does_not_calculate_or_change_a_null_match_score(): void
    {
        $org = $this->approvedOrganization();
        $opportunity = $this->opportunityFor($org);
        $application = $this->applicationWithScore($opportunity, null);

        Sanctum::actingAs($org->user);

        $this->getJson('/api/organization/applications')->assertStatus(200);
        $this->getJson("/api/organization/opportunities/{$opportunity->id}/applications")->assertStatus(200);

        $this->assertDatabaseHas('applications', [
            'id' => $application->id,
            'match_score' => null,
        ]);
    }

    public function test_student_application_list_ordering_is_unaffected(): void
    {
        $student = $this->studentWithProfileAndCv();

        $opportunityA = $this->openOpportunity(['title' => 'First']);
        $opportunityB = $this->openOpportunity(['title' => 'Second']);
        $opportunityC = $this->openOpportunity(['title' => 'Third']);

        $first = $this->studentApplicationFor($opportunityA, $student);
        $second = $this->studentApplicationFor($opportunityB, $student);
        $third = $this->studentApplicationFor($opportunityC, $student);

        // Deliberately give the *first-created* application the highest
        // score -- if student ordering were ever accidentally changed to
        // rank by match_score too, this would reorder the list and the
        // assertion below would fail.
        $first->match_score = 10.00;
        $first->save();
        $third->match_score = 99.00;
        $third->save();

        Sanctum::actingAs($student->user);

        $response = $this->getJson('/api/student/applications');

        $response->assertStatus(200)->assertJsonPath('data', function ($data) use ($first, $second, $third) {
            return array_column($data, 'id') === [$first->id, $second->id, $third->id];
        });
    }

    // -----------------------------------------------------------------
    // Organization visibility preservation.
    // -----------------------------------------------------------------

    public function test_organization_list_still_returns_null_for_an_uncalculated_score(): void
    {
        $org = $this->approvedOrganization();
        $opportunity = $this->opportunityFor($org);
        $this->applicationWithScore($opportunity, null);

        Sanctum::actingAs($org->user);

        $this->getJson('/api/organization/applications')
            ->assertStatus(200)
            ->assertJsonPath('data.0.match_score', null);
    }

    public function test_organization_list_still_returns_a_numeric_calculated_score(): void
    {
        $org = $this->approvedOrganization();
        $opportunity = $this->opportunityFor($org);
        $this->applicationWithScore($opportunity, 73.25);

        Sanctum::actingAs($org->user);

        $response = $this->getJson('/api/organization/applications');

        $response->assertStatus(200);
        $this->assertEquals(73.25, (float) $response->json('data.0.match_score'));
    }

    public function test_organization_list_still_returns_a_zero_score_correctly(): void
    {
        $org = $this->approvedOrganization();
        $opportunity = $this->opportunityFor($org);
        $this->applicationWithScore($opportunity, 0.00);

        Sanctum::actingAs($org->user);

        $response = $this->getJson('/api/organization/applications');

        $response->assertStatus(200);
        $this->assertEquals(0, (float) $response->json('data.0.match_score'));
    }

    public function test_organization_application_details_still_returns_the_score(): void
    {
        $org = $this->approvedOrganization();
        $opportunity = $this->opportunityFor($org);
        $application = $this->applicationWithScore($opportunity, 55.00);

        Sanctum::actingAs($org->user);

        $response = $this->getJson("/api/organization/applications/{$application->id}");

        $response->assertStatus(200);
        $this->assertEquals(55.00, (float) $response->json('data.match_score'));
    }

    public function test_organization_cannot_view_another_organizations_application_details(): void
    {
        $orgA = $this->approvedOrganization();
        $opportunity = $this->opportunityFor($orgA);
        $application = $this->applicationWithScore($opportunity, 60.00);

        $orgB = $this->approvedOrganization();
        Sanctum::actingAs($orgB->user);

        $response = $this->getJson("/api/organization/applications/{$application->id}");

        $response->assertStatus(404)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Application not found');
    }

    // -----------------------------------------------------------------
    // Helpers -- mirror OrganizationApplicationTest's own conventions.
    // -----------------------------------------------------------------

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

    private function openOpportunity(array $overrides = []): Opportunity
    {
        return $this->opportunityFor($this->approvedOrganization(), $overrides);
    }

    private function studentWithProfileAndCv(): object
    {
        $user = User::factory()->create([
            'role' => 'student',
            'status' => 'active',
        ]);

        $profile = StudentProfile::create(['user_id' => $user->id]);

        $cv = $profile->cvs()->create([
            'title' => 'My CV',
            'file_path' => 'cvs/my-cv.pdf',
        ]);

        return (object) ['user' => $user, 'profile' => $profile, 'cv' => $cv];
    }

    private function studentApplicationFor(Opportunity $opportunity, object $student): Application
    {
        return Application::create([
            'student_id' => $student->profile->id,
            'opportunity_id' => $opportunity->id,
            'cv_id' => $student->cv->id,
        ]);
    }

    /**
     * A fresh student + application for [$opportunity], with `match_score`
     * and `applied_at` forced to exact values -- both fall outside
     * `Application::$fillable`, so they're set directly and saved after
     * creation.
     */
    private function applicationWithScore(
        Opportunity $opportunity,
        ?float $matchScore,
        int $appledDaysAgo = 0,
    ): Application {
        $student = $this->studentWithProfileAndCv();

        $application = Application::create([
            'student_id' => $student->profile->id,
            'opportunity_id' => $opportunity->id,
            'cv_id' => $student->cv->id,
        ]);

        $application->match_score = $matchScore;
        $application->applied_at = now()->subDays($appledDaysAgo);
        $application->save();

        return $application;
    }
}
