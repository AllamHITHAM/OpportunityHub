<?php

namespace Tests\Unit\Mail;

use App\Mail\ApplicationRejectedMail;
use Tests\TestCase;

/**
 * Phase 7A-4.2: content and privacy coverage for `ApplicationRejectedMail`.
 */
class ApplicationRejectedMailTest extends TestCase
{
    public function test_subject_is_a_respectful_generic_update_not_a_blunt_rejection(): void
    {
        $mail = $this->applicationRejectedMail(opportunityTitle: 'Backend Developer');

        $mail->assertHasSubject('Application Update — Backend Developer');
    }

    public function test_greets_the_student_by_name(): void
    {
        $mail = $this->applicationRejectedMail(studentName: 'Jane Student');

        $mail->assertSeeInHtml('Jane Student');
    }

    public function test_includes_the_opportunity_title(): void
    {
        $mail = $this->applicationRejectedMail(opportunityTitle: 'Senior Backend Engineer');

        $mail->assertSeeInHtml('Senior Backend Engineer');
    }

    public function test_states_the_application_was_not_selected(): void
    {
        $mail = $this->applicationRejectedMail();

        $mail->assertSeeInHtml('was not selected');
    }

    public function test_includes_the_view_application_cta_linking_to_the_given_url(): void
    {
        $mail = $this->applicationRejectedMail(ctaUrl: 'http://localhost:8080/student/applications/5');

        $mail->assertSeeInHtml('View Application');
        $mail->assertSeeInHtml('http://localhost:8080/student/applications/5', false);
    }

    public function test_includes_the_platform_name_in_the_footer(): void
    {
        $mail = $this->applicationRejectedMail();

        $mail->assertSeeInHtml(config('app.name'));
    }

    // -----------------------------------------------------------------
    // Privacy: no invented or internal rejection reason.
    // -----------------------------------------------------------------

    public function test_never_renders_a_match_score(): void
    {
        $mail = $this->applicationRejectedMail();

        $mail->assertDontSeeInHtml('match_score');
    }

    public function test_never_renders_internal_assessment_feedback(): void
    {
        $mail = $this->applicationRejectedMail();

        $mail->assertDontSeeInHtml('company_feedback');
    }

    public function test_never_renders_a_rejection_reason_label(): void
    {
        $mail = $this->applicationRejectedMail();

        $mail->assertDontSeeInHtml('Reason:');
    }

    private function applicationRejectedMail(
        string $studentName = 'Jane Student',
        string $opportunityTitle = 'Backend Developer',
        string $ctaUrl = 'http://localhost:8080/student/applications/5',
    ): ApplicationRejectedMail {
        return new ApplicationRejectedMail(
            studentName: $studentName,
            opportunityTitle: $opportunityTitle,
            ctaUrl: $ctaUrl,
        );
    }
}
