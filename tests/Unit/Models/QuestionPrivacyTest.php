<?php

namespace Tests\Unit\Models;

use App\Models\Question;
use Tests\TestCase;

/**
 * Phase 6B-1 privacy regression: `Question::correct_answer` is
 * organization-internal -- safe to return to the organization that authored
 * the quiz, but it must never be included in a future Student-facing Quiz
 * API response. No Student Quiz endpoint exists yet (attempt/submission is
 * a later phase -- see docs/BUSINESS_RULES.md), so there is nothing to
 * assert against a real response today. Instead, this pins down the single
 * canonical list a future student serialization path (the quiz counterpart
 * to `App\Http\Controllers\Student\Concerns\HidesInternalInterviewFields`)
 * MUST hide -- so that whoever builds the Student endpoint has one place to
 * check, and this test fails loudly if `correct_answer` is ever quietly
 * dropped from it or a new organization-only field is added without being
 * registered here.
 */
class QuestionPrivacyTest extends TestCase
{
    public function test_correct_answer_is_registered_as_an_organization_only_field(): void
    {
        $this->assertContains('correct_answer', Question::ORGANIZATION_ONLY_FIELDS);
    }

    /**
     * `options`/`prompt`/`points`/`position`/`type` describe the question
     * itself and are always safe to show a student attempting it later --
     * only the answer key is sensitive. This guards against the constant
     * silently growing to hide fields that were never meant to be private.
     */
    public function test_only_correct_answer_is_registered_as_organization_only(): void
    {
        $this->assertSame(['correct_answer'], Question::ORGANIZATION_ONLY_FIELDS);
    }
}
