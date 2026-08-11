<?php

namespace Tests\Unit\Mail;

use App\Mail\OfferAcceptedMail;
use Tests\TestCase;

/**
 * Phase 7A-4.2: content and privacy coverage for `OfferAcceptedMail`.
 */
class OfferAcceptedMailTest extends TestCase
{
    public function test_subject_names_the_event_and_opportunity(): void
    {
        $mail = $this->offerAcceptedMail(opportunityTitle: 'Backend Developer');

        $mail->assertHasSubject('Offer Accepted — Backend Developer');
    }

    public function test_greets_the_organization_recipient_by_their_own_name(): void
    {
        $mail = $this->offerAcceptedMail(recipientName: 'Alex Recruiter');

        $mail->assertSeeInHtml('Alex Recruiter');
    }

    public function test_includes_the_student_name(): void
    {
        $mail = $this->offerAcceptedMail(studentName: 'Jane Student');

        $mail->assertSeeInHtml('Jane Student');
    }

    public function test_includes_the_opportunity_title(): void
    {
        $mail = $this->offerAcceptedMail(opportunityTitle: 'Senior Backend Engineer');

        $mail->assertSeeInHtml('Senior Backend Engineer');
    }

    public function test_states_the_candidate_accepted(): void
    {
        $mail = $this->offerAcceptedMail();

        $mail->assertSeeInHtml('accepted');
    }

    public function test_includes_the_view_application_cta_linking_to_the_given_url(): void
    {
        $mail = $this->offerAcceptedMail(ctaUrl: 'http://localhost:8080/organization/applications/5');

        $mail->assertSeeInHtml('View Application');
        $mail->assertSeeInHtml('http://localhost:8080/organization/applications/5', false);
    }

    public function test_includes_the_platform_name_in_the_footer(): void
    {
        $mail = $this->offerAcceptedMail();

        $mail->assertSeeInHtml(config('app.name'));
    }

    // -----------------------------------------------------------------
    // Privacy
    // -----------------------------------------------------------------

    public function test_never_renders_cv_or_private_profile_content(): void
    {
        $mail = $this->offerAcceptedMail();

        $mail->assertDontSeeInHtml('file_path');
        $mail->assertDontSeeInHtml('cover_letter');
    }

    public function test_never_renders_internal_assessment_data(): void
    {
        $mail = $this->offerAcceptedMail();

        $mail->assertDontSeeInHtml('match_score');
        $mail->assertDontSeeInHtml('company_feedback');
    }

    private function offerAcceptedMail(
        string $recipientName = 'Alex Recruiter',
        string $studentName = 'Jane Student',
        string $opportunityTitle = 'Backend Developer',
        string $ctaUrl = 'http://localhost:8080/organization/applications/5',
    ): OfferAcceptedMail {
        return new OfferAcceptedMail(
            recipientName: $recipientName,
            studentName: $studentName,
            opportunityTitle: $opportunityTitle,
            ctaUrl: $ctaUrl,
        );
    }
}
