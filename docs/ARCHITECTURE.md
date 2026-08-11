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
  hasOne Offer            (Phase 6C-1)
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

Offer                      (Phase 6C-1)
  belongsTo Application
```

- `Application` owns recruitment lifecycle only (`status`: pending,
  reviewed, shortlisted, in_assessment, offer_sent, interview_scheduled,
  accepted, rejected, withdrawn). As of Phase 6B-0, `in_assessment` is the
  generic value written whenever a real Assessment exists (interview or
  quiz); as of Phase 6C-1, `offer_sent` is written by
  `OfferService::sendOffer()`, and `accepted`/`rejected` (when reached via
  an Offer response) are written by `OfferService::acceptOffer()`/
  `declineOffer()` — `accepted` means specifically "the student accepted
  the Offer" rather than merely "the organization chose this candidate";
  `interview_scheduled` is deprecated legacy-compatibility only — see
  docs/BUSINESS_RULES.md section 5.
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

## Offer Status Architecture Migration (Phase 6C-0)

Prepares `Application.status` for the future Offer workflow (Phase 6C-1)
the same way Phase 6B-0 prepared it for the generic Assessment workflow:
adds one new generic status value, and narrows what the organization may
still set manually. Does **not** create any part of the Offer feature
itself — no `offers` table, no `Offer` model, no `OfferService`, no Offer
controllers/requests/routes. See docs/BUSINESS_RULES.md section 5 for the
full narrative and docs/API.md section 5 for the endpoint contract.

- **`offer_sent`** is the one new `Application.status` value. It is
  reserved for the future `OfferService::sendOffer()` (Phase 6C-1) to
  write — mirroring exactly how `in_assessment` is written from exactly one
  place (`AssessmentService::transitionToInAssessment()`). Nothing in the
  codebase writes `offer_sent` yet; Phase 6C-0 only makes the value valid
  in the database enum and API response contract.
- **`accepted`'s meaning changes, without changing its storage.** Before
  Phase 6C-0, `accepted` meant "the organization chose this candidate,"
  set directly through the generic status endpoint (and, in practice,
  never actually exercised by the Flutter app — no UI action ever called
  it). As of Phase 6C-0, `accepted` means specifically "the student
  accepted the Offer" — the only future workflow permitted to write it is
  `Student\OfferController::accept()` (Phase 6C-1). The column, its enum
  values, and every pre-existing `accepted` row are completely untouched;
  only `UpdateApplicationStatusRequest`'s allowed input set changed. This
  is a deliberate, temporary gap: **`accepted` cannot be reached through
  any current API action until Phase 6C-1 ships** — no placeholder/interim
  endpoint was added, since one would just have to be removed again once
  the real Offer-accept workflow exists.
- **The generic status endpoint** (`PUT /api/organization/applications/{application}/status`,
  see docs/API.md section 5) now accepts only `reviewed, shortlisted,
  rejected` — `accepted` and `offer_sent` join the pre-existing exclusions
  of `in_assessment` and `interview_scheduled`. `rejected` is deliberately
  left fully manually-settable and unrestricted by assessment state (no
  "assessment must be completed first" precondition was added) — Phase
  6C-0 does not implement an application-status state machine, only tightens
  this one endpoint's allowed input set.
- **Migration**: `applications.status` gains `offer_sent` in-place (no
  existing row is rewritten); rollback moves any `offer_sent` row back to
  `in_assessment` before shrinking the enum, so it stays reversible without
  data loss — the same pattern
  `2026_08_08_161938_add_in_assessment_status_to_applications_table.php`
  already established. See
  `database/migrations/2026_08_10_134012_add_offer_sent_status_to_applications_table.php`.
- **Dashboard counts are unchanged.** `GET /api/organization/dashboard` and
  `GET /api/student/dashboard` still expose `accepted_applications`/
  `rejected_applications` with the same field names and query shape;
  `accepted_applications` simply now reflects the narrower, student-driven
  meaning for any *new* data going forward. No `offer_sent_applications`
  field was added — deferred to Phase 6C-4, once the Offer feature exists
  to make such a count meaningful.
- Offer tables/models/controllers were not implemented in Phase 6C-0 — that
  phase only prepared `Application.status` to be Offer-ready. **Phase 6C-1
  (below) has since implemented the full Offer feature**, so `accepted`/
  `offer_sent` are no longer unreachable.

---

## Offer Foundation Architecture (Phase 6C-1)

The final hiring decision: `App\Services\OfferService` sending an Offer,
and the student accepting or declining it. Owned end-to-end by one service,
the same doctrine `AssessmentService` already established for Assessment
creation — no multi-model transition logic lives in a controller. See
docs/BUSINESS_RULES.md section 7b for the full business-rule narrative and
docs/API.md section 7b for the endpoint contracts.

- **`Offer`** (new model/table) `belongsTo Application`; `Application`
  gains a matching `hasOne Offer` alongside its existing `hasOne
  Assessment` — the same one-per-application shape, enforced the same way
  (`offers.application_id` unique + cascade delete).
- **`$fillable` includes `status`/`sent_at`/`responded_at`**, not just the
  organization-authored terms — matching this codebase's own
  `Assessment`/`QuizAttempt` precedent (both list every column a service
  ever mass-assigns, not just what an HTTP client may directly supply). The
  actual write boundary is enforced one layer up: only `OfferService` ever
  sets those three columns; `SendOfferRequest`'s validated data never
  contains them.
- **`OfferService::sendOffer(Application, array): Offer`** — pre-checks no
  existing Offer (`assertNoExistingOffer()`) and eligibility
  (`assertEligibleForOffer()`: `application.status === 'in_assessment'`
  and its Assessment `status === 'completed'` — never `result`, which is
  deliberately never inspected), then creates the Offer (`status=sent`,
  `sent_at=now()`) and sets `application.status = offer_sent`, in one
  transaction. A concurrent double-send is caught the same way
  `AssessmentService::createInterviewAssessment()` already catches a
  concurrent double-assessment: a `QueryException` on the
  `offers_application_id_unique` constraint is translated to
  `OfferAlreadyExistsException` by `isDuplicateOfferViolation()`, the exact
  same pattern as `isDuplicateAssessmentViolation()`.
- **`OfferService::acceptOffer(Offer)` / `declineOffer(Offer)`** share a
  private `respondToOffer()` that locks the Offer row (`lockForUpdate()`,
  the same discipline `Student\QuizController::submit()` already uses for
  its own once-only transition) before checking `status === 'sent'`, then
  updates both `Offer.status`/`responded_at` and `Application.status`
  (`accepted` or `rejected`) inside that same locked transaction. This is
  what guarantees the two can never diverge into an impossible combination
  (e.g. Offer `accepted` alongside Application `rejected`) even under a
  genuine concurrent accept-vs-decline race — whichever transaction commits
  first wins; the second re-reads `status` as no longer `sent` and throws
  `OfferAlreadyRespondedException` instead of overwriting the first
  response.
- **Three new domain exceptions**, mirroring the Assessment exceptions'
  shape exactly (plain `Exception` subclasses, a default message,
  constructor-overridable): `OfferAlreadyExistsException` (409),
  `InvalidOfferSourceStatusException` (422 — reused for all three distinct
  "not eligible yet" cases: wrong application status, no Assessment, or an
  incomplete Assessment, each with its own message at the throw site,
  rather than three separate exception classes), and
  `OfferAlreadyRespondedException` (409).
- **`Organization\OfferController`** (`store`/`show`) and
  **`Student\OfferController`** (`show`/`accept`/`decline`) both stay thin
  — ownership check, delegate to `OfferService`, translate the outcome into
  the shared response envelope — exactly like every other controller in
  this codebase. Neither exposes update/delete/cancel/resend; v1's Offer is
  immutable once sent.
- **Response shape never nests `application`.** Every Offer-returning
  response is the Offer's own fields only (see docs/API.md section 7b) —
  deliberately never eager-loading/serializing the parent `Application`
  alongside it, even though `Student\OfferController::accept()`/
  `decline()` need to read `offer.application.student_id` for the
  ownership check. That lazy read never touches the *response* object:
  `OfferService::respondToOffer()` re-fetches a clean `Offer` instance
  (via the row lock) that never had `application` loaded in the first
  place, so there is nothing to accidentally leak.
- **Pre-existing, unrelated bug discovered while testing this phase, since
  fixed** (see "Dashboard Interview Query Fix" below): `GET
  /api/organization/dashboard` and `GET /api/student/dashboard` both used
  to 500 on every call — `Organization\DashboardController`/
  `Student\DashboardController` called `Interview::whereHas('application...',
  ...)`, but `Interview` has had no real `application()` *relation* since
  the Phase 4A-1 retarget migration (only a read-only `application`
  *Attribute accessor*, which `whereHas()` cannot use). Neither dashboard
  endpoint had any test coverage before Phase 6C-1, so this was never
  caught before then. It was out of scope for Offer Foundation itself, so
  it was flagged rather than fixed in this phase — the fix landed as its
  own follow-up.

---

## Dashboard Interview Query Fix (post-Phase 6C-1)

Fixes the pre-existing bug flagged in "Offer Foundation Architecture (Phase
6C-1)" above — `GET /api/organization/dashboard` and `GET
/api/student/dashboard` both 500ing on every call, entirely unrelated to
Offers.

- **Root cause**: both `Organization\DashboardController`/
  `Student\DashboardController` queried `Interview::whereHas('application',
  ...)` (organization: `'application.opportunity'`). `Interview` has had no
  real `application()` Eloquent *relation* since the Phase 4A-1 Assessment
  retarget — only a read-only `application` *Attribute accessor*
  (`protected function application(): Attribute`, for backward-compatible
  JSON serialization only — see `Interview.php`), which `whereHas()` cannot
  resolve.
- **Fix**: both controllers now query the real, current relationship chain
  — `interview -> assessment -> application` (`Assessment belongsTo
  Application`, `Interview belongsTo Assessment`, both real relations).
  Organization: `Interview::whereHas('assessment.application.opportunity',
  ...)`. Student: `Interview::whereHas('assessment.application', ...)`.
  Laravel's dot-notation `whereHas()` nests through any number of real
  relations, so no intermediate model needed a new method.
- **No fake/duplicate `application()` relationship was added back onto
  `Interview`** — the existing Attribute accessor stays exactly as-is (it's
  still needed for the legacy top-level `data.application` JSON shape on
  Interview responses); only the dashboard queries changed, to use the
  relation chain that has been canonical since Phase 4A-1.
- **Response contract unchanged.** Every dashboard field name, response
  envelope, and value semantics are identical to before the fix — this was
  purely a query-path correction, not a feature change. No
  `offer_sent_applications` field was added.
- **Real end-to-end test coverage added**: `tests/Feature/Organization/OrganizationDashboardTest.php`
  and `tests/Feature/Student/StudentDashboardTest.php` both call the actual
  `GET` endpoints (not the query in isolation), covering the 200/envelope
  case, exact interview counts, cross-organization/cross-student isolation,
  a zero baseline, the other existing counts, and the pre-existing
  auth/role/active/profile-exists middleware behavior. Neither dashboard
  endpoint had any test coverage before this fix.

---

## Final Decision Cleanup & Regression Pass (Phase 6C-4)

Closes out the recruitment-flow work started at Phase 6B-0: adds the
final-funnel dashboard count deferred since then, and closes the one real
integrity gap the Offer feature (Phase 6C-1) left open — a generic
Application-status write that could still contradict an existing Offer.
No new recruitment states, no Offer expiry/cancellation/resend, no
Notifications/SMTP/AI ranking — all deliberately out of scope (see
docs/BUSINESS_RULES.md).

- **`offer_sent_applications` added to both dashboards** (the field
  deferred since the post-6C-1 Dashboard Interview Query Fix above) —
  `Organization\DashboardController`/`Student\DashboardController` each
  gained one additional `(clone $applications)->where('status',
  'offer_sent')->count()`, ownership-scoped exactly like every other count
  already there. Purely additive: `accepted_applications`/
  `rejected_applications` and every other existing field are unchanged,
  proven by dedicated test coverage in `OrganizationDashboardTest`/
  `StudentDashboardTest`.
- **Offer/Application integrity gap closed.**
  `Organization\ApplicationController::updateStatus()` now checks
  `$application->offer()->exists()` before applying any generic status
  change and returns `409` if one is found — regardless of the Offer's own
  `status` (`sent`/`accepted`/`declined`) and regardless of which status
  value was requested (including `rejected`, otherwise still valid input).
  Before this fix, an organization could call the generic endpoint with
  `status=rejected` on an application whose Offer was still `sent`,
  producing `Offer.status=sent` + `Application.status=rejected` — a
  combination the row-locked `OfferService::respondToOffer()` transaction
  already prevents from the student side, but which this endpoint had no
  equivalent guard against. See docs/BUSINESS_RULES.md section 5/7b and
  `tests/Feature/Offers/OfferApplicationIntegrityTest.php`.
- **No Offer cancel/rescind workflow was added to work around this** — the
  fix is a hard block, matching v1's existing "no rescind" design (section
  7b); an organization's only lever once an Offer exists is to wait for
  the student's response.
- **Flutter**: `OrganizationApplicationDetailsScreen`'s Reject button had
  the same latent gap on the client side — it was shown for any
  `in_assessment` application regardless of whether an Offer already
  existed for it (only `Send Offer` had the `!hasOffer` guard). Fixed by
  applying the same `!hasOffer` condition to `Reject`, so the button
  disappears the moment the real Offer data says one exists, even before
  `application.status` itself catches up to `offer_sent` — consistent
  with this screen's existing "actual Offer data wins over stale
  Application status" rule (see `_OfferSection`'s own doc comment).
- **No Student/Organization dashboard screen exists in Flutter yet** — only
  Admin's dashboard is wired up (`lib/providers/admin_dashboard_provider.dart`
  et al.). `offer_sent_applications` is therefore a backend-only addition
  this phase; there is no dashboard model/screen to extend on the Flutter
  side, and building one from scratch was out of this phase's cleanup-only
  scope.

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

**Future attachment points (Phase 6C-1):** `OfferService::sendOffer()` /
`acceptOffer()` / `declineOffer()` are each a single, already-transactional
call site — the natural place a future notification dispatch (Offer sent →
student; Offer accepted/declined → organization) would attach. No
event/listener infrastructure or notification-creation code exists yet;
this is a pointer for a later phase, not something Phase 6C-1 implements.

---

## Notification Service Foundation (Phase 7A-1)

Builds the one backend API for creating in-app Notification rows, and
widens `notifications.type` to precisely represent Quiz and Offer events.
**Does not wire it into any workflow** — that is Phase 7A-2. This phase is
independently complete and independently tested: `NotificationService` has
no callers yet.

- **`notifications.type` widened from 5 to 7 values**
  (`2026_08_11_090000_add_assessment_and_offer_types_to_notifications_table`):
  adds `assessment` and `offer` alongside the existing `system`,
  `application`, `interview`, `organization`, `opportunity`. Same
  doctrine/dbal-free driver split every prior enum-widening migration in
  this project uses (`add_offer_sent_status_to_applications_table`,
  `add_in_assessment_status_to_applications_table`): a raw `MODIFY` on
  MySQL/MariaDB, `Blueprint::change()` on SQLite. No row is rewritten going
  forward (nothing creates one with either new value yet); `down()`
  defensively falls any row already holding one back to `system` before
  shrinking the enum, matching the same non-destructive-rollback
  convention those migrations established, even though this case isn't
  currently reachable.
- **`App\Services\NotificationService`** — one generic `create(User $user,
  string $title, string $message, string $type = 'system', string
  $priority = 'normal', ?string $actionUrl = null): Notification`, which
  validates `$type`/`$priority` against the real enum values
  (`InvalidArgumentException` on a bad one, before ever reaching the
  database) and otherwise just calls `Notification::create()`. Ten
  convenience methods sit on top of it — one per recommended v1 event
  (`notifyApplicationSubmitted`, `notifyApplicationShortlisted`,
  `notifyApplicationRejected`, `notifyInterviewScheduled`,
  `notifyInterviewRescheduled`, `notifyQuizPublished`,
  `notifyQuizCompleted`, `notifyOfferSent`, `notifyOfferAccepted`,
  `notifyOfferDeclined`) — each resolving fixed copy, the correct
  `type`/`priority` (see docs/BUSINESS_RULES.md section 8), and an
  app-relative `action_url` matching the Flutter app's own `AppRoutes`
  path constants exactly (e.g. `/student/applications/{id}`,
  `/organization/applications/{id}`, `/student/assessments/{id}/quiz`) —
  never a full domain URL, never a `{type, id}` pair.
- **No Events/Listeners/Observers/Jobs/Mailables** — every method is a
  plain synchronous call that inserts one row and returns it. Matches this
  project's "one service owns this concern" doctrine (`AssessmentService`,
  `OfferService`) at its current scale; see the Phase 7A architecture
  inspection this phase followed for the fuller reasoning on why Events
  weren't adopted here.
- **`Notification` model/migration/`NotificationController` are otherwise
  untouched.** `fillable`/`casts` were already exactly what this phase
  needed; no model change was required.
- **No workflow wiring.** `Organization\ApplicationController`,
  `Student\ApplicationController`, `AssessmentService`,
  `Organization\InterviewController`, `Organization\QuizController`,
  `Student\QuizController`, `OfferService` are all untouched — none of them
  call `NotificationService`. See docs/BUSINESS_RULES.md section 8 for the
  exact recommended attachment points Phase 7A-2 will use.
- **Tested via `tests/Unit/Services/NotificationServiceTest.php`** (direct
  unit coverage — there is no HTTP endpoint to test through yet, since
  nothing calls the service) and
  `tests/Feature/Notifications/NotificationTypeMigrationTest.php` (the
  migration's up/rollback/re-run path, mirroring
  `OfferSentStatusMigrationTest`'s own pattern). The pre-existing
  `NotificationTest.php` (list/mark-read/mark-all/ownership) needed no
  changes at all.

---

## Backend Workflow Notification Integration (Phase 7A-2)

Wires `NotificationService` (Phase 7A-1) into the real recruitment
workflow. No Events/Listeners/Observers/Jobs, no SMTP/email, no push, no
Admin-facing events, and no change to the existing
`GET/PUT /api/notifications*` read/mark-read contract — all deliberately
out of scope for this phase (see docs/BUSINESS_RULES.md section 8).

- **Recipient resolution** uses the same canonical relation chains
  everywhere in this codebase already does:
  `Application::studentProfile->user` for the student, and
  `Application::opportunity->organizationProfile->user` for the
  organization. No new relation was added — both chains already existed.
- **Seven integration points, one call each**, every one placed inside the
  existing (or, for two controller methods that had none, newly added)
  `DB::transaction()` around the business mutation it accompanies:
  - `Student\ApplicationController::store()` — wrapped the previously
    untransacted `Application::create()` in `DB::transaction()` so the
    Application insert and the organization's "New Application"
    notification commit or roll back together; the pre-existing duplicate-
    apply `QueryException` catch still wraps the whole thing unchanged.
  - `Organization\ApplicationController::updateStatus()` — likewise newly
    wrapped in `DB::transaction()`. Captures `$previousStatus` before the
    mutation and only calls `notifyApplicationShortlisted()`/
    `notifyApplicationRejected()` when the *new* status differs from the
    *previous* one and is exactly `shortlisted`/`rejected` — transition-
    based, not request-based, so a request that re-asserts the current
    status (or asks for `reviewed`, which has no notification) creates
    nothing.
  - `AssessmentService::createInterviewAssessment()` — the notification is
    emitted from inside this one shared service method's existing
    transaction, not from either of its two callers
    (`Organization\InterviewController::store()`, the legacy dedicated
    route, and `Organization\AssessmentController::store()`, the generic
    `type=interview` route) — both delegate here, so emitting from a
    controller instead would risk a double notification if a future change
    ever called both paths for the same event.
  - `Organization\InterviewController::update()` — newly wrapped in
    `DB::transaction()`. Captures the interview's `scheduled_at`/
    `interview_type` before the mutation (Carbon's `equalTo()`, never
    `!==`/`===`, since two distinct Carbon instances for the same instant
    are never identity-equal) and only notifies when either actually
    changed — a logistics-only edit (`meeting_link`/`location`/
    `interviewer_name`/`notes`) is not treated as a reschedule.
  - `Organization\QuizController::publish()` — inside the method's existing
    transaction; only reachable once per quiz, since the pre-existing
    `status !== 'draft'` guard already 422s a second publish attempt before
    the transaction (and therefore the notification) is ever reached.
  - `Student\QuizController::submit()` — inside the method's existing
    row-locked grading transaction; only reached after every early-exit
    (not started, already submitted, time limit expired) has already
    thrown, so exactly one pair of notifications
    (`notifyQuizResultAvailable()` to the student,
    `notifyQuizCompleted()` to the organization) is created per genuine
    first submission.
  - `OfferService::sendOffer()`/`acceptOffer()`/`declineOffer()` (the
    latter two sharing `respondToOffer()`) — inside each method's existing
    transaction. `respondToOffer()`'s pre-existing row lock
    (`lockForUpdate()`) already guarantees a second response always finds
    `status` no longer `sent` and throws before the notification call is
    ever reached, so accept/decline notifications are structurally
    exactly-once per Offer, the same guarantee that already prevented
    `Offer.status`/`Application.status` from ever diverging.
- **`NotificationService` itself gained one new convenience method**,
  `notifyQuizResultAvailable()` (student-facing "your quiz result is
  ready") — distinct from the pre-existing `notifyQuizCompleted()`
  (organization-facing "a student finished a quiz"), since the two sides
  of the same underlying event genuinely need different copy and
  recipients, the same reasoning `notifyOfferAccepted()`/
  `notifyOfferDeclined()` already established.
- **The Offer-decline / generic-reject overlap is structurally impossible,
  not specially guarded.** An Offer decline
  (`OfferService::declineOffer()`) and a direct organization rejection
  (`Organization\ApplicationController::updateStatus()`) both land
  `Application.status` on `rejected`, but only the latter ever creates the
  generic "Application Update" notification — because the Phase 6C-4 Offer
  integrity rule already blocks `updateStatus()` entirely (409) the moment
  an Offer exists, before this phase's transition check is ever reached.
  No new code was needed to keep the two notifications from double-firing
  for the same application; it falls out of a guarantee this project
  already had.
- **Two Feature-test files needed a one-line constructor fix**
  (`tests/Feature/Assessments/AssessmentCreationParityTest.php`,
  `tests/Feature/Offers/SendOfferTest.php`): both directly reflected into a
  private method via `new AssessmentService()`/`new OfferService()`, which
  broke once each service gained a constructor dependency. Fixed by
  resolving through the container (`app(AssessmentService::class)`) instead
  — no assertion or test intent changed.
- **Tested via `tests/Feature/Notifications/WorkflowNotificationTest.php`**
  (33 tests) — one real HTTP call per event, asserting exact recipient,
  copy, `type`/`priority`/`action_url`, and (critically) that a failed,
  blocked, wrong-ownership, idempotent, or duplicate request creates zero
  notifications. The pre-existing `NotificationTest.php` needed no changes
  at all — confirmed via `git diff` showing zero lines touched.

---

## Queued Email Foundation (Phase 7A-4.1)

Adds the queued-transactional-email architecture and wires it into exactly
one event — Offer Received — as a pilot. Proves the mechanism end-to-end
before Phase 7A-4.2 extends the same pattern to the remaining six workflow
events. **Architecture only**: `MAIL_MAILER` stays `log`, no external SMTP
provider is configured, no live email is ever sent.

- **The hard requirement this phase exists to satisfy**: SMTP/email failure
  must never roll back or block a real business action (Offer creation,
  Application status transitions, Notification creation) — flagged as
  future work by `NotificationService`'s own doc comment and
  docs/BUSINESS_RULES.md section 8 since Phase 7A-2, now addressed.
- **`App\Mail\QueuedTransactionalMail`** — the shared abstract base class
  every workflow Mailable extends (`App\Mail\OfferReceivedMail` is the
  first). Implements `Illuminate\Contracts\Queue\ShouldQueueAfterCommit`
  (which itself extends `ShouldQueue`, so no separate declaration is
  needed) rather than setting the `Queueable` trait's public `$afterCommit`
  property directly — confirmed against the installed framework source
  (`vendor/laravel/framework/.../Mail/SendQueuedMailable.php`'s
  constructor, and `.../Queue/Queue.php::shouldDispatchAfterCommit()`) that
  this is the actual mechanism Laravel's queue layer checks, and that it is
  honored identically regardless of `config('queue.connections.database.after_commit')`
  (left `false`, the project-wide default — this is a **per-Mailable**
  opt-in, never a global change). Also sets `queue = 'emails'`,
  `tries = 3`, `timeout = 60`, `backoff = [30, 300, 1800]` (30s/5min/30min).
  Every subclass must call `parent::__construct()`.
- **`App\Services\EmailService`** — owns queued-email transport and
  frontend-link composition. Exposes one method so far,
  `sendOfferReceivedEmail(User $recipient, string $opportunityTitle, int $applicationId, ...)`,
  which builds the CTA URL from `config('app.frontend_url')` +
  `/student/applications/{applicationId}` (the same app-relative path
  `NotificationService` already uses for this event's in-app `action_url`)
  and queues `App\Mail\OfferReceivedMail` via `Mail::to(...)->queue(...)`
  — never `Mail::send()`. Deliberately takes primitives (recipient, title,
  ID, optional Offer display fields), not the `Offer`/`Application`/
  `Opportunity` models, keeping the queued job payload small.
- **`App\Mail\OfferReceivedMail`** — student-facing. Subject
  `"Offer Received — {opportunity title}"`. Content: greeting, opportunity
  title, start date and compensation (only rendered when
  `salary_amount`/`salary_currency`/`salary_period` are **all** present —
  a partial combination is a real, reachable state since every Offer term
  is optional at creation, and is never rendered as malformed/half-blank
  text), the Offer's own `message` field (already part of the student
  Offer contract — see the Offer Foundation Architecture section above),
  and a "View Offer" CTA. Uses the framework's default Markdown mail
  components (`x-mail::message`/`x-mail::button`) — no custom template/
  branding built. Never receives (and therefore can never render)
  interview feedback/rating, quiz correct answers/score, AI match score,
  or any other internal-only field — proven by rendering the actual
  Mailable in tests, not merely by reasoning about what the constructor
  accepts.
- **`config('app.frontend_url')` / `FRONTEND_URL`** — the deployed Flutter
  Web build's base URL (not this API's own `APP_URL`), added to
  `config/app.php` and `.env.example`. `EmailService` never calls `env()`
  directly.
- **`NotificationService` → `EmailService` boundary.** `NotificationService`
  remains the single conceptual "which workflow event happened, who does
  it concern" boundary — it still owns the in-app Notification's
  copy/type/priority/action_url exactly as before. `notifyOfferSent()` is
  the only convenience method changed: it now also calls
  `EmailService::sendOfferReceivedEmail()` after creating the Notification
  row, still inside `OfferService::sendOffer()`'s existing transaction — no
  second call site was added to `OfferService` itself (still exactly one
  call to `notifyOfferSent()`, now carrying one additional argument, the
  freshly-created `Offer`, so its display fields can be forwarded). Mixing
  SMTP/Mailable mechanics into `NotificationService` itself was
  deliberately avoided — that stays `EmailService`'s job.
  `notifyApplicationSubmitted`/`notifyApplicationShortlisted`/
  `notifyApplicationRejected`/`notifyInterviewScheduled`/
  `notifyInterviewRescheduled`/`notifyQuizPublished`/
  `notifyQuizResultAvailable`/`notifyQuizCompleted`/`notifyOfferAccepted`/
  `notifyOfferDeclined` are all untouched and queue no email (Phase
  7A-4.2).
- **Constructor-injection regression.** `NotificationService` gained a
  required `EmailService` dependency, which broke
  `tests/Unit/Services/NotificationServiceTest.php`'s
  `new NotificationService()` — the only direct-construction call site
  found repo-wide. Fixed by resolving through the container
  (`app(NotificationService::class)`), the same fix pattern the Phase
  7A-2 entry above already used for `AssessmentService`/`OfferService`.
- **Tested via four new files** —
  `tests/Unit/Mail/QueuedTransactionalMailTest.php` (the base class's
  public contract: `ShouldQueueAfterCommit`, queue/tries/timeout/backoff),
  `tests/Unit/Services/EmailServiceTest.php` (`Mail::fake()` — correct
  Mailable, recipient, CTA URL, forwarded Offer fields),
  `tests/Unit/Mail/OfferReceivedMailTest.php` (renders the real Mailable —
  subject, content, compensation formatting, and — critically — that
  interview/quiz/AI-internal fields are absent from the actual rendered
  output, not merely absent because nothing passed them in), and
  `tests/Feature/Notifications/WorkflowEmailTest.php` (one real HTTP call
  per scenario: success queues exactly one email; wrong-organization,
  incomplete-assessment, and duplicate-offer requests queue none/no
  additional email; a forced failure immediately after `notifyOfferSent()`
  but before commit rolls back both the Offer and the Notification). The
  rollback test deliberately does **not** use `Mail::fake()` — `MailFake`
  records a queued mailable the instant `->queue()` is called, bypassing
  the real `afterCommit`/`DatabaseTransactionsManager` deferral entirely,
  so it cannot prove anything about that specific mechanism; that the
  Mailable is never dispatched at all in the first place is proven
  structurally by `QueuedTransactionalMailTest` instead (observing a
  genuine top-level transaction *commit* from inside a
  `RefreshDatabase`-wrapped test is not possible, since `RefreshDatabase`
  itself keeps an outer transaction open for the whole test and rolls it
  back at teardown).

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