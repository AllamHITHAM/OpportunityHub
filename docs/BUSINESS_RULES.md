# OpportunityHub Business Rules

## 1. User Rules

- Every user must have one role: student, organization, or admin.
- User email must be unique.
- A suspended user cannot access protected features.
- Admin users manage platform approval and monitoring.

## 2. Student Rules

- A student must have a user account before creating a student profile.
- A student can have only one student profile.
- A student can upload multiple CVs.
- A student can choose one CV when applying to an opportunity.
- A student can add multiple skills.
- A student cannot apply to the same opportunity more than once.
- A student can view their own applications and interview details only.

## 3. Organization Rules

- An organization must have a user account before creating an organization profile.
- An organization can have only one organization profile.
- An organization must be approved by admin before publishing opportunities.
- An organization can create, update, and close its own opportunities.
- An organization cannot manage opportunities created by another organization.
- An organization can view applicants only for its own opportunities.
- An organization can update application status.
- An organization can schedule interviews for applications that are `shortlisted` or (legacy compatibility only) already `interview_scheduled` (re-scheduling is blocked separately by the one-assessment-per-application rule, not by this status check). An application already at `in_assessment` is never an allowed source, since a real assessment already exists for it by construction.

## 4. Opportunity Rules

- Every opportunity belongs to one organization.
- Opportunities can be job, internship, volunteer, scholarship, or competition.
- Opportunities can be open, closed, or draft.
- Closed opportunities cannot receive new applications.
- Each opportunity can have multiple required or preferred skills.
- Required skills have higher priority in AI matching than preferred skills.

## 5. Application Rules

- Every application belongs to one student and one opportunity.
- Every application must use one CV.
- Application status can be:
  - pending
  - reviewed
  - shortlisted
  - in_assessment
  - interview_scheduled *(deprecated — legacy compatibility only, see below)*
  - accepted
  - rejected
  - withdrawn
- Match score is calculated after application submission.
- A rejected application cannot be accepted unless admin/organization updates it manually — this is a stated rule, not one enforced by the API: the status endpoint currently accepts any of `reviewed, shortlisted, accepted, rejected` in any order, with no transition state machine.
- A withdrawn application is controlled by the student.
- **`application.status` owns recruitment progress only** (Phase 4A-1 decision, reaffirmed by Phase 6B-0). It does not, and must not, describe assessment-path or assessment-outcome detail — that belongs to `assessment.status`/`assessment.result` (see section 7).
- **`in_assessment` (Phase 6B-0) is the generic application-level status meaning "an active evaluation exists for this application."** It is set exactly once, by `AssessmentService::transitionToInAssessment()`, whenever a real `Assessment` is created — today only for `type=interview`; a future `type=quiz` will set the exact same value, without introducing a second status. It can never be set directly through the generic `PUT /api/organization/applications/{application}/status` endpoint — it must always be the byproduct of a real Assessment-creation workflow (see section 7 and docs/API.md section 5).
- **`interview_scheduled` is deprecated, legacy-compatibility-only.** Before Phase 6B-0, assessment creation wrote this value instead of a generic one; it remains a valid, readable `application.status` for pre-existing records (and is still an accepted *source* status for creating an assessment, so an old application stuck at `interview_scheduled` with no real Assessment can still receive one — see section 7). As of Phase 6B-0 it can no longer be set through the generic status endpoint, and no code path writes it going forward — new Assessment creation always writes `in_assessment` instead.
- **Responsibility boundaries across the assessment stack** (Phase 6B-0): `Application.status` owns recruitment progress and the single generic "an assessment is active" signal (`in_assessment`); `Assessment.type` identifies which kind of evaluation is active (`interview` today, `quiz` in a later phase); `Assessment.status`/`Assessment.result` own the generic evaluation lifecycle/outcome shared by every assessment type; `Interview.status` owns interview-specific scheduling/completion state; a future `Quiz.status` will own quiz-specific state the same way, without `Application.status` ever needing a third value.

## 6. Interview Rules

- Every interview belongs to one **assessment** (Phase 4A-1) rather than directly to an application — an interview is reached via `application → assessment → interview`. An application can still, in effect, have at most one interview, because it can have at most one assessment (see section 7) and an assessment can have at most one interview.
- Interviews can be online, phone, or on-site.
- Interview details must include date and time.
- Interview location or link is required depending on meeting type.
- Each interview can record a decision (pending, passed, failed, waiting) and an optional rating after it is completed. Completing an interview also updates its assessment's `status`/`result`/`completed_at`, but never changes the application's status — that remains a separate, organization-triggered decision.
- All existing Interview API routes (`/api/organization/applications/{application}/interview`, `/api/organization/interviews*`, `/api/student/interviews`) continue to work exactly as before; this phase is a backend restructuring, not a behavior change to any of them (see docs/API.md section 6).

## 7. Assessment Rules

Added in Phase 4A-1 as the shared foundation for the Interview/Quiz assessment paths; Phase 4A-2 adds the ability to create an interview assessment generically; Phase 6B-1 adds real Quiz creation and organization authoring.

- An assessment belongs to one application; an application can have **at most one assessment**, enforced by a database unique constraint (`assessments.application_id`).
- An assessment has a `type`: `interview` or `quiz`. **Both can be created as of Phase 6B-1.** `POST /api/organization/applications/{application}/assessments` with `type=quiz` creates a real `Assessment` + `Quiz` (see section 7a); any other `type` value still fails standard validation.
- An assessment has a `status` (`pending, scheduled, in_progress, completed, declined, cancelled`) and a `result` (`passed, failed, waiting`, or no result yet — represented as `null`, not the string `pending`).
- An organization can create an interview assessment two ways — the original per-type endpoint (`POST /api/organization/applications/{application}/interview`) and, as of Phase 4A-2, the generic endpoint (`POST /api/organization/applications/{application}/assessments` with `type=interview`). **Both call the same underlying workflow** (`App\Services\AssessmentService::createInterviewAssessment()`) and are subject to identical rules: allowed source application status, one-assessment-per-application, and the same Interview field validation — see docs/API.md sections 6–7 and docs/ARCHITECTURE.md. A quiz assessment has only the generic endpoint (`type=quiz`), calling the sibling `AssessmentService::createQuizAssessment()`, which reuses the exact same source-status/duplicate/transaction logic.
- Only `shortlisted` and (legacy) `interview_scheduled` applications may have an assessment created for them (interview or quiz); `pending`, `reviewed`, `in_assessment`, `accepted`, `rejected`, and `withdrawn` are blocked with a `422`. `in_assessment` is blocked here specifically because, by construction, it only exists once a real assessment already does — see section 5.
- An assessment's own `status`/`result` never changes `application.status`; only the organization's explicit status-update action does that.
- Read endpoints remain: `GET /api/organization/applications/{application}/assessment`, `GET /api/organization/assessments/{assessment}`, `GET /api/student/assessments`, `GET /api/student/assessments/{assessment}` — see docs/API.md section 7.

## 7a. Quiz Rules (Phase 6B-1 — organization authoring only)

Quiz v1 is organization-authoring only: creating a quiz assessment, adding/editing/removing its questions, and publishing it. **Student attempt/submission is not implemented yet** — there is no endpoint for a student to view, start, answer, or submit a quiz; that is a later phase.

- A quiz belongs to one assessment; an assessment can have **at most one quiz**, enforced by a database unique constraint (`quizzes.assessment_id`) — the same one-assessment-per-application guarantee in section 7 means an application can likewise have at most one quiz, reached via `Application → Assessment → Quiz` (there is no direct `Application`-to-`Quiz` relationship).
- A quiz has a lifecycle independent of `Assessment.status`: `draft` → `published`. It always starts `draft`. Creating a quiz assessment sets `Assessment.status = pending`; publishing the quiz (and only publishing it) advances `Assessment.status` to `scheduled` — publishing never touches `Application.status`, which is already `in_assessment` from creation and stays there.
- `Quiz.passing_score` is a **required integer percentage, 0–100**, set at creation and immutable afterward in v1 (no update-quiz-configuration endpoint exists).
- A quiz has zero or more questions (`hasMany`). Quiz v1 supports exactly two question types: `multiple_choice` and `true_false`. Both are **auto-grade-ready only** — there is no short-answer type and no manual-grading path, though grading itself (scoring a student's actual attempt) is not implemented until the student-attempt phase.
- `multiple_choice` questions store their choices in `options` (a JSON array, minimum two entries) and a `correct_answer` that must exactly match one submitted option (case-sensitive — options are free-form organization-authored text). `true_false` questions never store `options` (always `null` in the database — see docs/ARCHITECTURE.md for why this representation was chosen over serializing `["True", "False"]`); their two choices are fixed and implicit. A `true_false` `correct_answer` is canonicalized to exactly `"True"`/`"False"` regardless of the input casing submitted (`"true"`, `"TRUE"`, `"1"`, etc. all normalize the same way).
- **`Question.correct_answer` is organization-internal.** It is safe to return to the organization that authored the quiz (every organization-facing Quiz/Question response includes it), but it **must never be included in a future Student-facing Quiz API response** — see `Question::ORGANIZATION_ONLY_FIELDS` and `tests/Unit/Models/QuestionPrivacyTest.php`, which exists specifically to pin this down before any student endpoint is built.
- Questions may only be added, updated, or deleted while `quiz.status = draft`. Once `published`, all three are rejected with `422` (`"Published quizzes cannot be modified"`), and quiz configuration itself (title/instructions/time limit/passing score) is likewise treated as immutable in v1 — there is no update-quiz endpoint at all, published or not.
- Publishing (`PUT /api/organization/quizzes/{quiz}/publish`) requires: the quiz belongs to the requesting organization, `quiz.status = draft`, and at least one question exists. Every question is already structurally valid by construction (both create and update are gated by the same validation), so publish performs no further per-question re-validation.
- There is currently no dedicated Quiz/Assessment delete endpoint (the only existing Assessment cleanup path is `InterviewController::destroy()`, which is interview-specific). Deleting an `Assessment` still correctly cascades to its `Quiz` and `Question` rows at the database level (`cascadeOnDelete` on both foreign keys) — this is proven directly against the models in tests — but no organization-facing route exercises it for quiz today, which means **a quiz stuck in `draft` has no way to be cancelled/removed in this phase.** This is a known, deliberately deferred gap (see docs/ARCHITECTURE.md), not an oversight.

## 8. Notification Rules

- Notifications belong to users.
- Notifications are created when important events happen.
- Examples:
  - organization approved
  - application submitted
  - application reviewed
  - interview scheduled
  - application accepted or rejected
- Users can mark notifications as read.
- Notifications can have a priority level (low, normal, high) and record the timestamp they were sent.
- **Not yet implemented as of Phase 4A-1**: no code path creates a `Notification` row for any of the examples above (including assessment-related events) — only reading and marking-as-read are implemented. Deferred to a later phase.

## 9. AI Matching Rules

- AI matching starts simple and explainable.
- Matching score should be between 0 and 100.
- Initial matching weights:
  - Skills: 50%
  - Experience: 20%
  - Education: 10%
  - Location: 10%
  - Work mode: 10%
- The system should store the final score in applications.match_score.
- The first version should use rule-based matching before external AI APIs.

## 10. Security Rules

- Passwords must be hashed.
- Users cannot access data that does not belong to them.
- Admin-only actions must be protected.
- Organization-only actions must be protected.
- Student-only actions must be protected.
- API responses should not expose sensitive data.