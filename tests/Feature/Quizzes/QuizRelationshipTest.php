<?php

namespace Tests\Feature\Quizzes;

use App\Models\Application;
use App\Models\Assessment;
use App\Models\Opportunity;
use App\Models\OrganizationProfile;
use App\Models\Question;
use App\Models\Quiz;
use App\Models\StudentProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 6B-1: migration/model coverage for `quizzes`/`questions` --
 * mirrors AssessmentRelationshipTest's structure and conventions.
 */
class QuizRelationshipTest extends TestCase
{
    use RefreshDatabase;

    public function test_quizzes_table_exists_with_expected_columns(): void
    {
        $this->assertTrue(\Illuminate\Support\Facades\Schema::hasTable('quizzes'));
        $this->assertTrue(\Illuminate\Support\Facades\Schema::hasColumns('quizzes', [
            'id', 'assessment_id', 'title', 'instructions', 'time_limit_minutes',
            'passing_score', 'status', 'created_at', 'updated_at',
        ]));
    }

    public function test_questions_table_exists_with_expected_columns(): void
    {
        $this->assertTrue(\Illuminate\Support\Facades\Schema::hasTable('questions'));
        $this->assertTrue(\Illuminate\Support\Facades\Schema::hasColumns('questions', [
            'id', 'quiz_id', 'prompt', 'type', 'options', 'correct_answer',
            'points', 'position', 'created_at', 'updated_at',
        ]));
    }

    public function test_assessment_has_one_quiz(): void
    {
        $assessment = $this->assessmentFor($this->applicationFor($this->opportunityFor($this->approvedOrganization())));

        $quiz = $assessment->quiz()->create($this->quizAttributes());

        $this->assertInstanceOf(Quiz::class, $assessment->quiz);
        $this->assertSame($quiz->id, $assessment->quiz->id);
    }

    public function test_assessment_has_no_quiz_by_default(): void
    {
        $assessment = $this->assessmentFor($this->applicationFor($this->opportunityFor($this->approvedOrganization())));

        $this->assertNull($assessment->quiz);
    }

    public function test_quiz_belongs_to_assessment(): void
    {
        $assessment = $this->assessmentFor($this->applicationFor($this->opportunityFor($this->approvedOrganization())));

        $quiz = $assessment->quiz()->create($this->quizAttributes());

        $this->assertInstanceOf(Assessment::class, $quiz->assessment);
        $this->assertSame($assessment->id, $quiz->assessment->id);
    }

    public function test_assessment_has_one_quiz_does_not_break_has_one_interview(): void
    {
        $application = $this->applicationFor($this->opportunityFor($this->approvedOrganization()));
        $assessment = $application->assessment()->create([
            'type' => 'interview',
            'status' => 'scheduled',
            'result' => null,
        ]);
        $interview = $assessment->interview()->create([
            'interview_type' => 'phone',
            'scheduled_at' => now()->addDays(3),
        ]);

        $this->assertNull($assessment->quiz);
        $this->assertSame($interview->id, $assessment->interview->id);
    }

    public function test_quiz_assessment_id_is_unique(): void
    {
        $assessment = $this->assessmentFor($this->applicationFor($this->opportunityFor($this->approvedOrganization())));
        $assessment->quiz()->create($this->quizAttributes());

        $this->expectException(\Illuminate\Database\QueryException::class);

        $assessment->quiz()->create($this->quizAttributes());
    }

    public function test_question_belongs_to_quiz(): void
    {
        $assessment = $this->assessmentFor($this->applicationFor($this->opportunityFor($this->approvedOrganization())));
        $quiz = $assessment->quiz()->create($this->quizAttributes());

        $question = $quiz->questions()->create($this->mcqAttributes());

        $this->assertInstanceOf(Quiz::class, $question->quiz);
        $this->assertSame($quiz->id, $question->quiz->id);
    }

    public function test_quiz_has_many_questions_ordered_by_position(): void
    {
        $assessment = $this->assessmentFor($this->applicationFor($this->opportunityFor($this->approvedOrganization())));
        $quiz = $assessment->quiz()->create($this->quizAttributes());

        $second = $quiz->questions()->create(array_merge($this->mcqAttributes(), ['position' => 2, 'prompt' => 'Second']));
        $first = $quiz->questions()->create(array_merge($this->mcqAttributes(), ['position' => 1, 'prompt' => 'First']));

        $ordered = $quiz->questions()->get();

        $this->assertSame([$first->id, $second->id], $ordered->pluck('id')->all());
    }

    public function test_deleting_an_assessment_cascades_to_its_quiz_and_questions(): void
    {
        $assessment = $this->assessmentFor($this->applicationFor($this->opportunityFor($this->approvedOrganization())));
        $quiz = $assessment->quiz()->create($this->quizAttributes());
        $question = $quiz->questions()->create($this->mcqAttributes());

        $assessment->delete();

        $this->assertDatabaseMissing('quizzes', ['id' => $quiz->id]);
        $this->assertDatabaseMissing('questions', ['id' => $question->id]);
    }

    public function test_deleting_a_quiz_cascades_to_its_questions(): void
    {
        $assessment = $this->assessmentFor($this->applicationFor($this->opportunityFor($this->approvedOrganization())));
        $quiz = $assessment->quiz()->create($this->quizAttributes());
        $question = $quiz->questions()->create($this->mcqAttributes());

        $quiz->delete();

        $this->assertDatabaseMissing('questions', ['id' => $question->id]);
    }

    public function test_multiple_choice_options_cast_to_an_array(): void
    {
        $assessment = $this->assessmentFor($this->applicationFor($this->opportunityFor($this->approvedOrganization())));
        $quiz = $assessment->quiz()->create($this->quizAttributes());

        $question = $quiz->questions()->create($this->mcqAttributes());

        $this->assertIsArray($question->fresh()->options);
        $this->assertSame(['Paris', 'London', 'Berlin'], $question->fresh()->options);
    }

    public function test_true_false_options_are_null(): void
    {
        $assessment = $this->assessmentFor($this->applicationFor($this->opportunityFor($this->approvedOrganization())));
        $quiz = $assessment->quiz()->create($this->quizAttributes());

        $question = $quiz->questions()->create([
            'prompt' => 'The sky is blue.',
            'type' => 'true_false',
            'options' => null,
            'correct_answer' => 'True',
        ]);

        $this->assertNull($question->fresh()->options);
    }

    public function test_quiz_passing_score_and_time_limit_are_cast_to_integers(): void
    {
        $assessment = $this->assessmentFor($this->applicationFor($this->opportunityFor($this->approvedOrganization())));

        $quiz = $assessment->quiz()->create($this->quizAttributes());

        $this->assertIsInt($quiz->fresh()->passing_score);
        $this->assertIsInt($quiz->fresh()->time_limit_minutes);
    }

    public function test_question_points_and_position_are_cast_to_integers(): void
    {
        $assessment = $this->assessmentFor($this->applicationFor($this->opportunityFor($this->approvedOrganization())));
        $quiz = $assessment->quiz()->create($this->quizAttributes());

        $question = $quiz->questions()->create($this->mcqAttributes());

        $this->assertIsInt($question->fresh()->points);
        $this->assertIsInt($question->fresh()->position);
    }

    public function test_quiz_status_defaults_to_draft(): void
    {
        $assessment = $this->assessmentFor($this->applicationFor($this->opportunityFor($this->approvedOrganization())));

        $quiz = $assessment->quiz()->create([
            'title' => 'Backend Fundamentals',
            'passing_score' => 70,
        ]);

        $this->assertSame('draft', $quiz->fresh()->status);
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

    private function applicationFor(Opportunity $opportunity, string $status = 'shortlisted'): Application
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

    private function assessmentFor(Application $application): Assessment
    {
        return $application->assessment()->create([
            'type' => 'quiz',
            'status' => 'pending',
            'result' => null,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function quizAttributes(): array
    {
        return [
            'title' => 'Backend Fundamentals',
            'instructions' => 'Choose the best answer.',
            'time_limit_minutes' => 30,
            'passing_score' => 70,
            'status' => 'draft',
        ];
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
