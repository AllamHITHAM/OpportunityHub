<?php

namespace Tests\Unit\Mail;

use App\Mail\QuizAvailableMail;
use Tests\TestCase;

/**
 * Phase 7A-4.2: content and privacy coverage for `QuizAvailableMail`.
 */
class QuizAvailableMailTest extends TestCase
{
    public function test_subject_names_the_event_and_opportunity(): void
    {
        $mail = $this->quizAvailableMail(opportunityTitle: 'Backend Developer');

        $mail->assertHasSubject('Quiz Available — Backend Developer');
    }

    public function test_greets_the_student_by_name(): void
    {
        $mail = $this->quizAvailableMail(studentName: 'Jane Student');

        $mail->assertSeeInHtml('Jane Student');
    }

    public function test_includes_the_opportunity_title(): void
    {
        $mail = $this->quizAvailableMail(opportunityTitle: 'Senior Backend Engineer');

        $mail->assertSeeInHtml('Senior Backend Engineer');
    }

    public function test_includes_the_take_quiz_cta_linking_to_the_given_url(): void
    {
        $mail = $this->quizAvailableMail(ctaUrl: 'http://localhost:8080/student/assessments/9/quiz');

        $mail->assertSeeInHtml('Take Quiz');
        $mail->assertSeeInHtml('http://localhost:8080/student/assessments/9/quiz', false);
    }

    public function test_shows_the_time_limit_when_present(): void
    {
        $mail = $this->quizAvailableMail(timeLimitMinutes: 30);

        $mail->assertSeeInHtml('30 minutes');
    }

    public function test_omits_the_time_limit_when_absent(): void
    {
        $mail = $this->quizAvailableMail(timeLimitMinutes: null);

        $mail->assertDontSeeInHtml('Time limit');
    }

    public function test_shows_the_passing_score(): void
    {
        $mail = $this->quizAvailableMail(passingScore: 75);

        $mail->assertSeeInHtml('75%');
    }

    public function test_includes_the_platform_name_in_the_footer(): void
    {
        $mail = $this->quizAvailableMail();

        $mail->assertSeeInHtml(config('app.name'));
    }

    // -----------------------------------------------------------------
    // Privacy
    // -----------------------------------------------------------------

    public function test_never_renders_correct_answer(): void
    {
        $mail = $this->quizAvailableMail();

        $mail->assertDontSeeInHtml('correct_answer');
    }

    public function test_never_renders_question_answer_key_content(): void
    {
        $mail = $this->quizAvailableMail();

        $mail->assertDontSeeInHtml('answer_key');
    }

    /**
     * Deliberately checks for a specific mistaken-inclusion label, not the
     * bare substring "score" -- "Passing score" is legitimate, required
     * content for this event (see the test above); a student's own result
     * is not.
     */
    public function test_never_renders_the_students_own_score_or_result(): void
    {
        $mail = $this->quizAvailableMail();

        $mail->assertDontSeeInHtml('Your Score');
        $mail->assertDontSeeInHtml('Result:');
    }

    private function quizAvailableMail(
        string $studentName = 'Jane Student',
        string $opportunityTitle = 'Backend Developer',
        string $ctaUrl = 'http://localhost:8080/student/assessments/9/quiz',
        int $passingScore = 50,
        ?int $timeLimitMinutes = 30,
    ): QuizAvailableMail {
        return new QuizAvailableMail(
            studentName: $studentName,
            opportunityTitle: $opportunityTitle,
            ctaUrl: $ctaUrl,
            passingScore: $passingScore,
            timeLimitMinutes: $timeLimitMinutes,
        );
    }
}
