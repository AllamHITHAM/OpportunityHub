<?php

namespace Tests\Unit\Mail;

use App\Mail\OfferReceivedMail;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Phase 7A-4.1: content and privacy coverage for `OfferReceivedMail`.
 * Renders the actual Mailable/Blade view for every assertion -- including
 * the privacy ones -- rather than only reasoning about what the constructor
 * was given, per this phase's own requirement that forbidden content must
 * be proven absent from the real rendered output, not merely assumed absent
 * because nothing passed it in.
 */
class OfferReceivedMailTest extends TestCase
{
    public function test_subject_names_the_event_and_opportunity(): void
    {
        $mail = $this->offerReceivedMail(opportunityTitle: 'Backend Developer');

        $mail->assertHasSubject('Offer Received — Backend Developer');
    }

    public function test_greets_the_student_by_name(): void
    {
        $mail = $this->offerReceivedMail(studentName: 'Jane Student');

        $mail->assertSeeInHtml('Jane Student');
    }

    public function test_states_an_offer_was_received(): void
    {
        $mail = $this->offerReceivedMail();

        $mail->assertSeeInHtml('you have received an offer');
    }

    public function test_includes_the_opportunity_title(): void
    {
        $mail = $this->offerReceivedMail(opportunityTitle: 'Senior Backend Engineer');

        $mail->assertSeeInHtml('Senior Backend Engineer');
    }

    public function test_includes_a_view_offer_cta_linking_to_the_given_url(): void
    {
        $mail = $this->offerReceivedMail(ctaUrl: 'http://localhost:8080/student/applications/42');

        $mail->assertSeeInHtml('View Offer');
        $mail->assertSeeInHtml('http://localhost:8080/student/applications/42', false);
    }

    public function test_shows_the_start_date_when_present(): void
    {
        $mail = $this->offerReceivedMail(startDate: Carbon::parse('2026-09-01'));

        $mail->assertSeeInHtml('September 1, 2026');
    }

    public function test_omits_start_date_when_absent(): void
    {
        $mail = $this->offerReceivedMail(startDate: null);

        $mail->assertDontSeeInHtml('Start date');
    }

    public function test_shows_compensation_when_amount_currency_and_period_are_all_present(): void
    {
        $mail = $this->offerReceivedMail(
            salaryAmount: '90000.00',
            salaryCurrency: 'USD',
            salaryPeriod: 'yearly',
        );

        $mail->assertSeeInHtml('USD 90000.00 / year');
    }

    public function test_formats_monthly_compensation(): void
    {
        $mail = $this->offerReceivedMail(
            salaryAmount: '1500.00',
            salaryCurrency: 'USD',
            salaryPeriod: 'monthly',
        );

        $mail->assertSeeInHtml('USD 1500.00 / month');
    }

    public function test_omits_compensation_entirely_when_amount_is_missing(): void
    {
        $mail = $this->offerReceivedMail(
            salaryAmount: null,
            salaryCurrency: 'USD',
            salaryPeriod: 'yearly',
        );

        $mail->assertDontSeeInHtml('Compensation');
    }

    public function test_omits_compensation_entirely_when_currency_is_missing(): void
    {
        $mail = $this->offerReceivedMail(
            salaryAmount: '90000.00',
            salaryCurrency: null,
            salaryPeriod: 'yearly',
        );

        $mail->assertDontSeeInHtml('Compensation');
    }

    public function test_omits_compensation_entirely_when_period_is_missing(): void
    {
        $mail = $this->offerReceivedMail(
            salaryAmount: '90000.00',
            salaryCurrency: 'USD',
            salaryPeriod: null,
        );

        $mail->assertDontSeeInHtml('Compensation');
    }

    public function test_includes_the_offer_message_when_present(): void
    {
        $mail = $this->offerReceivedMail(offerMessage: 'Excited to have you on the team.');

        $mail->assertSeeInHtml('Excited to have you on the team.');
    }

    public function test_omits_the_offer_message_block_when_absent(): void
    {
        $mail = $this->offerReceivedMail(offerMessage: null);

        // Nothing to assert "not seen" for an empty message specifically
        // (there is no fixed label around it to check for) -- this test
        // instead proves rendering an offer with no message at all does
        // not throw and still produces the rest of the expected content.
        $mail->assertSeeInHtml('View Offer');
    }

    public function test_includes_the_platform_name_in_the_footer(): void
    {
        $mail = $this->offerReceivedMail();

        $mail->assertSeeInHtml(config('app.name'));
    }

    // -----------------------------------------------------------------
    // Privacy: forbidden content must be absent from the real rendered
    // output, not merely assumed absent because it was never passed in.
    // -----------------------------------------------------------------

    public function test_never_renders_interviewer_email(): void
    {
        $mail = $this->offerReceivedMail();

        $mail->assertDontSeeInHtml('interviewer_email');
    }

    public function test_never_renders_company_feedback(): void
    {
        $mail = $this->offerReceivedMail();

        $mail->assertDontSeeInHtml('company_feedback');
    }

    public function test_never_renders_rating(): void
    {
        $mail = $this->offerReceivedMail();

        $mail->assertDontSeeInHtml('rating');
    }

    public function test_never_renders_correct_answer(): void
    {
        $mail = $this->offerReceivedMail();

        $mail->assertDontSeeInHtml('correct_answer');
    }

    public function test_never_renders_match_score(): void
    {
        $mail = $this->offerReceivedMail();

        $mail->assertDontSeeInHtml('match_score');
    }

    private function offerReceivedMail(
        string $studentName = 'Jane Student',
        string $opportunityTitle = 'Backend Developer',
        string $ctaUrl = 'http://localhost:8080/student/applications/5',
        ?Carbon $startDate = null,
        ?string $salaryAmount = null,
        ?string $salaryCurrency = null,
        ?string $salaryPeriod = null,
        ?string $offerMessage = null,
    ): OfferReceivedMail {
        return new OfferReceivedMail(
            studentName: $studentName,
            opportunityTitle: $opportunityTitle,
            ctaUrl: $ctaUrl,
            startDate: $startDate,
            salaryAmount: $salaryAmount,
            salaryCurrency: $salaryCurrency,
            salaryPeriod: $salaryPeriod,
            offerMessage: $offerMessage,
        );
    }
}
