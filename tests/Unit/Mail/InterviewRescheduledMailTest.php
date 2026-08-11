<?php

namespace Tests\Unit\Mail;

use App\Mail\InterviewRescheduledMail;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Phase 7A-4.2: content and privacy coverage for `InterviewRescheduledMail`.
 * Mirrors `InterviewScheduledMailTest`'s own structure/conventions.
 */
class InterviewRescheduledMailTest extends TestCase
{
    public function test_subject_names_the_event_and_opportunity(): void
    {
        $mail = $this->interviewRescheduledMail(opportunityTitle: 'Backend Developer');

        $mail->assertHasSubject('Interview Rescheduled — Backend Developer');
    }

    public function test_greets_the_student_by_name(): void
    {
        $mail = $this->interviewRescheduledMail(studentName: 'Jane Student');

        $mail->assertSeeInHtml('Jane Student');
    }

    public function test_includes_the_opportunity_title(): void
    {
        $mail = $this->interviewRescheduledMail(opportunityTitle: 'Senior Backend Engineer');

        $mail->assertSeeInHtml('Senior Backend Engineer');
    }

    public function test_states_the_schedule_changed(): void
    {
        $mail = $this->interviewRescheduledMail();

        $mail->assertSeeInHtml('rescheduled');
    }

    public function test_includes_the_view_application_cta_linking_to_the_given_url(): void
    {
        $mail = $this->interviewRescheduledMail(ctaUrl: 'http://localhost:8080/student/applications/5');

        $mail->assertSeeInHtml('View Application');
        $mail->assertSeeInHtml('http://localhost:8080/student/applications/5', false);
    }

    public function test_formats_the_new_scheduled_date_and_time(): void
    {
        $mail = $this->interviewRescheduledMail(scheduledAt: Carbon::parse('2026-09-10 09:00:00'));

        $mail->assertSeeInHtml('September 10, 2026');
        $mail->assertSeeInHtml('9:00 AM');
    }

    public function test_shows_duration_when_present(): void
    {
        $mail = $this->interviewRescheduledMail(durationMinutes: 30);

        $mail->assertSeeInHtml('30 minutes');
    }

    public function test_omits_duration_when_absent(): void
    {
        $mail = $this->interviewRescheduledMail(durationMinutes: null);

        $mail->assertDontSeeInHtml('Duration');
    }

    public function test_shows_the_meeting_link_for_an_online_interview(): void
    {
        $mail = $this->interviewRescheduledMail(
            interviewType: 'online',
            meetingLink: 'https://meet.example.com/new-room',
        );

        $mail->assertSeeInHtml('https://meet.example.com/new-room', false);
    }

    public function test_shows_the_location_for_an_onsite_interview(): void
    {
        $mail = $this->interviewRescheduledMail(
            interviewType: 'onsite',
            location: '456 Second St',
        );

        $mail->assertSeeInHtml('456 Second St');
    }

    public function test_omits_null_optional_values_without_error(): void
    {
        $mail = $this->interviewRescheduledMail(
            interviewType: 'phone',
            meetingLink: null,
            location: null,
            interviewerName: null,
            durationMinutes: null,
        );

        $mail->assertSeeInHtml('View Application');
        $mail->assertDontSeeInHtml('Meeting link');
        $mail->assertDontSeeInHtml('Location');
        $mail->assertDontSeeInHtml('Interviewer');
        $mail->assertDontSeeInHtml('Duration');
    }

    public function test_includes_the_platform_name_in_the_footer(): void
    {
        $mail = $this->interviewRescheduledMail();

        $mail->assertSeeInHtml(config('app.name'));
    }

    // -----------------------------------------------------------------
    // Privacy
    // -----------------------------------------------------------------

    public function test_never_renders_interviewer_email(): void
    {
        $mail = $this->interviewRescheduledMail();

        $mail->assertDontSeeInHtml('interviewer_email');
    }

    public function test_never_renders_company_feedback(): void
    {
        $mail = $this->interviewRescheduledMail();

        $mail->assertDontSeeInHtml('company_feedback');
    }

    public function test_never_renders_rating(): void
    {
        $mail = $this->interviewRescheduledMail();

        $mail->assertDontSeeInHtml('rating');
    }

    public function test_never_renders_decision(): void
    {
        $mail = $this->interviewRescheduledMail();

        $mail->assertDontSeeInHtml('decision');
    }

    private function interviewRescheduledMail(
        string $studentName = 'Jane Student',
        string $opportunityTitle = 'Backend Developer',
        string $ctaUrl = 'http://localhost:8080/student/applications/5',
        string $interviewType = 'online',
        ?Carbon $scheduledAt = null,
        ?int $durationMinutes = 60,
        ?string $meetingLink = 'https://meet.example.com/room',
        ?string $location = null,
        ?string $interviewerName = null,
    ): InterviewRescheduledMail {
        return new InterviewRescheduledMail(
            studentName: $studentName,
            opportunityTitle: $opportunityTitle,
            ctaUrl: $ctaUrl,
            interviewType: $interviewType,
            scheduledAt: $scheduledAt ?? Carbon::parse('2026-09-10 09:00:00'),
            durationMinutes: $durationMinutes,
            meetingLink: $meetingLink,
            location: $location,
            interviewerName: $interviewerName,
        );
    }
}
