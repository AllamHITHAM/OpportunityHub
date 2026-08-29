<?php

namespace Tests\Feature\Notifications;

use App\Models\Notification;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Phase 7A-1: exercises the
 * `2026_08_11_090000_add_assessment_and_offer_types_to_notifications_table`
 * migration's up/rollback/re-run path directly, the same way
 * `OfferSentStatusMigrationTest` does for its own enum-widening migration.
 * Needs `DatabaseMigrations` (not `RefreshDatabase`) to roll a single
 * migration back and forward within one test.
 *
 * As of Phase 8B-1, `2026_08_18_090000_add_parsed_text_to_cvs_table`,
 * `2026_08_18_100000_create_skill_suggestions_table`,
 * `2026_08_18_100100_add_source_to_student_skills_table`,
 * `2026_08_18_100200_create_cv_skill_evidence_table`, and
 * `2026_08_19_100000_create_education_verifications_table` sit on top of
 * this migration; as of Phase 8B-3, one more
 * (`2026_08_20_100000_create_invitations_table`) sits on top of those; as
 * of Phase 8B-3.2, one more
 * (`2026_08_20_110000_create_opportunity_eligible_majors_table`) sits on
 * top of that; as of Phase Final-QA-1, one more
 * (`2026_08_20_164118_add_contact_phone_to_interviews_table`) sits on top
 * of that, so `--step => 9` was required to reach past all eight and
 * back to this migration's own effect (dropping `cvs.parsed_text`/
 * `skill_suggestions`/`student_skills.source`/`cv_skill_evidence`/
 * `education_verifications`/`invitations`/`opportunity_eligible_majors`/
 * `interviews.contact_phone` along the way is harmless for every assertion
 * in this file, which only ever touches `notifications`); as of Phase
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
 * of that -- the last of which itself widens this same
 * `notifications.type` enum again, but is still harmless to roll past here
 * since this file only ever asserts about the `assessment`/`offer` values,
 * not `message`; as of the Organization Public Profile phase, two more
 * (`2026_09_01_090000_add_location_id_to_organization_profiles_table`,
 * `2026_09_01_090100_create_organization_posts_table`) sit on top of
 * that; as of the Company Profile Polish phase, one more
 * (`2026_09_02_090000_add_image_path_to_organization_posts_table`) sits
 * on top of that; as of the Closed Opportunities Scalability Polish
 * phase, one more
 * (`2026_09_03_090000_add_closed_at_to_opportunities_table`) sits on top
 * of that -- so `--step => 30` is now required —
 * the same fragility `OfferSentStatusMigrationTest`'s own doc comment already flags
 * for itself; recalculate again if a later phase adds another migration on
 * top.
 */
class NotificationTypeMigrationTest extends TestCase
{
    use DatabaseMigrations;

    public function test_assessment_is_a_valid_type_after_migrating(): void
    {
        $notificationId = $this->seedNotification();

        DB::table('notifications')->where('id', $notificationId)->update(['type' => 'assessment']);

        $this->assertDatabaseHas('notifications', [
            'id' => $notificationId,
            'type' => 'assessment',
        ]);
    }

    public function test_offer_is_a_valid_type_after_migrating(): void
    {
        $notificationId = $this->seedNotification();

        DB::table('notifications')->where('id', $notificationId)->update(['type' => 'offer']);

        $this->assertDatabaseHas('notifications', [
            'id' => $notificationId,
            'type' => 'offer',
        ]);
    }

    public function test_every_pre_existing_type_remains_valid_after_migrating(): void
    {
        $types = ['system', 'application', 'interview', 'organization', 'opportunity'];

        foreach ($types as $type) {
            $notificationId = $this->seedNotification();
            DB::table('notifications')->where('id', $notificationId)->update(['type' => $type]);

            $this->assertDatabaseHas('notifications', [
                'id' => $notificationId,
                'type' => $type,
            ]);
        }
    }

    public function test_rollback_converts_an_assessment_row_to_system(): void
    {
        $notificationId = $this->seedNotification();
        DB::table('notifications')->where('id', $notificationId)->update(['type' => 'assessment']);

        Artisan::call('migrate:rollback', ['--step' => 30]);

        $this->assertSame(
            'system',
            DB::table('notifications')->where('id', $notificationId)->value('type'),
        );
    }

    public function test_rollback_converts_an_offer_row_to_system(): void
    {
        $notificationId = $this->seedNotification();
        DB::table('notifications')->where('id', $notificationId)->update(['type' => 'offer']);

        Artisan::call('migrate:rollback', ['--step' => 30]);

        $this->assertSame(
            'system',
            DB::table('notifications')->where('id', $notificationId)->value('type'),
        );
    }

    public function test_rollback_leaves_every_other_type_untouched(): void
    {
        $systemId = $this->seedNotification();
        $applicationId = $this->seedNotification();
        DB::table('notifications')->where('id', $applicationId)->update(['type' => 'application']);
        $interviewId = $this->seedNotification();
        DB::table('notifications')->where('id', $interviewId)->update(['type' => 'interview']);
        $organizationId = $this->seedNotification();
        DB::table('notifications')->where('id', $organizationId)->update(['type' => 'organization']);
        $opportunityId = $this->seedNotification();
        DB::table('notifications')->where('id', $opportunityId)->update(['type' => 'opportunity']);

        Artisan::call('migrate:rollback', ['--step' => 30]);

        $this->assertSame('system', DB::table('notifications')->where('id', $systemId)->value('type'));
        $this->assertSame('application', DB::table('notifications')->where('id', $applicationId)->value('type'));
        $this->assertSame('interview', DB::table('notifications')->where('id', $interviewId)->value('type'));
        $this->assertSame('organization', DB::table('notifications')->where('id', $organizationId)->value('type'));
        $this->assertSame('opportunity', DB::table('notifications')->where('id', $opportunityId)->value('type'));
    }

    public function test_rollback_does_not_lose_unrelated_notification_data(): void
    {
        $notificationId = $this->seedNotification();
        DB::table('notifications')->where('id', $notificationId)->update([
            'type' => 'offer',
            'title' => 'A Very Specific Title',
            'message' => 'A very specific message body.',
        ]);

        Artisan::call('migrate:rollback', ['--step' => 30]);

        $row = DB::table('notifications')->where('id', $notificationId)->first();
        $this->assertSame('A Very Specific Title', $row->title);
        $this->assertSame('A very specific message body.', $row->message);
        $this->assertSame('system', $row->type);
    }

    public function test_rollback_then_remigrate_restores_assessment_and_offer_validity(): void
    {
        $notificationId = $this->seedNotification();

        Artisan::call('migrate:rollback', ['--step' => 30]);
        Artisan::call('migrate');

        DB::table('notifications')->where('id', $notificationId)->update(['type' => 'assessment']);
        $this->assertDatabaseHas('notifications', ['id' => $notificationId, 'type' => 'assessment']);

        DB::table('notifications')->where('id', $notificationId)->update(['type' => 'offer']);
        $this->assertDatabaseHas('notifications', ['id' => $notificationId, 'type' => 'offer']);
    }

    public function test_rollback_removes_assessment_and_offer_from_the_enum(): void
    {
        Artisan::call('migrate:rollback', ['--step' => 30]);

        $notificationId = $this->seedNotification();

        $this->expectException(QueryException::class);

        DB::table('notifications')->where('id', $notificationId)->update(['type' => 'assessment']);
    }

    private function seedNotification(): int
    {
        $user = User::factory()->create([
            'role' => 'student',
            'status' => 'active',
        ]);

        $notification = Notification::create([
            'user_id' => $user->id,
            'title' => 'Test Notification',
            'message' => 'This is a test notification.',
            'type' => 'system',
            'priority' => 'normal',
            'action_url' => null,
        ]);

        return $notification->id;
    }
}
