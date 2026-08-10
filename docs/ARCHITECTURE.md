# OpportunityHub System Architecture

## Architecture Style

The project follows a layered architecture.

```
Flutter App
        │
        ▼
 REST API (Laravel)
        │
        ▼
 Route
        │
        ▼
 Request Validation
        │
        ▼
 Controller
        │
        ▼
 Service Layer (Future)
        │
        ▼
 Model (Eloquent)
        │
        ▼
 MySQL Database
```

---

# Main Layers

## Flutter

Responsibilities:
- User Interface
- API Requests
- Authentication Token Storage
- Navigation

Flutter never talks directly to the database.

---

## Laravel REST API

Responsibilities:

- Authentication
- Validation
- Business Logic
- Authorization
- Database Communication

All requests pass through Laravel.

---

## Controllers

Responsibilities:

- Receive HTTP requests
- Validate requests
- Call Models or Services
- Return JSON responses

Controllers should stay small.

---

## Models

Each table has one Eloquent Model.

Examples:

- User
- StudentProfile
- OrganizationProfile
- Skill
- Opportunity
- Application

Models define relationships.

---

## Database

MySQL stores all application data.

Tables are created only using Laravel Migrations.

Never manually create tables in phpMyAdmin.

---

## Assessment Architecture (Phase 4A-1)

An `Application`'s post-shortlist evaluation path (interview, and later quiz)
is modeled as a separate, generic `Assessment` entity rather than fields
bolted onto `Application` or a standalone `Interview`/`Quiz` pair with no
shared shape:

```
Application
  hasOne Assessment
  hasMany QuizAttempt    (Phase 6B-3)

Assessment
  belongsTo Application
  hasOne Interview
  hasOne Quiz            (Phase 6B-1)

Quiz                      (Phase 6B-1)
  belongsTo Assessment
  hasMany Question
  hasMany QuizAttempt     (Phase 6B-3)

Question                  (Phase 6B-1)
  belongsTo Quiz

QuizAttempt               (Phase 6B-3)
  belongsTo Quiz
  belongsTo Application
```

- `Application` owns recruitment lifecycle only (`status`: pending,
  reviewed, shortlisted, in_assessment, interview_scheduled, accepted,
  rejected, withdrawn). As of Phase 6B-0, `in_assessment` is the generic
  value written whenever a real Assessment exists (interview or quiz);
  `interview_scheduled` is deprecated legacy-compatibility only —
  see docs/BUSINESS_RULES.md section 5.
- `Assessment` owns the shared assessment lifecycle: `type` (`interview` |
  `quiz`), `status`, `result`, `completed_at`.
- `Interview` owns interview-specific scheduling/outcome detail
  (`interview_type`, `scheduled_at`, `decision`, `rating`, etc.) and
  belongs to `Assessment`, not directly to `Application`.
- `Quiz` (Phase 6B-1) owns quiz-specific authoring configuration (`title`,
  `instructions`, `time_limit_minutes`, `passing_score`) and its own
  `draft`/`published` lifecycle, independent of `Assessment.status` — and
  belongs to `Assessment`, not directly to `Application`, the same way
  `Interview` does. `Question` (Phase 6B-1) belongs to `Quiz`, never
  directly to `Assessment` or `Application` — reaching a question is always
  `Application → Assessment → Quiz → Question`. There is deliberately no
  direct `Application`-to-`Quiz`/`Question` relationship; nothing in this
  phase needed one, and adding one would just be a second path to the same
  data.
- An application has at most one assessment (enforced by
  `assessments.application_id` being unique), and, symmetrically, an
  assessment has at most one quiz (enforced by `quizzes.assessment_id`
  being unique). As of Phase 6B-1, `quiz` is no longer merely a
  schema-ready `assessment.type` value — real `quizzes`/`questions` tables,
  a `QuizController`, and organization-side authoring all exist. As of
  Phase 6B-3, real student-facing Quiz taking/grading exists too — see
  "Quiz Authoring Architecture (Phase 6B-1)" and "Student Quiz Taking
  Architecture (Phase 6B-3)" below.
- `QuizAttempt` (Phase 6B-3) belongs to both `Quiz` and `Application`
  directly (`quiz_attempts.quiz_id`/`application_id` are real columns, not
  a "through" relation) — a plain `hasMany` on each side, deliberately not
  an artificial `hasOne`, even though the `(quiz_id, application_id)`
  unique constraint means v1 only ever produces one row per pair. See
  "Student Quiz Taking Architecture (Phase 6B-3)" for why.
- This is a backend-only restructuring: all pre-existing Interview API
  routes, request/response bodies, and status codes are unchanged (see
  docs/API.md section 6); `application.status` is unchanged (see
  docs/BUSINESS_RULES.md section 5).

---

## Quiz Authoring Architecture (Phase 6B-1)

The organization side of Quiz v1: creating a quiz `Assessment`,
adding/editing/removing its `Question`s while still a draft, and
publishing it. Student-side taking/grading/attempts is a separate,
later phase — see "Student Quiz Taking Architecture (Phase 6B-3)" below,
which builds directly on top of everything here without changing any of
it.

- **`AssessmentService::createQuizAssessment()`** is the quiz sibling of
  `createInterviewAssessment()` (see the "Assessment Creation Workflow"
  section below), reusing the exact same `assertAllowedSourceStatus()`,
  `assertNoExistingAssessment()`, transaction pattern, and
  `transitionToInAssessment()` helper — none of that logic is duplicated
  for quiz. It creates the `Assessment` (`type=quiz`, `status=pending`)
  and an empty `Quiz` shell (`status=draft`, no questions) in one
  transaction, then moves `Application.status` to `in_assessment` exactly
  like the interview path does.
- **`Organization\AssessmentController::store()`** now branches on
  `type` (`createInterviewAssessment()` vs. `createQuizAssessment()`)
  instead of hard-rejecting `type=quiz`; it still contains no
  assessment-type-specific business logic itself -- both branches are one
  service call each, translated into the same response envelope.
- **`Organization\QuizController`** (new) owns everything past initial
  creation: `show()` (a quiz by its assessment), `storeQuestion()`,
  `updateQuestion()`, `destroyQuestion()`, and `publish()`. Ownership is
  checked the same way every other nested organization resource in this
  codebase is (see `OpportunitySkillController`): the full chain
  `quiz.assessment.application.opportunity.organization_id` must match the
  authenticated organization, and — for question update/delete —
  `question.quiz_id` must additionally match the route's `{quiz}`, exactly
  mirroring `OpportunitySkillController::destroy()`'s
  `opportunitySkill.opportunity_id` check. A mismatch on either check is a
  404, not a 403, matching every other "not yours" case in this codebase.
- **Draft mutability**: `storeQuestion()`/`updateQuestion()`/
  `destroyQuestion()` all reject with `422`
  (`"Published quizzes cannot be modified"`) once `quiz.status != draft`.
  There is no update-quiz-configuration endpoint at all (draft or
  published) — `title`/`instructions`/`time_limit_minutes`/`passing_score`
  are set once, at creation, and never changed afterward in v1.
- **Publish (`QuizController::publish()`)** requires `quiz.status=draft`
  and at least one question, then sets `quiz.status=published` and
  `assessment.status=scheduled` in one transaction. It does not
  re-validate each question's structure — every question already passed
  `StoreQuestionRequest`/`UpdateQuestionRequest` validation to exist in the
  database at all, so a question count check is the only "structural
  validity" left to verify. It never touches `Application.status`, which
  is already `in_assessment` from creation.
- **`true_false` questions never store `options`** (always `null` in the
  database) rather than persisting a redundant `["True", "False"]` on
  every row — those two choices are fixed and implicit for every
  `true_false` question, so a client/UI is expected to know them rather
  than read them from the response. This was chosen over the alternative
  the task considered (always serializing `options: ["True", "False"]`)
  specifically to keep storage clean and avoid a client ever
  mis-interpreting a per-row `options` value as authoritative for a type
  where it never varies.
- **`Question.correct_answer` is organization-internal** — `Question::ORGANIZATION_ONLY_FIELDS`
  names it as the single field every student-facing serialization path
  must hide. As of Phase 6B-3 that path is real:
  `App\Http\Controllers\Student\Concerns\HidesInternalQuestionFields` (the
  quiz counterpart to `HidesInternalInterviewFields`) reads this exact
  constant rather than repeating the field name — see "Student Quiz
  Taking Architecture (Phase 6B-3)" below. Every organization-facing
  response still includes it (the organization authored it);
  `tests/Unit/Models/QuestionPrivacyTest.php` continues to guard the
  constant itself from silently drifting.
- **No Quiz/Assessment delete endpoint.** The only existing Assessment
  cleanup path in this codebase is `InterviewController::destroy()`
  (interview-specific: delete the assessment, cascade to the interview,
  revert `application.status`). There is no generic
  `AssessmentController::destroy()` to extend, and this phase deliberately
  does not invent one. The FK cascade itself (`Assessment` delete →
  `Quiz` delete → `Question` delete) is real and tested directly against
  the models, so it would work correctly the moment *any* caller deletes
  an Assessment — there is just no organization-facing route that does so
  for a quiz today. **Known gap**: a `draft` quiz an organization no
  longer wants has no cancellation/deletion path in this phase, unlike an
  unwanted interview (`DELETE /api/organization/interviews/{interview}`
  already exists). Deferred rather than solved here, per minimal scope —
  flagged explicitly so it isn't mistaken for an oversight.

---

## Student Quiz Taking Architecture (Phase 6B-3)

Adds the student side Quiz v1 was missing: viewing a *published* quiz with
the answer key stripped, starting the one attempt allowed, and submitting
it for immediate server-side auto-grading. `App\Http\Controllers\Student\QuizController`
owns all three actions; no service class was introduced for grading —
the logic is small, used from exactly one place, and needs a single
transaction/lock scope that a service layer would only have to hand back
out to the controller anyway.

- **`QuizAttempt` has no status column.** Its state is derived entirely
  from two nullable timestamps: no row at all means "not started";
  `submitted_at === null` (with a row present) means "in progress";
  `submitted_at !== null` means "completed". This was a deliberate choice
  over adding a redundant enum that would just have to be kept in sync
  with those same two facts — see docs/BUSINESS_RULES.md section 7a for
  the full state-derivation rule.
- **One attempt, enforced at the database level.** `quiz_attempts.(quiz_id, application_id)`
  is a unique constraint, the same "pre-check plus translated
  `QueryException`-on-race" pattern `AssessmentService`/`Question` already
  establish for `assessments.application_id` and `quizzes.assessment_id`.
  `QuizController::start()` pre-checks for an existing row, and separately
  catches the unique-violation `QueryException` a genuine concurrent
  double-start race would produce, returning the winning attempt either
  way rather than surfacing a raw conflict.
- **`started_at` is schema-nullable specifically to dodge a MySQL/MariaDB
  footgun.** With `explicit_defaults_for_timestamp` OFF (this project's
  local server's setting), the first NOT-NULL `timestamp` column with no
  explicit default in a table silently gets `DEFAULT CURRENT_TIMESTAMP ON
  UPDATE CURRENT_TIMESTAMP` from MySQL itself — which would have quietly
  bumped `started_at` forward on every later `save()` (grading writes
  `answers`/`score`/`submitted_at` onto the same row), corrupting
  time-limit enforcement retroactively. Application code always populates
  it at creation regardless, so it is never actually `null` in practice —
  see the migration's own doc comment and
  `QuizAttemptRelationshipTest::test_started_at_does_not_change_on_a_later_save()`,
  a direct regression guard for this exact scenario.
- **Submit is one locked transaction.** `QuizController::submit()` opens a
  `DB::transaction()`, immediately `lockForUpdate()`s the attempt row,
  then checks (in order) that it exists, isn't already submitted, and
  isn't past its time limit — each a small domain exception
  (`QuizAttemptNotStartedException`/`QuizAlreadySubmittedException`/`QuizTimeLimitExpiredException`)
  caught just outside the transaction and translated into its own
  response, the same "domain exception crosses the transaction/service
  boundary" pattern `AssessmentService`'s exceptions already establish.
  Grading, saving the attempt, and updating the `Assessment` all happen
  inside that same locked transaction, so a concurrent double-submit is
  rejected the same way a simple repeat request is.
- **Grading trusts nothing from the client except which option/value was
  picked.** `App\Http\Requests\Student\SubmitQuizRequest` validates that
  every quiz question is answered exactly once, with a structurally valid
  answer for its type, entirely against the quiz's own question set
  fetched server-side — `points`, `score`, and `correct_answer` are never
  request fields at all. The controller then compares each submitted
  answer to `question->correct_answer` directly (both already
  canonicalized/validated to the same representation), sums `points` for
  matches, and computes `round(earned / total * 100)` — PHP's default
  round-half-away-from-zero, documented explicitly in
  docs/BUSINESS_RULES.md so the exact behavior at `X.5` is never
  ambiguous.
- **`Application.status` is never written by any of `show()`/`start()`/`submit()`.**
  It was already `in_assessment` from quiz-assessment creation (Phase
  6B-1) and stays there through the entire attempt lifecycle and after
  grading — only `Assessment.status`/`Assessment.result` move, mirroring
  exactly how completing an Interview never touches `Application.status`
  either (see the "Assessment Creation Workflow" section below).
- **Privacy**: `App\Http\Controllers\Student\Concerns\HidesInternalQuestionFields`
  is the Quiz/Question counterpart to `HidesInternalInterviewFields` —
  same per-response `makeHidden()` convention (never a model-level
  `$hidden`, so the Organization contract stays byte-for-byte unchanged),
  reading `Question::ORGANIZATION_ONLY_FIELDS` as its single source of
  truth rather than repeating the field name. Used from three places:
  `Student\QuizController::show()` and both of
  `Student\AssessmentController`'s actions (`index()`/`show()`), now that
  they eager-load `quiz.questions` too — the exact "major regression"
  surface the task called out explicitly.

---

## Assessment Creation Workflow (Phase 4A-2)

Adds `POST /api/organization/applications/{application}/assessments` (the
"organization chooses an assessment type" entry point) alongside the
existing legacy Interview creation route, without duplicating any of the
creation logic between them:

```
InterviewController::store()  \
                                > both call AssessmentService::createInterviewAssessment()
AssessmentController::store() /

AssessmentController::store() -- type=quiz --> AssessmentService::createQuizAssessment()
```

- **`App\Services\AssessmentService`** is the single shared write layer,
  following this codebase's existing controller+service convention (see
  `MatchingService` / `ApplicationAnalysisController`). It owns:
  the allowed-source-status check (`shortlisted`/`interview_scheduled` —
  legacy compatibility only, see below), the duplicate-assessment
  pre-check, the one database transaction, `Assessment` + `Interview`/
  `Quiz` (Phase 6B-1) creation, and (via the private
  `transitionToInAssessment()` helper — see Phase 6B-0 below) the
  `application.status = in_assessment` / `reviewed_at = now()` side
  effect. `createQuizAssessment()` (Phase 6B-1) is `createInterviewAssessment()`'s
  sibling: same precondition checks, same transaction pattern, same status
  transition, reused rather than re-implemented. It does not perform HTTP
  response construction, does not return a `JsonResponse`, and does not
  perform organization-ownership authorization — those stay in each
  controller, exactly like every other controller in this codebase (no
  Policy classes are used here).
- **No duplicated transaction.** Both `InterviewController::store()` and
  `AssessmentController::store()` call the service directly (constructor
  injection) and each wraps the result in its own response shape/message —
  there is no HTTP redirect and no controller calling another controller.
- **Domain exceptions**, not raw `QueryException`, cross the
  service→controller boundary for the two failure cases each controller
  must translate into its own wording:
  `App\Exceptions\InvalidAssessmentSourceStatusException` and
  `App\Exceptions\AssessmentAlreadyExistsException`. A real
  `assessments.application_id` unique-constraint violation (the final
  concurrency authority, for the narrow race the pre-check can't close) is
  translated into the same `AssessmentAlreadyExistsException` — but only
  after confirming the violation is actually that specific constraint, so
  an unrelated integrity failure is never masked as "duplicate".
- **One shared validation-rule source.** Interview field rules
  (`interview_type`, `scheduled_at`, `meeting_link`, `location`, etc.) live
  in exactly one place —
  `App\Http\Requests\Organization\Concerns\InteractsWithInterviewRules`,
  called with an empty prefix by the legacy `StoreInterviewRequest` and
  with an `interview.` prefix by the generic `StoreAssessmentRequest` (per
  its nested request contract, see docs/API.md section 7) — never
  hand-copied between the two request classes.

---

## Generic Assessment Status Migration (Phase 6B-0)

Replaces the type-specific `interview_scheduled` write with a generic
`Application.status` value that any assessment type can share, without
encoding assessment-specific detail into `Application.status`:

- **`in_assessment`** is the one new `Application.status` value. It is
  written from exactly one place — the private
  `AssessmentService::transitionToInAssessment()` helper, called by
  `createInterviewAssessment()` and, as of Phase 6B-1,
  `createQuizAssessment()` too, unchanged — confirming no second
  Application status was ever needed for quiz. `Assessment.type`
  (`interview`/`quiz`) is what distinguishes the assessment kind;
  `Application.status` never does.
- **`interview_scheduled` is now deprecated, legacy-compatibility only.**
  The database enum keeps it (existing rows still load and serialize
  correctly — see docs/BUSINESS_RULES.md section 5), and
  `AssessmentService::ALLOWED_SOURCE_STATUSES` still accepts it as a
  *source* status so a legacy application stuck in that state with no real
  Assessment can still receive one. No code path writes it anymore.
- **`in_assessment` is deliberately excluded from `ALLOWED_SOURCE_STATUSES`.**
  By construction, an application only reaches `in_assessment` once a real
  Assessment already exists, so treating it as a valid source for creating
  *another* assessment would contradict the one-assessment-per-application
  rule. The DB-level `assessments.application_id` unique constraint remains
  the ultimate duplicate-assessment authority either way.
- **The generic status endpoint** (`PUT /api/organization/applications/{application}/status`,
  see docs/API.md section 5) no longer accepts `in_assessment` **or**
  `interview_scheduled` as input — only `reviewed, shortlisted, accepted,
  rejected`. This closes a prior gap where an organization could set
  `interview_scheduled` manually with no real Assessment behind it.
- **Delete/revert** (`InterviewController::destroy()`) now reverts either
  `in_assessment` or `interview_scheduled` back to `shortlisted` when the
  application's only assessment is deleted; every other status
  (`accepted`/`rejected`/`withdrawn`/...) is left untouched, unchanged from
  before this phase.
- **Migration**: `applications.status` gains `in_assessment` in-place (no
  existing row is rewritten); rollback moves any `in_assessment` row back
  to `shortlisted` before shrinking the enum, so it stays reversible without
  data loss. See
  `database/migrations/2026_08_08_161938_add_in_assessment_status_to_applications_table.php`.
- Quiz tables/models/controllers are still not implemented — this phase
  only prepares `Application.status` to be quiz-ready.

---

## Authentication

Authentication will use Laravel Sanctum.

Every protected API requires authentication.

---

## File Uploads

CVs

Profile Images

Organization Logos

will be stored using Laravel Storage.

---

## AI Module

The first version uses rule-based matching.

Future versions may integrate LLM APIs.

---

## Notifications

Notifications are stored in the database.

Flutter will fetch them through REST API.

---

## Development Flow

Database

↓

Model

↓

Relationship

↓

Validation

↓

Controller

↓

API

↓

Flutter

↓

Testing

↓

Git Commit