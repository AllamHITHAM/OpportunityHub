<?php

namespace Tests\Feature\Offers;

use App\Models\Application;
use App\Models\Opportunity;
use App\Models\OrganizationProfile;
use App\Models\StudentProfile;
use App\Models\User;
use App\Services\OfferService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Phase 6C-1: POST /api/organization/applications/{application}/offer --
 * eligibility rules (section B) and request validation (section C).
 */
class SendOfferTest extends TestCase
{
    use RefreshDatabase;

    // ---------------------------------------------------------------
    // B. Send Offer eligibility
    // ---------------------------------------------------------------

    public function test_completed_assessment_and_in_assessment_status_allows_sending_an_offer(): void
    {
        $org = $this->approvedOrganization();
        $opportunity = $this->opportunityFor($org);
        $application = $this->eligibleApplication($opportunity);

        Sanctum::actingAs($org->user);

        $response = $this->postJson(
            "/api/organization/applications/{$application->id}/offer",
            $this->validOfferPayload()
        );

        $response->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Offer sent successfully')
            ->assertJsonPath('data.application_id', $application->id)
            ->assertJsonPath('data.status', 'sent');

        $this->assertNotNull($response->json('data.sent_at'));

        $this->assertDatabaseHas('offers', [
            'application_id' => $application->id,
            'status' => 'sent',
        ]);
        $this->assertDatabaseCount('offers', 1);
    }

    public function test_sending_an_offer_sets_application_status_to_offer_sent(): void
    {
        $org = $this->approvedOrganization();
        $opportunity = $this->opportunityFor($org);
        $application = $this->eligibleApplication($opportunity);

        Sanctum::actingAs($org->user);

        $this->postJson(
            "/api/organization/applications/{$application->id}/offer",
            $this->validOfferPayload()
        )->assertStatus(201);

        $this->assertDatabaseHas('applications', [
            'id' => $application->id,
            'status' => 'offer_sent',
        ]);
    }

    public function test_sending_an_offer_never_touches_the_assessment(): void
    {
        $org = $this->approvedOrganization();
        $opportunity = $this->opportunityFor($org);
        $application = $this->eligibleApplication($opportunity, result: 'passed');
        $assessment = $application->assessment()->first();

        Sanctum::actingAs($org->user);

        $this->postJson(
            "/api/organization/applications/{$application->id}/offer",
            $this->validOfferPayload()
        )->assertStatus(201);

        $assessment->refresh();
        $this->assertSame('completed', $assessment->status);
        $this->assertSame('passed', $assessment->result);
    }

    #[DataProvider('assessmentResults')]
    public function test_offer_can_be_sent_regardless_of_assessment_result(?string $result): void
    {
        $org = $this->approvedOrganization();
        $opportunity = $this->opportunityFor($org);
        $application = $this->eligibleApplication($opportunity, result: $result);

        Sanctum::actingAs($org->user);

        $this->postJson(
            "/api/organization/applications/{$application->id}/offer",
            $this->validOfferPayload()
        )->assertStatus(201);
    }

    /**
     * @return array<string, array{0: string|null}>
     */
    public static function assessmentResults(): array
    {
        return [
            'passed' => ['passed'],
            'failed' => ['failed'],
            'waiting' => ['waiting'],
            'no result recorded yet' => [null],
        ];
    }

    public function test_no_assessment_returns_422(): void
    {
        $org = $this->approvedOrganization();
        $opportunity = $this->opportunityFor($org);
        // `in_assessment` with no backing Assessment row -- a data
        // inconsistency the service must still guard against defensively,
        // the same case `_AssessmentSection`'s own recovery UI handles on
        // the Flutter side.
        $application = $this->applicationFor($opportunity, 'in_assessment');

        Sanctum::actingAs($org->user);

        $response = $this->postJson(
            "/api/organization/applications/{$application->id}/offer",
            $this->validOfferPayload()
        );

        $response->assertStatus(422);
        $this->assertDatabaseCount('offers', 0);
    }

    #[DataProvider('incompleteAssessmentStatuses')]
    public function test_incomplete_assessment_returns_422(string $assessmentStatus): void
    {
        $org = $this->approvedOrganization();
        $opportunity = $this->opportunityFor($org);
        $application = $this->applicationFor($opportunity, 'in_assessment');
        $application->assessment()->create([
            'type' => 'interview',
            'status' => $assessmentStatus,
            'result' => null,
        ]);

        Sanctum::actingAs($org->user);

        $response = $this->postJson(
            "/api/organization/applications/{$application->id}/offer",
            $this->validOfferPayload()
        );

        $response->assertStatus(422)
            ->assertJsonPath('message', 'An offer can only be sent once the assessment is completed.');
        $this->assertDatabaseCount('offers', 0);
        $this->assertDatabaseHas('applications', ['id' => $application->id, 'status' => 'in_assessment']);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function incompleteAssessmentStatuses(): array
    {
        return [
            'pending' => ['pending'],
            'scheduled' => ['scheduled'],
            'in_progress' => ['in_progress'],
        ];
    }

    #[DataProvider('ineligibleApplicationStatuses')]
    public function test_wrong_application_status_returns_422(string $status): void
    {
        $org = $this->approvedOrganization();
        $opportunity = $this->opportunityFor($org);
        $application = $this->applicationFor($opportunity, $status);

        Sanctum::actingAs($org->user);

        $response = $this->postJson(
            "/api/organization/applications/{$application->id}/offer",
            $this->validOfferPayload()
        );

        $response->assertStatus(422)
            ->assertJsonPath('success', false);
        $this->assertDatabaseCount('offers', 0);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function ineligibleApplicationStatuses(): array
    {
        return [
            'pending' => ['pending'],
            'reviewed' => ['reviewed'],
            'shortlisted' => ['shortlisted'],
            'offer_sent' => ['offer_sent'],
            'accepted' => ['accepted'],
            'rejected' => ['rejected'],
            'withdrawn' => ['withdrawn'],
            'legacy interview_scheduled' => ['interview_scheduled'],
        ];
    }

    /**
     * A legacy `interview_scheduled` application with a fully completed
     * Assessment attached must still be rejected -- the eligibility check
     * is on `application.status === 'in_assessment'` specifically, never
     * merely "does a completed assessment exist somewhere for this
     * application". `interview_scheduled` never transitions to
     * `in_assessment` on its own; only a fresh Assessment-creation
     * workflow does that.
     */
    public function test_legacy_interview_scheduled_with_a_completed_assessment_still_cannot_receive_an_offer(): void
    {
        $org = $this->approvedOrganization();
        $opportunity = $this->opportunityFor($org);
        $application = $this->applicationFor($opportunity, 'interview_scheduled');
        $application->assessment()->create([
            'type' => 'interview',
            'status' => 'completed',
            'result' => 'passed',
            'completed_at' => now(),
        ]);

        Sanctum::actingAs($org->user);

        $response = $this->postJson(
            "/api/organization/applications/{$application->id}/offer",
            $this->validOfferPayload()
        );

        $response->assertStatus(422);
        $this->assertDatabaseCount('offers', 0);
    }

    public function test_withdrawn_application_cannot_receive_an_offer(): void
    {
        $org = $this->approvedOrganization();
        $opportunity = $this->opportunityFor($org);
        $application = $this->applicationFor($opportunity, 'withdrawn');

        Sanctum::actingAs($org->user);

        $response = $this->postJson(
            "/api/organization/applications/{$application->id}/offer",
            $this->validOfferPayload()
        );

        $response->assertStatus(422);
        $this->assertDatabaseHas('applications', ['id' => $application->id, 'status' => 'withdrawn']);
        $this->assertDatabaseCount('offers', 0);
    }

    public function test_duplicate_offer_returns_409(): void
    {
        $org = $this->approvedOrganization();
        $opportunity = $this->opportunityFor($org);
        $application = $this->eligibleApplication($opportunity);
        $application->offer()->create([
            'status' => 'sent',
            'sent_at' => now(),
        ]);
        // The application has already moved on from `in_assessment` by
        // virtue of the first offer having been sent -- the duplicate
        // pre-check must still fire *before* (and instead of) the
        // source-status check, so this reports 409, not 422.
        $application->status = 'offer_sent';
        $application->save();

        Sanctum::actingAs($org->user);

        $response = $this->postJson(
            "/api/organization/applications/{$application->id}/offer",
            $this->validOfferPayload()
        );

        $response->assertStatus(409)
            ->assertJsonPath('success', false);
        $this->assertDatabaseCount('offers', 1);
    }

    public function test_wrong_organization_returns_404(): void
    {
        $orgA = $this->approvedOrganization();
        $opportunity = $this->opportunityFor($orgA);
        $application = $this->eligibleApplication($opportunity);

        $orgB = $this->approvedOrganization();
        Sanctum::actingAs($orgB->user);

        $response = $this->postJson(
            "/api/organization/applications/{$application->id}/offer",
            $this->validOfferPayload()
        );

        $response->assertStatus(404)
            ->assertJsonPath('message', 'Application not found');
        $this->assertDatabaseCount('offers', 0);
    }

    /**
     * True concurrency can't be reproduced inside a single synchronous
     * PHPUnit process -- this proves the translation logic directly,
     * mirroring AssessmentCreationParityTest's own precedent for
     * `isDuplicateAssessmentViolation()`.
     */
    public function test_only_the_application_id_unique_violation_is_treated_as_a_duplicate_offer(): void
    {
        $org = $this->approvedOrganization();
        $opportunity = $this->opportunityFor($org);
        $application = $this->eligibleApplication($opportunity);

        DB::table('offers')->insert([
            'application_id' => $application->id,
            'status' => 'sent',
            'sent_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $duplicateViolation = null;
        try {
            DB::table('offers')->insert([
                'application_id' => $application->id,
                'status' => 'sent',
                'sent_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } catch (QueryException $e) {
            $duplicateViolation = $e;
        }
        $this->assertNotNull($duplicateViolation);

        $unrelatedViolation = null;
        try {
            DB::table('opportunities')->insert(['organization_id' => $opportunity->organization_id]);
        } catch (QueryException $e) {
            $unrelatedViolation = $e;
        }
        $this->assertNotNull($unrelatedViolation);

        $service = app(OfferService::class);
        $method = new ReflectionMethod(OfferService::class, 'isDuplicateOfferViolation');
        $method->setAccessible(true);

        $this->assertTrue($method->invoke($service, $duplicateViolation));
        $this->assertFalse($method->invoke($service, $unrelatedViolation));
    }

    // ---------------------------------------------------------------
    // C. SendOfferRequest validation
    // ---------------------------------------------------------------

    public function test_all_optional_terms_may_be_omitted(): void
    {
        $org = $this->approvedOrganization();
        $opportunity = $this->opportunityFor($org);
        $application = $this->eligibleApplication($opportunity);

        Sanctum::actingAs($org->user);

        $response = $this->postJson("/api/organization/applications/{$application->id}/offer", []);

        $response->assertStatus(201)
            ->assertJsonPath('data.title', null)
            ->assertJsonPath('data.salary_amount', null)
            ->assertJsonPath('data.start_date', null)
            ->assertJsonPath('data.message', null);
    }

    public function test_title_over_max_length_is_rejected(): void
    {
        $org = $this->approvedOrganization();
        $opportunity = $this->opportunityFor($org);
        $application = $this->eligibleApplication($opportunity);

        Sanctum::actingAs($org->user);

        $response = $this->postJson(
            "/api/organization/applications/{$application->id}/offer",
            $this->validOfferPayload(['title' => str_repeat('a', 256)])
        );

        $response->assertStatus(422)->assertJsonValidationErrors(['title']);
    }

    public function test_negative_salary_amount_is_rejected(): void
    {
        $org = $this->approvedOrganization();
        $opportunity = $this->opportunityFor($org);
        $application = $this->eligibleApplication($opportunity);

        Sanctum::actingAs($org->user);

        $response = $this->postJson(
            "/api/organization/applications/{$application->id}/offer",
            $this->validOfferPayload(['salary_amount' => -1])
        );

        $response->assertStatus(422)->assertJsonValidationErrors(['salary_amount']);
    }

    public function test_salary_amount_without_currency_is_rejected(): void
    {
        $org = $this->approvedOrganization();
        $opportunity = $this->opportunityFor($org);
        $application = $this->eligibleApplication($opportunity);

        Sanctum::actingAs($org->user);

        $response = $this->postJson("/api/organization/applications/{$application->id}/offer", [
            'salary_amount' => 5000,
            'salary_period' => 'monthly',
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors(['salary_currency']);
    }

    public function test_salary_amount_without_period_is_rejected(): void
    {
        $org = $this->approvedOrganization();
        $opportunity = $this->opportunityFor($org);
        $application = $this->eligibleApplication($opportunity);

        Sanctum::actingAs($org->user);

        $response = $this->postJson("/api/organization/applications/{$application->id}/offer", [
            'salary_amount' => 5000,
            'salary_currency' => 'USD',
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors(['salary_period']);
    }

    public function test_salary_currency_without_amount_is_rejected(): void
    {
        $org = $this->approvedOrganization();
        $opportunity = $this->opportunityFor($org);
        $application = $this->eligibleApplication($opportunity);

        Sanctum::actingAs($org->user);

        $response = $this->postJson("/api/organization/applications/{$application->id}/offer", [
            'salary_currency' => 'USD',
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors(['salary_amount']);
    }

    public function test_salary_period_without_amount_is_rejected(): void
    {
        $org = $this->approvedOrganization();
        $opportunity = $this->opportunityFor($org);
        $application = $this->eligibleApplication($opportunity);

        Sanctum::actingAs($org->user);

        $response = $this->postJson("/api/organization/applications/{$application->id}/offer", [
            'salary_period' => 'monthly',
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors(['salary_amount']);
    }

    public function test_invalid_salary_period_is_rejected(): void
    {
        $org = $this->approvedOrganization();
        $opportunity = $this->opportunityFor($org);
        $application = $this->eligibleApplication($opportunity);

        Sanctum::actingAs($org->user);

        $response = $this->postJson(
            "/api/organization/applications/{$application->id}/offer",
            $this->validOfferPayload(['salary_period' => 'weekly'])
        );

        $response->assertStatus(422)->assertJsonValidationErrors(['salary_period']);
    }

    public function test_past_start_date_is_rejected(): void
    {
        $org = $this->approvedOrganization();
        $opportunity = $this->opportunityFor($org);
        $application = $this->eligibleApplication($opportunity);

        Sanctum::actingAs($org->user);

        $response = $this->postJson(
            "/api/organization/applications/{$application->id}/offer",
            ['start_date' => now()->subDay()->toDateString()]
        );

        $response->assertStatus(422)->assertJsonValidationErrors(['start_date']);
    }

    public function test_todays_start_date_is_accepted(): void
    {
        $org = $this->approvedOrganization();
        $opportunity = $this->opportunityFor($org);
        $application = $this->eligibleApplication($opportunity);

        Sanctum::actingAs($org->user);

        $response = $this->postJson(
            "/api/organization/applications/{$application->id}/offer",
            ['start_date' => now()->toDateString()]
        );

        $response->assertStatus(201);
    }

    public function test_future_start_date_is_accepted(): void
    {
        $org = $this->approvedOrganization();
        $opportunity = $this->opportunityFor($org);
        $application = $this->eligibleApplication($opportunity);

        Sanctum::actingAs($org->user);

        $response = $this->postJson(
            "/api/organization/applications/{$application->id}/offer",
            ['start_date' => now()->addMonth()->toDateString()]
        );

        $response->assertStatus(201);
    }

    public function test_a_full_valid_payload_persists_every_field(): void
    {
        $org = $this->approvedOrganization();
        $opportunity = $this->opportunityFor($org);
        $application = $this->eligibleApplication($opportunity);

        Sanctum::actingAs($org->user);

        $startDate = now()->addWeeks(2)->toDateString();

        $response = $this->postJson("/api/organization/applications/{$application->id}/offer", [
            'title' => 'Senior Backend Engineer',
            'salary_amount' => 95000.5,
            'salary_currency' => 'USD',
            'salary_period' => 'yearly',
            'start_date' => $startDate,
            'message' => 'Excited to have you join the team.',
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.title', 'Senior Backend Engineer')
            ->assertJsonPath('data.salary_amount', '95000.50')
            ->assertJsonPath('data.salary_currency', 'USD')
            ->assertJsonPath('data.salary_period', 'yearly')
            // A `date`-cast attribute still serializes as a full ISO
            // datetime (midnight UTC), not a bare `Y-m-d` string -- the
            // real Eloquent shape, not an assumed one.
            ->assertJsonPath('data.start_date', $startDate.'T00:00:00.000000Z')
            ->assertJsonPath('data.message', 'Excited to have you join the team.');

        $this->assertDatabaseHas('offers', [
            'application_id' => $application->id,
            'title' => 'Senior Backend Engineer',
            'salary_amount' => 95000.50,
            'salary_currency' => 'USD',
            'salary_period' => 'yearly',
            // The raw stored value, not the JSON-serialized one -- SQLite
            // stores the `date`-cast column with a zeroed time component.
            'start_date' => $startDate.' 00:00:00',
            'message' => 'Excited to have you join the team.',
        ]);
    }

    // ---------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------

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

    /**
     * An `in_assessment` application with a `completed` Assessment attached
     * -- the baseline eligible-for-offer state every send-offer-success
     * test starts from.
     */
    private function eligibleApplication(Opportunity $opportunity, ?string $result = 'passed'): Application
    {
        $application = $this->applicationFor($opportunity, 'in_assessment');
        $application->assessment()->create([
            'type' => 'interview',
            'status' => 'completed',
            'result' => $result,
            'completed_at' => now(),
        ]);

        return $application;
    }

    private function validOfferPayload(array $overrides = []): array
    {
        return array_merge([
            'title' => 'Backend Engineer',
            'salary_amount' => 90000,
            'salary_currency' => 'USD',
            'salary_period' => 'yearly',
            'start_date' => now()->addWeeks(2)->toDateString(),
            'message' => 'We would love to have you on the team.',
        ], $overrides);
    }
}
