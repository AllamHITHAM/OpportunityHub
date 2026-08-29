<?php

namespace Tests\Feature\Candidates;

use App\Models\Application;
use App\Models\Invitation;
use App\Models\Opportunity;
use App\Models\OrganizationProfile;
use App\Models\Skill;
use App\Models\StudentProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Phase O8.1 — `GET /organization/opportunities/{opportunity}/recommended-candidates`:
 * real, eligible candidates for one specific Opportunity, ranked by the
 * exact same `MatchingService` formula an `Application` would later be
 * scored with, computed live (never via a throwaway `Application` row).
 */
class OpportunityRecommendationTest extends TestCase
{
    use RefreshDatabase;

    // -----------------------------------------------------------------
    // 1-3: access control.
    // -----------------------------------------------------------------

    public function test_organization_gets_recommendations_for_its_own_opportunity(): void
    {
        $org = $this->approvedOrganization();
        $opportunity = $this->opportunityFor($org);
        $this->studentWithProfile(['major' => 'Computer Science'], name: 'Jane Student');

        Sanctum::actingAs($org->user);

        $this->getJson("/api/organization/opportunities/{$opportunity->id}/recommended-candidates")
            ->assertStatus(200)
            ->assertJsonCount(1, 'data.candidates');
    }

    public function test_wrong_organization_is_blocked(): void
    {
        $orgA = $this->approvedOrganization();
        $orgB = $this->approvedOrganization();
        $opportunityB = $this->opportunityFor($orgB);

        Sanctum::actingAs($orgA->user);

        $this->getJson("/api/organization/opportunities/{$opportunityB->id}/recommended-candidates")
            ->assertStatus(404)
            ->assertJsonPath('message', 'Opportunity not found');
    }

    public function test_student_is_blocked(): void
    {
        $org = $this->approvedOrganization();
        $opportunity = $this->opportunityFor($org);
        $student = $this->studentWithProfile();

        Sanctum::actingAs($student->user);

        $this->getJson("/api/organization/opportunities/{$opportunity->id}/recommended-candidates")
            ->assertStatus(403);
    }

    public function test_guest_is_blocked(): void
    {
        $org = $this->approvedOrganization();
        $opportunity = $this->opportunityFor($org);

        $this->getJson("/api/organization/opportunities/{$opportunity->id}/recommended-candidates")
            ->assertStatus(401);
    }

    // -----------------------------------------------------------------
    // 4-7: eligibility comes before ranking.
    // -----------------------------------------------------------------

    public function test_eligible_candidates_are_included(): void
    {
        $org = $this->approvedOrganization();
        $opportunity = $this->opportunityWithMajors($org, ['Computer Science']);
        $this->studentWithProfile(['major' => 'Computer Science'], name: 'Eligible Student');

        Sanctum::actingAs($org->user);

        $response = $this->getJson("/api/organization/opportunities/{$opportunity->id}/recommended-candidates");

        $response->assertStatus(200)->assertJsonCount(1, 'data.candidates');
        $this->assertSame('Eligible Student', $response->json('data.candidates.0.name'));
    }

    public function test_explicitly_ineligible_major_is_excluded(): void
    {
        $org = $this->approvedOrganization();
        $opportunity = $this->opportunityWithMajors($org, ['Computer Science']);
        $this->studentWithProfile(['major' => 'Fine Arts'], name: 'Ineligible Student');

        Sanctum::actingAs($org->user);

        $response = $this->getJson("/api/organization/opportunities/{$opportunity->id}/recommended-candidates");

        $response->assertStatus(200)->assertJsonCount(0, 'data.candidates');
    }

    public function test_empty_eligible_majors_permits_all_majors(): void
    {
        $org = $this->approvedOrganization();
        $opportunity = $this->opportunityFor($org, ['field_of_study' => null]);
        $this->studentWithProfile(['major' => 'Fine Arts'], name: 'Any Major Student');

        Sanctum::actingAs($org->user);

        $response = $this->getJson("/api/organization/opportunities/{$opportunity->id}/recommended-candidates");

        $response->assertStatus(200)->assertJsonCount(1, 'data.candidates');
    }

    public function test_field_of_study_alone_does_not_restrict_eligibility(): void
    {
        $org = $this->approvedOrganization();
        // eligible_majors genuinely empty -- field_of_study is descriptive
        // only, never an eligibility gate (mirrors CandidateSearchTest's
        // own regression test for this exact rule).
        $opportunity = $this->opportunityFor($org, ['field_of_study' => 'Civil Engineering']);
        $this->studentWithProfile(['major' => 'Fine Arts'], name: 'Different Major Student');

        Sanctum::actingAs($org->user);

        $response = $this->getJson("/api/organization/opportunities/{$opportunity->id}/recommended-candidates");

        $response->assertStatus(200)->assertJsonCount(1, 'data.candidates');
    }

    // -----------------------------------------------------------------
    // 8-9: ranking.
    // -----------------------------------------------------------------

    public function test_highest_match_is_ordered_first(): void
    {
        $org = $this->approvedOrganization();
        $opportunity = $this->opportunityFor($org, ['field_of_study' => 'Computer Science', 'experience_level' => 'no_experience']);
        $skill = Skill::create(['name' => 'Laravel']);
        $opportunity->opportunitySkills()->create(['skill_id' => $skill->id, 'is_required' => true]);

        $strong = $this->studentWithProfile(['major' => 'Computer Science'], name: 'Strong Match');
        $strong->studentSkills()->create(['skill_id' => $skill->id, 'level' => 'intermediate']);

        $weak = $this->studentWithProfile(['major' => 'Fine Arts'], name: 'Weak Match');

        Sanctum::actingAs($org->user);

        $response = $this->getJson("/api/organization/opportunities/{$opportunity->id}/recommended-candidates");
        $response->assertStatus(200)->assertJsonCount(2, 'data.candidates');

        $names = collect($response->json('data.candidates'))->pluck('name')->all();
        $this->assertSame(['Strong Match', 'Weak Match'], $names);
        $this->assertGreaterThan(
            $response->json('data.candidates.1.match_score'),
            $response->json('data.candidates.0.match_score'),
        );
    }

    public function test_tied_scores_break_deterministically_by_student_id(): void
    {
        $org = $this->approvedOrganization();
        $opportunity = $this->opportunityFor($org, ['field_of_study' => null]);

        $first = $this->studentWithProfile([], name: 'Alpha');
        $second = $this->studentWithProfile([], name: 'Beta');

        Sanctum::actingAs($org->user);

        $response = $this->getJson("/api/organization/opportunities/{$opportunity->id}/recommended-candidates");
        $response->assertStatus(200)->assertJsonCount(2, 'data.candidates');

        // Both score identically (nothing scoreable -> 0.0 for both) --
        // the lower student ID (created first) must sort first, every time.
        $ids = collect($response->json('data.candidates'))->pluck('id')->all();
        $this->assertSame([$first->id, $second->id], $ids);

        // Re-request to prove it is not randomized/order-of-iteration
        // dependent.
        $again = $this->getJson("/api/organization/opportunities/{$opportunity->id}/recommended-candidates");
        $this->assertSame($ids, collect($again->json('data.candidates'))->pluck('id')->all());
    }

    // -----------------------------------------------------------------
    // 10-14: computing recommendations must not mutate anything.
    // -----------------------------------------------------------------

    public function test_recommendations_do_not_create_applications(): void
    {
        $org = $this->approvedOrganization();
        $opportunity = $this->opportunityFor($org);
        $this->studentWithProfile();

        Sanctum::actingAs($org->user);
        $this->getJson("/api/organization/opportunities/{$opportunity->id}/recommended-candidates")->assertStatus(200);

        $this->assertDatabaseCount('applications', 0);
    }

    public function test_recommendations_do_not_create_invitations(): void
    {
        $org = $this->approvedOrganization();
        $opportunity = $this->opportunityFor($org);
        $this->studentWithProfile();

        Sanctum::actingAs($org->user);
        $this->getJson("/api/organization/opportunities/{$opportunity->id}/recommended-candidates")->assertStatus(200);

        $this->assertDatabaseCount('invitations', 0);
    }

    public function test_recommendation_calculation_does_not_mutate_student(): void
    {
        $org = $this->approvedOrganization();
        $opportunity = $this->opportunityFor($org);
        $student = $this->studentWithProfile(['major' => 'Computer Science', 'university' => 'State U']);
        $before = $student->fresh()->getAttributes();

        Sanctum::actingAs($org->user);
        $this->getJson("/api/organization/opportunities/{$opportunity->id}/recommended-candidates")->assertStatus(200);

        $this->assertSame($before, $student->fresh()->getAttributes());
    }

    public function test_recommendation_calculation_does_not_mutate_opportunity(): void
    {
        $org = $this->approvedOrganization();
        $opportunity = $this->opportunityFor($org);
        $this->studentWithProfile();
        $before = $opportunity->fresh()->getAttributes();

        Sanctum::actingAs($org->user);
        $this->getJson("/api/organization/opportunities/{$opportunity->id}/recommended-candidates")->assertStatus(200);

        $this->assertSame($before, $opportunity->fresh()->getAttributes());
    }

    public function test_candidate_a_score_is_independent_from_candidate_b(): void
    {
        $org = $this->approvedOrganization();
        $opportunity = $this->opportunityFor($org, ['field_of_study' => 'Computer Science', 'experience_level' => 'no_experience']);
        $skill = Skill::create(['name' => 'Laravel']);
        $opportunity->opportunitySkills()->create(['skill_id' => $skill->id, 'is_required' => true]);

        $withSkill = $this->studentWithProfile(['major' => 'Computer Science'], name: 'Has Skill');
        $withSkill->studentSkills()->create(['skill_id' => $skill->id, 'level' => 'intermediate']);
        $withoutSkill = $this->studentWithProfile(['major' => 'Computer Science'], name: 'No Skill');

        Sanctum::actingAs($org->user);
        $response = $this->getJson("/api/organization/opportunities/{$opportunity->id}/recommended-candidates");

        $byName = collect($response->json('data.candidates'))->keyBy('name');
        $this->assertEquals(100.0, (float) $byName['Has Skill']['match_score']);
        // Field(100) + experience(100) scoreable, skills unavailable for
        // "No Skill" the same way it would for "Has Skill" if it had none
        // -- but here skills ARE scoreable overall-wide (the opportunity
        // has a defined skill), so "No Skill" genuinely scores lower.
        $this->assertLessThan(100.0, (float) $byName['No Skill']['match_score']);
    }

    // -----------------------------------------------------------------
    // 15-17: relationship state.
    // -----------------------------------------------------------------

    public function test_existing_applicant_is_identified_as_applied(): void
    {
        $org = $this->approvedOrganization();
        $opportunity = $this->opportunityFor($org);
        $applied = $this->studentWithProfile([], name: 'Applied Student');
        $cv = $applied->cvs()->create(['title' => 'CV', 'file_path' => 'cvs/a.pdf']);
        $application = $opportunity->applications()->create(['student_id' => $applied->id, 'cv_id' => $cv->id]);

        Sanctum::actingAs($org->user);
        $response = $this->getJson("/api/organization/opportunities/{$opportunity->id}/recommended-candidates");

        $row = collect($response->json('data.candidates'))->firstWhere('name', 'Applied Student');
        $this->assertTrue($row['already_applied']);
        $this->assertSame($application->id, $row['application_id']);
        $this->assertNull($row['invitation_status']);
    }

    public function test_existing_invitation_is_identified_correctly(): void
    {
        $org = $this->approvedOrganization();
        $opportunity = $this->opportunityFor($org);
        $invited = $this->studentWithProfile([], name: 'Invited Student');
        // 'status' isn't fillable (see Invitation::$fillable) -- set and
        // save it explicitly after creation, the same as the real
        // Student\InvitationController::accept() flow would.
        $invitation = $opportunity->invitations()->create(['student_id' => $invited->id]);
        $invitation->status = 'accepted';
        $invitation->save();

        Sanctum::actingAs($org->user);
        $response = $this->getJson("/api/organization/opportunities/{$opportunity->id}/recommended-candidates");

        $row = collect($response->json('data.candidates'))->firstWhere('name', 'Invited Student');
        $this->assertFalse($row['already_applied']);
        $this->assertSame('accepted', $row['invitation_status']);
    }

    public function test_duplicate_invite_is_still_prevented_by_the_real_invitation_endpoint(): void
    {
        $org = $this->approvedOrganization();
        $opportunity = $this->opportunityFor($org);
        $student = $this->studentWithProfile();
        $opportunity->invitations()->create(['student_id' => $student->id]);

        Sanctum::actingAs($org->user);

        $this->postJson('/api/organization/invitations', [
            'opportunity_id' => $opportunity->id,
            'student_id' => $student->id,
        ])->assertStatus(409);
    }

    // -----------------------------------------------------------------
    // 18: inviting still uses the real, unmodified Invitation endpoint.
    // -----------------------------------------------------------------

    public function test_invite_creates_a_real_invitation_for_the_current_opportunity(): void
    {
        $org = $this->approvedOrganization();
        $opportunity = $this->opportunityFor($org);
        $student = $this->studentWithProfile(['major' => 'Computer Science']);

        Sanctum::actingAs($org->user);

        $this->postJson('/api/organization/invitations', [
            'opportunity_id' => $opportunity->id,
            'student_id' => $student->id,
        ])->assertStatus(201);

        $this->assertDatabaseHas('invitations', [
            'opportunity_id' => $opportunity->id,
            'student_id' => $student->id,
            'status' => 'pending',
        ]);
    }

    // -----------------------------------------------------------------
    // 20: privacy.
    // -----------------------------------------------------------------

    public function test_student_private_data_is_not_leaked(): void
    {
        $org = $this->approvedOrganization();
        $opportunity = $this->opportunityFor($org);
        $student = $this->studentWithProfile([
            'phone' => '555-1234',
            'bio' => 'A public bio.',
            'profile_image' => 'images/secret.png',
        ]);
        $student->cvs()->create([
            'title' => 'My CV',
            'file_path' => 'cvs/secret.pdf',
            'parsed_text' => 'This is the raw extracted CV text that must never leak.',
        ]);

        Sanctum::actingAs($org->user);

        $response = $this->getJson("/api/organization/opportunities/{$opportunity->id}/recommended-candidates");
        $response->assertStatus(200);

        $raw = $response->getContent();
        $this->assertStringNotContainsString('raw extracted CV text', $raw);
        $this->assertStringNotContainsString('secret.pdf', $raw);
        $this->assertStringNotContainsString('555-1234', $raw);
        $this->assertStringNotContainsString('images/secret.png', $raw);

        // Organization Candidate Profile Enrichment: `bio` is now
        // intentionally exposed unconditionally (spec item 4), but
        // `phone`/`email` stay absent here since this Student never
        // applied to this Opportunity -- no `application_id` relationship
        // to gate contact info on (see CandidateController's own doc
        // comment on the same rule).
        $candidate = $response->json('data.candidates.0');
        $this->assertArrayNotHasKey('phone', $candidate);
        $this->assertArrayNotHasKey('email', $candidate);
        $this->assertArrayNotHasKey('profile_image', $candidate);
        $this->assertSame('A public bio.', $candidate['bio']);
        $this->assertNull($candidate['application_id']);
    }

    public function test_another_organizations_data_is_never_included(): void
    {
        $orgA = $this->approvedOrganization();
        $orgB = $this->approvedOrganization();
        $opportunityA = $this->opportunityFor($orgA);
        $opportunityB = $this->opportunityFor($orgB);
        $student = $this->studentWithProfile();
        $opportunityB->applications()->create([
            'student_id' => $student->id,
            'cv_id' => $student->cvs()->create(['title' => 'CV', 'file_path' => 'cvs/a.pdf'])->id,
        ]);

        Sanctum::actingAs($orgA->user);
        $response = $this->getJson("/api/organization/opportunities/{$opportunityA->id}/recommended-candidates");

        // The student's real application is to a DIFFERENT organization's
        // opportunity -- orgA's own recommendation view must never say
        // "already applied" based on it.
        $row = $response->json('data.candidates.0');
        $this->assertFalse($row['already_applied']);
    }

    // -----------------------------------------------------------------
    // Helpers -- mirror CandidateSearchTest's own conventions.
    // -----------------------------------------------------------------

    private function opportunityWithMajors(object $org, array $majors): Opportunity
    {
        $opportunity = $this->opportunityFor($org, ['field_of_study' => null]);

        foreach ($majors as $major) {
            $opportunity->eligibleMajorRecords()->create([
                'major_name' => $major,
                'normalized_major_name' => \App\Support\MajorNormalizer::normalize($major),
            ]);
        }

        return $opportunity->fresh();
    }

    private function approvedOrganization(): object
    {
        $user = User::factory()->create(['role' => 'organization', 'status' => 'active']);

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

    private function studentWithProfile(
        array $overrides = [],
        string $name = 'Jane Student',
        string $userStatus = 'active',
    ): StudentProfile {
        $user = User::factory()->create([
            'role' => 'student',
            'status' => $userStatus,
            'name' => $name,
        ]);

        return StudentProfile::create(array_merge(['user_id' => $user->id], $overrides));
    }
}
