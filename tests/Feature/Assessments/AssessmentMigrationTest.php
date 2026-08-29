<?php

namespace Tests\Feature\Assessments;

use App\Models\Application;
use App\Models\Opportunity;
use App\Models\OrganizationProfile;
use App\Models\StudentProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Exercises the two Phase 4A-1 migrations
 * (`..._create_assessments_table` and
 * `..._retarget_interviews_to_assessments`) directly against realistic
 * pre-existing `interviews` rows, by rolling the retarget back to the
 * legacy `interviews.application_id` schema, seeding raw legacy rows, then
 * re-running the migration and inspecting the backfilled result.
 *
 * Uses `DatabaseMigrations` (full `migrate:fresh` + `migrate:rollback` per
 * test, confined to the sqlite `:memory:` testing connection configured in
 * phpunit.xml) instead of `RefreshDatabase`, since this test needs to roll
 * migrations back and forward within a single test method -- something
 * RefreshDatabase's per-test transaction wrapping is not meant for.
 *
 * `--step` rolls back the N most recently applied migrations regardless of
 * which table they touch, so `rollBackRetargetMigrations()`'s and
 * `test_full_rollback_drops_the_assessments_table()`'s own step counts
 * need recalculating whenever a later phase adds a new migration on top --
 * see `OfferSentStatusMigrationTest`'s own doc comment for the identical
 * fragility. As of Phase 8A-6.1, three migrations
 * (`2026_08_18_100000_create_skill_suggestions_table`,
 * `2026_08_18_100100_add_source_to_student_skills_table`,
 * `2026_08_18_100200_create_cv_skill_evidence_table`) sat on top of every
 * migration this file rolls back to/past; as of Phase 8B-1, one more
 * (`2026_08_19_100000_create_education_verifications_table`) sits on top of
 * those; as of Phase 8B-3, one more
 * (`2026_08_20_100000_create_invitations_table`) sits on top of that, so
 * both step counts are five higher than before Phase 8A-6.1; as of Phase
 * 8B-3.2, one more
 * (`2026_08_20_110000_create_opportunity_eligible_majors_table`) sits on
 * top of that, making both step counts six higher; as of Phase Final-QA-1,
 * one more (`2026_08_20_164118_add_contact_phone_to_interviews_table`) sits
 * on top of that, making both step counts seven higher; as of Phase
 * 10A.2, two more
 * (`2026_08_25_090000_add_display_mode_and_result_release_to_quizzes_table`,
 * `2026_08_25_090100_add_result_released_at_to_assessments_table`) sit on
 * top of that, making both step counts nine higher; as of Phase 10A.3, one
 * more
 * (`2026_08_26_090000_remove_unique_constraint_from_assessments_application_id`)
 * sits on top of that, making both step counts ten higher; as of Phase
 * 10A.4A, one more
 * (`2026_08_27_090000_add_decision_release_fields_to_assessments_table`)
 * sits on top of that, making both step counts eleven higher; as of Phase
 * 10A.4B, two more
 * (`2026_08_28_090000_add_recruitment_process_to_opportunities_table`,
 * `2026_08_28_090100_add_shared_quiz_template_support`) sit on top of that,
 * making both step counts thirteen higher (22/23); as of the Phase 10A.4B
 * addendum, two more
 * (`2026_08_29_090000_add_availability_policy_to_quizzes_table`,
 * `2026_08_29_090100_add_availability_dates_to_assessments_table`) sit on
 * top of that, making both step counts fifteen higher (24/25); as of Phase
 * O8.2, three more
 * (`2026_08_29_090200_create_locations_table`,
 * `2026_08_29_090300_create_student_available_locations_table`,
 * `2026_08_29_090400_add_location_id_to_opportunities_table`) sit on top of
 * that, making both step counts eighteen higher (27/28); as of the Student
 * Location Profile Patch, one more
 * (`2026_08_29_090500_add_current_location_id_to_student_profiles_table`)
 * sits on top of that, making both step counts nineteen higher (28/29); as
 * of the Recommendation Accuracy Patch, one more
 * (`2026_08_29_090600_create_location_aliases_table`) sits on top of that,
 * making both step counts twenty higher (29/30); as of the Candidate
 * Opportunity Preferences patch, one more
 * (`2026_08_30_090000_add_interested_in_to_student_profiles_table`) sits on
 * top of that, making both step counts twenty-one higher (30/31); as of the
 * Organization Candidate Profile Enrichment + Messaging MVP phase, three
 * more (`2026_08_31_090000_create_conversations_table`,
 * `2026_08_31_090100_create_messages_table`,
 * `2026_08_31_090200_add_message_type_to_notifications_table`) sit on top
 * of that, making both step counts twenty-four higher (33/34); as of the
 * Organization Public Profile phase, two more
 * (`2026_09_01_090000_add_location_id_to_organization_profiles_table`,
 * `2026_09_01_090100_create_organization_posts_table`) sit on top of
 * that, making both step counts twenty-six higher (35/36); as of the
 * Company Profile Polish phase, one more
 * (`2026_09_02_090000_add_image_path_to_organization_posts_table`) sits
 * on top of that, making both step counts twenty-seven higher (36/37); as
 * of the Closed Opportunities Scalability Polish phase, one more
 * (`2026_09_03_090000_add_closed_at_to_opportunities_table`) sits on top
 * of that, making both step counts twenty-eight higher (37/38).
 */
class AssessmentMigrationTest extends TestCase
{
    use DatabaseMigrations;

    public function test_existing_interview_gets_exactly_one_assessment(): void
    {
        $applicationId = $this->seedApplication();
        $this->rollBackRetargetMigrations();
        $this->insertLegacyInterview($applicationId);

        Artisan::call('migrate');

        $this->assertSame(1, DB::table('assessments')->count());
        $this->assertSame(1, DB::table('interviews')->count());
    }

    public function test_assessment_application_id_matches_the_interviews_previous_application_id(): void
    {
        $applicationId = $this->seedApplication();
        $this->rollBackRetargetMigrations();
        $this->insertLegacyInterview($applicationId);

        Artisan::call('migrate');

        $assessment = DB::table('assessments')->first();
        $this->assertSame($applicationId, $assessment->application_id);
        $this->assertSame('interview', $assessment->type);
    }

    public function test_interview_assessment_id_is_populated(): void
    {
        $applicationId = $this->seedApplication();
        $this->rollBackRetargetMigrations();
        $this->insertLegacyInterview($applicationId);

        Artisan::call('migrate');

        $interview = DB::table('interviews')->first();
        $assessment = DB::table('assessments')->first();

        $this->assertNotNull($interview->assessment_id);
        $this->assertSame($assessment->id, $interview->assessment_id);
        $this->assertObjectNotHasProperty('application_id', $interview);
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function interviewStatusMappings(): array
    {
        return [
            'scheduled -> scheduled' => ['scheduled', 'scheduled'],
            'rescheduled -> scheduled' => ['rescheduled', 'scheduled'],
            'completed -> completed' => ['completed', 'completed'],
            'no_show -> completed' => ['no_show', 'completed'],
            'cancelled -> cancelled' => ['cancelled', 'cancelled'],
        ];
    }

    #[DataProvider('interviewStatusMappings')]
    public function test_interview_state_maps_correctly_to_assessment_state(
        string $interviewStatus,
        string $expectedAssessmentStatus
    ): void {
        $applicationId = $this->seedApplication();
        $this->rollBackRetargetMigrations();
        $this->insertLegacyInterview($applicationId, ['status' => $interviewStatus]);

        Artisan::call('migrate');

        $assessment = DB::table('assessments')->first();
        $this->assertSame($expectedAssessmentStatus, $assessment->status);
    }

    /**
     * @return array<string, array{0: string, 1: string|null}>
     */
    public static function interviewDecisionMappings(): array
    {
        return [
            'passed -> passed' => ['passed', 'passed'],
            'failed -> failed' => ['failed', 'failed'],
            'waiting -> waiting' => ['waiting', 'waiting'],
            'pending -> null' => ['pending', null],
        ];
    }

    #[DataProvider('interviewDecisionMappings')]
    public function test_interview_decision_maps_correctly_to_assessment_result(
        string $interviewDecision,
        ?string $expectedAssessmentResult
    ): void {
        $applicationId = $this->seedApplication();
        $this->rollBackRetargetMigrations();
        $this->insertLegacyInterview($applicationId, ['decision' => $interviewDecision]);

        Artisan::call('migrate');

        $assessment = DB::table('assessments')->first();
        $this->assertSame($expectedAssessmentResult, $assessment->result);
    }

    public function test_interview_completed_at_is_copied_onto_the_assessment(): void
    {
        $applicationId = $this->seedApplication();
        $this->rollBackRetargetMigrations();
        $completedAt = '2026-07-05 12:16:02';
        $this->insertLegacyInterview($applicationId, [
            'status' => 'completed',
            'completed_at' => $completedAt,
        ]);

        Artisan::call('migrate');

        $assessment = DB::table('assessments')->first();
        $this->assertSame($completedAt, $assessment->completed_at);
    }

    public function test_no_duplicate_assessment_is_created_for_one_application(): void
    {
        $applicationOne = $this->seedApplication();
        $applicationTwo = $this->seedApplication();
        $this->rollBackRetargetMigrations();
        $this->insertLegacyInterview($applicationOne);
        $this->insertLegacyInterview($applicationTwo);

        Artisan::call('migrate');

        $this->assertSame(2, DB::table('assessments')->count());
        $this->assertSame(
            1,
            DB::table('assessments')->where('application_id', $applicationOne)->count()
        );
        $this->assertSame(
            1,
            DB::table('assessments')->where('application_id', $applicationTwo)->count()
        );
    }

    public function test_migration_handles_zero_existing_interview_rows(): void
    {
        $this->rollBackRetargetMigrations();

        Artisan::call('migrate');

        $this->assertSame(0, DB::table('assessments')->count());
        $this->assertSame(0, DB::table('interviews')->count());
    }

    public function test_new_unique_constraint_on_assessment_id_is_enforced(): void
    {
        $applicationId = $this->seedApplication();

        $assessmentOne = DB::table('assessments')->insertGetId([
            'application_id' => $applicationId,
            'type' => 'interview',
            'status' => 'scheduled',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('interviews')->insert([
            'assessment_id' => $assessmentOne,
            'interview_type' => 'phone',
            'scheduled_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->expectException(\Illuminate\Database\QueryException::class);

        DB::table('interviews')->insert([
            'assessment_id' => $assessmentOne,
            'interview_type' => 'phone',
            'scheduled_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * As of Phase 10A.3, `assessments.application_id` is deliberately no
     * longer unique -- renamed from
     * `test_new_unique_constraint_on_assessments_application_id_is_enforced`
     * (Phase 4A-1), which asserted the exact opposite of what this
     * migration now guarantees. See
     * `2026_08_26_090000_remove_unique_constraint_from_assessments_application_id`'s
     * own doc comment for why: an application can now accumulate real
     * Assessment history (a completed Quiz followed by a new Interview),
     * so a second row for the same `application_id` must succeed at the
     * database level -- the "at most one *active*" invariant is enforced
     * in `AssessmentService`, not the schema (see
     * `AssessmentServiceActiveAssessmentTest`).
     */
    public function test_assessments_application_id_is_no_longer_unique(): void
    {
        $applicationId = $this->seedApplication();

        $firstId = DB::table('assessments')->insertGetId([
            'application_id' => $applicationId,
            'type' => 'interview',
            'status' => 'completed',
            'completed_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $secondId = DB::table('assessments')->insertGetId([
            'application_id' => $applicationId,
            'type' => 'interview',
            'status' => 'scheduled',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertNotSame($firstId, $secondId);
        $this->assertSame(
            2,
            DB::table('assessments')->where('application_id', $applicationId)->count(),
        );

        // Clean up the duplicate before this test ends: `DatabaseMigrations`
        // automatically rolls every migration back at teardown (see that
        // trait's own `beforeApplicationDestroyed` hook), and this
        // migration's own `down()` re-adds the unique constraint this test
        // just proved is gone -- by design, that's only safe to roll back
        // while `application_id` really is still unique (see the
        // migration's own doc comment on `down()`). Leaving two rows with
        // the same `application_id` in place would make teardown itself
        // fail with the exact violation this test demonstrates, for a
        // reason that has nothing to do with this test's own assertions.
        DB::table('assessments')->where('id', $secondId)->delete();
    }

    /**
     * The unique constraint's replacement -- a plain index -- still exists,
     * so `Application::assessments()`/the active-assessment-invariant
     * lookup in `AssessmentService` aren't full table scans. Asserted
     * directly against the schema rather than indirectly through query
     * behavior, since a missing index would only ever show up as a
     * performance regression, never a functional test failure.
     */
    public function test_assessments_application_id_still_has_a_plain_index(): void
    {
        // `Schema::getIndexes()` (driver-agnostic, unlike `SHOW INDEX FROM`
        // which is MySQL-only and would fail against this test suite's
        // sqlite `:memory:` connection) reports each index's own `unique`
        // flag directly, so this checks both "an index on `application_id`
        // still exists" and "it is not unique" in one pass.
        $indexes = collect(\Illuminate\Support\Facades\Schema::getIndexes('assessments'));

        $applicationIdIndex = $indexes->first(
            fn (array $index) => $index['columns'] === ['application_id']
        );

        $this->assertNotNull($applicationIdIndex, 'Expected an index on assessments.application_id');
        $this->assertFalse($applicationIdIndex['unique']);
    }

    public function test_assessment_id_not_null_is_enforced_after_migration(): void
    {
        $this->expectException(\Illuminate\Database\QueryException::class);

        DB::table('interviews')->insert([
            'assessment_id' => null,
            'interview_type' => 'phone',
            'scheduled_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_assessment_id_foreign_key_rejects_a_nonexistent_assessment(): void
    {
        $this->expectException(\Illuminate\Database\QueryException::class);

        DB::table('interviews')->insert([
            'assessment_id' => 999999,
            'interview_type' => 'phone',
            'scheduled_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_rollback_restores_valid_interviews_application_id_data(): void
    {
        $applicationId = $this->seedApplication();
        $this->rollBackRetargetMigrations();
        $this->insertLegacyInterview($applicationId, [
            'status' => 'completed',
            'decision' => 'passed',
        ]);

        Artisan::call('migrate');

        // Rolls back the nine most recently applied migrations: Phase
        // 8A-5's `add_parsed_text_to_cvs_table`, Phase 7A-1's
        // `add_assessment_and_offer_types_to_notifications_table`,
        // Phase 6C-1's `create_offers_table`, Phase 6C-0's
        // `add_offer_sent_status_to_applications_table`, Phase
        // 6B-3's `create_quiz_attempts_table`, Phase 6B-1's
        // `create_questions_table`/`create_quizzes_table`, and Phase 6B-0's
        // `add_in_assessment_status_to_applications_table` (all unrelated
        // to `interviews`, a no-op for this assertion) and the retarget
        // migration itself. Assessments must still exist immediately after,
        // since retarget's `down()` reads from it.
        Artisan::call('migrate:rollback', ['--step' => 37]);

        $interview = DB::table('interviews')->first();
        $this->assertSame($applicationId, $interview->application_id);
        $this->assertObjectNotHasProperty('assessment_id', $interview);

        $this->assertSame(
            1,
            DB::table('interviews')->where('application_id', $applicationId)->count()
        );
    }

    public function test_full_rollback_drops_the_assessments_table(): void
    {
        $applicationId = $this->seedApplication();
        $this->rollBackRetargetMigrations();
        $this->insertLegacyInterview($applicationId);

        Artisan::call('migrate');
        // Ten most recently applied migrations: Phase 8A-5's
        // `add_parsed_text_to_cvs_table`, Phase 7A-1's
        // `add_assessment_and_offer_types_to_notifications_table`, Phase
        // 6C-1's `create_offers_table`, Phase 6C-0's
        // `add_offer_sent_status_to_applications_table`, Phase 6B-3's
        // `create_quiz_attempts_table`, Phase 6B-1's
        // `create_questions_table`/`create_quizzes_table`, Phase 6B-0's
        // `add_in_assessment_status_to_applications_table`, the retarget
        // migration, and `create_assessments_table` itself.
        Artisan::call('migrate:rollback', ['--step' => 38]);

        $this->assertFalse(\Illuminate\Support\Facades\Schema::hasTable('assessments'));
        $this->assertTrue(\Illuminate\Support\Facades\Schema::hasColumn('interviews', 'application_id'));
        $this->assertFalse(\Illuminate\Support\Facades\Schema::hasColumn('interviews', 'assessment_id'));
    }

    public function test_application_id_not_null_is_enforced_after_rollback(): void
    {
        Artisan::call('migrate:rollback', ['--step' => 37]);

        $this->expectException(\Illuminate\Database\QueryException::class);

        DB::table('interviews')->insert([
            'application_id' => null,
            'interview_type' => 'phone',
            'scheduled_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_application_id_foreign_key_rejects_a_nonexistent_application_after_rollback(): void
    {
        Artisan::call('migrate:rollback', ['--step' => 37]);

        $this->expectException(\Illuminate\Database\QueryException::class);

        DB::table('interviews')->insert([
            'application_id' => 999999,
            'interview_type' => 'phone',
            'scheduled_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_application_id_unique_constraint_is_enforced_after_rollback(): void
    {
        $applicationId = $this->seedApplication();

        Artisan::call('migrate:rollback', ['--step' => 37]);

        DB::table('interviews')->insert([
            'application_id' => $applicationId,
            'interview_type' => 'phone',
            'scheduled_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->expectException(\Illuminate\Database\QueryException::class);

        DB::table('interviews')->insert([
            'application_id' => $applicationId,
            'interview_type' => 'phone',
            'scheduled_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * Rolls back the retarget migration (plus every later, unrelated
     * migration stacked on top of it since -- Phase 6B-0's
     * `add_in_assessment_status_to_applications_table`, Phase 6B-1's
     * `create_quizzes_table`/`create_questions_table`, Phase 6B-3's
     * `create_quiz_attempts_table`, Phase 6C-0's
     * `add_offer_sent_status_to_applications_table`, Phase 6C-1's
     * `create_offers_table`, and Phase 7A-1's
     * `add_assessment_and_offer_types_to_notifications_table` -- all no-ops
     * for `interviews`), returning the schema to its pre-Phase-4A-1 shape so
     * a legacy `interviews.application_id` row can be seeded.
     */
    private function rollBackRetargetMigrations(): void
    {
        Artisan::call('migrate:rollback', ['--step' => 37]);
    }

    private function insertLegacyInterview(int $applicationId, array $overrides = []): int
    {
        return DB::table('interviews')->insertGetId(array_merge([
            'application_id' => $applicationId,
            'interview_type' => 'phone',
            'decision' => 'pending',
            'scheduled_at' => now(),
            'duration_minutes' => 60,
            'status' => 'scheduled',
            'completed_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides));
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

        $application->status = 'shortlisted';
        $application->save();

        return $application->id;
    }
}
