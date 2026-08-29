<?php

namespace Tests\Feature\Assessments;

use App\Models\Application;
use App\Models\Opportunity;
use App\Models\OrganizationProfile;
use App\Models\StudentProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Phase 4A-2: POST /api/organization/applications/{application}/assessments
 */
class StoreAssessmentTest extends TestCase
{
    use RefreshDatabase;

    public function test_approved_organization_creates_an_interview_assessment(): void
    {
        $org = $this->approvedOrganization();
        $opportunity = $this->opportunityFor($org);
        $application = $this->applicationFor($opportunity, 'shortlisted');

        Sanctum::actingAs($org->user);

        $response = $this->postJson(
            "/api/organization/applications/{$application->id}/assessments",
            $this->validAssessmentPayload()
        );

        $response->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Assessment created successfully')
            ->assertJsonPath('data.application_id', $application->id)
            ->assertJsonPath('data.type', 'interview')
            ->assertJsonPath('data.status', 'scheduled')
            ->assertJsonPath('data.result', null)
            ->assertJsonPath('data.application.id', $application->id)
            ->assertJsonPath('data.application.cv.id', $application->cv_id)
            ->assertJsonPath('data.application.cv.file_path', 'cvs/my-cv.pdf')
            ->assertJsonPath('data.interview.interview_type', 'online')
            ->assertJsonPath('data.interview.meeting_link', 'https://meet.example.com/room');

        $this->assertDatabaseHas('assessments', [
            'application_id' => $application->id,
            'type' => 'interview',
            'status' => 'scheduled',
            'result' => null,
        ]);
        $this->assertDatabaseHas('interviews', [
            'interview_type' => 'online',
            'meeting_link' => 'https://meet.example.com/room',
        ]);
        $this->assertDatabaseCount('assessments', 1);
        $this->assertDatabaseCount('interviews', 1);
    }

    public function test_creating_an_assessment_updates_application_status_to_in_assessment(): void
    {
        $org = $this->approvedOrganization();
        $opportunity = $this->opportunityFor($org);
        $application = $this->applicationFor($opportunity, 'shortlisted');

        Sanctum::actingAs($org->user);

        $this->postJson(
            "/api/organization/applications/{$application->id}/assessments",
            $this->validAssessmentPayload()
        )->assertStatus(201);

        $this->assertDatabaseHas('applications', [
            'id' => $application->id,
            'status' => 'in_assessment',
        ]);
        $this->assertDatabaseMissing('applications', [
            'id' => $application->id,
            'status' => 'interview_scheduled',
        ]);
    }

    public function test_creating_an_assessment_populates_reviewed_at(): void
    {
        $org = $this->approvedOrganization();
        $opportunity = $this->opportunityFor($org);
        $application = $this->applicationFor($opportunity, 'shortlisted');
        $this->assertNull($application->reviewed_at);

        Sanctum::actingAs($org->user);

        $this->postJson(
            "/api/organization/applications/{$application->id}/assessments",
            $this->validAssessmentPayload()
        )->assertStatus(201);

        $application->refresh();
        $this->assertNotNull($application->reviewed_at);
    }

    public function test_response_excludes_interview_application_to_avoid_redundant_nesting(): void
    {
        $org = $this->approvedOrganization();
        $opportunity = $this->opportunityFor($org);
        $application = $this->applicationFor($opportunity, 'shortlisted');

        Sanctum::actingAs($org->user);

        $data = $this->postJson(
            "/api/organization/applications/{$application->id}/assessments",
            $this->validAssessmentPayload()
        )->assertStatus(201)->json('data');

        $this->assertArrayHasKey('application', $data);
        $this->assertArrayHasKey('interview', $data);
        $this->assertArrayNotHasKey('application', $data['interview']);
    }

    public function test_another_organizations_application_returns_404(): void
    {
        $orgA = $this->approvedOrganization();
        $opportunity = $this->opportunityFor($orgA);
        $application = $this->applicationFor($opportunity, 'shortlisted');

        $orgB = $this->approvedOrganization();
        Sanctum::actingAs($orgB->user);

        $response = $this->postJson(
            "/api/organization/applications/{$application->id}/assessments",
            $this->validAssessmentPayload()
        );

        $response->assertStatus(404)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Application not found');

        $this->assertDatabaseCount('assessments', 0);
    }

    public function test_guest_receives_401(): void
    {
        $org = $this->approvedOrganization();
        $opportunity = $this->opportunityFor($org);
        $application = $this->applicationFor($opportunity, 'shortlisted');

        $response = $this->postJson(
            "/api/organization/applications/{$application->id}/assessments",
            $this->validAssessmentPayload()
        );

        $response->assertStatus(401)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Unauthenticated');
    }

    public function test_student_receives_403(): void
    {
        $org = $this->approvedOrganization();
        $opportunity = $this->opportunityFor($org);
        $application = $this->applicationFor($opportunity, 'shortlisted');

        $studentUser = User::factory()->create(['role' => 'student', 'status' => 'active']);
        Sanctum::actingAs($studentUser);

        $response = $this->postJson(
            "/api/organization/applications/{$application->id}/assessments",
            $this->validAssessmentPayload()
        );

        $response->assertStatus(403)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'This action is unauthorized for your account type');
    }

    public function test_admin_receives_403(): void
    {
        $org = $this->approvedOrganization();
        $opportunity = $this->opportunityFor($org);
        $application = $this->applicationFor($opportunity, 'shortlisted');

        $adminUser = User::factory()->create(['role' => 'admin', 'status' => 'active']);
        Sanctum::actingAs($adminUser);

        $response = $this->postJson(
            "/api/organization/applications/{$application->id}/assessments",
            $this->validAssessmentPayload()
        );

        $response->assertStatus(403)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'This action is unauthorized for your account type');
    }

    public function test_inactive_organization_receives_403(): void
    {
        $org = $this->approvedOrganization();
        $opportunity = $this->opportunityFor($org);
        $application = $this->applicationFor($opportunity, 'shortlisted');

        $org->user->status = 'suspended';
        $org->user->save();

        Sanctum::actingAs($org->user);

        $response = $this->postJson(
            "/api/organization/applications/{$application->id}/assessments",
            $this->validAssessmentPayload()
        );

        $response->assertStatus(403)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Account is not active');
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function invalidSourceStatuses(): array
    {
        return [
            'pending' => ['pending'],
            'reviewed' => ['reviewed'],
            'offer_sent' => ['offer_sent'],
            'accepted' => ['accepted'],
            'rejected' => ['rejected'],
            'withdrawn' => ['withdrawn'],
            // `in_assessment` is deliberately absent from this list as of
            // Phase 10A.3 -- it's no longer categorically invalid, since it
            // now also describes a real, legitimate state: an ongoing
            // evaluation chain whose most recent Assessment has already
            // been finalized (a completed Quiz, before "Advance to
            // Interview"). See
            // test_in_assessment_with_no_active_assessment_is_allowed
            // below for that case, and
            // test_duplicate_assessment_at_in_assessment_status_is_blocked
            // for the case this list used to cover on its own -- an
            // `in_assessment` application whose Assessment is still active,
            // which is still correctly blocked, just by
            // assertNoActiveAssessment() alone now rather than by both
            // guards redundantly.
        ];
    }

    /**
     * The Phase 10A.3 case carved out of `invalidSourceStatuses()` above:
     * an `in_assessment` application with no *active* Assessment blocking
     * it (either no Assessment at all -- an edge case, since `in_assessment`
     * is normally only ever set alongside a real Assessment -- or, more
     * realistically, one that's already `completed`) is now a legitimate
     * source for a new Assessment, matching "Advance to Interview".
     */
    public function test_in_assessment_with_no_active_assessment_is_allowed(): void
    {
        $org = $this->approvedOrganization();
        $opportunity = $this->opportunityFor($org);
        $application = $this->applicationFor($opportunity, 'in_assessment');

        Sanctum::actingAs($org->user);

        $response = $this->postJson(
            "/api/organization/applications/{$application->id}/assessments",
            $this->validAssessmentPayload()
        );

        $response->assertStatus(201)
            ->assertJsonPath('success', true);

        $this->assertDatabaseCount('assessments', 1);
    }

    #[DataProvider('invalidSourceStatuses')]
    public function test_invalid_source_application_status_is_blocked(string $status): void
    {
        $org = $this->approvedOrganization();
        $opportunity = $this->opportunityFor($org);
        $application = $this->applicationFor($opportunity, $status);

        Sanctum::actingAs($org->user);

        $response = $this->postJson(
            "/api/organization/applications/{$application->id}/assessments",
            $this->validAssessmentPayload()
        );

        $response->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'An assessment can only be created for shortlisted applications');

        $this->assertDatabaseCount('assessments', 0);
    }

    public function test_duplicate_assessment_returns_409(): void
    {
        $org = $this->approvedOrganization();
        $opportunity = $this->opportunityFor($org);
        $application = $this->applicationFor($opportunity, 'shortlisted');
        $application->assessment()->create(['type' => 'interview', 'status' => 'scheduled', 'result' => null]);

        Sanctum::actingAs($org->user);

        $response = $this->postJson(
            "/api/organization/applications/{$application->id}/assessments",
            $this->validAssessmentPayload()
        );

        $response->assertStatus(409)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'An assessment already exists for this application');

        $this->assertDatabaseCount('assessments', 1);
        $this->assertDatabaseCount('interviews', 0);
    }

    /**
     * The real-world shape of an already-`in_assessment` application: a
     * genuine Assessment already exists, matching what
     * AssessmentService::transitionToInAssessment() always produces.
     * Existing-assessment is checked before source-status (see
     * AssessmentService::createInterviewAssessment()), so this still
     * reports as the same "already exists" 409 as any other duplicate --
     * either way, no second assessment is ever created.
     */
    public function test_duplicate_assessment_at_in_assessment_status_is_blocked(): void
    {
        $org = $this->approvedOrganization();
        $opportunity = $this->opportunityFor($org);
        $application = $this->applicationFor($opportunity, 'shortlisted');
        $application->assessment()->create(['type' => 'interview', 'status' => 'scheduled', 'result' => null]);
        $application->status = 'in_assessment';
        $application->save();

        Sanctum::actingAs($org->user);

        $response = $this->postJson(
            "/api/organization/applications/{$application->id}/assessments",
            $this->validAssessmentPayload()
        );

        $response->assertStatus(409)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'An assessment already exists for this application');

        $this->assertDatabaseCount('assessments', 1);
        $this->assertDatabaseCount('interviews', 0);
    }

    public function test_unknown_type_returns_standard_validation_422(): void
    {
        $org = $this->approvedOrganization();
        $opportunity = $this->opportunityFor($org);
        $application = $this->applicationFor($opportunity, 'shortlisted');

        Sanctum::actingAs($org->user);

        $response = $this->postJson(
            "/api/organization/applications/{$application->id}/assessments",
            ['type' => 'not-a-real-type']
        );

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['type']);

        $this->assertDatabaseCount('assessments', 0);
    }

    public function test_missing_type_returns_validation_422(): void
    {
        $org = $this->approvedOrganization();
        $opportunity = $this->opportunityFor($org);
        $application = $this->applicationFor($opportunity, 'shortlisted');

        Sanctum::actingAs($org->user);

        $response = $this->postJson(
            "/api/organization/applications/{$application->id}/assessments",
            []
        );

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['type']);

        $this->assertDatabaseCount('assessments', 0);
    }

    public function test_missing_interview_object_for_type_interview_returns_validation_422(): void
    {
        $org = $this->approvedOrganization();
        $opportunity = $this->opportunityFor($org);
        $application = $this->applicationFor($opportunity, 'shortlisted');

        Sanctum::actingAs($org->user);

        $response = $this->postJson(
            "/api/organization/applications/{$application->id}/assessments",
            ['type' => 'interview']
        );

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['interview']);

        $this->assertDatabaseCount('assessments', 0);
    }

    public function test_online_interview_without_meeting_link_returns_validation_422(): void
    {
        $org = $this->approvedOrganization();
        $opportunity = $this->opportunityFor($org);
        $application = $this->applicationFor($opportunity, 'shortlisted');

        Sanctum::actingAs($org->user);

        $response = $this->postJson(
            "/api/organization/applications/{$application->id}/assessments",
            [
                'type' => 'interview',
                'interview' => [
                    'interview_type' => 'online',
                    'scheduled_at' => now()->addDays(2)->toDateTimeString(),
                ],
            ]
        );

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['interview.meeting_link']);

        $this->assertDatabaseCount('assessments', 0);
    }

    public function test_onsite_interview_without_location_returns_validation_422(): void
    {
        $org = $this->approvedOrganization();
        $opportunity = $this->opportunityFor($org);
        $application = $this->applicationFor($opportunity, 'shortlisted');

        Sanctum::actingAs($org->user);

        $response = $this->postJson(
            "/api/organization/applications/{$application->id}/assessments",
            [
                'type' => 'interview',
                'interview' => [
                    'interview_type' => 'onsite',
                    'scheduled_at' => now()->addDays(2)->toDateTimeString(),
                ],
            ]
        );

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['interview.location']);

        $this->assertDatabaseCount('assessments', 0);
    }

    public function test_missing_scheduled_at_returns_validation_422(): void
    {
        $org = $this->approvedOrganization();
        $opportunity = $this->opportunityFor($org);
        $application = $this->applicationFor($opportunity, 'shortlisted');

        Sanctum::actingAs($org->user);

        $response = $this->postJson(
            "/api/organization/applications/{$application->id}/assessments",
            [
                'type' => 'interview',
                'interview' => [
                    'interview_type' => 'phone',
                ],
            ]
        );

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['interview.scheduled_at']);

        $this->assertDatabaseCount('assessments', 0);
    }

    public function test_invalid_scheduled_at_returns_validation_422(): void
    {
        $org = $this->approvedOrganization();
        $opportunity = $this->opportunityFor($org);
        $application = $this->applicationFor($opportunity, 'shortlisted');

        Sanctum::actingAs($org->user);

        $response = $this->postJson(
            "/api/organization/applications/{$application->id}/assessments",
            [
                'type' => 'interview',
                'interview' => [
                    'interview_type' => 'phone',
                    'scheduled_at' => 'not-a-real-date',
                ],
            ]
        );

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['interview.scheduled_at']);

        $this->assertDatabaseCount('assessments', 0);
    }

    public function test_no_partial_assessment_remains_after_a_validation_failure(): void
    {
        $org = $this->approvedOrganization();
        $opportunity = $this->opportunityFor($org);
        $application = $this->applicationFor($opportunity, 'shortlisted');

        Sanctum::actingAs($org->user);

        $this->postJson("/api/organization/applications/{$application->id}/assessments", [
            'type' => 'interview',
            'interview' => ['interview_type' => 'online'],
        ])->assertStatus(422);

        $this->assertDatabaseCount('assessments', 0);
        $this->assertDatabaseCount('interviews', 0);
        $this->assertDatabaseHas('applications', [
            'id' => $application->id,
            'status' => 'shortlisted',
        ]);
    }

    public function test_no_partial_assessment_remains_after_a_duplicate_conflict(): void
    {
        $org = $this->approvedOrganization();
        $opportunity = $this->opportunityFor($org);
        $application = $this->applicationFor($opportunity, 'shortlisted');
        $application->assessment()->create(['type' => 'interview', 'status' => 'scheduled', 'result' => null]);

        Sanctum::actingAs($org->user);

        $this->postJson(
            "/api/organization/applications/{$application->id}/assessments",
            $this->validAssessmentPayload()
        )->assertStatus(409);

        $this->assertDatabaseCount('assessments', 1);
        $this->assertDatabaseCount('interviews', 0);
    }

    public function test_nested_request_fields_persist_correctly(): void
    {
        $org = $this->approvedOrganization();
        $opportunity = $this->opportunityFor($org);
        $application = $this->applicationFor($opportunity, 'shortlisted');

        Sanctum::actingAs($org->user);

        $scheduledAt = now()->addDays(5)->toDateTimeString();

        $response = $this->postJson("/api/organization/applications/{$application->id}/assessments", [
            'type' => 'interview',
            'interview' => [
                'interview_type' => 'onsite',
                'scheduled_at' => $scheduledAt,
                'duration_minutes' => 45,
                'location' => '221B Baker Street',
                'interviewer_name' => 'Jane Recruiter',
                'interviewer_email' => 'jane@example.com',
                'notes' => 'Bring a laptop.',
            ],
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.interview.interview_type', 'onsite')
            ->assertJsonPath('data.interview.duration_minutes', 45)
            ->assertJsonPath('data.interview.location', '221B Baker Street')
            ->assertJsonPath('data.interview.interviewer_name', 'Jane Recruiter')
            ->assertJsonPath('data.interview.interviewer_email', 'jane@example.com')
            ->assertJsonPath('data.interview.notes', 'Bring a laptop.');

        $this->assertDatabaseHas('interviews', [
            'interview_type' => 'onsite',
            'duration_minutes' => 45,
            'location' => '221B Baker Street',
            'interviewer_name' => 'Jane Recruiter',
            'interviewer_email' => 'jane@example.com',
            'notes' => 'Bring a laptop.',
        ]);
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

    private function validAssessmentPayload(array $interviewOverrides = []): array
    {
        return [
            'type' => 'interview',
            'interview' => array_merge([
                'interview_type' => 'online',
                'scheduled_at' => now()->addDays(3)->toDateTimeString(),
                'meeting_link' => 'https://meet.example.com/room',
            ], $interviewOverrides),
        ];
    }
}
