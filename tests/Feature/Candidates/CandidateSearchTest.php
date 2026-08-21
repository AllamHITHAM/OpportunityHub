<?php

namespace Tests\Feature\Candidates;

use App\Models\Opportunity;
use App\Models\OrganizationProfile;
use App\Models\Skill;
use App\Models\StudentProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Phase 8B-3: `GET /organization/candidates` -- Flow B's discovery step.
 * Deliberately filter/search-only over structured `student_profiles`/
 * `student_skills` data; never calls `MatchingService`, never touches
 * `parsed_text` or education documents.
 */
class CandidateSearchTest extends TestCase
{
    use RefreshDatabase;

    public function test_organization_can_search_candidates(): void
    {
        $org = $this->approvedOrganization();
        $this->studentWithProfile(['university' => 'State University']);

        Sanctum::actingAs($org->user);

        $this->getJson('/api/organization/candidates')
            ->assertStatus(200)
            ->assertJsonCount(1, 'data');
    }

    public function test_student_is_denied(): void
    {
        $student = $this->studentWithProfile();
        Sanctum::actingAs($student->user);

        $this->getJson('/api/organization/candidates')->assertStatus(403);
    }

    public function test_admin_is_denied(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'status' => 'active']);
        Sanctum::actingAs($admin);

        $this->getJson('/api/organization/candidates')->assertStatus(403);
    }

    public function test_guest_is_denied(): void
    {
        $this->getJson('/api/organization/candidates')->assertStatus(401);
    }

    public function test_only_active_student_profiles_are_returned(): void
    {
        $org = $this->approvedOrganization();
        $this->studentWithProfile(['university' => 'Active U']);
        $this->studentWithProfile(['university' => 'Suspended U'], userStatus: 'suspended');
        $this->studentWithProfile(['university' => 'Pending U'], userStatus: 'pending');

        Sanctum::actingAs($org->user);

        $response = $this->getJson('/api/organization/candidates');

        $response->assertStatus(200)->assertJsonCount(1, 'data');
        $this->assertSame('Active U', $response->json('data.0.university'));
    }

    public function test_search_by_name(): void
    {
        $org = $this->approvedOrganization();
        $this->studentWithProfile([], name: 'Omar Hassan');
        $this->studentWithProfile([], name: 'Leem Khaled');

        Sanctum::actingAs($org->user);

        $response = $this->getJson('/api/organization/candidates?name=Omar');

        $response->assertStatus(200)->assertJsonCount(1, 'data');
        $this->assertSame('Omar Hassan', $response->json('data.0.name'));
    }

    public function test_filter_by_major(): void
    {
        $org = $this->approvedOrganization();
        $this->studentWithProfile(['major' => 'Computer Science']);
        $this->studentWithProfile(['major' => 'Civil Engineering']);

        Sanctum::actingAs($org->user);

        $response = $this->getJson('/api/organization/candidates?major=Computer');

        $response->assertStatus(200)->assertJsonCount(1, 'data');
        $this->assertSame('Computer Science', $response->json('data.0.major'));
    }

    public function test_filter_by_university(): void
    {
        $org = $this->approvedOrganization();
        $this->studentWithProfile(['university' => 'State University']);
        $this->studentWithProfile(['university' => 'Tech Institute']);

        Sanctum::actingAs($org->user);

        $response = $this->getJson('/api/organization/candidates?university=Tech');

        $response->assertStatus(200)->assertJsonCount(1, 'data');
        $this->assertSame('Tech Institute', $response->json('data.0.university'));
    }

    public function test_filter_by_graduation_year(): void
    {
        $org = $this->approvedOrganization();
        $this->studentWithProfile(['graduation_year' => 2026]);
        $this->studentWithProfile(['graduation_year' => 2027]);

        Sanctum::actingAs($org->user);

        $response = $this->getJson('/api/organization/candidates?graduation_year=2026');

        $response->assertStatus(200)->assertJsonCount(1, 'data');
        $this->assertSame(2026, $response->json('data.0.graduation_year'));
    }

    public function test_filter_by_skill(): void
    {
        $org = $this->approvedOrganization();
        $php = Skill::create(['name' => 'PHP', 'category' => 'Programming']);
        $react = Skill::create(['name' => 'React', 'category' => 'Programming']);

        $withPhp = $this->studentWithProfile([], name: 'Has PHP');
        $withPhp->studentSkills()->create(['skill_id' => $php->id, 'level' => 'intermediate']);

        $withReact = $this->studentWithProfile([], name: 'Has React');
        $withReact->studentSkills()->create(['skill_id' => $react->id, 'level' => 'intermediate']);

        Sanctum::actingAs($org->user);

        $response = $this->getJson('/api/organization/candidates?skill=PHP');

        $response->assertStatus(200)->assertJsonCount(1, 'data');
        $this->assertSame('Has PHP', $response->json('data.0.name'));
    }

    public function test_response_only_contains_safe_fields(): void
    {
        $org = $this->approvedOrganization();
        $skill = Skill::create(['name' => 'PHP', 'category' => 'Programming']);
        $student = $this->studentWithProfile([
            'phone' => '555-1234',
            'bio' => 'A secret bio.',
            'profile_image' => 'images/secret.png',
        ], name: 'Jane Student');
        $student->studentSkills()->create(['skill_id' => $skill->id, 'level' => 'intermediate', 'source' => 'manual']);

        Sanctum::actingAs($org->user);

        $response = $this->getJson('/api/organization/candidates');
        $response->assertStatus(200);

        $candidate = $response->json('data.0');

        $this->assertSame(
            ['id', 'name', 'university', 'major', 'graduation_year', 'education_verification_status', 'skills'],
            array_keys($candidate),
        );
        $this->assertSame('manual', $candidate['skills'][0]['source']);
        $this->assertSame(['name', 'source'], array_keys($candidate['skills'][0]));
    }

    public function test_no_parsed_cv_text_or_education_document_path_leaks(): void
    {
        $org = $this->approvedOrganization();
        $student = $this->studentWithProfile();
        $student->cvs()->create([
            'title' => 'My CV',
            'file_path' => 'cvs/secret.pdf',
            'parsed_text' => 'This is the raw extracted CV text that must never leak.',
        ]);
        $student->educationVerification()->create([
            'institution_name' => 'State University',
            'degree_or_program' => 'BSc Computer Science',
            'document_path' => 'education-verifications/secret-document.pdf',
            'status' => 'pending',
            'submitted_at' => now(),
        ]);

        Sanctum::actingAs($org->user);

        $response = $this->getJson('/api/organization/candidates');
        $response->assertStatus(200);

        $raw = $response->getContent();
        $this->assertStringNotContainsString('raw extracted CV text', $raw);
        $this->assertStringNotContainsString('secret-document.pdf', $raw);
        $this->assertStringNotContainsString('secret.pdf', $raw);
        $this->assertSame('pending', $response->json('data.0.education_verification_status'));
    }

    public function test_opportunity_specific_search_is_scoped_to_the_owning_organization(): void
    {
        $orgA = $this->approvedOrganization();
        $orgB = $this->approvedOrganization();
        $opportunityB = $this->opportunityFor($orgB);
        $this->studentWithProfile();

        Sanctum::actingAs($orgA->user);

        $this->getJson("/api/organization/candidates?opportunity_id={$opportunityB->id}")
            ->assertStatus(404)
            ->assertJsonPath('message', 'Opportunity not found');
    }

    public function test_opportunity_specific_search_flags_already_applied_and_already_invited(): void
    {
        $org = $this->approvedOrganization();
        $opportunity = $this->opportunityFor($org);

        $applied = $this->studentWithProfile([], name: 'Applied Student');
        $cv = $applied->cvs()->create(['title' => 'CV', 'file_path' => 'cvs/a.pdf']);
        $opportunity->applications()->create(['student_id' => $applied->id, 'cv_id' => $cv->id]);

        $invited = $this->studentWithProfile([], name: 'Invited Student');
        $opportunity->invitations()->create(['student_id' => $invited->id]);

        $neither = $this->studentWithProfile([], name: 'Fresh Student');

        Sanctum::actingAs($org->user);

        $response = $this->getJson("/api/organization/candidates?opportunity_id={$opportunity->id}");
        $response->assertStatus(200);

        $byName = collect($response->json('data'))->keyBy('name');
        $this->assertTrue($byName['Applied Student']['already_applied']);
        $this->assertFalse($byName['Applied Student']['already_invited']);
        $this->assertTrue($byName['Invited Student']['already_invited']);
        $this->assertFalse($byName['Invited Student']['already_applied']);
        $this->assertFalse($byName['Fresh Student']['already_applied']);
        $this->assertFalse($byName['Fresh Student']['already_invited']);
    }

    // -----------------------------------------------------------------
    // Phase 8B-3.2: opportunity-specific eligibility filtering.
    // -----------------------------------------------------------------

    public function test_opportunity_specific_search_only_returns_eligible_majors(): void
    {
        $org = $this->approvedOrganization();
        $opportunity = $this->opportunityWithMajors($org, ['Computer Engineering', 'Computer Science', 'Software Engineering']);

        $this->studentWithProfile(['major' => 'Computer Science'], name: 'Eligible Student');
        $this->studentWithProfile(['major' => 'Fine Arts'], name: 'Ineligible Student');

        Sanctum::actingAs($org->user);

        $response = $this->getJson("/api/organization/candidates?opportunity_id={$opportunity->id}");

        $response->assertStatus(200)->assertJsonCount(1, 'data');
        $this->assertSame('Eligible Student', $response->json('data.0.name'));
    }

    public function test_opportunity_specific_search_accepts_any_of_multiple_eligible_majors(): void
    {
        $org = $this->approvedOrganization();
        $opportunity = $this->opportunityWithMajors($org, ['Computer Engineering', 'Computer Science', 'Software Engineering']);

        $this->studentWithProfile(['major' => 'Computer Engineering'], name: 'Student A');
        $this->studentWithProfile(['major' => 'Computer Science'], name: 'Student B');
        $this->studentWithProfile(['major' => 'Software Engineering'], name: 'Student C');

        Sanctum::actingAs($org->user);

        $response = $this->getJson("/api/organization/candidates?opportunity_id={$opportunity->id}");

        $response->assertStatus(200)->assertJsonCount(3, 'data');
    }

    public function test_opportunity_specific_search_falls_back_to_legacy_field_of_study(): void
    {
        $org = $this->approvedOrganization();
        $opportunity = $this->opportunityFor($org, ['field_of_study' => 'Civil Engineering']);

        $this->studentWithProfile(['major' => 'civil engineering'], name: 'Matching Student');
        $this->studentWithProfile(['major' => 'Fine Arts'], name: 'Ineligible Student');

        Sanctum::actingAs($org->user);

        $response = $this->getJson("/api/organization/candidates?opportunity_id={$opportunity->id}");

        $response->assertStatus(200)->assertJsonCount(1, 'data');
        $this->assertSame('Matching Student', $response->json('data.0.name'));
    }

    public function test_general_search_ignores_opportunity_eligibility(): void
    {
        $org = $this->approvedOrganization();
        $this->opportunityWithMajors($org, ['Computer Science']);
        $this->studentWithProfile(['major' => 'Fine Arts'], name: 'Unrelated Major Student');

        Sanctum::actingAs($org->user);

        // No opportunity_id -- the general search, unaffected by any
        // Opportunity's eligibility restrictions.
        $response = $this->getJson('/api/organization/candidates');

        $response->assertStatus(200)->assertJsonCount(1, 'data');
        $this->assertSame('Unrelated Major Student', $response->json('data.0.name'));
    }

    // -----------------------------------------------------------------
    // Helper for the eligibility tests above.
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

    // -----------------------------------------------------------------
    // Helpers -- mirror ApplicationRankingTest's own conventions.
    // -----------------------------------------------------------------

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
