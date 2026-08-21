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

    public function test_legacy_field_of_study_fallback_matches(): void
    {
        $opportunity = $this->opportunityFor(['field_of_study' => 'Civil Engineering']);
        $student = $this->studentWithMajor('civil engineering');

        $this->assertTrue($this->eligibility->isStudentEligible($opportunity, $student));
    }

    public function test_legacy_field_of_study_fallback_rejects_a_mismatch(): void
    {
        $opportunity = $this->opportunityFor(['field_of_study' => 'Civil Engineering']);
        $student = $this->studentWithMajor('Fine Arts');

        $this->assertFalse($this->eligibility->isStudentEligible($opportunity, $student));
    }

    public function test_explicit_eligible_majors_take_precedence_over_field_of_study(): void
    {
        // field_of_study says Civil Engineering, but the explicit list
        // (rule A) is the canonical source once it exists -- Fine Arts is
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

    public function test_a_null_student_major_is_rejected_by_legacy_field_of_study(): void
    {
        $opportunity = $this->opportunityFor(['field_of_study' => 'Civil Engineering']);
        $student = $this->studentWithMajor(null);

        $this->assertFalse($this->eligibility->isStudentEligible($opportunity, $student));
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
