<?php

namespace Tests\Feature\Assessments;

use App\Models\Application;
use App\Models\Assessment;
use App\Models\Interview;
use App\Models\Opportunity;
use App\Models\OrganizationProfile;
use App\Models\StudentProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AssessmentRelationshipTest extends TestCase
{
    use RefreshDatabase;

    public function test_application_has_one_assessment(): void
    {
        $application = $this->applicationFor($this->opportunityFor($this->approvedOrganization()));

        $assessment = $application->assessment()->create([
            'type' => 'interview',
            'status' => 'scheduled',
            'result' => null,
        ]);

        $this->assertInstanceOf(Assessment::class, $application->assessment);
        $this->assertSame($assessment->id, $application->assessment->id);
        $this->assertSame($application->id, $application->assessment->application_id);
    }

    public function test_application_has_no_assessment_by_default(): void
    {
        $application = $this->applicationFor($this->opportunityFor($this->approvedOrganization()));

        $this->assertNull($application->assessment);
    }

    public function test_assessment_belongs_to_application(): void
    {
        $application = $this->applicationFor($this->opportunityFor($this->approvedOrganization()));

        $assessment = $application->assessment()->create([
            'type' => 'interview',
            'status' => 'scheduled',
            'result' => null,
        ]);

        $this->assertInstanceOf(Application::class, $assessment->application);
        $this->assertSame($application->id, $assessment->application->id);
    }

    public function test_assessment_has_one_interview(): void
    {
        $application = $this->applicationFor($this->opportunityFor($this->approvedOrganization()));

        $assessment = $application->assessment()->create([
            'type' => 'interview',
            'status' => 'scheduled',
            'result' => null,
        ]);

        $interview = $assessment->interview()->create([
            'interview_type' => 'phone',
            'scheduled_at' => now()->addDays(3),
        ]);

        $this->assertInstanceOf(Interview::class, $assessment->interview);
        $this->assertSame($interview->id, $assessment->interview->id);
    }

    public function test_assessment_has_no_interview_by_default(): void
    {
        $application = $this->applicationFor($this->opportunityFor($this->approvedOrganization()));

        $assessment = $application->assessment()->create([
            'type' => 'interview',
            'status' => 'scheduled',
            'result' => null,
        ]);

        $this->assertNull($assessment->interview);
    }

    public function test_interview_belongs_to_assessment(): void
    {
        $application = $this->applicationFor($this->opportunityFor($this->approvedOrganization()));

        $assessment = $application->assessment()->create([
            'type' => 'interview',
            'status' => 'scheduled',
            'result' => null,
        ]);

        $interview = $assessment->interview()->create([
            'interview_type' => 'phone',
            'scheduled_at' => now()->addDays(3),
        ]);

        $this->assertInstanceOf(Assessment::class, $interview->assessment);
        $this->assertSame($assessment->id, $interview->assessment->id);
    }

    /**
     * `Interview` no longer has a direct `application_id` column, so it has
     * no direct, queryable `belongsTo(Application)` *relation* any more --
     * only `assessment`. A same-named `application` accessor exists purely
     * for backward-compatible JSON serialization (see
     * InterviewResponseShapeTest) but is a protected attribute accessor,
     * not an Eloquent relation: it cannot be eager-loaded via `with()`.
     */
    public function test_interview_has_no_queryable_application_relation(): void
    {
        $application = $this->applicationFor($this->opportunityFor($this->approvedOrganization()));
        $assessment = $application->assessment()->create([
            'type' => 'interview',
            'status' => 'scheduled',
            'result' => null,
        ]);
        $assessment->interview()->create([
            'interview_type' => 'phone',
            'scheduled_at' => now()->addDays(3),
        ]);

        $this->expectException(\Illuminate\Database\Eloquent\RelationNotFoundException::class);

        Interview::with('application')->get();
    }

    /**
     * Application::interview() is a read-only convenience accessor through
     * Assessment, matching the ownership chain the task requires: it must
     * not be an incorrect direct relation using the removed
     * `interviews.application_id` column.
     */
    public function test_application_interview_convenience_accessor_reaches_the_interview_through_assessment(): void
    {
        $application = $this->applicationFor($this->opportunityFor($this->approvedOrganization()));

        $assessment = $application->assessment()->create([
            'type' => 'interview',
            'status' => 'scheduled',
            'result' => null,
        ]);

        $interview = $assessment->interview()->create([
            'interview_type' => 'phone',
            'scheduled_at' => now()->addDays(3),
        ]);

        $this->assertInstanceOf(Interview::class, $application->interview);
        $this->assertSame($interview->id, $application->interview->id);
    }

    public function test_deleting_an_application_cascades_to_its_assessment_and_interview(): void
    {
        $application = $this->applicationFor($this->opportunityFor($this->approvedOrganization()));

        $assessment = $application->assessment()->create([
            'type' => 'interview',
            'status' => 'scheduled',
            'result' => null,
        ]);

        $interview = $assessment->interview()->create([
            'interview_type' => 'phone',
            'scheduled_at' => now()->addDays(3),
        ]);

        $application->delete();

        $this->assertDatabaseMissing('assessments', ['id' => $assessment->id]);
        $this->assertDatabaseMissing('interviews', ['id' => $interview->id]);
    }

    public function test_deleting_an_assessment_cascades_to_its_interview(): void
    {
        $application = $this->applicationFor($this->opportunityFor($this->approvedOrganization()));

        $assessment = $application->assessment()->create([
            'type' => 'interview',
            'status' => 'scheduled',
            'result' => null,
        ]);

        $interview = $assessment->interview()->create([
            'interview_type' => 'phone',
            'scheduled_at' => now()->addDays(3),
        ]);

        $assessment->delete();

        $this->assertDatabaseMissing('interviews', ['id' => $interview->id]);
    }

    public function test_an_application_can_have_at_most_one_assessment(): void
    {
        $application = $this->applicationFor($this->opportunityFor($this->approvedOrganization()));

        $application->assessment()->create([
            'type' => 'interview',
            'status' => 'scheduled',
            'result' => null,
        ]);

        $this->expectException(\Illuminate\Database\QueryException::class);

        $application->assessment()->create([
            'type' => 'interview',
            'status' => 'scheduled',
            'result' => null,
        ]);
    }

    public function test_assessment_completed_at_is_cast_to_a_datetime(): void
    {
        $application = $this->applicationFor($this->opportunityFor($this->approvedOrganization()));

        $assessment = $application->assessment()->create([
            'type' => 'interview',
            'status' => 'completed',
            'result' => 'passed',
            'completed_at' => now(),
        ]);

        $this->assertInstanceOf(\Illuminate\Support\Carbon::class, $assessment->completed_at);
    }

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

    private function applicationFor(Opportunity $opportunity, string $status = 'shortlisted'): Application
    {
        $studentUser = User::factory()->create([
            'role' => 'student',
            'status' => 'active',
        ]);

        $studentProfile = StudentProfile::create(['user_id' => $studentUser->id]);

        $cv = $studentProfile->cvs()->create([
            'title' => 'My CV',
            'file_path' => 'cvs/my-cv.pdf',
        ]);

        $application = Application::create([
            'student_id' => $studentProfile->id,
            'opportunity_id' => $opportunity->id,
            'cv_id' => $cv->id,
        ]);

        $application->status = $status;
        $application->save();

        return $application;
    }
}
