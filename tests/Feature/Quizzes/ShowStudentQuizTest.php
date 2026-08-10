<?php

namespace Tests\Feature\Quizzes;

use App\Models\Application;
use App\Models\Assessment;
use App\Models\Opportunity;
use App\Models\OrganizationProfile;
use App\Models\Quiz;
use App\Models\StudentProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Phase 6B-3: GET /api/student/assessments/{assessment}/quiz
 */
class ShowStudentQuizTest extends TestCase
{
    use RefreshDatabase;

    public function test_student_sees_their_own_published_quiz(): void
    {
        $org = $this->approvedOrganization();
        $opportunity = $this->opportunityFor($org);
        $student = $this->studentWithProfileAndCv();
        $application = $this->applicationForStudent($opportunity, $student);
        [$assessment, $quiz] = $this->quizFor($application, status: 'published');
        $quiz->questions()->create($this->mcqAttributes());

        Sanctum::actingAs($student->user);

        $response = $this->getJson("/api/student/assessments/{$assessment->id}/quiz");

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.id', $quiz->id)
            ->assertJsonPath('data.title', 'Backend Fundamentals')
            ->assertJsonPath('data.passing_score', 70)
            ->assertJsonPath('data.status', 'published')
            ->assertJsonCount(1, 'data.questions');
    }

    public function test_draft_quiz_is_hidden(): void
    {
        $org = $this->approvedOrganization();
        $opportunity = $this->opportunityFor($org);
        $student = $this->studentWithProfileAndCv();
        $application = $this->applicationForStudent($opportunity, $student);
        [$assessment] = $this->quizFor($application, status: 'draft');

        Sanctum::actingAs($student->user);

        $response = $this->getJson("/api/student/assessments/{$assessment->id}/quiz");

        $response->assertStatus(404)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Quiz not found');
    }

    public function test_another_students_quiz_is_denied(): void
    {
        $org = $this->approvedOrganization();
        $opportunity = $this->opportunityFor($org);
        $owner = $this->studentWithProfileAndCv();
        $ownerApplication = $this->applicationForStudent($opportunity, $owner);
        [$assessment] = $this->quizFor($ownerApplication, status: 'published');

        $otherStudent = $this->studentWithProfileAndCv();
        Sanctum::actingAs($otherStudent->user);

        $response = $this->getJson("/api/student/assessments/{$assessment->id}/quiz");

        $response->assertStatus(404)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Quiz not found');
    }

    public function test_interview_type_assessment_returns_not_found(): void
    {
        $org = $this->approvedOrganization();
        $opportunity = $this->opportunityFor($org);
        $student = $this->studentWithProfileAndCv();
        $application = $this->applicationForStudent($opportunity, $student);
        $assessment = $application->assessment()->create([
            'type' => 'interview',
            'status' => 'scheduled',
            'result' => null,
        ]);

        Sanctum::actingAs($student->user);

        $response = $this->getJson("/api/student/assessments/{$assessment->id}/quiz");

        $response->assertStatus(404)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Quiz not found');
    }

    public function test_correct_answer_is_absent_from_the_response(): void
    {
        $org = $this->approvedOrganization();
        $opportunity = $this->opportunityFor($org);
        $student = $this->studentWithProfileAndCv();
        $application = $this->applicationForStudent($opportunity, $student);
        [$assessment, $quiz] = $this->quizFor($application, status: 'published');
        $quiz->questions()->create($this->mcqAttributes());

        Sanctum::actingAs($student->user);

        $response = $this->getJson("/api/student/assessments/{$assessment->id}/quiz");

        $response->assertStatus(200);
        $this->assertArrayNotHasKey('correct_answer', $response->json('data.questions.0'));
    }

    public function test_true_false_options_are_null_the_same_as_the_organization_side(): void
    {
        $org = $this->approvedOrganization();
        $opportunity = $this->opportunityFor($org);
        $student = $this->studentWithProfileAndCv();
        $application = $this->applicationForStudent($opportunity, $student);
        [$assessment, $quiz] = $this->quizFor($application, status: 'published');
        $quiz->questions()->create([
            'prompt' => 'The sky is blue.',
            'type' => 'true_false',
            'options' => null,
            'correct_answer' => 'True',
            'points' => 1,
            'position' => 0,
        ]);

        Sanctum::actingAs($student->user);

        $response = $this->getJson("/api/student/assessments/{$assessment->id}/quiz");

        $response->assertStatus(200)
            ->assertJsonPath('data.questions.0.type', 'true_false')
            ->assertJsonPath('data.questions.0.options', null);
        $this->assertArrayNotHasKey('correct_answer', $response->json('data.questions.0'));
    }

    public function test_questions_are_ordered_by_position(): void
    {
        $org = $this->approvedOrganization();
        $opportunity = $this->opportunityFor($org);
        $student = $this->studentWithProfileAndCv();
        $application = $this->applicationForStudent($opportunity, $student);
        [$assessment, $quiz] = $this->quizFor($application, status: 'published');
        $quiz->questions()->create(array_merge($this->mcqAttributes(), ['position' => 2, 'prompt' => 'Second']));
        $quiz->questions()->create(array_merge($this->mcqAttributes(), ['position' => 1, 'prompt' => 'First']));

        Sanctum::actingAs($student->user);

        $response = $this->getJson("/api/student/assessments/{$assessment->id}/quiz");

        $response->assertStatus(200)
            ->assertJsonPath('data.questions.0.prompt', 'First')
            ->assertJsonPath('data.questions.1.prompt', 'Second');
    }

    public function test_organization_only_fields_are_absent(): void
    {
        $org = $this->approvedOrganization();
        $opportunity = $this->opportunityFor($org);
        $student = $this->studentWithProfileAndCv();
        $application = $this->applicationForStudent($opportunity, $student);
        [$assessment, $quiz] = $this->quizFor($application, status: 'published');
        $quiz->questions()->create($this->mcqAttributes());

        Sanctum::actingAs($student->user);

        $response = $this->getJson("/api/student/assessments/{$assessment->id}/quiz");

        foreach (\App\Models\Question::ORGANIZATION_ONLY_FIELDS as $field) {
            $this->assertArrayNotHasKey($field, $response->json('data.questions.0'));
        }
    }

    public function test_guest_receives_401(): void
    {
        $org = $this->approvedOrganization();
        $opportunity = $this->opportunityFor($org);
        $student = $this->studentWithProfileAndCv();
        $application = $this->applicationForStudent($opportunity, $student);
        [$assessment] = $this->quizFor($application, status: 'published');

        $response = $this->getJson("/api/student/assessments/{$assessment->id}/quiz");

        $response->assertStatus(401)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Unauthenticated');
    }

    public function test_organization_role_cannot_access_student_quiz_routes(): void
    {
        $org = $this->approvedOrganization();
        $opportunity = $this->opportunityFor($org);
        $student = $this->studentWithProfileAndCv();
        $application = $this->applicationForStudent($opportunity, $student);
        [$assessment] = $this->quizFor($application, status: 'published');

        Sanctum::actingAs($org->user);

        $response = $this->getJson("/api/student/assessments/{$assessment->id}/quiz");

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

    private function studentWithProfileAndCv(): object
    {
        $user = User::factory()->create([
            'role' => 'student',
            'status' => 'active',
        ]);

        $profile = StudentProfile::create(['user_id' => $user->id]);

        $cv = $profile->cvs()->create([
            'title' => 'My CV',
            'file_path' => 'cvs/my-cv.pdf',
        ]);

        return (object) ['user' => $user, 'profile' => $profile, 'cv' => $cv];
    }

    private function applicationForStudent(Opportunity $opportunity, object $student, string $status = 'in_assessment'): Application
    {
        $application = Application::create([
            'student_id' => $student->profile->id,
            'opportunity_id' => $opportunity->id,
            'cv_id' => $student->cv->id,
        ]);

        $application->status = $status;
        $application->save();

        return $application;
    }

    /**
     * @return array{0: Assessment, 1: Quiz}
     */
    private function quizFor(Application $application, string $status = 'draft'): array
    {
        $assessment = $application->assessment()->create([
            'type' => 'quiz',
            'status' => $status === 'published' ? 'scheduled' : 'pending',
            'result' => null,
        ]);

        $quiz = $assessment->quiz()->create([
            'title' => 'Backend Fundamentals',
            'instructions' => 'Choose the best answer.',
            'time_limit_minutes' => 30,
            'passing_score' => 70,
            'status' => $status,
        ]);

        return [$assessment, $quiz];
    }

    /**
     * @return array<string, mixed>
     */
    private function mcqAttributes(): array
    {
        return [
            'prompt' => 'What is the capital of France?',
            'type' => 'multiple_choice',
            'options' => ['Paris', 'London', 'Berlin'],
            'correct_answer' => 'Paris',
            'points' => 1,
            'position' => 0,
        ];
    }
}
