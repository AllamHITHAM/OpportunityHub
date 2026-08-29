<?php

namespace Tests\Feature\Quizzes;

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
 * Phase 6B-1: POST /api/organization/applications/{application}/assessments
 * with `type=quiz` -- mirrors StoreAssessmentTest's (interview) structure
 * and conventions for the quiz path.
 */
class StoreQuizAssessmentTest extends TestCase
{
    use RefreshDatabase;

    public function test_approved_organization_creates_a_quiz_assessment(): void
    {
        $org = $this->approvedOrganization();
        $opportunity = $this->opportunityFor($org);
        $application = $this->applicationFor($opportunity, 'shortlisted');

        Sanctum::actingAs($org->user);

        $response = $this->postJson(
            "/api/organization/applications/{$application->id}/assessments",
            $this->validQuizPayload()
        );

        $response->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Assessment created successfully')
            ->assertJsonPath('data.application_id', $application->id)
            ->assertJsonPath('data.type', 'quiz')
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.result', null)
            ->assertJsonPath('data.application.id', $application->id)
            ->assertJsonPath('data.quiz.title', 'Backend Fundamentals')
            ->assertJsonPath('data.quiz.instructions', 'Choose the best answer.')
            ->assertJsonPath('data.quiz.time_limit_minutes', 30)
            ->assertJsonPath('data.quiz.passing_score', 70)
            ->assertJsonPath('data.quiz.status', 'draft')
            ->assertJsonPath('data.quiz.questions', []);

        $this->assertDatabaseHas('assessments', [
            'application_id' => $application->id,
            'type' => 'quiz',
            'status' => 'pending',
            'result' => null,
        ]);
        $this->assertDatabaseHas('quizzes', [
            'title' => 'Backend Fundamentals',
            'passing_score' => 70,
            'status' => 'draft',
        ]);
        $this->assertDatabaseCount('assessments', 1);
        $this->assertDatabaseCount('quizzes', 1);
    }

    public function test_creating_a_quiz_assessment_updates_application_status_to_in_assessment(): void
    {
        $org = $this->approvedOrganization();
        $opportunity = $this->opportunityFor($org);
        $application = $this->applicationFor($opportunity, 'shortlisted');

        Sanctum::actingAs($org->user);

        $this->postJson(
            "/api/organization/applications/{$application->id}/assessments",
            $this->validQuizPayload()
        )->assertStatus(201);

        $this->assertDatabaseHas('applications', [
            'id' => $application->id,
            'status' => 'in_assessment',
        ]);
    }

    public function test_creating_a_quiz_assessment_populates_reviewed_at(): void
    {
        $org = $this->approvedOrganization();
        $opportunity = $this->opportunityFor($org);
        $application = $this->applicationFor($opportunity, 'shortlisted');
        $this->assertNull($application->reviewed_at);

        Sanctum::actingAs($org->user);

        $this->postJson(
            "/api/organization/applications/{$application->id}/assessments",
            $this->validQuizPayload()
        )->assertStatus(201);

        $application->refresh();
        $this->assertNotNull($application->reviewed_at);
    }

    public function test_no_questions_are_created_implicitly(): void
    {
        $org = $this->approvedOrganization();
        $opportunity = $this->opportunityFor($org);
        $application = $this->applicationFor($opportunity, 'shortlisted');

        Sanctum::actingAs($org->user);

        $this->postJson(
            "/api/organization/applications/{$application->id}/assessments",
            $this->validQuizPayload()
        )->assertStatus(201);

        $this->assertDatabaseCount('questions', 0);
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
            $this->validQuizPayload()
        );

        $response->assertStatus(404)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Application not found');

        $this->assertDatabaseCount('assessments', 0);
        $this->assertDatabaseCount('quizzes', 0);
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
            // `in_assessment` is deliberately absent as of Phase 10A.3 --
            // see `StoreAssessmentTest::invalidSourceStatuses()`'s own
            // comment for the full explanation; the quiz-specific version
            // of that now-allowed case is
            // test_in_assessment_with_no_active_assessment_is_allowed
            // below.
        ];
    }

    /**
     * The quiz-specific counterpart to
     * `StoreAssessmentTest::test_in_assessment_with_no_active_assessment_is_allowed()`
     * -- Phase 10A.3's generic backend capability doesn't restrict what
     * *type* the follow-up Assessment is, even though the Flutter "Advance
     * to Interview" UI action specifically only ever creates an Interview.
     */
    public function test_in_assessment_with_no_active_assessment_is_allowed(): void
    {
        $org = $this->approvedOrganization();
        $opportunity = $this->opportunityFor($org);
        $application = $this->applicationFor($opportunity, 'in_assessment');

        Sanctum::actingAs($org->user);

        $response = $this->postJson(
            "/api/organization/applications/{$application->id}/assessments",
            $this->validQuizPayload()
        );

        $response->assertStatus(201)
            ->assertJsonPath('success', true);

        $this->assertDatabaseCount('assessments', 1);
        $this->assertDatabaseCount('quizzes', 1);
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
            $this->validQuizPayload()
        );

        $response->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'An assessment can only be created for shortlisted applications');

        $this->assertDatabaseCount('assessments', 0);
        $this->assertDatabaseCount('quizzes', 0);
    }

    public function test_duplicate_assessment_returns_409(): void
    {
        $org = $this->approvedOrganization();
        $opportunity = $this->opportunityFor($org);
        $application = $this->applicationFor($opportunity, 'shortlisted');
        $application->assessment()->create(['type' => 'quiz', 'status' => 'pending', 'result' => null]);

        Sanctum::actingAs($org->user);

        $response = $this->postJson(
            "/api/organization/applications/{$application->id}/assessments",
            $this->validQuizPayload()
        );

        $response->assertStatus(409)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'An assessment already exists for this application');

        $this->assertDatabaseCount('assessments', 1);
        $this->assertDatabaseCount('quizzes', 0);
    }

    public function test_a_prior_interview_assessment_blocks_a_quiz_assessment(): void
    {
        $org = $this->approvedOrganization();
        $opportunity = $this->opportunityFor($org);
        $application = $this->applicationFor($opportunity, 'shortlisted');
        $application->assessment()->create(['type' => 'interview', 'status' => 'scheduled', 'result' => null]);

        Sanctum::actingAs($org->user);

        $response = $this->postJson(
            "/api/organization/applications/{$application->id}/assessments",
            $this->validQuizPayload()
        );

        $response->assertStatus(409)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'An assessment already exists for this application');

        $this->assertDatabaseCount('quizzes', 0);
    }

    public function test_missing_quiz_object_for_type_quiz_returns_validation_422(): void
    {
        $org = $this->approvedOrganization();
        $opportunity = $this->opportunityFor($org);
        $application = $this->applicationFor($opportunity, 'shortlisted');

        Sanctum::actingAs($org->user);

        $response = $this->postJson(
            "/api/organization/applications/{$application->id}/assessments",
            ['type' => 'quiz']
        );

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['quiz']);

        $this->assertDatabaseCount('assessments', 0);
    }

    public function test_missing_title_returns_validation_422(): void
    {
        $org = $this->approvedOrganization();
        $opportunity = $this->opportunityFor($org);
        $application = $this->applicationFor($opportunity, 'shortlisted');

        Sanctum::actingAs($org->user);

        $response = $this->postJson("/api/organization/applications/{$application->id}/assessments", [
            'type' => 'quiz',
            'quiz' => [
                'passing_score' => 70,
            ],
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['quiz.title']);

        $this->assertDatabaseCount('assessments', 0);
    }

    public function test_title_over_max_length_returns_validation_422(): void
    {
        $org = $this->approvedOrganization();
        $opportunity = $this->opportunityFor($org);
        $application = $this->applicationFor($opportunity, 'shortlisted');

        Sanctum::actingAs($org->user);

        $response = $this->postJson("/api/organization/applications/{$application->id}/assessments", [
            'type' => 'quiz',
            'quiz' => [
                'title' => str_repeat('a', 256),
                'passing_score' => 70,
            ],
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['quiz.title']);
    }

    public function test_missing_passing_score_returns_validation_422(): void
    {
        $org = $this->approvedOrganization();
        $opportunity = $this->opportunityFor($org);
        $application = $this->applicationFor($opportunity, 'shortlisted');

        Sanctum::actingAs($org->user);

        $response = $this->postJson("/api/organization/applications/{$application->id}/assessments", [
            'type' => 'quiz',
            'quiz' => [
                'title' => 'Backend Fundamentals',
            ],
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['quiz.passing_score']);

        $this->assertDatabaseCount('assessments', 0);
    }

    /**
     * @return array<string, array{0: int}>
     */
    public static function outOfRangePassingScores(): array
    {
        return [
            'negative' => [-1],
            'over 100' => [101],
        ];
    }

    #[DataProvider('outOfRangePassingScores')]
    public function test_passing_score_out_of_range_returns_validation_422(int $passingScore): void
    {
        $org = $this->approvedOrganization();
        $opportunity = $this->opportunityFor($org);
        $application = $this->applicationFor($opportunity, 'shortlisted');

        Sanctum::actingAs($org->user);

        $response = $this->postJson("/api/organization/applications/{$application->id}/assessments", [
            'type' => 'quiz',
            'quiz' => [
                'title' => 'Backend Fundamentals',
                'passing_score' => $passingScore,
            ],
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['quiz.passing_score']);
    }

    public function test_time_limit_minutes_below_one_returns_validation_422(): void
    {
        $org = $this->approvedOrganization();
        $opportunity = $this->opportunityFor($org);
        $application = $this->applicationFor($opportunity, 'shortlisted');

        Sanctum::actingAs($org->user);

        $response = $this->postJson("/api/organization/applications/{$application->id}/assessments", [
            'type' => 'quiz',
            'quiz' => [
                'title' => 'Backend Fundamentals',
                'passing_score' => 70,
                'time_limit_minutes' => 0,
            ],
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['quiz.time_limit_minutes']);
    }

    public function test_time_limit_minutes_is_optional(): void
    {
        $org = $this->approvedOrganization();
        $opportunity = $this->opportunityFor($org);
        $application = $this->applicationFor($opportunity, 'shortlisted');

        Sanctum::actingAs($org->user);

        $response = $this->postJson("/api/organization/applications/{$application->id}/assessments", [
            'type' => 'quiz',
            'quiz' => [
                'title' => 'Backend Fundamentals',
                'passing_score' => 70,
            ],
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.quiz.time_limit_minutes', null);
    }

    public function test_instructions_are_optional(): void
    {
        $org = $this->approvedOrganization();
        $opportunity = $this->opportunityFor($org);
        $application = $this->applicationFor($opportunity, 'shortlisted');

        Sanctum::actingAs($org->user);

        $response = $this->postJson("/api/organization/applications/{$application->id}/assessments", [
            'type' => 'quiz',
            'quiz' => [
                'title' => 'Backend Fundamentals',
                'passing_score' => 70,
            ],
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.quiz.instructions', null);
    }

    // ---- Phase 10A.2: display mode / result release -----------------

    public function test_display_mode_and_result_release_mode_default_when_omitted(): void
    {
        $org = $this->approvedOrganization();
        $opportunity = $this->opportunityFor($org);
        $application = $this->applicationFor($opportunity, 'shortlisted');

        Sanctum::actingAs($org->user);

        $response = $this->postJson(
            "/api/organization/applications/{$application->id}/assessments",
            $this->validQuizPayload()
        );

        $response->assertStatus(201)
            ->assertJsonPath('data.quiz.display_mode', 'all')
            ->assertJsonPath('data.quiz.questions_per_page', null)
            ->assertJsonPath('data.quiz.result_release_mode', 'immediate')
            ->assertJsonPath('data.quiz.result_release_at', null);
    }

    public function test_paginated_display_mode_persists_questions_per_page(): void
    {
        $org = $this->approvedOrganization();
        $opportunity = $this->opportunityFor($org);
        $application = $this->applicationFor($opportunity, 'shortlisted');

        Sanctum::actingAs($org->user);

        $response = $this->postJson("/api/organization/applications/{$application->id}/assessments", [
            'type' => 'quiz',
            'quiz' => [
                'title' => 'Backend Fundamentals',
                'passing_score' => 70,
                'display_mode' => 'paginated',
                'questions_per_page' => 3,
            ],
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.quiz.display_mode', 'paginated')
            ->assertJsonPath('data.quiz.questions_per_page', 3);
    }

    public function test_paginated_display_mode_without_questions_per_page_is_rejected(): void
    {
        $org = $this->approvedOrganization();
        $opportunity = $this->opportunityFor($org);
        $application = $this->applicationFor($opportunity, 'shortlisted');

        Sanctum::actingAs($org->user);

        $response = $this->postJson("/api/organization/applications/{$application->id}/assessments", [
            'type' => 'quiz',
            'quiz' => [
                'title' => 'Backend Fundamentals',
                'passing_score' => 70,
                'display_mode' => 'paginated',
            ],
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['quiz.questions_per_page']);
        $this->assertDatabaseCount('quizzes', 0);
    }

    public function test_single_display_mode_does_not_require_questions_per_page(): void
    {
        $org = $this->approvedOrganization();
        $opportunity = $this->opportunityFor($org);
        $application = $this->applicationFor($opportunity, 'shortlisted');

        Sanctum::actingAs($org->user);

        $response = $this->postJson("/api/organization/applications/{$application->id}/assessments", [
            'type' => 'quiz',
            'quiz' => [
                'title' => 'Backend Fundamentals',
                'passing_score' => 70,
                'display_mode' => 'single',
            ],
        ]);

        $response->assertStatus(201)->assertJsonPath('data.quiz.display_mode', 'single');
    }

    public function test_an_invalid_display_mode_is_rejected(): void
    {
        $org = $this->approvedOrganization();
        $opportunity = $this->opportunityFor($org);
        $application = $this->applicationFor($opportunity, 'shortlisted');

        Sanctum::actingAs($org->user);

        $response = $this->postJson("/api/organization/applications/{$application->id}/assessments", [
            'type' => 'quiz',
            'quiz' => [
                'title' => 'Backend Fundamentals',
                'passing_score' => 70,
                'display_mode' => 'grid',
            ],
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors(['quiz.display_mode']);
    }

    public function test_scheduled_result_release_persists_the_release_time(): void
    {
        $org = $this->approvedOrganization();
        $opportunity = $this->opportunityFor($org);
        $application = $this->applicationFor($opportunity, 'shortlisted');
        $releaseAt = now()->addWeek();

        Sanctum::actingAs($org->user);

        $response = $this->postJson("/api/organization/applications/{$application->id}/assessments", [
            'type' => 'quiz',
            'quiz' => [
                'title' => 'Backend Fundamentals',
                'passing_score' => 70,
                'result_release_mode' => 'scheduled',
                'result_release_at' => $releaseAt->toISOString(),
            ],
        ]);

        $response->assertStatus(201)->assertJsonPath('data.quiz.result_release_mode', 'scheduled');
        $this->assertNotNull($response->json('data.quiz.result_release_at'));
    }

    public function test_scheduled_result_release_without_a_release_time_is_rejected(): void
    {
        $org = $this->approvedOrganization();
        $opportunity = $this->opportunityFor($org);
        $application = $this->applicationFor($opportunity, 'shortlisted');

        Sanctum::actingAs($org->user);

        $response = $this->postJson("/api/organization/applications/{$application->id}/assessments", [
            'type' => 'quiz',
            'quiz' => [
                'title' => 'Backend Fundamentals',
                'passing_score' => 70,
                'result_release_mode' => 'scheduled',
            ],
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['quiz.result_release_at']);
        $this->assertDatabaseCount('quizzes', 0);
    }

    public function test_a_result_release_time_in_the_past_is_rejected(): void
    {
        $org = $this->approvedOrganization();
        $opportunity = $this->opportunityFor($org);
        $application = $this->applicationFor($opportunity, 'shortlisted');

        Sanctum::actingAs($org->user);

        $response = $this->postJson("/api/organization/applications/{$application->id}/assessments", [
            'type' => 'quiz',
            'quiz' => [
                'title' => 'Backend Fundamentals',
                'passing_score' => 70,
                'result_release_mode' => 'scheduled',
                'result_release_at' => now()->subDay()->toISOString(),
            ],
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['quiz.result_release_at']);
    }

    public function test_manual_result_release_mode_is_accepted(): void
    {
        $org = $this->approvedOrganization();
        $opportunity = $this->opportunityFor($org);
        $application = $this->applicationFor($opportunity, 'shortlisted');

        Sanctum::actingAs($org->user);

        $response = $this->postJson("/api/organization/applications/{$application->id}/assessments", [
            'type' => 'quiz',
            'quiz' => [
                'title' => 'Backend Fundamentals',
                'passing_score' => 70,
                'result_release_mode' => 'manual',
            ],
        ]);

        $response->assertStatus(201)->assertJsonPath('data.quiz.result_release_mode', 'manual');
    }

    public function test_guest_receives_401(): void
    {
        $org = $this->approvedOrganization();
        $opportunity = $this->opportunityFor($org);
        $application = $this->applicationFor($opportunity, 'shortlisted');

        $response = $this->postJson(
            "/api/organization/applications/{$application->id}/assessments",
            $this->validQuizPayload()
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
            $this->validQuizPayload()
        );

        $response->assertStatus(403)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'This action is unauthorized for your account type');
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

    /**
     * @return array<string, mixed>
     */
    private function validQuizPayload(): array
    {
        return [
            'type' => 'quiz',
            'quiz' => [
                'title' => 'Backend Fundamentals',
                'instructions' => 'Choose the best answer.',
                'time_limit_minutes' => 30,
                'passing_score' => 70,
            ],
        ];
    }
}
