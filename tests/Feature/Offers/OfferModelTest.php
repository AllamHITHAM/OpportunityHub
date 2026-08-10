<?php

namespace Tests\Feature\Offers;

use App\Models\Application;
use App\Models\Offer;
use App\Models\Opportunity;
use App\Models\OrganizationProfile;
use App\Models\StudentProfile;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Phase 6C-1: schema/model-level coverage for the `offers` table and the
 * `Offer` model -- ownership/business-rule behavior is covered separately
 * in SendOfferTest/OfferShowTest/RespondToOfferTest.
 */
class OfferModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_offers_table_exists(): void
    {
        $this->assertTrue(Schema::hasTable('offers'));
    }

    public function test_offers_table_has_the_expected_columns(): void
    {
        $this->assertTrue(Schema::hasColumns('offers', [
            'id',
            'application_id',
            'title',
            'salary_amount',
            'salary_currency',
            'salary_period',
            'start_date',
            'message',
            'status',
            'sent_at',
            'responded_at',
            'created_at',
            'updated_at',
        ]));
    }

    public function test_application_id_is_unique(): void
    {
        $application = $this->applicationFor($this->opportunityFor($this->approvedOrganization()));

        DB::table('offers')->insert([
            'application_id' => $application->id,
            'status' => 'sent',
            'sent_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->expectException(QueryException::class);

        DB::table('offers')->insert([
            'application_id' => $application->id,
            'status' => 'sent',
            'sent_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_application_id_foreign_key_rejects_a_nonexistent_application(): void
    {
        $this->expectException(QueryException::class);

        DB::table('offers')->insert([
            'application_id' => 999999,
            'status' => 'sent',
            'sent_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_deleting_an_application_cascades_to_its_offer(): void
    {
        $application = $this->applicationFor($this->opportunityFor($this->approvedOrganization()));

        DB::table('offers')->insert([
            'application_id' => $application->id,
            'status' => 'sent',
            'sent_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->assertDatabaseCount('offers', 1);

        $application->delete();

        $this->assertDatabaseCount('offers', 0);
    }

    public function test_status_defaults_to_sent_at_the_database_level(): void
    {
        $application = $this->applicationFor($this->opportunityFor($this->approvedOrganization()));

        DB::table('offers')->insert([
            'application_id' => $application->id,
            'sent_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertSame('sent', DB::table('offers')->where('application_id', $application->id)->value('status'));
    }

    public function test_offer_belongs_to_application(): void
    {
        $application = $this->applicationFor($this->opportunityFor($this->approvedOrganization()));
        $offer = Offer::create([
            'application_id' => $application->id,
            'status' => 'sent',
            'sent_at' => now(),
        ]);

        $this->assertInstanceOf(Application::class, $offer->application);
        $this->assertSame($application->id, $offer->application->id);
    }

    public function test_application_has_one_offer(): void
    {
        $application = $this->applicationFor($this->opportunityFor($this->approvedOrganization()));
        $offer = Offer::create([
            'application_id' => $application->id,
            'status' => 'sent',
            'sent_at' => now(),
        ]);

        $this->assertInstanceOf(Offer::class, $application->offer);
        $this->assertSame($offer->id, $application->offer->id);
    }

    public function test_application_offer_is_null_when_none_exists(): void
    {
        $application = $this->applicationFor($this->opportunityFor($this->approvedOrganization()));

        $this->assertNull($application->offer);
    }

    public function test_salary_amount_casts_to_a_two_decimal_string(): void
    {
        $application = $this->applicationFor($this->opportunityFor($this->approvedOrganization()));
        $offer = Offer::create([
            'application_id' => $application->id,
            'salary_amount' => 75000,
            'salary_currency' => 'USD',
            'salary_period' => 'yearly',
            'status' => 'sent',
            'sent_at' => now(),
        ]);

        $this->assertSame('75000.00', $offer->fresh()->salary_amount);
    }

    public function test_start_date_casts_to_a_date(): void
    {
        $application = $this->applicationFor($this->opportunityFor($this->approvedOrganization()));
        $offer = Offer::create([
            'application_id' => $application->id,
            'start_date' => '2026-09-01',
            'status' => 'sent',
            'sent_at' => now(),
        ]);

        $this->assertInstanceOf(\Illuminate\Support\Carbon::class, $offer->fresh()->start_date);
        $this->assertSame('2026-09-01', $offer->fresh()->start_date->toDateString());
    }

    public function test_sent_at_and_responded_at_cast_to_datetimes(): void
    {
        $application = $this->applicationFor($this->opportunityFor($this->approvedOrganization()));
        $offer = Offer::create([
            'application_id' => $application->id,
            'status' => 'accepted',
            'sent_at' => '2026-08-10 12:00:00',
            'responded_at' => '2026-08-11 09:30:00',
        ]);

        $fresh = $offer->fresh();
        $this->assertInstanceOf(\Illuminate\Support\Carbon::class, $fresh->sent_at);
        $this->assertInstanceOf(\Illuminate\Support\Carbon::class, $fresh->responded_at);
    }

    public function test_responded_at_defaults_to_null(): void
    {
        $application = $this->applicationFor($this->opportunityFor($this->approvedOrganization()));
        $offer = Offer::create([
            'application_id' => $application->id,
            'status' => 'sent',
            'sent_at' => now(),
        ]);

        $this->assertNull($offer->fresh()->responded_at);
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

    private function applicationFor(Opportunity $opportunity, string $status = 'pending'): Application
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
