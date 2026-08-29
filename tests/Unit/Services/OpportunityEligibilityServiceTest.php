<?php

namespace Tests\Unit\Services;

use App\Models\Opportunity;
use App\Models\OrganizationProfile;
use App\Models\StudentProfile;
use App\Models\User;
use App\Services\OpportunityEligibilityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 8B-3.2: direct unit coverage for the eligibility rule itself,
 * exercised directly (not through an HTTP endpoint) since it's a pure,
 * side-effect-free query -- mirrors `NotificationServiceTest`'s own
 * "exercise the service directly, still via RefreshDatabase" convention.
 * See `CandidateSearchTest`/`InvitationCreationTest`/
 * `ApplicationEligibilityTest` for the same rule proven through each of
 * its three real call sites.
 */
class OpportunityEligibilityServiceTest extends TestCase
{
    use RefreshDatabase;

    private OpportunityEligibilityService $eligibility;

    protected function setUp(): void
    {
        parent::setUp();

        $this->eligibility = app(OpportunityEligibilityService::class);
    }

    public function test_exact_eligible_major_is_accepted(): void
    {
        $opportunity = $this->opportunityWithMajors(['Computer Science']);
        $student = $this->studentWithMajor('Computer Science');

        $this->assertTrue($this->eligibility->isStudentEligible($opportunity, $student));
    }

    public function test_second_eligible_major_is_accepted(): void
    {
        $opportunity = $this->opportunityWithMajors([
            'Computer Engineering',
            'Computer Science',
            'Software Engineering',
        ]);
        $student = $this->studentWithMajor('Computer Science');

        $this->assertTrue($this->eligibility->isStudentEligible($opportunity, $student));
    }

    public function test_third_eligible_major_is_accepted(): void
    {
        $opportunity = $this->opportunityWithMajors([
            'Computer Engineering',
            'Computer Science',
            'Software Engineering',
        ]);
        $student = $this->studentWithMajor('Software Engineering');

        $this->assertTrue($this->eligibility->isStudentEligible($opportunity, $student));
    }

    public function test_an_ineligible_major_is_rejected(): void
    {
        $opportunity = $this->opportunityWithMajors([
            'Computer Engineering',
            'Computer Science',
            'Software Engineering',
        ]);
        $student = $this->studentWithMajor('Fine Arts');

        $this->assertFalse($this->eligibility->isStudentEligible($opportunity, $student));
    }

    public function test_case_and_whitespace_differences_still_match(): void
    {
        $opportunity = $this->opportunityWithMajors(['Civil Engineering']);
        $student = $this->studentWithMajor('  civil   engineering  ');

        $this->assertTrue($this->eligibility->isStudentEligible($opportunity, $student));
    }

    public function test_field_of_study_alone_never_restricts_eligibility(): void
    {
        // A real regression case: field_of_study is set but eligible_majors
        // is genuinely empty -- the Opportunity must remain unrestricted.
        // field_of_study is descriptive metadata, never an eligibility
        // gate (see OpportunityEligibilityService's own doc comment).
        $opportunity = $this->opportunityFor(['field_of_study' => 'Civil Engineering']);
        $student = $this->studentWithMajor('civil engineering');

        $this->assertTrue($this->eligibility->isStudentEligible($opportunity, $student));
    }

    public function test_field_of_study_does_not_reject_a_completely_different_major(): void
    {
        // Same regression case as above, but with a major that wouldn't
        // even normalize-match field_of_study -- still eligible, because
        // field_of_study is never consulted for eligibility at all.
        $opportunity = $this->opportunityFor(['field_of_study' => 'Civil Engineering']);
        $student = $this->studentWithMajor('Fine Arts');

        $this->assertTrue($this->eligibility->isStudentEligible($opportunity, $student));
    }

    public function test_explicit_eligible_majors_are_the_only_restriction_field_of_study_is_ignored(): void
    {
        // field_of_study says Civil Engineering, but the explicit list
        // (rule A) is the only source of restriction -- Fine Arts is
        // accepted because it's in the explicit list, even though it
        // wouldn't match field_of_study.
        $opportunity = $this->opportunityFor(['field_of_study' => 'Civil Engineering']);
        $opportunity->eligibleMajorRecords()->create([
            'major_name' => 'Fine Arts',
            'normalized_major_name' => 'fine arts',
        ]);
        $student = $this->studentWithMajor('Fine Arts');

        $this->assertTrue($this->eligibility->isStudentEligible($opportunity->fresh(), $student));
    }

    public function test_a_mismatched_major_is_still_rejected_by_an_explicit_list_even_with_field_of_study_set(): void
    {
        // The inverse of the previous test -- an explicit eligible_majors
        // list still restricts normally; only the field_of_study fallback
        // was removed, not rule (A) itself.
        $opportunity = $this->opportunityFor(['field_of_study' => 'Civil Engineering']);
        $opportunity->eligibleMajorRecords()->create([
            'major_name' => 'Fine Arts',
            'normalized_major_name' => 'fine arts',
        ]);
        $student = $this->studentWithMajor('Computer Science');

        $this->assertFalse($this->eligibility->isStudentEligible($opportunity->fresh(), $student));
    }

    public function test_an_opportunity_with_neither_is_unrestricted(): void
    {
        $opportunity = $this->opportunityFor(['field_of_study' => null]);
        $student = $this->studentWithMajor('Anything At All');

        $this->assertTrue($this->eligibility->isStudentEligible($opportunity, $student));
    }

    public function test_an_unrestricted_opportunity_accepts_a_null_student_major(): void
    {
        $opportunity = $this->opportunityFor(['field_of_study' => null]);
        $student = $this->studentWithMajor(null);

        $this->assertTrue($this->eligibility->isStudentEligible($opportunity, $student));
    }

    public function test_a_null_student_major_is_rejected_by_explicit_eligible_majors(): void
    {
        $opportunity = $this->opportunityWithMajors(['Computer Science']);
        $student = $this->studentWithMajor(null);

        $this->assertFalse($this->eligibility->isStudentEligible($opportunity, $student));
    }

    public function test_a_blank_student_major_is_rejected_by_explicit_eligible_majors(): void
    {
        $opportunity = $this->opportunityWithMajors(['Computer Science']);
        $student = $this->studentWithMajor('   ');

        $this->assertFalse($this->eligibility->isStudentEligible($opportunity, $student));
    }

    public function test_a_null_student_major_is_still_eligible_when_only_field_of_study_is_set(): void
    {
        // field_of_study alone (no explicit eligible_majors) never
        // restricts eligibility -- not even for a Student with no major
        // set at all, since there's nothing to be restricted by.
        $opportunity = $this->opportunityFor(['field_of_study' => 'Civil Engineering']);
        $student = $this->studentWithMajor(null);

        $this->assertTrue($this->eligibility->isStudentEligible($opportunity, $student));
    }

    // -----------------------------------------------------------------
    // Helpers.
    // -----------------------------------------------------------------

    private function opportunityFor(array $overrides = []): Opportunity
    {
        $organizationUser = User::factory()->create(['role' => 'organization', 'status' => 'active']);
        $organizationProfile = OrganizationProfile::create([
            'user_id' => $organizationUser->id,
            'organization_name' => 'Hiring Co',
            'organization_type' => 'company',
        ]);
        $organizationProfile->approval_status = 'approved';
        $organizationProfile->save();

        return $organizationProfile->opportunities()->create(array_merge([
            'title' => 'Backend Developer',
            'description' => 'Great role.',
            'opportunity_type' => 'job',
            'employment_type' => 'full_time',
            'work_mode' => 'remote',
            'experience_level' => 'junior',
            'status' => 'open',
        ], $overrides));
    }

    /**
     * @param  list<string>  $majors
     */
    private function opportunityWithMajors(array $majors): Opportunity
    {
        $opportunity = $this->opportunityFor(['field_of_study' => null]);

        foreach ($majors as $major) {
            $opportunity->eligibleMajorRecords()->create([
                'major_name' => $major,
                'normalized_major_name' => \App\Support\MajorNormalizer::normalize($major),
            ]);
        }

        return $opportunity->fresh();
    }

    private function studentWithMajor(?string $major): StudentProfile
    {
        $user = User::factory()->create(['role' => 'student', 'status' => 'active']);

        return StudentProfile::create(['user_id' => $user->id, 'major' => $major]);
    }
}
