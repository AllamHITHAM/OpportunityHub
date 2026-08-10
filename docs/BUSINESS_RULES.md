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
  - offer_sent
  - interview_scheduled *(deprecated — legacy compatibility only, see below)*
  - accepted
  - rejected
  - withdrawn
- Match score is calculated after application submission.
- **As of Phase 6C-0, `accepted` means specifically "the student accepted the Offer."** It is no longer organization-writable through the generic status endpoint — the only workflow permitted to set it is the future `Student\OfferController::accept()` (Phase 6C-1). Since no Offer workflow exists yet, `accepted` is **temporarily unreachable through any normal API action** after this phase — this is intentional, not a bug, and no temporary replacement endpoint was added. Pre-existing `accepted` rows are untouched and remain fully valid, legacy-compatible data; the semantics shift only governs *new* writes going forward.
- `rejected` continues to mean the hiring process ended negatively (the organization declined the candidate, at any appropriate stage — see section 3 and section 7). It remains directly organization-writable through the generic status endpoint, unchanged by Phase 6C-0. A rejected application is not automatically re-openable — reversing one requires a manual data change, not an API action; this is a stated rule, not one enforced by the API.
- A withdrawn application is controlled by the student; once `withdrawn`, no further status change is accepted from any source (`409`).
- **`application.status` owns recruitment progress only** (Phase 4A-1 decision, reaffirmed by Phase 6B-0 and Phase 6C-0). It does not, and must not, describe assessment-path/assessment-outcome detail (that belongs to `assessment.status`/`assessment.result`, see section 7) or Offer-specific detail (that will belong to `offer.status`, Phase 6C-1) — it only ever tracks the overall stage the application itself is at.
- **`in_assessment` (Phase 6B-0) is the generic application-level status meaning "an active evaluation exists for this application."** It is set exactly once, by `AssessmentService::transitionToInAssessment()`, whenever a real `Assessment` is created — for both `type=interview` and `type=quiz`, without introducing a second status per type. It can never be set directly through the generic `PUT /api/organization/applications/{application}/status` endpoint — it must always be the byproduct of a real Assessment-creation workflow (see section 7 and docs/API.md section 5).
- **`offer_sent` (Phase 6C-0) is the new generic application-level status meaning "the organization has sent a final Offer, and the student's response is pending."** It is reserved for the future `OfferService::sendOffer()` (Phase 6C-1) to write — Phase 6C-0 only adds the value to the database enum and API response contract; nothing in the codebase writes it yet. Like `in_assessment`, it can never be set directly through the generic status endpoint.
- **`interview_scheduled` is deprecated, legacy-compatibility-only.** Before Phase 6B-0, assessment creation wrote this value instead of a generic one; it remains a valid, readable `application.status` for pre-existing records (and is still an accepted *source* status for creating an assessment, so an old application stuck at `interview_scheduled` with no real Assessment can still receive one — see section 7). It can no longer be set through the generic status endpoint, and no code path writes it going forward — new Assessment creation always writes `in_assessment` instead.
- **The generic organization status endpoint may set only `reviewed`, `shortlisted`, or `rejected`.** As of Phase 6C-0 it can no longer set `accepted` (see above) — combined with the pre-existing exclusions of `in_assessment`, `offer_sent`, and `interview_scheduled`, this endpoint is now purely for early-funnel screening moves and rejection; every other transition is the byproduct of a real domain workflow (Assessment creation, the future Offer workflow).
- **Responsibility boundaries across the assessment/offer stack** (Phase 6B-0, extended Phase 6C-0): `Application.status` owns recruitment progress and the two generic "a workflow is active" signals (`in_assessment`, `offer_sent`); `Assessment.type` identifies which kind of evaluation is active (`interview`/`quiz`); `Assessment.status`/`Assessment.result` own the generic evaluation lifecycle/outcome shared by every assessment type; `Interview.status`/`Quiz.status` own their own type-specific state; a future `Offer.status` (Phase 6C-1) will own Offer-specific state (`sent`/`accepted`/`declined`) the same way, without `Application.status` ever needing more than its own two generic signals plus the final `accepted`/`rejected` outcome.

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
- Only `shortlisted` and (legacy) `interview_scheduled` applications may have an assessment created for them (interview or quiz); `pending`, `reviewed`, `in_assessment`, `offer_sent`, `accepted`, `rejected`, and `withdrawn` are blocked with a `422`. `in_assessment` and `offer_sent` are blocked here specifically because, by construction, each only exists once a real assessment (or, for `offer_sent`, an Offer) already does — see section 5.
- An assessment's own `status`/`result` never changes `application.status`; only the organization's explicit status-update action does that.
- Read endpoints remain: `GET /api/organization/applications/{application}/assessment`, `GET /api/organization/assessments/{assessment}`, `GET /api/student/assessments`, `GET /api/student/assessments/{assessment}` — see docs/API.md section 7.

## 7a. Quiz Rules (Phase 6B-1 organization authoring; Phase 6B-3 student taking/grading)

Quiz v1 covers both organization authoring (creating a quiz assessment, adding/editing/removing its questions, and publishing it — Phase 6B-1) and student taking (starting the one attempt allowed, submitting it, and immediate server-side auto-grading — Phase 6B-3).

- A quiz belongs to one assessment; an assessment can have **at most one quiz**, enforced by a database unique constraint (`quizzes.assessment_id`) — the same one-assessment-per-application guarantee in section 7 means an application can likewise have at most one quiz, reached via `Application → Assessment → Quiz` (there is no direct `Application`-to-`Quiz` relationship).
- A quiz has a lifecycle independent of `Assessment.status`: `draft` → `published`. It always starts `draft`. Creating a quiz assessment sets `Assessment.status = pending`; publishing the quiz (and only publishing it) advances `Assessment.status` to `scheduled` — publishing never touches `Application.status`, which is already `in_assessment` from creation and stays there.
- `Quiz.passing_score` is a **required integer percentage, 0–100**, set at creation and immutable afterward in v1 (no update-quiz-configuration endpoint exists).
- A quiz has zero or more questions (`hasMany`). Quiz v1 supports exactly two question types: `multiple_choice` and `true_false`. Both are **auto-graded only** — there is no short-answer type and no manual-grading path.
- `multiple_choice` questions store their choices in `options` (a JSON array, minimum two entries) and a `correct_answer` that must exactly match one submitted option (case-sensitive — options are free-form organization-authored text). `true_false` questions never store `options` (always `null` in the database — see docs/ARCHITECTURE.md for why this representation was chosen over serializing `["True", "False"]`); their two choices are fixed and implicit. A `true_false` `correct_answer` is canonicalized to exactly `"True"`/`"False"` regardless of the input casing submitted (`"true"`, `"TRUE"`, `"1"`, etc. all normalize the same way).
- **`Question.correct_answer` is organization-internal.** It is safe to return to the organization that authored the quiz (every organization-facing Quiz/Question response includes it), but it **must never be included in any Student-facing Quiz API response** — see `Question::ORGANIZATION_ONLY_FIELDS`, `App\Http\Controllers\Student\Concerns\HidesInternalQuestionFields`, and `tests/Unit/Models/QuestionPrivacyTest.php`. As of Phase 6B-3 this is enforced on every student-facing path that can nest a quiz: `GET /student/assessments/{assessment}/quiz`, `GET /student/assessments`, and `GET /student/assessments/{assessment}` — never just the dedicated Quiz endpoint.
- Questions may only be added, updated, or deleted while `quiz.status = draft`. Once `published`, all three are rejected with `422` (`"Published quizzes cannot be modified"`), and quiz configuration itself (title/instructions/time limit/passing score) is likewise treated as immutable in v1 — there is no update-quiz endpoint at all, published or not.
- Publishing (`PUT /api/organization/quizzes/{quiz}/publish`) requires: the quiz belongs to the requesting organization, `quiz.status = draft`, and at least one question exists. Every question is already structurally valid by construction (both create and update are gated by the same validation), so publish performs no further per-question re-validation.
- There is currently no dedicated Quiz/Assessment delete endpoint (the only existing Assessment cleanup path is `InterviewController::destroy()`, which is interview-specific). Deleting an `Assessment` still correctly cascades to its `Quiz`, `Question`, and (as of Phase 6B-3) `QuizAttempt` rows at the database level (`cascadeOnDelete` on every foreign key) — this is proven directly against the models in tests — but no organization-facing route exercises it for quiz today, which means **a quiz stuck in `draft` has no way to be cancelled/removed in this phase.** This is a known, deliberately deferred gap (see docs/ARCHITECTURE.md), not an oversight.

### Student Quiz Taking (Phase 6B-3)

The student workflow: **published quiz → start → attempt in progress → submit → auto-grade → Assessment `completed` → `passed`/`failed`.** There is no student-facing draft-quiz visibility, no retake, no manual grading, and no answer-review endpoint (a submitted attempt's correct answers are never returned to the student).

- **One attempt only.** `quiz_attempts.(quiz_id, application_id)` is a database unique constraint, not just an application-level check — a genuine concurrent double "Start" race is resolved by catching the resulting integrity violation and returning the winning attempt, the same translated-`QueryException` pattern `AssessmentService` already uses for duplicate assessments.
- **No separate attempt-status column.** An attempt's state is derived, not stored: no row at all means "not started"; `submitted_at === null` means "in progress"; `submitted_at !== null` means "completed" (and graded). See docs/ARCHITECTURE.md for the full reasoning.
- **Start is idempotent for an in-progress attempt.** Calling Start again while unsubmitted returns the *same* attempt — `started_at` is never reset and no extra time is ever granted. This is what lets the client safely re-open/refresh mid-attempt without it costing the student anything. A submitted attempt can never be restarted (no retakes in v1) — Start on an already-submitted quiz returns `409` (`"Quiz has already been submitted"`).
- **Start never changes `Application.status`.** It moves `Assessment.status` to `in_progress` only; the application was already `in_assessment` (set at quiz-assessment creation, Phase 6B-1) and stays there through the entire attempt.
- **Server-enforced time limit, never client-trusted.** When `quiz.time_limit_minutes` is set, a submission is only accepted while `now() <= attempt.started_at + time_limit_minutes`, checked entirely server-side with no grace period. An expired submission is rejected with `422` (`"Quiz time limit has expired"`) and the attempt is left unsubmitted (a student cannot be locked out of ever getting a graded result by an expired timer — retrying isn't possible in v1, but the rejection itself never corrupts attempt state). A `null` time limit never expires. The Flutter countdown (a later phase) is a UI convenience only.
- **Grading is entirely server-side, from each question's own `correct_answer`.** The submission body only ever supplies `question_id`/`answer` pairs (see `App\Http\Requests\Student\SubmitQuizRequest`) — `points`, `score`, and `correct_answer` are never accepted as client input and are never read from the request even if sent.
- **Every quiz question must be answered exactly once** to submit at all — no partial submission in v1. `SubmitQuizRequest` rejects a missing question, a duplicate `question_id`, a `question_id` that doesn't belong to the quiz, an MCQ answer that isn't one of the question's own `options`, and a `true_false` answer that isn't `"True"`/`"False"` (canonicalized the same way `correct_answer` is on the organization side — `"true"`/`"TRUE"`/`"1"` and the false equivalents all normalize identically before validation).
- **Scoring**: `score = round(earned_points / total_points * 100)`, an integer percentage. `earned_points` sums `question.points` for every question whose submitted answer exactly equals `question.correct_answer`; `total_points` sums every question's `points`. Rounding uses PHP's `round()` default (round-half-away-from-zero) — e.g. exactly 12.5% rounds to 13, not 12.
- **Result**: `score >= quiz.passing_score` → `Assessment.result = passed`; otherwise `failed`. Both happen atomically with `Assessment.status = completed` and `Assessment.completed_at = now()` inside the same database transaction that saves the attempt's `answers`/`score`/`submitted_at` — a genuine concurrent double-submit is caught by row-locking the attempt (`lockForUpdate()`) before checking `submitted_at`, returning `409` (`"Quiz has already been submitted"`) the same as a simple repeat request.
- **Submit without Start is rejected**, not implicitly treated as a fresh start — `422` (`"Start the quiz before submitting."`). Phase 6B-3 deliberately keeps Start and Submit as two distinct, required steps.
- **`Application.status` is never touched by grading.** It remains `in_assessment` after a quiz is submitted and graded, exactly like it does after an interview is completed (section 6) — the final accepted/rejected decision always stays a separate, organization-triggered action via the existing status endpoint, never automatic from a quiz result.

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