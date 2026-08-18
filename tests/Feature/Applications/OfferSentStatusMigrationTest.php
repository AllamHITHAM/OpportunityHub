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
 * to what an earlier version of this comment claimed. As of Phase 8A-5,
 * `2026_08_10_142102_create_offers_table`,
 * `2026_08_11_090000_add_assessment_and_offer_types_to_notifications_table`,
 * and `2026_08_18_090000_add_parsed_text_to_cvs_table` all sit on top of
 * this migration, so `--step => 4` is required to reach past all four and
 * back to this migration's own effect (dropping `offers`/widening
 * `notifications.type`/dropping `cvs.parsed_text` along the way is harmless
 * for every assertion in this file, which only ever touches
 * `applications`).
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

        Artisan::call('migrate:rollback', ['--step' => 4]);

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

        Artisan::call('migrate:rollback', ['--step' => 4]);

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

        Artisan::call('migrate:rollback', ['--step' => 4]);

        $row = DB::table('applications')->where('id', $applicationId)->first();
        $this->assertSame('A very specific cover letter.', $row->cover_letter);
        $this->assertSame('in_assessment', $row->status);
    }

    public function test_rollback_then_remigrate_restores_offer_sent_validity(): void
    {
        $applicationId = $this->seedApplication();

        Artisan::call('migrate:rollback', ['--step' => 4]);
        Artisan::call('migrate');

        DB::table('applications')->where('id', $applicationId)->update(['status' => 'offer_sent']);

        $this->assertDatabaseHas('applications', [
            'id' => $applicationId,
            'status' => 'offer_sent',
        ]);
    }

    public function test_rollback_removes_offer_sent_from_the_enum(): void
    {
        Artisan::call('migrate:rollback', ['--step' => 4]);

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
