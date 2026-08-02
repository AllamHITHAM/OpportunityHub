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

    public function test_new_unique_constraint_on_assessments_application_id_is_enforced(): void
    {
        $applicationId = $this->seedApplication();

        DB::table('assessments')->insert([
            'application_id' => $applicationId,
            'type' => 'interview',
            'status' => 'scheduled',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->expectException(\Illuminate\Database\QueryException::class);

        DB::table('assessments')->insert([
            'application_id' => $applicationId,
            'type' => 'interview',
            'status' => 'scheduled',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
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

        // Roll back only the retarget migration -- assessments must still
        // exist immediately after, since `down()` reads from it.
        Artisan::call('migrate:rollback', ['--step' => 1]);

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
        Artisan::call('migrate:rollback', ['--step' => 2]);

        $this->assertFalse(\Illuminate\Support\Facades\Schema::hasTable('assessments'));
        $this->assertTrue(\Illuminate\Support\Facades\Schema::hasColumn('interviews', 'application_id'));
        $this->assertFalse(\Illuminate\Support\Facades\Schema::hasColumn('interviews', 'assessment_id'));
    }

    public function test_application_id_not_null_is_enforced_after_rollback(): void
    {
        Artisan::call('migrate:rollback', ['--step' => 2]);

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
        Artisan::call('migrate:rollback', ['--step' => 2]);

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

        Artisan::call('migrate:rollback', ['--step' => 2]);

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
     * Rolls back exactly the two Phase 4A-1 migrations (retarget, then
     * assessments), returning the schema to its pre-Phase-4A-1 shape so a
     * legacy `interviews.application_id` row can be seeded.
     */
    private function rollBackRetargetMigrations(): void
    {
        Artisan::call('migrate:rollback', ['--step' => 2]);
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
