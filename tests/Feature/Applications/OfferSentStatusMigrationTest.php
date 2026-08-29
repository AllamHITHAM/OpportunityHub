<?php

namespace Tests\Feature\Applications;

use App\Models\Application;
use App\Models\Opportunity;
use App\Models\OrganizationProfile;
use App\Models\StudentProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Phase 6C-0: exercises the
 * `2026_08_10_134012_add_offer_sent_status_to_applications_table` migration's
 * rollback/re-run path directly. `ApplicationStatusMigrationTest` already
 * covers `offer_sent` being a valid, correctly-serializing status going
 * forward -- this file is specifically about what happens when the
 * migration itself is rolled back and re-applied, which needs
 * `DatabaseMigrations` (not `RefreshDatabase`) to roll a single migration
 * back and forward within one test, the same way `AssessmentMigrationTest`
 * does for its own migrations.
 *
 * `--step` rolls back the N most recently applied migrations regardless of
 * which table they touch, so this file's own step count still needs
 * recalculating whenever a later phase adds a new migration on top --
 * exactly the same fragility `AssessmentMigrationTest` already has, contrary
 * to what an earlier version of this comment claimed. As of Phase 8B-1,
 * `2026_08_10_142102_create_offers_table`,
 * `2026_08_11_090000_add_assessment_and_offer_types_to_notifications_table`,
 * `2026_08_18_090000_add_parsed_text_to_cvs_table`,
 * `2026_08_18_100000_create_skill_suggestions_table`,
 * `2026_08_18_100100_add_source_to_student_skills_table`,
 * `2026_08_18_100200_create_cv_skill_evidence_table`, and
 * `2026_08_19_100000_create_education_verifications_table` all sit on top of
 * this migration; as of Phase 8B-3, one more
 * (`2026_08_20_100000_create_invitations_table`) sits on top of those; as
 * of Phase 8B-3.2, one more
 * (`2026_08_20_110000_create_opportunity_eligible_majors_table`) sits on
 * top of that; as of Phase Final-QA-1, one more
 * (`2026_08_20_164118_add_contact_phone_to_interviews_table`) sits on top
 * of that, so `--step => 11` was required to reach past all eleven and
 * back to this migration's own effect (dropping `offers`/widening
 * `notifications.type`/dropping `cvs.parsed_text`/dropping
 * `skill_suggestions`/dropping `student_skills.source`/dropping
 * `cv_skill_evidence`/dropping `education_verifications`/dropping
 * `invitations`/dropping `opportunity_eligible_majors`/dropping
 * `interviews.contact_phone` along the way is harmless for every assertion
 * in this file, which only ever touches `applications`); as of Phase
 * 10A.2, two more
 * (`2026_08_25_090000_add_display_mode_and_result_release_to_quizzes_table`,
 * `2026_08_25_090100_add_result_released_at_to_assessments_table`) sit on
 * top of that; as of Phase 10A.3, one more
 * (`2026_08_26_090000_remove_unique_constraint_from_assessments_application_id`)
 * sits on top of that; as of Phase 10A.4A, one more
 * (`2026_08_27_090000_add_decision_release_fields_to_assessments_table`)
 * sits on top of that; as of Phase 10A.4B, two more
 * (`2026_08_28_090000_add_recruitment_process_to_opportunities_table`,
 * `2026_08_28_090100_add_shared_quiz_template_support`) sit on top of that;
 * as of the Phase 10A.4B addendum, two more
 * (`2026_08_29_090000_add_availability_policy_to_quizzes_table`,
 * `2026_08_29_090100_add_availability_dates_to_assessments_table`) sit on
 * top of that; as of Phase O8.2, three more
 * (`2026_08_29_090200_create_locations_table`,
 * `2026_08_29_090300_create_student_available_locations_table`,
 * `2026_08_29_090400_add_location_id_to_opportunities_table`) sit on top of
 * that; as of the Student Location Profile Patch, one more
 * (`2026_08_29_090500_add_current_location_id_to_student_profiles_table`)
 * sits on top of that; as of the Recommendation Accuracy Patch, one more
 * (`2026_08_29_090600_create_location_aliases_table`) sits on top of that;
 * as of the Candidate Opportunity Preferences patch, one more
 * (`2026_08_30_090000_add_interested_in_to_student_profiles_table`) sits on
 * top of that; as of the Organization Candidate Profile Enrichment +
 * Messaging MVP phase, three more
 * (`2026_08_31_090000_create_conversations_table`,
 * `2026_08_31_090100_create_messages_table`,
 * `2026_08_31_090200_add_message_type_to_notifications_table`) sit on top
 * of that; as of the Organization Public Profile phase, two more
 * (`2026_09_01_090000_add_location_id_to_organization_profiles_table`,
 * `2026_09_01_090100_create_organization_posts_table`) sit on top of
 * that; as of the Company Profile Polish phase, one more
 * (`2026_09_02_090000_add_image_path_to_organization_posts_table`) sits
 * on top of that; as of the Closed Opportunities Scalability Polish
 * phase, one more
 * (`2026_09_03_090000_add_closed_at_to_opportunities_table`) sits on top
 * of that, so `--step => 32` is now required.
 */
class OfferSentStatusMigrationTest extends TestCase
{
    use DatabaseMigrations;

    public function test_offer_sent_is_a_valid_status_after_migrating(): void
    {
        $applicationId = $this->seedApplication();

        DB::table('applications')->where('id', $applicationId)->update(['status' => 'offer_sent']);

        $this->assertDatabaseHas('applications', [
            'id' => $applicationId,
            'status' => 'offer_sent',
        ]);
    }

    public function test_every_pre_existing_status_remains_valid_after_migrating(): void
    {
        $statuses = [
            'pending',
            'reviewed',
            'shortlisted',
            'in_assessment',
            'interview_scheduled',
            'accepted',
            'rejected',
            'withdrawn',
        ];

        foreach ($statuses as $status) {
            $applicationId = $this->seedApplication();
            DB::table('applications')->where('id', $applicationId)->update(['status' => $status]);

            $this->assertDatabaseHas('applications', [
                'id' => $applicationId,
                'status' => $status,
            ]);
        }
    }

    public function test_rollback_converts_an_offer_sent_row_to_in_assessment(): void
    {
        $applicationId = $this->seedApplication();
        DB::table('applications')->where('id', $applicationId)->update(['status' => 'offer_sent']);

        Artisan::call('migrate:rollback', ['--step' => 32]);

        $this->assertSame(
            'in_assessment',
            DB::table('applications')->where('id', $applicationId)->value('status'),
        );
    }

    public function test_rollback_leaves_every_other_status_untouched(): void
    {
        $pendingId = $this->seedApplication();
        $shortlistedId = $this->seedApplication();
        DB::table('applications')->where('id', $shortlistedId)->update(['status' => 'shortlisted']);
        $acceptedId = $this->seedApplication();
        DB::table('applications')->where('id', $acceptedId)->update(['status' => 'accepted']);
        $rejectedId = $this->seedApplication();
        DB::table('applications')->where('id', $rejectedId)->update(['status' => 'rejected']);
        $interviewScheduledId = $this->seedApplication();
        DB::table('applications')->where('id', $interviewScheduledId)->update(['status' => 'interview_scheduled']);

        Artisan::call('migrate:rollback', ['--step' => 32]);

        $this->assertSame('pending', DB::table('applications')->where('id', $pendingId)->value('status'));
        $this->assertSame('shortlisted', DB::table('applications')->where('id', $shortlistedId)->value('status'));
        $this->assertSame('accepted', DB::table('applications')->where('id', $acceptedId)->value('status'));
        $this->assertSame('rejected', DB::table('applications')->where('id', $rejectedId)->value('status'));
        $this->assertSame(
            'interview_scheduled',
            DB::table('applications')->where('id', $interviewScheduledId)->value('status'),
        );
    }

    public function test_rollback_does_not_lose_unrelated_application_data(): void
    {
        $applicationId = $this->seedApplication();
        DB::table('applications')->where('id', $applicationId)->update([
            'status' => 'offer_sent',
            'cover_letter' => 'A very specific cover letter.',
        ]);

        Artisan::call('migrate:rollback', ['--step' => 32]);

        $row = DB::table('applications')->where('id', $applicationId)->first();
        $this->assertSame('A very specific cover letter.', $row->cover_letter);
        $this->assertSame('in_assessment', $row->status);
    }

    public function test_rollback_then_remigrate_restores_offer_sent_validity(): void
    {
        $applicationId = $this->seedApplication();

        Artisan::call('migrate:rollback', ['--step' => 32]);
        Artisan::call('migrate');

        DB::table('applications')->where('id', $applicationId)->update(['status' => 'offer_sent']);

        $this->assertDatabaseHas('applications', [
            'id' => $applicationId,
            'status' => 'offer_sent',
        ]);
    }

    public function test_rollback_removes_offer_sent_from_the_enum(): void
    {
        Artisan::call('migrate:rollback', ['--step' => 32]);

        $applicationId = $this->seedApplication();

        $this->expectException(\Illuminate\Database\QueryException::class);

        DB::table('applications')->where('id', $applicationId)->update(['status' => 'offer_sent']);
    }

    private function seedApplication(): int
    {
        $organizationUser = User::factory()->create([
            'role' => 'organization',
            'status' => 'active',
        ]);

        $organizationProfile = OrganizationProfile::create([
            'user_id' => $organizationUser->id,
            'organization_name' => 'Hiring Co',
            'organization_type' => 'company',
        ]);

        $opportunity = Opportunity::create([
            'organization_id' => $organizationProfile->id,
            'title' => 'Backend Developer',
            'description' => 'Great role.',
            'opportunity_type' => 'job',
            'employment_type' => 'full_time',
            'work_mode' => 'remote',
            'experience_level' => 'junior',
            'status' => 'open',
        ]);

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

        return $application->id;
    }
}
