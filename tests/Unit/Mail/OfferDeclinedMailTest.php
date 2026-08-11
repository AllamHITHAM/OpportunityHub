<?php

namespace Tests\Unit\Mail;

use App\Mail\OfferDeclinedMail;
use Tests\TestCase;

/**
 * Phase 7A-4.2: content and privacy coverage for `OfferDeclinedMail`.
 * Mirrors `OfferAcceptedMailTest`'s own structure/conventions.
 */
class OfferDeclinedMailTest extends TestCase
{
    public function test_subject_names_the_event_and_opportunity(): void
    {
        $mail = $this->offerDeclinedMail(opportunityTitle: 'Backend Developer');

        $mail->assertHasSubject('Offer Declined — Backend Developer');
    }

    public function test_greets_the_organization_recipient_by_their_own_name(): void
    {
        $mail = $this->offerDeclinedMail(recipientName: 'Alex Recruiter');

        $mail->assertSeeInHtml('Alex Recruiter');
    }

    public function test_includes_the_student_name(): void
    {
        $mail = $this->offerDeclinedMail(studentName: 'Jane Student');

        $mail->assertSeeInHtml('Jane Student');
    }

    public function test_includes_the_opportunity_title(): void
    {
        $mail = $this->offerDeclinedMail(opportunityTitle: 'Senior Backend Engineer');

        $mail->assertSeeInHtml('Senior Backend Engineer');
    }

    public function test_states_the_candidate_declined(): void
    {
        $mail = $this->offerDeclinedMail();

        $mail->assertSeeInHtml('declined');
    }

    public function test_includes_the_view_application_cta_linking_to_the_given_url(): void
    {
        $mail = $this->offerDeclinedMail(ctaUrl: 'http://localhost:8080/organization/applications/5');

        $mail->assertSeeInHtml('View Application');
        $mail->assertSeeInHtml('http://localhost:8080/organization/applications/5', false);
    }

    public function test_includes_the_platform_name_in_the_footer(): void
    {
        $mail = $this->offerDeclinedMail();

        $mail->assertSeeInHtml(config('app.name'));
    }

    // -----------------------------------------------------------------
    // Privacy
    // -----------------------------------------------------------------

    public function test_never_invents_a_decline_reason(): void
    {
        $mail = $this->offerDeclinedMail();

        $mail->assertDontSeeInHtml('Reason:');
    }

    public function test_never_renders_cv_or_private_profile_content(): void
    {
        $mail = $this->offerDeclinedMail();

        $mail->assertDontSeeInHtml('file_path');
        $mail->assertDontSeeInHtml('cover_letter');
    }

    public function test_never_renders_internal_assessment_data(): void
    {
        $mail = $this->offerDeclinedMail();

        $mail->assertDontSeeInHtml('match_score');
        $mail->assertDontSeeInHtml('company_feedback');
    }

    private function offerDeclinedMail(
        string $recipientName = 'Alex Recruiter',
        string $studentName = 'Jane Student',
        string $opportunityTitle = 'Backend Developer',
        string $ctaUrl = 'http://localhost:8080/organization/applications/5',
    ): OfferDeclinedMail {
        return new OfferDeclinedMail(
            recipientName: $recipientName,
            studentName: $studentName,
            opportunityTitle: $opportunityTitle,
            ctaUrl: $ctaUrl,
        );
    }
}
