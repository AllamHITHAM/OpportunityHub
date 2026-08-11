<?php

namespace Tests\Unit\Mail;

use App\Mail\InterviewScheduledMail;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Phase 7A-4.2: content and privacy coverage for `InterviewScheduledMail`.
 * Renders the actual Mailable/Blade view for every assertion, including the
 * privacy ones, matching `OfferReceivedMailTest`'s own convention.
 */
class InterviewScheduledMailTest extends TestCase
{
    public function test_subject_names_the_event_and_opportunity(): void
    {
        $mail = $this->interviewScheduledMail(opportunityTitle: 'Backend Developer');

        $mail->assertHasSubject('Interview Scheduled — Backend Developer');
    }

    public function test_greets_the_student_by_name(): void
    {
        $mail = $this->interviewScheduledMail(studentName: 'Jane Student');

        $mail->assertSeeInHtml('Jane Student');
    }

    public function test_includes_the_opportunity_title(): void
    {
        $mail = $this->interviewScheduledMail(opportunityTitle: 'Senior Backend Engineer');

        $mail->assertSeeInHtml('Senior Backend Engineer');
    }

    public function test_includes_the_view_application_cta_linking_to_the_given_url(): void
    {
        $mail = $this->interviewScheduledMail(ctaUrl: 'http://localhost:8080/student/applications/5');

        $mail->assertSeeInHtml('View Application');
        $mail->assertSeeInHtml('http://localhost:8080/student/applications/5', false);
    }

    public function test_formats_the_scheduled_date_and_time(): void
    {
        $mail = $this->interviewScheduledMail(scheduledAt: Carbon::parse('2026-09-01 14:30:00'));

        $mail->assertSeeInHtml('September 1, 2026');
        $mail->assertSeeInHtml('2:30 PM');
    }

    public function test_shows_duration_when_present(): void
    {
        $mail = $this->interviewScheduledMail(durationMinutes: 45);

        $mail->assertSeeInHtml('45 minutes');
    }

    public function test_omits_duration_when_absent(): void
    {
        $mail = $this->interviewScheduledMail(durationMinutes: null);

        $mail->assertDontSeeInHtml('Duration');
    }

    public function test_shows_the_meeting_link_for_an_online_interview(): void
    {
        $mail = $this->interviewScheduledMail(
            interviewType: 'online',
            meetingLink: 'https://meet.example.com/room',
        );

        $mail->assertSeeInHtml('https://meet.example.com/room', false);
    }

    public function test_omits_the_meeting_link_when_absent(): void
    {
        $mail = $this->interviewScheduledMail(interviewType: 'online', meetingLink: null);

        $mail->assertDontSeeInHtml('Meeting link');
    }

    public function test_shows_the_location_for_an_onsite_interview(): void
    {
        $mail = $this->interviewScheduledMail(
            interviewType: 'onsite',
            location: '123 Main St, Suite 400',
        );

        $mail->assertSeeInHtml('123 Main St, Suite 400');
    }

    public function test_omits_the_location_when_absent(): void
    {
        $mail = $this->interviewScheduledMail(interviewType: 'onsite', location: null);

        $mail->assertDontSeeInHtml('Location');
    }

    public function test_does_not_show_a_meeting_link_for_an_onsite_interview_even_if_one_is_set(): void
    {
        $mail = $this->interviewScheduledMail(
            interviewType: 'onsite',
            location: '123 Main St',
            meetingLink: 'https://meet.example.com/room',
        );

        $mail->assertDontSeeInHtml('Meeting link');
    }

    public function test_shows_the_interviewer_name_when_present(): void
    {
        $mail = $this->interviewScheduledMail(interviewerName: 'Jane Recruiter');

        $mail->assertSeeInHtml('Jane Recruiter');
    }

    public function test_omits_the_interviewer_line_when_absent(): void
    {
        $mail = $this->interviewScheduledMail(interviewerName: null);

        $mail->assertDontSeeInHtml('Interviewer');
    }

    public function test_includes_the_platform_name_in_the_footer(): void
    {
        $mail = $this->interviewScheduledMail();

        $mail->assertSeeInHtml(config('app.name'));
    }

    // -----------------------------------------------------------------
    // Privacy: forbidden content must be absent from the real rendered
    // output, not merely assumed absent because it was never passed in.
    // -----------------------------------------------------------------

    public function test_never_renders_interviewer_email(): void
    {
        $mail = $this->interviewScheduledMail();

        $mail->assertDontSeeInHtml('interviewer_email');
    }

    public function test_never_renders_company_feedback(): void
    {
        $mail = $this->interviewScheduledMail();

        $mail->assertDontSeeInHtml('company_feedback');
    }

    public function test_never_renders_rating(): void
    {
        $mail = $this->interviewScheduledMail();

        $mail->assertDontSeeInHtml('rating');
    }

    public function test_never_renders_decision(): void
    {
        $mail = $this->interviewScheduledMail();

        $mail->assertDontSeeInHtml('decision');
    }

    public function test_never_renders_internal_notes(): void
    {
        $mail = $this->interviewScheduledMail();

        $mail->assertDontSeeInHtml('notes');
    }

    private function interviewScheduledMail(
        string $studentName = 'Jane Student',
        string $opportunityTitle = 'Backend Developer',
        string $ctaUrl = 'http://localhost:8080/student/applications/5',
        string $interviewType = 'online',
        ?Carbon $scheduledAt = null,
        ?int $durationMinutes = 60,
        ?string $meetingLink = 'https://meet.example.com/room',
        ?string $location = null,
        ?string $interviewerName = null,
    ): InterviewScheduledMail {
        return new InterviewScheduledMail(
            studentName: $studentName,
            opportunityTitle: $opportunityTitle,
            ctaUrl: $ctaUrl,
            interviewType: $interviewType,
            scheduledAt: $scheduledAt ?? Carbon::parse('2026-09-01 14:30:00'),
            durationMinutes: $durationMinutes,
            meetingLink: $meetingLink,
            location: $location,
            interviewerName: $interviewerName,
        );
    }
}
