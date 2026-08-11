<?php

namespace Tests\Unit\Services;

use App\Mail\OfferReceivedMail;
use App\Models\User;
use App\Services\EmailService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Phase 7A-4.1: direct unit coverage for `EmailService`. Uses `Mail::fake()`
 * throughout -- no real SMTP connection is ever attempted here, and
 * `Mail::fake()` intercepts at the Mail facade level regardless of the
 * `queue` connection or `MAIL_MAILER` value, so this is safe under any
 * environment configuration.
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

    private function studentUser(string $name = 'Jane Student'): User
    {
        return User::factory()->create([
            'name' => $name,
            'role' => 'student',
            'status' => 'active',
        ]);
    }
}
