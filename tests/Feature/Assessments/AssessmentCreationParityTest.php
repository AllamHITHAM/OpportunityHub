<?php

namespace Tests\Feature\Assessments;

use App\Http\Requests\Organization\StoreAssessmentRequest;
use App\Http\Requests\Organization\StoreInterviewRequest;
use App\Models\Application;
use App\Models\Opportunity;
use App\Models\OrganizationProfile;
use App\Models\StudentProfile;
use App\Models\User;
use App\Services\AssessmentService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Phase 4A-2: proves the legacy Interview endpoint and the generic
 * Assessment endpoint share one creation workflow (AssessmentService)
 * without duplicating logic, and that each still preserves its own
 * historical response contract.
 */
class AssessmentCreationParityTest extends TestCase
{
    use RefreshDatabase;

    public function test_legacy_and_generic_endpoints_produce_equivalent_database_state(): void
    {
        $orgA = $this->approvedOrganization();
        $opportunityA = $this->opportunityFor($orgA);
        $applicationA = $this->applicationFor($opportunityA, 'shortlisted');

        $orgB = $this->approvedOrganization();
        $opportunityB = $this->opportunityFor($orgB);
        $applicationB = $this->applicationFor($opportunityB, 'shortlisted');

        $payload = [
            'interview_type' => 'phone',
            'scheduled_at' => now()->addDays(3)->toDateTimeString(),
            'contact_phone' => '+1 555-0100',
        ];

        Sanctum::actingAs($orgA->user);
        $this->postJson("/api/organization/applications/{$applicationA->id}/interview", $payload)
            ->assertStatus(201);

        Sanctum::actingAs($orgB->user);
        $this->postJson("/api/organization/applications/{$applicationB->id}/assessments", [
            'type' => 'interview',
            'interview' => $payload,
        ])->assertStatus(201);

        $applicationA->refresh();
        $applicationB->refresh();

        $this->assertSame($applicationA->status, $applicationB->status);
        $this->assertSame('in_assessment', $applicationA->status);
        $this->assertNotNull($applicationA->reviewed_at);
        $this->assertNotNull($applicationB->reviewed_at);

        $assessmentA = $applicationA->assessment;
        $assessmentB = $applicationB->assessment;

        $this->assertSame($assessmentA->type, $assessmentB->type);
        $this->assertSame($assessmentA->status, $assessmentB->status);
        $this->assertSame($assessmentA->result, $assessmentB->result);

        $this->assertSame($assessmentA->interview->interview_type, $assessmentB->interview->interview_type);
        $this->assertSame(
            $assessmentA->interview->scheduled_at->toDateTimeString(),
            $assessmentB->interview->scheduled_at->toDateTimeString(),
        );
    }

    public function test_legacy_endpoint_preserves_its_historical_response_contract(): void
    {
        $org = $this->approvedOrganization();
        $opportunity = $this->opportunityFor($org);
        $application = $this->applicationFor($opportunity, 'shortlisted');

        Sanctum::actingAs($org->user);

        $response = $this->postJson("/api/organization/applications/{$application->id}/interview", [
            'interview_type' => 'phone',
            'scheduled_at' => now()->addDays(3)->toDateTimeString(),
            'contact_phone' => '+1 555-0100',
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('message', 'Interview scheduled successfully')
            ->assertJsonPath('data.application.id', $application->id)
            ->assertJsonPath('data.assessment.type', 'interview');

        $this->assertArrayHasKey('assessment', $response->json('data'));
        $this->assertArrayHasKey('application', $response->json('data'));
    }

    public function test_generic_endpoint_preserves_its_own_response_contract(): void
    {
        $org = $this->approvedOrganization();
        $opportunity = $this->opportunityFor($org);
        $application = $this->applicationFor($opportunity, 'shortlisted');

        Sanctum::actingAs($org->user);

        $response = $this->postJson("/api/organization/applications/{$application->id}/assessments", [
            'type' => 'interview',
            'interview' => [
                'interview_type' => 'phone',
                'scheduled_at' => now()->addDays(3)->toDateTimeString(),
                'contact_phone' => '+1 555-0100',
            ],
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('message', 'Assessment created successfully')
            ->assertJsonPath('data.application.id', $application->id)
            ->assertJsonPath('data.interview.interview_type', 'phone');

        $data = $response->json('data');
        $this->assertArrayHasKey('application', $data);
        $this->assertArrayHasKey('interview', $data);
        $this->assertArrayNotHasKey('application', $data['interview']);
    }

    public function test_legacy_then_generic_on_the_same_application_leaves_exactly_one_assessment(): void
    {
        $org = $this->approvedOrganization();
        $opportunity = $this->opportunityFor($org);
        $application = $this->applicationFor($opportunity, 'shortlisted');

        Sanctum::actingAs($org->user);

        $this->postJson("/api/organization/applications/{$application->id}/interview", [
            'interview_type' => 'phone',
            'scheduled_at' => now()->addDays(3)->toDateTimeString(),
            'contact_phone' => '+1 555-0100',
        ])->assertStatus(201);

        $response = $this->postJson("/api/organization/applications/{$application->id}/assessments", [
            'type' => 'interview',
            'interview' => [
                'interview_type' => 'phone',
                'scheduled_at' => now()->addDays(3)->toDateTimeString(),
                'contact_phone' => '+1 555-0100',
            ],
        ]);

        $response->assertStatus(409)
            ->assertJsonPath('message', 'An assessment already exists for this application');

        $this->assertDatabaseCount('assessments', 1);
    }

    public function test_generic_then_legacy_on_the_same_application_leaves_exactly_one_assessment(): void
    {
        $org = $this->approvedOrganization();
        $opportunity = $this->opportunityFor($org);
        $application = $this->applicationFor($opportunity, 'shortlisted');

        Sanctum::actingAs($org->user);

        $this->postJson("/api/organization/applications/{$application->id}/assessments", [
            'type' => 'interview',
            'interview' => [
                'interview_type' => 'phone',
                'scheduled_at' => now()->addDays(3)->toDateTimeString(),
                'contact_phone' => '+1 555-0100',
            ],
        ])->assertStatus(201);

        $response = $this->postJson("/api/organization/applications/{$application->id}/interview", [
            'interview_type' => 'phone',
            'scheduled_at' => now()->addDays(3)->toDateTimeString(),
            'contact_phone' => '+1 555-0100',
        ]);

        $response->assertStatus(409)
            ->assertJsonPath('message', 'An interview already exists for this application');

        $this->assertDatabaseCount('assessments', 1);
    }

    /**
     * The pre-check (`assessment()->exists()`) covers the common case;
     * the DB-level `assessments.application_id` unique constraint is the
     * final authority for a genuine race between two concurrent requests.
     * True concurrency can't be reproduced inside a single synchronous
     * PHPUnit process, so this proves the translation logic directly:
     * a real unique-constraint violation is classified as a duplicate
     * (and would become a clean 409, never raw SQL), while an unrelated
     * QueryException is deliberately left alone rather than being masked
     * as "duplicate".
     */
    public function test_only_the_application_id_unique_violation_is_treated_as_a_duplicate_conflict(): void
    {
        $org = $this->approvedOrganization();
        $opportunity = $this->opportunityFor($org);
        $application = $this->applicationFor($opportunity, 'shortlisted');

        DB::table('assessments')->insert([
            'application_id' => $application->id,
            'type' => 'interview',
            'status' => 'scheduled',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $duplicateViolation = null;

        try {
            DB::table('assessments')->insert([
                'application_id' => $application->id,
                'type' => 'interview',
                'status' => 'scheduled',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } catch (QueryException $e) {
            $duplicateViolation = $e;
        }

        $this->assertNotNull($duplicateViolation, 'Expected the unique constraint to reject the duplicate insert.');

        $unrelatedViolation = null;

        try {
            // A NOT NULL violation on an unrelated table/column -- must
            // never be mistaken for an assessments.application_id conflict.
            DB::table('opportunities')->insert(['organization_id' => $opportunity->organization_id]);
        } catch (QueryException $e) {
            $unrelatedViolation = $e;
        }

        $this->assertNotNull($unrelatedViolation, 'Expected the missing required opportunity fields to fail.');

        $service = app(AssessmentService::class);
        $method = new ReflectionMethod(AssessmentService::class, 'isDuplicateAssessmentViolation');
        $method->setAccessible(true);

        $this->assertTrue(
            $method->invoke($service, $duplicateViolation),
            'A genuine assessments.application_id unique violation must be classified as a duplicate.'
        );
        $this->assertFalse(
            $method->invoke($service, $unrelatedViolation),
            'An unrelated integrity violation must never be masked as a duplicate assessment.'
        );
    }

    public function test_a_translated_duplicate_conflict_never_leaks_sql_details_in_the_response(): void
    {
        $org = $this->approvedOrganization();
        $opportunity = $this->opportunityFor($org);
        $application = $this->applicationFor($opportunity, 'shortlisted');
        $application->assessment()->create(['type' => 'interview', 'status' => 'scheduled', 'result' => null]);

        Sanctum::actingAs($org->user);

        $response = $this->postJson("/api/organization/applications/{$application->id}/assessments", [
            'type' => 'interview',
            'interview' => [
                'interview_type' => 'phone',
                'scheduled_at' => now()->addDays(3)->toDateTimeString(),
                'contact_phone' => '+1 555-0100',
            ],
        ]);

        $response->assertStatus(409);
        $body = $response->getContent();

        foreach (['SQLSTATE', 'QueryException', 'insert into', 'Integrity constraint'] as $needle) {
            $this->assertStringNotContainsString($needle, $body);
        }
    }

    public function test_legacy_and_generic_requests_enforce_the_same_interview_field_requirements(): void
    {
        $legacyRules = (new StoreInterviewRequest())->rules();
        $genericRules = (new StoreAssessmentRequest())->rules();

        $fields = [
            'interview_type', 'scheduled_at', 'duration_minutes',
            'meeting_link', 'location', 'contact_phone', 'interviewer_name', 'interviewer_email', 'notes',
        ];

        foreach ($fields as $field) {
            $this->assertArrayHasKey($field, $legacyRules, "Legacy rules missing {$field}");
            $this->assertArrayHasKey("interview.{$field}", $genericRules, "Generic rules missing interview.{$field}");

            // Every rule string in the legacy set (other than the bare
            // "required") must appear, unprefixed, in the generic set too
            // -- proving both are built from the same shared source and
            // never hand-copied out of sync.
            foreach ($legacyRules[$field] as $rule) {
                if ($rule === 'required') {
                    continue;
                }

                $normalized = str_replace('interview_type', 'interview.interview_type', $rule);

                $this->assertContains(
                    $normalized,
                    $genericRules["interview.{$field}"],
                    "Rule '{$rule}' on legacy '{$field}' has no equivalent in generic 'interview.{$field}'"
                );
            }
        }
    }

    public function test_online_requires_meeting_link_identically_on_both_endpoints(): void
    {
        $org = $this->approvedOrganization();
        $opportunity = $this->opportunityFor($org);

        $legacyApplication = $this->applicationFor($opportunity, 'shortlisted');
        $genericApplication = $this->applicationFor($opportunity, 'shortlisted');

        Sanctum::actingAs($org->user);

        $legacyResponse = $this->postJson("/api/organization/applications/{$legacyApplication->id}/interview", [
            'interview_type' => 'online',
            'scheduled_at' => now()->addDays(2)->toDateTimeString(),
        ]);
        $genericResponse = $this->postJson("/api/organization/applications/{$genericApplication->id}/assessments", [
            'type' => 'interview',
            'interview' => [
                'interview_type' => 'online',
                'scheduled_at' => now()->addDays(2)->toDateTimeString(),
            ],
        ]);

        $legacyResponse->assertStatus(422)->assertJsonValidationErrors(['meeting_link']);
        $genericResponse->assertStatus(422)->assertJsonValidationErrors(['interview.meeting_link']);
    }

    public function test_onsite_requires_location_identically_on_both_endpoints(): void
    {
        $org = $this->approvedOrganization();
        $opportunity = $this->opportunityFor($org);

        $legacyApplication = $this->applicationFor($opportunity, 'shortlisted');
        $genericApplication = $this->applicationFor($opportunity, 'shortlisted');

        Sanctum::actingAs($org->user);

        $legacyResponse = $this->postJson("/api/organization/applications/{$legacyApplication->id}/interview", [
            'interview_type' => 'onsite',
            'scheduled_at' => now()->addDays(2)->toDateTimeString(),
        ]);
        $genericResponse = $this->postJson("/api/organization/applications/{$genericApplication->id}/assessments", [
            'type' => 'interview',
            'interview' => [
                'interview_type' => 'onsite',
                'scheduled_at' => now()->addDays(2)->toDateTimeString(),
            ],
        ]);

        $legacyResponse->assertStatus(422)->assertJsonValidationErrors(['location']);
        $genericResponse->assertStatus(422)->assertJsonValidationErrors(['interview.location']);
    }

    public function test_phone_requires_contact_phone_identically_on_both_endpoints(): void
    {
        $org = $this->approvedOrganization();
        $opportunity = $this->opportunityFor($org);

        $legacyApplication = $this->applicationFor($opportunity, 'shortlisted');
        $genericApplication = $this->applicationFor($opportunity, 'shortlisted');

        Sanctum::actingAs($org->user);

        $legacyResponse = $this->postJson("/api/organization/applications/{$legacyApplication->id}/interview", [
            'interview_type' => 'phone',
            'scheduled_at' => now()->addDays(2)->toDateTimeString(),
        ]);
        $genericResponse = $this->postJson("/api/organization/applications/{$genericApplication->id}/assessments", [
            'type' => 'interview',
            'interview' => [
                'interview_type' => 'phone',
                'scheduled_at' => now()->addDays(2)->toDateTimeString(),
            ],
        ]);

        $legacyResponse->assertStatus(422)->assertJsonValidationErrors(['contact_phone']);
        $genericResponse->assertStatus(422)->assertJsonValidationErrors(['interview.contact_phone']);
    }

    public function test_legacy_validation_behavior_is_unchanged_by_the_shared_rule_source(): void
    {
        $rules = (new StoreInterviewRequest())->rules();

        $this->assertSame(['required', 'in:onsite,online,phone'], $rules['interview_type']);
        $this->assertSame(['required', 'date'], $rules['scheduled_at']);
        $this->assertSame(['nullable', 'integer', 'min:1'], $rules['duration_minutes']);
        $this->assertSame(
            ['required_if:interview_type,online', 'nullable', 'string', 'max:2048', 'url:http,https'],
            $rules['meeting_link']
        );
        $this->assertSame(
            ['required_if:interview_type,onsite', 'nullable', 'string', 'max:255'],
            $rules['location']
        );
        $this->assertSame(
            ['required_if:interview_type,phone', 'nullable', 'string', 'max:30'],
            $rules['contact_phone']
        );
        $this->assertSame(['nullable', 'string', 'max:255'], $rules['interviewer_name']);
        $this->assertSame(['nullable', 'email', 'max:255'], $rules['interviewer_email']);
        $this->assertSame(['nullable', 'string', 'max:2000'], $rules['notes']);
        $this->assertCount(9, $rules);
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
