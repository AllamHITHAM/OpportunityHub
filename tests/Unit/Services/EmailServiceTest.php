<?php

namespace Tests\Unit\Services;

use App\Mail\ApplicationRejectedMail;
use App\Mail\InterviewRescheduledMail;
use App\Mail\InterviewScheduledMail;
use App\Mail\OfferAcceptedMail;
use App\Mail\OfferDeclinedMail;
use App\Mail\OfferReceivedMail;
use App\Mail\QuizAvailableMail;
use App\Models\User;
use App\Services\EmailService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Phase 7A-4.1 (Offer Received), extended Phase 7A-4.2 (the remaining six):
 * direct unit coverage for `EmailService`. Uses `Mail::fake()` throughout --
 * no real SMTP connection is ever attempted here, and `Mail::fake()`
 * intercepts at the Mail facade level regardless of the `queue` connection
 * or `MAIL_MAILER` value, so this is safe under any environment
 * configuration.
 */
class EmailServiceTest extends TestCase
{
    use RefreshDatabase;

    private EmailService $emails;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();

        $this->emails = app(EmailService::class);
    }

    public function test_send_offer_received_email_queues_offer_received_mail(): void
    {
        $student = $this->studentUser();

        $this->emails->sendOfferReceivedEmail($student, 'Backend Developer', 5);

        Mail::assertQueued(OfferReceivedMail::class);
    }

    public function test_send_offer_received_email_never_sends_synchronously(): void
    {
        $student = $this->studentUser();

        $this->emails->sendOfferReceivedEmail($student, 'Backend Developer', 5);

        Mail::assertNotSent(OfferReceivedMail::class);
    }

    public function test_send_offer_received_email_uses_the_recipients_own_address(): void
    {
        $student = $this->studentUser();

        $this->emails->sendOfferReceivedEmail($student, 'Backend Developer', 5);

        Mail::assertQueued(
            OfferReceivedMail::class,
            fn (OfferReceivedMail $mail) => $mail->hasTo($student->email),
        );
    }

    public function test_send_offer_received_email_passes_the_recipients_name_as_the_greeting_name(): void
    {
        $student = $this->studentUser(name: 'Jane Student');

        $this->emails->sendOfferReceivedEmail($student, 'Backend Developer', 5);

        Mail::assertQueued(
            OfferReceivedMail::class,
            fn (OfferReceivedMail $mail) => $mail->studentName === 'Jane Student',
        );
    }

    public function test_send_offer_received_email_passes_the_opportunity_title(): void
    {
        $student = $this->studentUser();

        $this->emails->sendOfferReceivedEmail($student, 'Senior Backend Engineer', 5);

        Mail::assertQueued(
            OfferReceivedMail::class,
            fn (OfferReceivedMail $mail) => $mail->opportunityTitle === 'Senior Backend Engineer',
        );
    }

    public function test_send_offer_received_email_builds_the_cta_url_from_frontend_url_and_application_id(): void
    {
        $student = $this->studentUser();

        $this->emails->sendOfferReceivedEmail($student, 'Backend Developer', 42);

        // phpunit.xml sets FRONTEND_URL=http://localhost:8080 for the test
        // environment.
        Mail::assertQueued(
            OfferReceivedMail::class,
            fn (OfferReceivedMail $mail) => $mail->ctaUrl === 'http://localhost:8080/student/applications/42',
        );
    }

    public function test_send_offer_received_email_forwards_optional_offer_fields(): void
    {
        $student = $this->studentUser();
        $startDate = Carbon::parse('2026-09-01');

        $this->emails->sendOfferReceivedEmail(
            $student,
            'Backend Developer',
            5,
            startDate: $startDate,
            salaryAmount: '90000.00',
            salaryCurrency: 'USD',
            salaryPeriod: 'yearly',
            offerMessage: 'Excited to have you on the team.',
        );

        Mail::assertQueued(OfferReceivedMail::class, function (OfferReceivedMail $mail) use ($startDate) {
            return $mail->startDate?->equalTo($startDate)
                && $mail->salaryAmount === '90000.00'
                && $mail->salaryCurrency === 'USD'
                && $mail->salaryPeriod === 'yearly'
                && $mail->offerMessage === 'Excited to have you on the team.';
        });
    }

    public function test_send_offer_received_email_leaves_optional_offer_fields_null_when_omitted(): void
    {
        $student = $this->studentUser();

        $this->emails->sendOfferReceivedEmail($student, 'Backend Developer', 5);

        Mail::assertQueued(OfferReceivedMail::class, function (OfferReceivedMail $mail) {
            return $mail->startDate === null
                && $mail->salaryAmount === null
                && $mail->salaryCurrency === null
                && $mail->salaryPeriod === null
                && $mail->offerMessage === null;
        });
    }

    // -----------------------------------------------------------------
    // sendInterviewScheduledEmail (Phase 7A-4.2)
    // -----------------------------------------------------------------

    public function test_send_interview_scheduled_email_queues_interview_scheduled_mail(): void
    {
        $student = $this->studentUser();

        $this->emails->sendInterviewScheduledEmail(
            $student,
            'Backend Developer',
            5,
            interviewType: 'online',
            scheduledAt: Carbon::parse('2026-09-01 14:00:00'),
            durationMinutes: 60,
            meetingLink: 'https://meet.example.com/room',
        );

        Mail::assertQueued(
            InterviewScheduledMail::class,
            fn (InterviewScheduledMail $mail) => $mail->hasTo($student->email)
                && $mail->interviewType === 'online'
                && $mail->durationMinutes === 60
                && $mail->meetingLink === 'https://meet.example.com/room',
        );
    }

    public function test_send_interview_scheduled_email_builds_the_cta_url_from_frontend_url_and_application_id(): void
    {
        $student = $this->studentUser();

        $this->emails->sendInterviewScheduledEmail(
            $student,
            'Backend Developer',
            42,
            interviewType: 'onsite',
            scheduledAt: Carbon::parse('2026-09-01 14:00:00'),
            location: '123 Main St',
        );

        Mail::assertQueued(
            InterviewScheduledMail::class,
            fn (InterviewScheduledMail $mail) => $mail->ctaUrl === 'http://localhost:8080/student/applications/42',
        );
    }

    public function test_send_interview_scheduled_email_leaves_optional_fields_null_when_omitted(): void
    {
        $student = $this->studentUser();

        $this->emails->sendInterviewScheduledEmail(
            $student,
            'Backend Developer',
            5,
            interviewType: 'phone',
            scheduledAt: Carbon::parse('2026-09-01 14:00:00'),
        );

        Mail::assertQueued(InterviewScheduledMail::class, function (InterviewScheduledMail $mail) {
            return $mail->durationMinutes === null
                && $mail->meetingLink === null
                && $mail->location === null
                && $mail->interviewerName === null;
        });
    }

    // -----------------------------------------------------------------
    // sendInterviewRescheduledEmail (Phase 7A-4.2)
    // -----------------------------------------------------------------

    public function test_send_interview_rescheduled_email_queues_interview_rescheduled_mail(): void
    {
        $student = $this->studentUser();

        $this->emails->sendInterviewRescheduledEmail(
            $student,
            'Backend Developer',
            5,
            interviewType: 'online',
            scheduledAt: Carbon::parse('2026-09-10 09:00:00'),
            durationMinutes: 30,
        );

        Mail::assertQueued(
            InterviewRescheduledMail::class,
            fn (InterviewRescheduledMail $mail) => $mail->hasTo($student->email)
                && $mail->scheduledAt->equalTo(Carbon::parse('2026-09-10 09:00:00')),
        );
    }

    public function test_send_interview_rescheduled_email_never_sends_synchronously(): void
    {
        $student = $this->studentUser();

        $this->emails->sendInterviewRescheduledEmail(
            $student,
            'Backend Developer',
            5,
            interviewType: 'online',
            scheduledAt: Carbon::parse('2026-09-10 09:00:00'),
        );

        Mail::assertNotSent(InterviewRescheduledMail::class);
    }

    // -----------------------------------------------------------------
    // sendQuizAvailableEmail (Phase 7A-4.2)
    // -----------------------------------------------------------------

    public function test_send_quiz_available_email_queues_quiz_available_mail(): void
    {
        $student = $this->studentUser();

        $this->emails->sendQuizAvailableEmail(
            $student,
            'Backend Developer',
            9,
            passingScore: 70,
            timeLimitMinutes: 30,
        );

        Mail::assertQueued(
            QuizAvailableMail::class,
            fn (QuizAvailableMail $mail) => $mail->hasTo($student->email)
                && $mail->passingScore === 70
                && $mail->timeLimitMinutes === 30,
        );
    }

    public function test_send_quiz_available_email_builds_the_cta_url_from_frontend_url_and_assessment_id(): void
    {
        $student = $this->studentUser();

        $this->emails->sendQuizAvailableEmail($student, 'Backend Developer', 9, passingScore: 50);

        // The Student Quiz route, addressed by assessment ID -- not the
        // application-details route every other email's CTA uses.
        Mail::assertQueued(
            QuizAvailableMail::class,
            fn (QuizAvailableMail $mail) => $mail->ctaUrl === 'http://localhost:8080/student/assessments/9/quiz',
        );
    }

    public function test_send_quiz_available_email_leaves_time_limit_null_when_omitted(): void
    {
        $student = $this->studentUser();

        $this->emails->sendQuizAvailableEmail($student, 'Backend Developer', 9, passingScore: 50);

        Mail::assertQueued(
            QuizAvailableMail::class,
            fn (QuizAvailableMail $mail) => $mail->timeLimitMinutes === null,
        );
    }

    // -----------------------------------------------------------------
    // sendApplicationRejectedEmail (Phase 7A-4.2)
    // -----------------------------------------------------------------

    public function test_send_application_rejected_email_queues_application_rejected_mail(): void
    {
        $student = $this->studentUser();

        $this->emails->sendApplicationRejectedEmail($student, 'Backend Developer', 5);

        Mail::assertQueued(
            ApplicationRejectedMail::class,
            fn (ApplicationRejectedMail $mail) => $mail->hasTo($student->email)
                && $mail->opportunityTitle === 'Backend Developer',
        );
    }

    public function test_send_application_rejected_email_builds_the_cta_url_from_frontend_url_and_application_id(): void
    {
        $student = $this->studentUser();

        $this->emails->sendApplicationRejectedEmail($student, 'Backend Developer', 42);

        Mail::assertQueued(
            ApplicationRejectedMail::class,
            fn (ApplicationRejectedMail $mail) => $mail->ctaUrl === 'http://localhost:8080/student/applications/42',
        );
    }

    // -----------------------------------------------------------------
    // sendOfferAcceptedEmail (Phase 7A-4.2)
    // -----------------------------------------------------------------

    public function test_send_offer_accepted_email_queues_offer_accepted_mail(): void
    {
        $organization = $this->organizationUser();

        $this->emails->sendOfferAcceptedEmail($organization, 'Jane Student', 'Backend Developer', 5);

        Mail::assertQueued(
            OfferAcceptedMail::class,
            fn (OfferAcceptedMail $mail) => $mail->hasTo($organization->email)
                && $mail->recipientName === $organization->name
                && $mail->studentName === 'Jane Student'
                && $mail->opportunityTitle === 'Backend Developer',
        );
    }

    public function test_send_offer_accepted_email_builds_the_cta_url_from_frontend_url_and_application_id(): void
    {
        $organization = $this->organizationUser();

        $this->emails->sendOfferAcceptedEmail($organization, 'Jane Student', 'Backend Developer', 42);

        // The Organization Application Details route, not the student one.
        Mail::assertQueued(
            OfferAcceptedMail::class,
            fn (OfferAcceptedMail $mail) => $mail->ctaUrl === 'http://localhost:8080/organization/applications/42',
        );
    }

    public function test_send_offer_accepted_email_never_sends_synchronously(): void
    {
        $organization = $this->organizationUser();

        $this->emails->sendOfferAcceptedEmail($organization, 'Jane Student', 'Backend Developer', 5);

        Mail::assertNotSent(OfferAcceptedMail::class);
    }

    // -----------------------------------------------------------------
    // sendOfferDeclinedEmail (Phase 7A-4.2)
    // -----------------------------------------------------------------

    public function test_send_offer_declined_email_queues_offer_declined_mail(): void
    {
        $organization = $this->organizationUser();

        $this->emails->sendOfferDeclinedEmail($organization, 'Jane Student', 'Backend Developer', 5);

        Mail::assertQueued(
            OfferDeclinedMail::class,
            fn (OfferDeclinedMail $mail) => $mail->hasTo($organization->email)
                && $mail->recipientName === $organization->name
                && $mail->studentName === 'Jane Student'
                && $mail->opportunityTitle === 'Backend Developer',
        );
    }

    public function test_send_offer_declined_email_builds_the_cta_url_from_frontend_url_and_application_id(): void
    {
        $organization = $this->organizationUser();

        $this->emails->sendOfferDeclinedEmail($organization, 'Jane Student', 'Backend Developer', 42);

        Mail::assertQueued(
            OfferDeclinedMail::class,
            fn (OfferDeclinedMail $mail) => $mail->ctaUrl === 'http://localhost:8080/organization/applications/42',
        );
    }

    public function test_send_offer_declined_email_never_sends_synchronously(): void
    {
        $organization = $this->organizationUser();

        $this->emails->sendOfferDeclinedEmail($organization, 'Jane Student', 'Backend Developer', 5);

        Mail::assertNotSent(OfferDeclinedMail::class);
    }

    private function studentUser(string $name = 'Jane Student'): User
    {
        return User::factory()->create([
            'name' => $name,
            'role' => 'student',
            'status' => 'active',
        ]);
    }

    private function organizationUser(string $name = 'Alex Recruiter'): User
    {
        return User::factory()->create([
            'name' => $name,
            'role' => 'organization',
            'status' => 'active',
        ]);
    }
}
