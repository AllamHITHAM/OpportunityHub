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
  hasOne Assessment       (the current/latest one — latestOfMany; Phase 10A.3)
  hasMany Assessment      (full history, oldest first; Phase 10A.3)
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
- **As of Phase 10A.3, an application can accumulate Assessment *history*** —
  a completed Quiz followed by a real "Advance to Interview" Assessment,
  both preserved permanently — so `assessments.application_id` is
  deliberately no longer unique. What remains invariant is that an
  application has **at most one *active* (non-final) Assessment at a
  time**, enforced application-side by `AssessmentService` (row-locking the
  parent `Application`, then checking history for an active row) rather
  than a database constraint, since a per-application unique constraint and
  real history are mutually exclusive. `Application::assessment()` (the
  `hasOne`) resolves to the *current/latest* one via `latestOfMany()`;
  `Application::assessments()` (the `hasMany`) is the full ordered history
  — see "Assessment History Architecture (Phase 10A.3)" below for the full
  design and the alternatives considered. Symmetrically, an assessment
  still has at most one quiz (enforced by `quizzes.assessment_id` being
  unique) — that constraint is untouched; each individual `Assessment` row
  is still exactly one `type`, with exactly one matching detail record. As
  of Phase 6B-1, `quiz` is no longer merely a
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
  the allowed-source-status check (`shortlisted`/`interview_scheduled`/
  `in_assessment` — see below for why `in_assessment` was added in Phase
  10A.3), the active-Assessment check (Phase 10A.3 — previously a
  duplicate-*any*-assessment check), the one database transaction,
  `Assessment` + `Interview`/`Quiz` (Phase 6B-1) creation, and (via the
  private `transitionToInAssessment()` helper — see Phase 6B-0 below) the
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
  `App\Exceptions\AssessmentAlreadyExistsException`.
  **Phase 10A.3 revision**: before this phase, the final concurrency
  authority for a duplicate-assessment race was a real
  `assessments.application_id` unique-constraint violation, translated into
  `AssessmentAlreadyExistsException` after confirming the violation was
  actually that specific constraint. That constraint no longer exists (it
  can't, once Assessment history is allowed — see "Assessment History
  Architecture (Phase 10A.3)" below), so the concurrency authority is now
  row-locking the parent `Application` (`lockForUpdate()`) inside the
  creation transaction before checking for an active Assessment — no
  `QueryException` translation involved at all any more. Two concurrent
  creation requests for the same application now serialize against that
  lock rather than racing to insert and having one lose to a constraint.
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
- **`in_assessment` was excluded from `ALLOWED_SOURCE_STATUSES` before Phase 10A.3, and is included as of it.**
  Before Phase 10A.3: by construction, an application only reached
  `in_assessment` once a real (and, at the time, *only ever one possible*)
  Assessment already existed, so treating it as a valid source for creating
  *another* assessment would have contradicted the one-assessment-per-
  application rule; the DB-level `assessments.application_id` unique
  constraint was the ultimate duplicate-assessment authority regardless.
  **As of Phase 10A.3**, `in_assessment` can also legitimately mean "the
  evaluation chain is ongoing, but the most recent Assessment has already
  been finalized" — exactly the state a completed Quiz leaves behind before
  "Advance to Interview" — so excluding it would have blocked that entirely
  new capability. Allowing it here is safe specifically because the
  active-Assessment check (see above) now runs first and independently
  blocks the one case that would actually be wrong (an `in_assessment`
  application whose most recent Assessment is still active) with its own
  409 — this list no longer needs to carry that responsibility alone. See
  "Assessment History Architecture (Phase 10A.3)" below for the full
  design.
- **The generic status endpoint** (`PUT /api/organization/applications/{application}/status`,
  see docs/API.md section 5) no longer accepts `in_assessment` **or**
  `interview_scheduled` as input — only `reviewed, shortlisted, accepted,
  rejected`. This closes a prior gap where an organization could set
  `interview_scheduled` manually with no real Assessment behind it.
- **Delete/revert** (`InterviewController::destroy()`) reverts either
  `in_assessment` or `interview_scheduled` back to `shortlisted` when the
  application has **no Assessment left at all** after the deletion; every
  other status (`accepted`/`rejected`/`withdrawn`/...) is left untouched,
  unchanged from before this phase. **Phase 10A.3 revision**: before this
  phase, "the application's only assessment is deleted" and "the
  application has no Assessment left" were the same condition by
  construction (at most one Assessment could ever exist). Now that Assessment
  history is possible, they're not the same condition any more — deleting a
  still-`scheduled` Interview that was itself an "Advance to Interview"
  follow-up to an earlier *completed* Quiz must leave that completed Quiz's
  history intact and the application still meaningfully `in_assessment`,
  not incorrectly revert all the way back to `shortlisted`. The check was
  updated from "was this the application's only assessment" (implicit,
  always true) to an explicit re-query — `assessments()->exists()` — after
  the delete.
- **Migration**: `applications.status` gains `in_assessment` in-place (no
  existing row is rewritten); rollback moves any `in_assessment` row back
  to `shortlisted` before shrinking the enum, so it stays reversible without
  data loss. See
  `database/migrations/2026_08_08_161938_add_in_assessment_status_to_applications_table.php`.
- Quiz tables/models/controllers are still not implemented — this phase
  only prepares `Application.status` to be quiz-ready.

---

## Assessment History Architecture (Phase 10A.3)

Enables the recruitment workflow this whole phase exists for: **Shortlisted
→ Quiz → completed → Organization chooses Advance to Interview / Direct
Offer / Reject.** "Advance to Interview" was architecturally impossible
before this phase — `assessments.application_id` was unique, so an
application could have at most one Assessment, ever, and turning a
completed Quiz into a follow-up Interview would have meant either
destructively deleting the completed Quiz/QuizAttempt or a genuine schema
change. This phase is that schema change.

**Audit finding — every place that assumed `Application hasOne Assessment`
(done before writing any code, per this phase's own process):**

| Assumption site | Nature of the assumption | Resolution |
|---|---|---|
| `assessments.application_id` unique constraint | The schema itself | New forward migration drops it, replaces it with a plain index |
| `Application::assessment(): HasOne` | Model relationship | Redefined as `hasOne(...)->latestOfMany(['created_at', 'id'])` — same method name, same return type, now explicitly "the current/latest one" rather than "the only one" |
| `AssessmentService::assertNoExistingAssessment()` | Checked *any* assessment existed | Replaced with `assertNoActiveAssessment()` — checks only non-final `status` |
| `AssessmentService::ALLOWED_SOURCE_STATUSES` | Excluded `in_assessment` | `in_assessment` added — see the Phase 6B-0 section above for the full reasoning |
| `AssessmentService`'s duplicate-conflict detection | A `QueryException` translation keyed on the unique constraint | Replaced with row-locking the `Application` (`lockForUpdate()`) inside the creation transaction |
| `Organization\AssessmentController::showForApplication()` | `$application->assessment()->first()` — implicitly "the one" | Now `$application->assessments()->get()` — the full history, returned as an array |
| `OfferService::assertEligibleForOffer()` | `$application->assessment()->first()` | **No code change needed** — since `assessment()` now means "latest" via `latestOfMany()`, this already-correct-looking line started doing the right thing (latest-Assessment eligibility) for free |
| `Organization\InterviewController::destroy()` | Reverting to `shortlisted` whenever the deleted Assessment's application was `in_assessment` | Now re-checks `assessments()->exists()` after the delete, so a still-intact completed Quiz keeps the application at `in_assessment` |
| `Application::interview(): HasOneThrough` | An unordered join would silently pick an arbitrary row if more than one Interview-type Assessment ever existed | Made explicitly deterministic (`orderByDesc('assessments.id')`) — was already correct in every real single-Assessment scenario, but relied on an invariant that no longer holds |
| `Student\AssessmentController::index()` | No explicit ordering (didn't matter with one row) | `orderBy('created_at')->orderBy('id')` added for determinism |
| Various tests (`AssessmentMigrationTest`, `AssessmentRelationshipTest`, `AssessmentCreationParityTest`, `OrganizationAssessmentTest`, `StoreAssessmentTest`, `StoreQuizAssessmentTest`) | Asserted the *opposite* of the new behavior, or used the old array/singular shape | Rewritten to assert the new contract; see each test file's own updated doc comments |

**The two-relation model, not a rename:**

```
Application
  assessment(): HasOne   -- hasOne(Assessment::class)->latestOfMany(['created_at', 'id'])
                             the current/latest Assessment. Safe to .create()/.exists()
                             exactly as before -- ofMany scoping only affects SELECTs.
  assessments(): HasMany -- hasMany(Assessment::class)->orderBy('created_at')->orderBy('id')
                             the full ordered history, oldest first.
```

This was chosen over renaming `assessment()` everywhere (which would have
touched ~30 call sites across app code and tests) because `HasOne::create()`
and `HasOne::exists()` don't consult the `ofMany` ordering scope at all —
only `SELECT`s (`first()`, eager-loading) do. That means every existing
`$application->assessment()->create([...])` and
`$application->assessment()->exists()` call site kept working, unchanged,
the instant `assessment()`'s definition changed — and for every application
that still has exactly one Assessment (the overwhelming majority, always,
for any application that hasn't been through "Advance to Interview"),
`assessment()`'s behavior is bit-for-bit identical to before. Only the one
call site that genuinely needed the *history*, not just the *latest* one
(`Organization\AssessmentController::showForApplication()`), was changed to
use the new `assessments()` relation instead.

**The active-Assessment invariant, and why it isn't a database constraint:**
"An application may have multiple historical Assessments, but at most one
*active* (non-final) Assessment at a time" is enforced entirely in
`AssessmentService`, not the schema. A conditional/partial unique index
(unique only among `pending`/`scheduled`/`in_progress` rows) was considered
and rejected — it isn't portably expressible across this project's two real
connections (MySQL in dev, SQLite in tests) without a generated-column
workaround, and this codebase already has a precedent for enforcing a
similar "at most one active X" invariant via row-locking rather than a
constraint (`OfferService::respondToOffer()`, which locks the `Offer` row
before its own once-only transition). `AssessmentService::lockApplication()`
locks the parent `Application` row for the duration of the creation
transaction; two concurrent creation requests for the same application
serialize against that lock, so the active-Assessment check and the
subsequent insert can never race.

**Quiz result release (Phase 10A.2) was substantially redesigned one phase later** — see "Decision-Aware Quiz Result Release Architecture (Phase 10A.4A)" below. At the time this phase shipped, the statement "nothing about Quiz result release changed" was accurate (an organization could advance to Interview before or after releasing the Quiz result and neither triggered the other); Phase 10A.4A is what first coupled the two.

**Deliberately out of scope, this phase:** the full Interview UI redesign
(Flutter reuses the existing Schedule Interview screen as-is for "Advance to
Interview" — see docs/BUSINESS_RULES.md section 7a), and any change to
Offer UI. Both remain exactly as Phase 6C-1/6C-4 left them.

---

## Decision-Aware Quiz Result Release Architecture (Phase 10A.4A)

**The problem this phase fixes:** a manual E2E pass after Phase 10A.3 found that a Quiz result could release at its scheduled/immediate time even though the Organization hadn't yet decided what happens next — technically correct per Phase 10A.2's own rule, but an incomplete recruitment update for the Student (a bare pass/fail with no next step). The fix is architectural, not cosmetic: a Student-facing release now requires *both* the technical result *and* a ready Organization decision, computed by one shared readiness check every release path uses.

**Reusing the Phase 10A.3 self-referencing schema, in the other direction.** Phase 10A.3 added Assessment history by making `assessments.application_id` non-unique. Phase 10A.4A adds two nullable self-referencing foreign keys on the same table, both `nullOnDelete()`, in one new forward migration:

```
Assessment
  next_action_assessment_id -> assessments.id   (child: this Quiz's staged Interview, if any)
  origin_assessment_id      -> assessments.id   (parent: set on that staged Interview itself)
```

The two columns are inverses of the same relationship, stored on both ends — `next_action_assessment_id` lets the Quiz side answer "what did I stage?" in one column read (no join needed to render the Organization's Next Step panel), while `origin_assessment_id` lets the *child* Interview answer "am I still waiting on my origin Quiz's release?" without knowing which Quiz staged it. Both are needed because the Student-visibility gate (below) is evaluated from the child's own row.

**The Student-visibility gate is derived, never stored.** `Assessment::isPendingDecisionRelease(): bool` is computed as `origin_assessment_id !== null && ! originAssessment->isResultReleased()` — not a separate boolean column kept in sync by hand. This means a staged Interview Assessment automatically becomes Student-visible the instant its origin Quiz's `result_released_at` is set, with zero additional writes to the child record and zero risk of the two ever disagreeing. The same predicate (inverted, via a `whereHas`/`whereNull` pair) filters `Student\AssessmentController::index()`, `Student\InterviewController::index()`, and the Student dashboard's `total_interviews` count identically — one pattern, three call sites, not three separate hand-written gates that could drift.

**Staging asymmetry between Interview and Offer is deliberate, not an inconsistency.** Interview: the real follow-up `Assessment` + `Interview` rows are created immediately at staging time (reusing `AssessmentService::createInterviewAssessment()` verbatim, with an added optional `$originAssessmentId` param that both stamps `origin_assessment_id` and suppresses the normal "interview scheduled" notification) — because the Interview flow already has real validation, real scheduling-conflict/active-assessment checks, and a real row is exactly what "Organization can see it internally" (an explicit requirement) needs. Offer: nothing real is created at staging time at all — the validated terms are stored as JSON on `next_action_data`, and the real `Offer` row is created for the first time at release by calling `OfferService::sendOffer()` completely unchanged. This is strictly safer than any half-created/flagged-hidden Offer row: there is nothing to leak early, by construction, and zero risk of the staged and released Offer logic ever diverging, since they're the same code path.

**One consequence of the Interview asymmetry: a staged Interview counts as "active."** Because it's a real `status=scheduled` Assessment, the existing active-assessment invariant (Phase 10A.3) sees it exactly like any other in-progress Assessment. Switching the decision away from Interview (to Offer/Reject, or re-staging Interview with corrected details) must therefore explicitly discard the abandoned staged Interview first (`Organization\QuizController::discardStaleInterviewDecision()`, a straightforward `Assessment::whereKey(...)->delete()` that cascades to the `Interview` row via the same DB-level foreign key the direct interview-delete endpoint already relies on) — otherwise the abandoned row would stay active forever and permanently block this application from ever getting a new Assessment, despite never having been released or seen by the Student. This was caught and fixed during this phase's own audit, not left as a known gap.

**`QuizResultReleaseService`'s three-tier structure is the single concurrency/idempotency authority.** All four release triggers (Student submit, an Organization decision-completion call, the scheduled `ReleaseQuizResultJob`, and the explicit manual-release endpoint) funnel through the same small surface:
- `isReadyToRelease()` — mode-agnostic: graded, decision ready, not already released. The one readiness predicate everything else builds on.
- `attemptRelease()` — mode/timing-aware; the one method implementing all three `result_release_mode` semantics via a single `match`, used by submit, decision-completion, and the job.
- `releaseManually()` — bypasses timing but still requires readiness; used only by the explicit organization endpoint.
- `release()` (private) — the *only* place `result_released_at` is ever written or a release communication ever dispatched. Re-locks the Assessment row (`lockForUpdate()`) inside its own `DB::transaction()` and re-checks readiness before writing anything. This is what makes a delayed job racing an Organization's decision-completion request, a double-clicked Release button, or a retried queue job all safe without any race-specific code elsewhere: whichever caller's transaction commits first wins, and every later caller's re-check inside the lock finds "already released" and silently no-ops.

**One coherent communication per release** is a direct consequence of `release()` being the single dispatch point: it `match`es on `next_action` exactly once (`interview` → one new combined notification/email; `offer` → the existing, unmodified `OfferService::sendOffer()` and its own existing notification/email; `reject` → the existing rejection transition and its own existing notification/email), so there is structurally no way for two different code paths to both decide to notify the Student about the same release.

**The forgotten-decision reminder reuses the exact same lock-and-recheck pattern**, just for a different write: `sendDecisionReminder()` locks the Assessment, re-checks that it's still unreleased and the reminder hasn't already been sent (`decision_reminder_sent_at`), and only then creates the Organization's one "Decision Required" notification — so a retried scheduled job can never send the reminder twice, the same guarantee `release()` gives the Student-facing communications.

---

## Shared Quiz Template Architecture (Phase 10A.4B)

**The problem this phase fixes:** a Quiz was always authored per-candidate. If an Opportunity received 50 applicants, the Organization would author the identical quiz 50 separate times. The fix lets ONE `Quiz` row be authored once for an Opportunity and referenced by every candidate's own Assessment, while candidate score/result/decision/release stay exactly as isolated as they were before this phase.

**Two mutually exclusive Quiz shapes, deliberately never unified into one.** The audit before writing any code (per this phase's own process) considered unifying legacy and shared quizzes onto one FK direction (backfilling `quizzes.assessment_id` into a new `assessments.quiz_id` for every historical row too, so `Assessment::quiz()` could be one clean `belongsTo`). That was rejected: it would have touched `AssessmentService::createQuizAssessment()` — the heavily-tested, currently-working ad-hoc creation path (~275 backend + ~350 Flutter test methods depend on its current shape, per this phase's own audit) — for a purely cosmetic long-term win, and Eloquent cannot safely express "this relation is `belongsTo` for some rows and `hasOne` for others" without risking a wrong batched query under eager-loading across a heterogeneous collection. The chosen design instead adds two independent, purely additive columns:

```
quizzes.opportunity_id  -> opportunities.id  (nullable, unique)   -- set only on a shared template
quizzes.assessment_id   -> assessments.id    (now nullable, was required+unique) -- unchanged for every legacy row; never written again for anything new after this phase
assessments.quiz_id     -> quizzes.id        (nullable)           -- set only when an Assessment *references* a shared template
```

A Quiz row is always exactly one of the two shapes; never both, never neither. The legacy ad-hoc flow (`AssessmentService::createQuizAssessment()`, `Assessment::quiz(): HasOne`) is **completely untouched** — zero code changes, zero test rewrites needed for it. The new shared-template flow (`AssessmentService::advanceToSharedQuiz()`, `Assessment::sharedQuiz(): BelongsTo`) is purely additive. `Assessment::resolvedQuiz(): ?Quiz` (a plain method, not a relation) picks whichever the Assessment actually uses; `Assessment::withResolvedQuizRelation()` normalizes the result onto the `quiz` relation itself via `setRelation()` before JSON serialization, so **every existing `data.quiz` response shape, and every existing Flutter parsing of it, stays byte-for-byte identical regardless of which shape produced it** — no Flutter-side branching was needed to render a shared-template Assessment's quiz.

**`QuizAttempt` needed zero schema change.** It was already unique on `(quiz_id, application_id)`, not `(assessment_id, application_id)` — this table was already correctly shaped for many candidates sharing one Quiz row, a fact confirmed by audit before any code was written, not assumed.

**`Student\QuizController` required the only genuinely invasive change.** `start()`/`submit()`/`show()`/`ownedApplicationFor()` previously resolved "the assessment for this quiz" via the unconditional assumption `quiz->assessment` (a single row). That assumption is only true for a legacy Quiz; a shared template has *many* candidates' Assessments referencing it. These methods now resolve "this specific candidate's own Assessment for this Quiz" via a targeted query (`Assessment::where('quiz_id', ...)->where('application_id', ...)`) for a shared template, falling back to the original `quiz->assessment` lookup for a legacy Quiz — branching on `quiz->opportunity_id !== null`. This is the one place the two shapes could not stay entirely decoupled, because Student quiz-taking is inherently "one Quiz, many candidates" once sharing exists.

**Candidate isolation on a shared Quiz is enforced at two layers.** `QuizAttempt`'s existing unique constraint and the query-based ownership resolution above give each candidate their own attempt/score/answers. Separately, the *Organization's* single-candidate views (`GET .../assessments/{assessment}/quiz`, `GET .../assessments/{assessment}`) must narrow a shared Quiz's `attempts()` relation (which spans every candidate) down to the one Assessment's own `application_id` before returning it — `Assessment::withResolvedQuizRelation()` does this by construction (always re-fetches with a scoped `attempts` filter, never trusts a blanket eager-load), closing what would otherwise be a real cross-candidate data leak in a single-candidate view.

**Candidate advancement is dispatch-timing-sensitive for the scheduled-release job.** Before this phase, `ReleaseQuizResultJob` was dispatched once per Quiz, at publish time, keyed by `quiz->id` — correct only because one Quiz always had exactly one Assessment then. A shared template breaks that: at template-publish time, zero candidate Assessments exist yet (they're created later, individually, as each candidate is advanced). The job is now keyed by `assessment_id`, not `quiz_id`, and dispatch moves to `AssessmentService::advanceToSharedQuiz()` for the shared path (still at `publish()` time for the legacy path, where the Assessment already exists). This is a real behavioral change to the job's contract, not just a schema-following rename — see `ReleaseQuizResultJob`'s own doc comment.

**Ownership resolution branches the same way for question authoring.** `Organization\QuizController::ownsQuiz()` — used unchanged by every existing question CRUD endpoint — now resolves ownership through `quiz.opportunity.organization_id` for a template, `quiz.assessment.application.opportunity.organization_id` for legacy. This is the *only* change needed for the entire existing question-authoring surface (create/update/delete question, and the draft-only immutability guard) to already work correctly against a template Quiz id, with zero other code changes — a direct payoff of keeping question CRUD purely `quiz.id`-keyed from the start (true even before this phase).

**Deliberately out of scope, this phase:** a code compiler/execution sandbox, AI essay auto-grading, a general assessment-workflow builder, random question banks, automatic bulk Interview decisions (the Quiz Results dashboard surfaces every candidate's state in one place, but each decision is still made individually through the existing real endpoints), and any Interview/Offer/Admin UI redesign.

---

## Candidate Availability Window Architecture (Phase 10A.4B addendum)

**The problem this addendum fixes:** a shared Quiz template is authored once, but candidates are advanced to it at different moments. A single fixed availability window on the template would be unfair (a later-advanced candidate gets less notice); per-candidate manual scheduling would defeat the point of sharing a template at all. The fix splits the concern in two: a **policy**, configured once on the shared Quiz, and a **schedule**, computed independently for each candidate the moment they're advanced.

**Policy lives on the Quiz; the computed schedule lives on the Assessment — deliberately never the reverse.** `quizzes.availability_delay_days`/`availability_time`/`submission_window_hours` (all nullable — `null` on a legacy or no-policy Quiz means "no gating" wherever read) describe the *rule*; `assessments.available_at`/`due_at` (also nullable) are each candidate's own *frozen result* of applying that rule once, at `AssessmentService::advanceToSharedQuiz()` time. Storing the computed dates on the shared Quiz itself was explicitly considered and rejected during this addendum's own pre-implementation audit — it would force every candidate to share one instant regardless of when they were actually advanced, defeating the entire point of a per-candidate window.

```
quizzes.availability_delay_days     -- policy: days after assignment the window opens
quizzes.availability_time           -- policy: time-of-day it opens at ("H:i", deliberately uncast — see below)
quizzes.submission_window_hours     -- policy: how many hours the candidate then has

assessments.available_at            -- frozen result: this candidate's own window opens
assessments.due_at                  -- frozen result: this candidate's own submission deadline
```

**Calculation is a deterministic overwrite, not an addition.** `AssessmentService::calculateAvailabilityWindow()` computes `available_at = assignedAt->copy()->addDays(delayDays)->setTimeFromTimeString(availabilityTime)` — `setTimeFromTimeString` *replaces* the time-of-day component rather than adding a duration, matching the product's actual intent ("opens at 10am on day N," not "opens N days and some fractional-day offset later"). `due_at = available_at->copy()->addHours(submissionWindowHours)`. Both fall back to `[assignedAt, null]` (immediate, no deadline) if the shared Quiz's policy fields are somehow null — defensive only, since `StoreOpportunityQuizRequest` now requires all three for every template.

**`availability_time` is deliberately left uncast** (no Laravel `datetime`/`time` cast on the `Quiz` model) — returned as a raw `"HH:MM:SS"` string. Casting it would force Carbon to attach a fake reference date to a value that is genuinely date-independent (it's a policy about time-of-day, reused across arbitrary future assignment dates), which both complicates the cast and buys nothing `setTimeFromTimeString()` doesn't already handle directly from the raw string.

**Timezone: no second convention introduced.** The addendum's own pre-implementation audit (its explicit first requirement) confirmed `config('app.timezone') === 'UTC'`, no custom `serializeDate()` override anywhere in the app, and that Flutter's `formatDate()`/`formatTime()` already render raw UTC values as literal wall-clock numbers with zero `.toLocal()` conversion — a deliberate existing simplicity convention. Every new date this addendum introduces follows that same convention unchanged; introducing per-user timezone conversion was explicitly out of scope.

**Exposing `available_at`/`due_at` to the Student without a Quiz schema change.** `Student\QuizController::show()` (and, for the same reason, the generic `Student\AssessmentController::index()`/`show()`, which also nest `quiz.questions`) attaches `available_at`/`due_at` as *response-shaping extras* — `$quiz->available_at = $assessment->available_at` — rather than adding real columns to `Quiz`. This works because these values are genuinely per-candidate, not per-Quiz; attaching them to the serialized response object (via Eloquent's ordinary dynamic-attribute mechanism, the same "virtual attribute for response shaping" pattern `QuizModel.assessmentStatus` already established in the base phase) keeps the client-facing shape convenient (one place to read the schedule) without implying these are real `Quiz` columns.

**Two independent enforcement layers, matching the addendum's explicit security requirement that a disabled Flutter button is not sufficient.** `Assessment::isUpcoming()`/`isPastDue()`/`isAvailableNow()` are the single source of truth both `Student\QuizController::start()` (rejects before `available_at`, after `due_at`) and `::submit()` (effective cutoff = `min(started_at + time_limit_minutes, due_at)`, mirroring the addendum's own worked example) independently re-check server-side on every request — never trusting whatever state the Flutter client believes it's in. `::show()` additionally withholds `questions` entirely (an empty array) while a window is upcoming, so no endpoint that nests `quiz.questions` can leak question text ahead of the real availability moment.

**No automatic outcome on a missed deadline.** `isPastDue()`/`isUpcoming()` are purely descriptive; nothing in this addendum writes `assessment.result`, transitions `application.status`, or otherwise invents a decision when a candidate's window closes unused. The existing decision-and-release machinery (Phase 10A.4A) remains the only place any of that happens, and only ever in response to a real Organization action.

**A derived, non-stored timing label for Organization views.** `Assessment::quizTimingStatus()` computes one of `upcoming`/`available`/`in_progress`/`submitted`/`deadline_passed` fresh on every request, from `available_at`/`due_at` plus the candidate's own `QuizAttempt` (or a raw attempt row already fetched by the caller, avoiding N+1 on the Results dashboard) — deliberately never a stored enum column, matching this addendum's own explicit "don't create unnecessary permanent enums" instruction.

**Backward compatibility follows the identical pattern the base phase already established for the two Quiz shapes.** Every pre-addendum Assessment has `available_at`/`due_at` both `null`; every `isUpcoming()`/`isPastDue()`/`isAvailableNow()` check treats `null` as "no gating" by construction, so a legacy Assessment's behavior is completely unchanged with zero backfill and zero special-casing at any call site.

**Deliberately out of scope, this addendum:** retakes, a deadline-extension UI, automatic rejection/failure on a missed deadline, and any change to the Phase 10A.4A decision/release architecture — availability/deadline and result release are, and remain, two entirely independent concerns that happen to both live on `Assessment`.

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

## Remaining Workflow Emails (Phase 7A-4.2)

Extends the Phase 7A-4.1 queued-email foundation to the remaining six
approved events, reusing the exact same pattern (`QueuedTransactionalMail`
base class, `NotificationService` → `EmailService` → queued Mailable,
after-commit semantics) with **zero changes to the foundation itself** —
`app/Mail/QueuedTransactionalMail.php` is untouched by this phase.
**Architecture only, still**: `MAIL_MAILER` stays `log`, no external SMTP
provider is configured, no live email is ever sent.

- **Six new Mailables**, all extending `QueuedTransactionalMail` unchanged
  (inheriting `ShouldQueueAfterCommit`, the `emails` queue, `tries=3`,
  `timeout=60`, `backoff=[30,300,1800]` with no per-class overrides):
  `InterviewScheduledMail`, `InterviewRescheduledMail`, `QuizAvailableMail`,
  `ApplicationRejectedMail`, `OfferAcceptedMail`, `OfferDeclinedMail`. Each
  is constructed from primitives, matching `OfferReceivedMail`'s own
  precedent — never the `Interview`/`Quiz`/`Application`/`Opportunity`
  models themselves.
- **`App\Services\EmailService` gained six new methods**, one per Mailable
  (`sendInterviewScheduledEmail`, `sendInterviewRescheduledEmail`,
  `sendQuizAvailableEmail`, `sendApplicationRejectedEmail`,
  `sendOfferAcceptedEmail`, `sendOfferDeclinedEmail`) — deliberately six
  explicit methods, not a generic `sendByEventType()` dispatcher, since each
  event's required data genuinely differs. Two new private URL helpers
  (`studentQuizUrl()`, `organizationApplicationUrl()`) mirror
  `NotificationService`'s own `studentQuizPath()`/`organizationApplicationPath()`
  path helpers, just prefixed with `config('app.frontend_url')`.
- **`NotificationService` gained email calls in six more convenience
  methods** (`notifyApplicationRejected`, `notifyInterviewScheduled`,
  `notifyInterviewRescheduled`, `notifyQuizPublished`, `notifyOfferAccepted`,
  `notifyOfferDeclined`) — each unchanged in its existing in-app
  Notification copy/type/priority/action_url, now also calling the matching
  `EmailService` method after creating that Notification. The four
  deliberately-in-app-only methods (`notifyApplicationSubmitted`,
  `notifyApplicationShortlisted`, `notifyQuizCompleted`,
  `notifyQuizResultAvailable`) are untouched — see
  docs/BUSINESS_RULES.md section 8 for the full eligibility matrix and the
  frequency/actionability reasoning behind it.
- **Two convenience methods needed one additional parameter, three call
  sites needed a one-line change to supply it** — the "extend the signature
  minimally, update the existing caller" path, not a new call site or a
  large Eloquent graph:
  - `notifyInterviewScheduled(..., Interview $interview)` — the freshly-
    created Interview (`AssessmentService::createInterviewAssessment()` now
    captures `$assessment->interview()->create($interviewData)` into a
    variable instead of discarding it).
  - `notifyInterviewRescheduled(..., Interview $interview)` — the already-
    updated Interview (`Organization\InterviewController::update()` already
    had `$interview` in scope).
  - `notifyQuizPublished(..., Quiz $quiz)` — the just-published Quiz
    (`Organization\QuizController::publish()` already had `$quiz` in
    scope).
  - `notifyApplicationRejected`/`notifyOfferAccepted`/`notifyOfferDeclined`
    needed **no** signature change — their existing arguments (student
    user, opportunity title, application ID; organization user, student
    name for the latter two) were already everything their email needs.
- **A real latent bug surfaced and was fixed during this phase**:
  `interviews.duration_minutes` has a DB-level default (`60`), but a
  freshly-`create()`d Eloquent model does not reflect a DB-applied default
  in memory without an explicit `fresh()`/`refresh()` — so
  `$interview->duration_minutes` is `null` immediately after creation
  whenever the caller didn't explicitly supply it (true for every
  `interviewPayload()` in this project's own tests, and for any real
  request that omits it, since it's optional in
  `StoreInterviewRequest`/`UpdateInterviewRequest`). Rather than forcing an
  extra `fresh()` query solely for email content, `durationMinutes` is
  nullable end-to-end (`EmailService` → `InterviewScheduledMail`/
  `InterviewRescheduledMail` → the Blade view's own `@if ($durationMinutes)`
  guard) — which also matches this event's own content spec ("duration if
  available").
- **Content sourced directly from already-confirmed-safe fields**:
  `interviewer_name` is included because docs/API.md's "Student-visible
  Interview fields" note already confirms it (unlike
  `interviewer_email`/`rating`/`decision`/`company_feedback`/`notes`) is
  returned to students today; `Quiz.passing_score` is included for the
  same reason ("a deliberate v1 product decision: the passing threshold is
  a transparent, known-in-advance assessment rule, not a grading
  internal" — docs/API.md's "Student-visible Quiz fields" note).
  `Quiz.time_limit_minutes` is nullable (a quiz may have no time limit) and
  omitted from the email when absent. Application Rejected and Offer
  Declined both deliberately invent no reason — v1 has no student-visible
  rejection-reason field and no Offer-decline-reason field at all.
- **Organization-facing Mailables (`OfferAcceptedMail`/`OfferDeclinedMail`)
  distinguish the greeting target from the subject of the email**:
  `$recipientName` (the organization account's own name, from
  `$organizationUser->name`) greets the reader; `$studentName` (the
  applicant, never the recipient) is mentioned in the body — the two are
  never conflated.
- **Constructor-injection ripple**: three `tests/Unit/Services/NotificationServiceTest.php`
  tests (`test_notify_interview_scheduled`, `test_notify_interview_rescheduled`,
  `test_notify_quiz_published`) called their respective convenience method
  directly with the old (pre-this-phase) argument list — fixed by passing a
  small, never-persisted `Interview`/`Quiz` instance (`new Interview([...])`/
  `new Quiz([...])`), the same "unsaved model as a test double" pattern
  `test_notify_offer_sent` already established in Phase 7A-4.1 for `Offer`.
  No other test or production call site constructs `NotificationService`/
  `EmailService` directly — both are resolved through the container
  everywhere else (`app(NotificationService::class)`, or real dependency
  injection via the framework).
- **Tested via**: six new `tests/Unit/Mail/*Test.php` files (one per new
  Mailable — subject, greeting, opportunity title, CTA text/URL, every
  optional-field present/absent branch, and, critically, that every
  category of forbidden content listed above is absent from the actual
  rendered output); `tests/Unit/Services/EmailServiceTest.php` extended
  with 16 new tests (one method, one recipient/CTA/data-forwarding group per
  new `EmailService` method); `tests/Feature/Notifications/WorkflowEmailTest.php`
  extended with 20 new tests — six new event groups (A–F, one real HTTP
  call per scenario: success queues exactly one email of the right class to
  the right recipient; wrong-ownership/failed/ineligible/duplicate/
  idempotent/logistics-only requests queue none) plus a seventh explicit
  in-app-only regression group (G) proving all four deliberately-excluded
  events still create their in-app Notification but queue zero email. The
  pre-existing Phase 7A-4.1 Offer Received tests in the same file needed no
  changes and still pass unmodified — proving the extension didn't disturb
  the pilot.

---

## Match Score Privacy & Applicant Ranking (Phase 8A-1)

Fixes a confirmed privacy leak (`Application.match_score` — an
organization-internal output of `App\Services\MatchingService` — was
reachable by the student it belongs to through several Student-facing
responses) and adds deterministic organization-side applicant ranking
using the already-stored `match_score` column. Deliberately scoped
narrowly: no `MatchingService` formula/weight change, no automatic score
calculation, no migration, no Flutter change.

- **Privacy mechanism: per-instance `makeHidden()`, not model-level
  `$hidden`** — `App\Http\Controllers\Student\Concerns\HidesInternalApplicationFields`,
  the `Application` counterpart to the pre-existing
  `HidesInternalInterviewFields`/`HidesInternalQuestionFields` (Phase
  4A-1/6B-3). `Application` deliberately gains no `$hidden` of its own: doing
  so globally would also hide `match_score` from the Organization endpoints
  that generate and rely on it, so the fix is applied only from the Student
  side, at exactly the response paths that serialize an `Application`:
  `Student\ApplicationController::store()`/`index()` (direct), and the
  nested `application` on `Student\InterviewController::index()`
  (`assessment.application`, which is also the same object
  `Interview::application()`'s backward-compat accessor resolves to — one
  `makeHidden()` call covers both keys) and
  `Student\AssessmentController::index()`/`show()`. `Student\QuizController`
  and `Student\OfferController` were inspected and confirmed to never
  serialize a nested `Application` at all (only used internally for
  ownership checks), so neither needed a change.
- **Organization visibility is untouched by construction** — because the
  fix never touches `Organization\ApplicationController`,
  `ApplicationAnalysisController`, or the `Application` model itself, there
  was no risk of "accidentally hiding `match_score` from the Organization
  side" to guard against; that endpoint code is simply unmodified.
- **Ranking**: `Organization\ApplicationController::index()` and
  `indexForOpportunity()` add
  `->orderByRaw('match_score IS NULL ASC, match_score DESC, applied_at ASC')`.
  `match_score IS NULL` evaluates to a plain `0`/`1` identically on this
  project's MySQL/MariaDB runtime and its SQLite test driver, so ordering
  ascending on it reliably sorts every non-null score before every null one
  on both without relying on driver-specific `NULLS LAST` syntax. Applied
  only to the two list endpoints — `show()`, `updateStatus()`, and every
  Student-facing endpoint are unchanged. Ranking only ever reads the
  already-stored column; it never calls `MatchingService` and never
  mutates an `Application`.
- **No migration.** `applications.match_score` was already the correct
  nullable `decimal(5,2)` — this phase is pure application-code, zero
  schema change, zero backfill.
- **Tested via two new files** —
  `tests/Feature/Applications/ApplicationPrivacyTest.php` (every direct and
  nested Student-facing leak path, including a non-null and a `0.00` score
  to prove this is field-level privacy, not null-omission) and
  `tests/Feature/Applications/ApplicationRankingTest.php` (the exact
  92/75/75/10/0/null/null scenario with out-of-insertion-order
  `applied_at` timestamps on both endpoints, ownership scoping, "listing
  never calculates a score", Student ordering unaffected, and Organization
  visibility of null/zero/numeric scores preserved). All pre-existing
  `Applications`/`AI` tests pass unmodified.

---

## MatchingService v1.1 & Automatic Match Calculation (Phase 8A-2)

Rewrites `App\Services\MatchingService`'s formula and wires it into
application creation. Deliberately deterministic and synchronous
throughout: no AI/LLM API, no CV parsing, no migration/backfill, no
queue/job, no new endpoint, no Flutter change.

- **New base weights: Skills 60% / Field-Major 20% / Experience 20%.**
  Replaces the old Skills 62.5% / Experience 25% / Education 12.5%
  formula. The fixed `50.0` "education" placeholder (there being no
  structured education field on `student_profiles`) is removed entirely,
  not just hidden — the response no longer has an `education_match_score`
  key at all, replaced by `field_match_score`.
- **Proportional weight redistribution for unavailable factors.** A
  factor is included in the weighted average only when its required input
  genuinely exists; `MatchingService::weightedAverage()` sums
  `score * weight` and `weight` only over non-null components and divides
  one by the other — algebraically identical to "redistribute the missing
  factor's weight proportionally across the rest." If every factor is
  unavailable, the total weight is `0` and the method returns a real,
  calculated `0.00` rather than `null` or throwing — this preserves
  `analyze()`'s existing contract of always returning a persistable float
  (`ApplicationAnalysisController::analyze()` unconditionally does
  `$application->match_score = $result['overall_match_score']`). In
  practice this all-unavailable case is not reachable through normal data
  today, since `experience_level` is a required column (see below).
- **Skills (60%)** keeps its exact pre-8A-2 mechanics (required skills
  count double, preferred count once, `matched_weight / total_weight *
  100`) with one deliberate behavior change: an opportunity with zero
  required/preferred skills defined now makes skills *unavailable*
  (weight redistributes) instead of the old fake "100, trivially
  satisfied" default for an empty requirement list.
- **Field/Major (20%, new)** compares `studentProfile.major` against
  `opportunity.field_of_study` after normalizing both (trim, collapse
  internal whitespace, lowercase). Scores 100 on an exact match or when
  one value contains the other as a whole word-boundary-anchored segment
  (`(^|\s)needle(\s|$)` against the haystack) — e.g. "Civil Engineering"
  matches inside "Civil Engineering and Construction" — otherwise 0. The
  boundary anchor is deliberate: a naive substring check would let "Art"
  falsely match inside "Part-time Arts Program". This is intentionally
  not fuzzy/Levenshtein/NLP matching. If either `major` or
  `field_of_study` is null/blank, the factor is unavailable (not a zero).
- **Experience (20%)** keeps its exact pre-8A-2 mechanics (max
  `years_of_experience` across the student's skills vs. the opportunity's
  `experience_level` year threshold, capped at 100; `no_experience`
  correctly always scores 100, a real answer not a placeholder). A
  defensive "unavailable" branch was added for a missing/unrecognized
  `experience_level`, but `experience_level` is a required, non-nullable
  enum column on `Opportunity`, so this branch is not reachable through
  normal opportunity creation today — kept only so a future schema
  relaxation or a malformed row degrades safely instead of silently
  scoring against an assumed 0-year requirement.
- **Automatic calculation on application creation.**
  `Student\ApplicationController::store()` now also constructor-injects
  `MatchingService`. Inside the existing `DB::transaction()`: create
  `Application` → calculate `MatchingService::analyze()` and persist
  `match_score` → create the "New Application" `Notification` → commit.
  Synchronous, no external I/O, no queue — safe to run inline before
  commit exactly like the Phase 7A-2 Notification insert it now precedes.
  `HidesInternalApplicationFields` (Phase 8A-1) already strips
  `match_score` from the response regardless of whether the value is
  `null` or a freshly-calculated number, so no privacy-mechanism change
  was needed for this phase.
- **`POST .../analyze` remains the single implementation of manual
  recalculation** — unchanged, still calls the same
  `MatchingService::analyze()`, so the auto-calc path and the manual path
  can never drift into two different formulas.
- **No backfill.** Applications created before this phase keep
  `match_score: null` until an organization explicitly calls `analyze`;
  only newly-created applications get an automatic score.
- **Tested via an extended `tests/Feature/AI/MatchingBehaviorTest.php`**
  (formula scenarios: perfect/zero match, missing-major and
  missing-field-of-study redistribution with exact weighted-average
  arithmetic, case/whitespace-normalized field matching, the
  word-boundary contains-match and its false-positive guard, the
  no-opportunity-skills-is-unavailable fix, `no_experience` always
  scoring 100, proportional experience scoring, required-vs-preferred
  skill weighting, and confirmation `education_match_score` no longer
  appears in the response) and a new
  `tests/Feature/Applications/ApplicationAutoMatchingTest.php` (apply
  auto-calculates and persists a score, the auto-calculated score matches
  what a manual `analyze` call would produce for identical data, a sparse
  profile/opportunity still gets a real non-null score instead of
  crashing, the create response still never exposes `match_score`, the
  "New Application" notification still fires alongside auto-matching, and
  a rolled-back duplicate-apply leaves no stray calculation). All
  pre-existing `Applications`/`AI`/`ApplicationPrivacyTest`/
  `ApplicationRankingTest` tests pass unmodified.

---

## Real CV Upload Foundation (Phase 8A-4)

Replaces the CV feature's placeholder "client types a `file_path` string"
behavior with a genuine multipart PDF upload, private managed storage, and
authenticated ownership-checked download endpoints. Deliberately scoped
narrowly: no CV parsing, no AI skill extraction, no `MatchingService`
change, no migration.

- **No migration.** `cvs.file_path` (a plain `string` column) was already
  sufficient to hold a server-generated relative storage path like
  `cvs/3/1f9c2b1a-....pdf` — well under its 255-char default length — so
  this phase is pure application-code. No `original_filename` (or any
  other) column was added: the client's original filename is never even
  read past validation, so there was nothing worth preserving that the
  existing schema didn't already support.
- **Upload contract**: `POST /api/student/cvs` request body changed from
  JSON (`title`, `file_path`) to `multipart/form-data` (`title`, `file`).
  `App\Http\Requests\Student\StoreCVRequest` now validates `file` as
  `['required', 'file', 'mimes:pdf', 'max:5120']` — `mimes:pdf` inspects
  the file's real content/magic bytes, not just its extension or
  client-reported MIME type. A `file_path` field in the request body is
  simply not a validated/accepted input any more — nothing reads it, so it
  can never influence where or under what name a file is stored.
- **Storage**: the `local` disk (`storage/app/private`, `serve => true`,
  see config/filesystems.php — already the project's pre-existing default,
  untouched by this phase) under `cvs/{student_profile_id}/{uuid}.pdf`.
  `Str::uuid()` generates the filename — the client's original filename
  (even something like `../../../evil.pdf`) is never used to construct a
  path or filename at all, so there is no path-traversal surface: nothing
  about the request influences the stored path except the authenticated
  student's own `student_profile.id` (never client-supplied) and a
  server-generated random name.
- **Backward compatibility, no destructive migration.** Rows created
  before this phase may still hold whatever string a student typed into
  the old `file_path` text field (e.g. a local Windows path). These rows
  are left exactly as they are — never migrated, never deleted. They
  continue to list normally; the download endpoints below simply treat
  them as "file not found" (a controlled 404, via `Storage::exists()`
  returning false for a string that was never really stored on this disk)
  rather than special-casing or crashing on them, and delete-time file
  cleanup structurally never touches them either (see below).
- **Secure download, two endpoints, both inline `application/pdf`
  responses via `Storage::disk('local')->response()`:**
  - `GET /api/student/cvs/{cv}/download` (`App\Http\Controllers\Student\
    CVController::download()`) — the CV's own owning student only (404
    otherwise), mirroring the ownership check every other Student CV
    action already uses.
  - `GET /api/organization/applications/{application}/cv`
    (`App\Http\Controllers\Organization\ApplicationController::
    downloadCv()`) — only reachable through an `Application` the
    requesting organization's own `Opportunity` actually owns (the same
    `opportunity->organization_id` check every other organization
    Application action uses); there is no `GET /organization/cvs/{cv}`
    route, so a CV can never be reached by guessing its ID directly, only
    through an application the organization genuinely owns. A missing
    physical file (managed or legacy) returns the same controlled 404 as
    the student endpoint.
  - Neither endpoint accepts or exposes a public/static URL — a file is
    only ever served by streaming it through one of these two
    authenticated, ownership-checked actions.
- **Delete-time file cleanup is conservative by construction.**
  `CVController::destroy()` deletes the physical file only when
  `file_path` starts with `cvs/{owning student_profile_id}/` — the exact
  prefix this application itself always generates for that CV's owner.
  Since `file_path` is a trusted, already-validated DB column (never a
  per-request client value at delete time), this is a safe, sufficient
  check: a legacy fake path never matches this prefix and is simply left
  alone, so `Storage::delete()` is never called against an arbitrary,
  client-influenced path. The existing delete-conflict rule (409 when the
  CV is used by an Application) is completely unchanged and still leaves
  both the DB row and any physical file untouched.
- **CV response shape is unchanged.** `file_path` is still returned as
  part of the CV JSON (kept for compatibility, per docs/API.md section 4)
  — for new uploads it's now always a safe server-managed relative path,
  never a client-chosen or absolute-filesystem string; the real security
  boundary is the two ownership-checked download endpoints above, not
  hiding the column. The Flutter app (Phase 8A-4 Flutter side) no longer
  displays this raw value to either a student or an organization — see the
  Flutter changes below.
- **Application/CV relationship is completely unchanged.** Applying still
  submits `cv_id` only (`Student\ApplicationController::store()`, via
  `ApplyToOpportunityRequest`'s existing `cv_id` ownership-scoped
  `Rule::exists()`); the CV itself is never re-uploaded or re-validated at
  apply time, exactly as before this phase.
- **Tested via `tests/Feature/Student/StudentCvUploadTest.php`** (new —
  valid upload, physical storage, managed-path shape, title persistence,
  auth/role/profile-existence gating, non-PDF rejection, over-5MB
  rejection, an exact-5MB file accepted, missing-file rejection, a
  client-supplied `file_path` being fully ignored, path-traversal-proof
  filenames, both download endpoints' ownership/missing-file/legacy-path
  behavior, and all three delete-cleanup scenarios) plus
  `tests/Feature/Student/StudentCvTest.php`'s existing create test updated
  for the new multipart contract — every other pre-existing CV/Application
  test (list, set-default, delete-conflict, apply-with-cv_id) passes
  unmodified, using `Storage::fake()`/`UploadedFile::fake()` throughout, no
  real filesystem writes in tests.

---

## CV PDF Text Parsing Foundation (Phase 8A-5)

Adds deterministic, server-side plain-text extraction for every newly
uploaded CV PDF, so a future AI-matching phase has real CV content to work
from. Deliberately scoped narrowly: no AI/LLM call of any kind, no skill
extraction, no `MatchingService` change, no OCR, no DOCX support, no
Flutter change.

- **Parser: `smalot/pdfparser` v2.12.5** (Composer, LGPL-3.0, OSI
  approved). Chosen because it's pure PHP — no external executable (no
  `pdftotext` shell-out), no Python sidecar, no OCR — and requires only
  `ext-iconv`/`ext-zlib` (already present) plus
  `symfony/polyfill-mbstring`, both trivial, non-abandoned dependencies.
  No PDF-parsing library was already present transitively before this
  phase (`composer.lock` had none). The only other advisories `composer
  audit` reports (several `league/commonmark` DoS advisories) are
  pre-existing, required by `laravel/framework` itself, and entirely
  unrelated to this addition.
- **Schema: one nullable column, no other new tables/fields.**
  `cvs.parsed_text` (`LONGTEXT NULL`, added via
  `2026_08_18_090000_add_parsed_text_to_cvs_table`) — `LONGTEXT` because a
  multi-page CV's extracted plain text can exceed `TEXT`'s 64KB limit in
  principle, and there's no reason to risk truncation for what's already a
  cheap column type in MySQL. Nullable so every pre-existing row (and
  every new row whose PDF has no extractable text) stays valid with no
  backfill. Deliberately **not** added: `extracted_skills`, `ai_summary`,
  `parsed_at`, `parser_version`, `parsing_status`, embeddings, or any
  other metadata — `parsed_text IS NULL` alone is a sufficient signal for
  "not available yet" for everything this phase (or the AI phase after
  it) needs to know; a future phase can add real metadata if it turns out
  to genuinely need it.
- **`App\Services\CvTextExtractor`** — the one place PDF parsing happens.
  `extract(string $relativePath): string` takes a path on the `local`
  disk (never a client-supplied path — see below), resolves it to an
  absolute path via `Storage::disk('local')->path()`, and hands it to
  `smalot/pdfparser`. Purely mechanical: no skill identification, no
  summarization, no AI call, no model reads/writes — it returns a string
  and nothing else. Normalization is minimal and non-destructive: CRLF/CR
  unified to LF, runs of 3+ blank lines collapsed to one, outer whitespace
  trimmed — deliberately no lowercasing and no punctuation stripping,
  since a later AI-extraction phase needs text as close to the source as
  possible. Returns an empty string (not an exception) when the PDF is
  valid but has no extractable text (e.g. scanned/image-only, which this
  phase does not OCR). Throws `App\Exceptions\CvTextExtractionException`
  only for a genuine parse failure: the path isn't shaped like a managed
  upload (`cvs/{student_id}/{uuid}.pdf` — defense in depth, since this
  service is only ever called with a path this application itself just
  generated, never client input), the file doesn't exist on disk, or the
  PDF itself can't be opened at all (corrupt structure, or
  encrypted/password-protected — `smalot/pdfparser` explicitly refuses
  those with its own "Secured pdf file are currently not supported."
  message, which this phase never exposes to an API response, only to the
  Laravel log via `Log::warning()`).
- **Synchronous, at the one real upload boundary, best-effort.**
  `Student\CVController::store()` (Phase 8A-4's endpoint, unchanged route)
  now: stores the file → calls `CvTextExtractor::extract()` → creates the
  `CV` row with `parsed_text` set from the result. Chosen synchronous over
  queued because extraction is bounded by the existing 5MB upload limit
  and `smalot/pdfparser` has no external I/O to wait on — no new queue
  infrastructure was worth introducing for that bound. Extraction is
  wrapped so it **never blocks or fails the upload**: the file already
  passed Laravel's own `mimes:pdf` validation before extraction runs, so
  a parse failure at this point (image-only PDF, or a corrupt/encrypted
  PDF that still identified as a PDF) is purely an internal processing
  outcome, not a reason to reject an otherwise-valid upload — the hard
  product requirement that a valid PDF upload must never produce a
  broken/inconsistent CV record. Both "genuinely no text" and "extraction
  failed" collapse to the same `parsed_text = null` outcome (see the
  schema note above on why no separate status flag was needed to tell
  them apart) — `CVController::extractParsedText()` is the one place that
  decision is made.
- **Orphan-file cleanup is narrow and precise.** The only failure mode
  that can leave a stored-but-unreferenced file is the `CV` row's own
  `create()` call failing *after* the file was already stored (e.g. a DB
  error) — parsing failures never reach this path since they never block
  row creation. `store()` wraps the `create()` call in a try/catch that
  deletes the just-stored file and re-throws on any such failure. Legacy
  files and files belonging to other CVs are never touched — the delete
  only ever targets the exact path this same request just wrote.
- **`parsed_text` is hidden at the model level** (`CV::$hidden =
  ['parsed_text']`), not per-response like `HidesInternalApplicationFields`
  hides `match_score` — there is no consumer of this API (Student or
  Organization) that should ever see it, so there was no case to carve an
  exception for. The CV response shape is otherwise completely unchanged
  from Phase 8A-4; no new endpoint, no new response field.
- **Delete is unmodified.** `parsed_text` disappears naturally with the
  row when a CV is deleted — no separate parsed-text artifact/file exists
  to clean up.
- **No backfill.** Every CV row created before this phase — and any
  legacy fake-path row — keeps `parsed_text = null` until (if ever) a
  future phase adds an explicit re-parse action; none exists yet, per
  this phase's own scope.
- **Migration verified against the real local dev database, not only
  SQLite tests.** A `mysqldump` backup of `opportunityhub_db` was taken
  immediately before migrating (`storage/app/db-backups/`, already
  covered by `storage/app/.gitignore`'s default `*` rule — never
  committed). `php artisan migrate` ran cleanly, all 3 pre-existing `cvs`
  rows were preserved with `parsed_text` correctly `null`, and a full
  `migrate:rollback --step=1` + re-`migrate` round-trip confirmed the
  migration is safe to reverse and re-apply without touching row count.
- **Tested via `tests/Unit/Services/CvTextExtractorTest.php`** (new — hand-
  built, byte-offset-correct minimal PDF fixtures covering simple text,
  multiple lines, CRLF/blank-line normalization, WinAnsi-encoded accented
  text, an image-only/no-text PDF, a malformed PDF, a truncated PDF, a
  missing file, an encrypted PDF, and three "never read outside a managed
  path" guards) and
  `tests/Feature/Student/StudentCvParsingTest.php`** (new — a real text
  PDF upload persists `parsed_text`, an image-only PDF and a
  PDF-identified-but-unparseable upload both still succeed with
  `parsed_text` null, `parsed_text` is absent from the create response,
  the student CV list, an organization's single-application response, and
  an organization's applicants list, a forced DB-insert failure via a
  SQLite trigger leaves neither a CV row nor an orphaned file, and
  default/delete/`cv_id`-apply behavior are all unaffected). Two
  pre-existing migration-rollback test files
  (`AssessmentMigrationTest.php`, `OfferSentStatusMigrationTest.php`) and
  one more (`NotificationTypeMigrationTest.php`) needed their hardcoded
  `--step` counts incremented by one, since each counts backward from
  "however many migrations currently exist" to reach an earlier target
  migration, and this phase's new migration now sits on top of all three
  targets — a pre-existing, self-documented fragility (each file's own
  doc comment already flagged it), not a behavior change.

---

## AI CV Skill Extraction (Phase 8A-6)

The first feature in this project that calls an external AI provider.
Adds a suggestion-only skill-extraction step on top of Phase 8A-5's
`cvs.parsed_text`: a Student can ask the backend to send their CV's
already-extracted text to Groq, get back structured skill
suggestions, and explicitly choose which ones to add to their profile
through the **existing** Student Skill endpoint. The AI never mutates
data on its own, never sees anything beyond the CV text, and never
touches `match_score` or `MatchingService`.

- **Provider: Groq, `openai/gpt-oss-120b` (default, configurable via
  `GROQ_MODEL`), called directly over HTTP via its OpenAI-compatible Chat
  Completions API.** No AI SDK was added to `composer.json` —
  `AiSkillExtractionService` calls `POST
  https://api.groq.com/openai/v1/chat/completions` via Laravel's built-in
  `Http` facade with `Authorization: Bearer {GROQ_API_KEY}`, per this
  phase's own explicit preference for the simplest provider-compatible
  REST integration over an unnecessary PHP SDK dependency. Structured
  JSON output is requested via `response_format: {"type": "json_schema",
  "json_schema": {"strict": true, "schema": ...}}` — Groq's strict,
  schema-guaranteed structured-output mode, currently supported on the
  `openai/gpt-oss-20b` and `openai/gpt-oss-120b` models (verified against
  Groq's official docs at the time this integration was built; `openai/
  gpt-oss-120b` was chosen as the default both because it's the
  recommended replacement for the earlier candidate model and because it
  supports this strict mode, giving the same schema-guaranteed
  reliability the original design required). All provider-specific code
  (the endpoint URL, headers, request/response shape) is confined
  entirely to `AiSkillExtractionService` — swapping providers later means
  rewriting that one file.
- **Configuration lives in `config/services.php` → `'groq'`.**
  `env('GROQ_API_KEY')` / `env('GROQ_MODEL', 'openai/gpt-oss-120b')`
  are read only there, never inside the service (`config('services.groq.*')`
  instead) — the same convention already used for `postmark`/`resend`/`ses`/
  `slack`. `.env.example` carries both keys as blank placeholders. The API
  key is never sent to Flutter in any form.
- **Minimum data sent to the provider: the CV's `parsed_text`, and
  nothing else.** No student name, email, phone, exact user ID, password,
  auth token, `match_score`, offers, application status, or interview/
  company feedback ever reaches the request body — see
  `AiSkillExtractionService::requestBody()`, and
  `tests/Feature/AI/AiSkillExtractionServiceTest.php`'s explicit assertion
  that none of that data appears in the outbound request.
- **`App\Services\AiSkillExtractionService`** — the one place in the app
  that talks to an AI provider. `extractSkills(CV $cv): array` builds the
  request, calls the provider with a bounded 25-second timeout and no
  retries (a single controlled attempt, appropriate for a synchronous
  interactive Student action — no new queue infrastructure was introduced
  for this), validates/normalizes/deduplicates the structured response
  (trimmed, case-insensitive dedup keeping the highest-confidence
  duplicate, confidence clamped to `[0.0, 1.0]`, capped at 20 suggestions),
  and maps each name to the existing Admin-owned `Skill` catalog by exact
  case-insensitive match. It never creates a `Skill` row, never writes a
  `StudentSkill` row, never touches the `CV` row or `match_score`, and
  never sends a notification or email.
- **No new persisted table.** Given the project deadline and the
  suggestion-only nature of the feature, suggestions are transient —
  computed per-request and returned directly in the API response, never
  stored. This also means the feature needed **zero new migrations**,
  avoiding the pre-existing `--step`-count fragility in
  `AssessmentMigrationTest`/`OfferSentStatusMigrationTest`/
  `NotificationTypeMigrationTest` that Phase 8A-5 already had to work
  around.
- **Endpoint: `POST /api/student/cvs/{cv}/extract-skills`**
  (`CVController::extractSkills`), same ownership/role/profile-completion
  middleware stack as every other `/student/cvs/*` route. Ownership check
  is the usual `student_id` comparison → 404 if not the caller's CV. If
  `parsed_text` is null/blank (no extractable text — e.g. a scanned PDF,
  or a legacy pre-Phase-8A-5 row), the controller returns 422 *before*
  the service is ever called, with the message "Text could not be
  extracted from this CV." Response data shape:
  `{"skills": [{"name", "confidence", "skill_id", "is_available",
  "already_added"}]}` — `parsed_text` itself is never returned (it's
  already hidden at the model level per Phase 8A-5).
- **`already_added` prevents duplicate-add offers client-side.** For each
  catalog-matched suggestion, the service checks the current student's
  existing `StudentSkill` rows and flags whether they already have it —
  computed fresh on every extraction call, never cached, and never itself
  a write.
- **Unmatched suggestions are never silently turned into new Skill
  records.** The Admin-owned catalog is the sole source of truth for
  `Skill` rows (see Business Rule 9 below); a suggestion with no catalog
  match comes back with `skill_id: null, is_available: false` and the
  Flutter UI disables selecting it, with an inline "Not in skill catalog"
  label. This is intentional, controlled AI behavior, not a gap.
- **Accepting a suggestion reuses the existing Student Skill endpoint —
  no second "accept" mutation endpoint was added.** Flutter's "Add
  Selected Skills" action calls `POST /api/student/skills`
  (`StudentSkillController::store`, unchanged) once per selected
  suggestion, with a client-side default `level: 'intermediate'` (the AI
  only ever produces a confidence score, never a proficiency level).
- **Failure handling never risks CV/Profile/Application data.** Missing
  `GROQ_API_KEY`, a network/timeout failure, a non-2xx provider
  response (401/403/429/5xx), and a malformed/invalid structured response
  are all caught inside `AiSkillExtractionService` and re-thrown as the
  single `App\Exceptions\AiSkillExtractionException`, which
  `CVController::extractSkills` always maps to a safe `503` with a
  generic, user-safe message — never the provider's API key, raw stack
  trace, or full provider response body. Technical detail (status code,
  exception message) is logged server-side via `Log::error()` only.
  `tests/Feature/AI/CvSkillExtractionEndpointTest.php` confirms a provider
  failure leaves every table (`cvs`, `student_skills`) byte-for-byte
  unchanged.
- **No AI match_score, ever.** `AiSkillExtractionService` never computes,
  requests, or returns a match score, ranking, or hire/no-hire signal of
  any kind — the deterministic `MatchingService` from Phase 8A-2 remains
  the sole, unmodified, authoritative source for `match_score`. See
  Business Rule 9 below.
- **No OCR, no DOCX.** Exactly like Phase 8A-5, a CV with no extractable
  text (scanned/image-only PDF) is handled with a controlled message, not
  a new capability — consistent with this phase's explicit scope
  boundary.
- **Flutter**: `CvSkillSuggestion` (new model, `lib/models/`) — strict
  parsing of `name`/`confidence`/`skill_id`/`is_available`/`already_added`,
  no `parsed_text` field exists on it at all. `CvRepository.extractSkills()`
  (new method) calls the endpoint. `StudentSkillRepository` (new, minimal
  — `lib/features/skills/data/`) adds the one method (`addSkill`) Flutter
  needed to call the existing Student Skill endpoint; no other Student
  Skills UI exists yet, and none was added beyond what this feature
  needs. `StudentCvProvider` gained extraction/add-skills state
  (`isExtracting`, `extractionErrorMessage`, `skillSuggestions`,
  `isAddingSkills`, `addSkillsErrorMessage`) and two methods
  (`extractSkills`, `addSelectedSkills`) rather than a new provider file —
  the smallest architecture consistent with the existing CV feature.
  `StudentCvScreen` gained an "Analyze CV" action on each CV card that
  opens a bottom sheet (`_SkillSuggestionsSheet`) listing suggestions with
  a checkbox per selectable one (disabled when unmatched or already
  added) and an "Add Selected Skills" button.
- **Tested via `tests/Feature/AI/AiSkillExtractionServiceTest.php`** (new
  — every scenario uses `Http::fake()`, the real Groq API is never
  called in automated tests: successful structured response, the correct
  Groq endpoint and `Authorization: Bearer` header, the configured model
  being sent, case-insensitive dedup, the 20-suggestion cap, confidence
  clamping/defaulting, blank-name discarding, catalog mapping (matched
  and unmatched), already-added detection, an empty `skills` array,
  malformed JSON, an invalid top-level structure (including a response
  missing `choices` entirely), 401/403/429/5xx provider responses, a
  connection failure, missing API key configuration, and the
  sensitive-data-never-sent assertion) and
  `tests/Feature/AI/CvSkillExtractionEndpointTest.php`** (new — owning-
  student success, wrong-student 404, wrong-role 403, unauthenticated
  401, incomplete-profile 404, null/blank/legacy `parsed_text` all 422,
  the response never contains `parsed_text`, already-added flagging,
  extraction never creates a `StudentSkill` row or modifies the `CV` row,
  a pre-existing `StudentSkill` is untouched, and a provider failure
  leaves all DB state unchanged).

## Dynamic Skill Catalog + Skill Evidence + Baseline Skill Seeder (Phase 8A-6.1)

Three minimal, tightly-scoped additions on top of Phase 8A-6: a realistic
baseline Skill Catalog, a path for AI-extracted unknown skills to grow the
catalog under Admin control instead of dead-ending, and evidence tracking
on `StudentSkill` so Organizations and Students can see whether a skill is
CV-supported or self-declared. No certificate/credential verification, no
`MatchingService` change, and no evidence-weighted scoring — all
explicitly deferred.

- **`App\Support\SkillNameNormalizer`** — the one normalization rule used
  everywhere a skill name is compared: trim, collapse internal whitespace,
  lowercase (`mb_strtolower`). Deliberately simple and deterministic (no
  fuzzy/Levenshtein/NLP matching) so "AutoCAD"/"autocad"/"AUTOCAD" always
  collapse together without over-matching unrelated names. Used by AI
  catalog mapping, pending-suggestion dedup, and Admin approval's
  duplicate check.
- **`database/seeders/BaselineSkillSeeder`** — a real Laravel seeder (not
  manual SQL), 63 deduplicated skills across Civil Engineering,
  Architecture, Computer/Software Engineering, Electrical/Electronics
  Engineering, Mechanical Engineering, Business, and Healthcare. Each
  entry is `Skill::firstOrCreate(['name' => ...], ['category' => ...])`,
  plus an in-memory normalized-name guard as defense-in-depth against an
  accidental duplicate in the source array. Fully idempotent — running it
  any number of times creates no duplicates, never touches an existing
  Skill row (manual or previously seeded), and respects the existing
  unique-name constraint. Wired into `DatabaseSeeder` (safe to include
  unconditionally, unlike the non-idempotent Test User seeder).
- **`skill_suggestions` table / `SkillSuggestion` model (new)** —
  `name`, `normalized_name` (indexed), `source` (`ai_cv`/`student`/
  `organization` — only `ai_cv` is actively created in this phase; the
  other two exist in the schema for future use, with no UI), `status`
  (`pending`/`approved`/`rejected`, default `pending`),
  `suggested_by_user_id` (nullable), `approved_skill_id` (nullable).
- **`AiSkillExtractionService::mapToCatalog()` now creates-or-reuses a
  pending suggestion for any name with no catalog match**, via
  `SkillSuggestion::firstOrCreate(['normalized_name' => ..., 'status' =>
  'pending'], ['name' => ..., 'source' => 'ai_cv'])`. Scoping the lookup
  to `(normalized_name, pending)` means repeated extractions — by the
  same or a different student — reuse one shared pending row, while a
  previously-rejected name can still be freshly re-suggested later (it no
  longer matches the "pending" half of the lookup). The AI never creates
  a `Skill` directly and never approves its own suggestion. The
  extract-skills response gained `suggestion_id`/`suggestion_status`
  alongside the existing per-skill fields.
- **`Admin\SkillSuggestionController` (new)** —
  `GET /admin/skill-suggestions` (pending only), `PUT .../approve`, `PUT
  .../reject`, admin-role-gated like every other Admin route. `approve()`
  looks up an existing equivalent Skill by normalized name
  (`Skill::findByNormalizedName()`) before creating a new one — if the
  baseline seeder or another Admin created an equivalent Skill between
  suggestion creation and approval, the suggestion links to that Skill
  instead of creating a duplicate (a `QueryException` catch-and-relookup
  fallback around the `Skill::create()` call closes the same race even if
  it happens between the lookup and the insert). `reject()` never creates
  a Skill. Both actions return a controlled `409` if the suggestion was
  already reviewed, rather than silently double-processing it.
- **`cv_skill_evidence` table / `CvSkillEvidence` model (new)** — the
  anti-spoofing evidence record backing `source = cv_ai`:
  `(student_id, cv_id, skill_id)`, `unique(['cv_id', 'skill_id'])`,
  `cascadeOnDelete()` on `cv_id` (so deleting a CV safely removes the
  evidence it produced, with no explicit cleanup code needed). Written
  idempotently (`firstOrCreate`) inside `mapToCatalog()` every time a
  suggested name resolves to a real catalog Skill — this is the smallest
  new table that lets the backend verify, across requests, that this
  exact student's own CV genuinely produced this exact skill through the
  AI extraction flow, per this phase's own "smallest necessary evidence
  record" instruction.
- **`student_skills.source` (new column, enum `manual`/`cv_ai`, default
  `manual`)** — every pre-existing row is therefore automatically and
  correctly classified as `manual` with no backfill migration needed.
  `StudentSkillController::store()` reads `source` from the request
  (default `manual`); when `source = cv_ai`, it requires `cv_id` and
  verifies a matching `CvSkillEvidence` row
  (`cv_id` + `skill_id` + `student_id` — a single indexed lookup, no
  join) before ever storing the row, returning a `422` otherwise. The
  ordinary manual-add path is byte-for-byte unchanged and needs no
  evidence at all. `StudentSkill` uniqueness (`student_id` + `skill_id`)
  is unaffected by `source`.
- **Evidence labels, never "Verified":** `manual` → "Self-declared",
  `cv_ai` → "CV-supported". "CV-supported" means the skill name was found
  in this student's own CV text and identified through the AI extraction
  flow — it does not confirm proficiency, and the product never claims
  otherwise. Organization-facing Application responses now eager-load
  `studentProfile.studentSkills.skill` so each skill's evidence label is
  available without exposing `parsed_text`, AI request/response, or
  confidence.
- **`MatchingService` is untouched.** It never reads the `source` column;
  a `manual` and a `cv_ai` `StudentSkill` with identical `skill_id`/
  `years_of_experience` produce an identical `match_score`. Evidence-
  aware scoring is explicitly out of scope for this phase.
- **Flutter**: `StudentSkillModel` (new, `lib/models/`) carries `source`
  and exposes `isCvSupported`/`evidenceLabel`. `SkillSuggestionModel`
  (new) backs the Admin suggestion list. `StudentSkillsScreen` (new,
  read-only "My Skills" list, routed at `/student/cvs/skills` — nested
  under the existing `/student/cvs` prefix specifically so it's covered
  by `AppRouter`'s existing role/profile-completion redirect gating with
  no new gating code) and `StudentSkillProvider` (new) show each skill
  with its evidence label. `AdminSkillSuggestionsRepository`/
  `AdminSkillSuggestionsProvider` (new) back a "Pending Skill
  Suggestions" section added to the existing `AdminSkillsScreen` (list,
  Approve, Reject — no redesign). `StudentCvScreen`'s AI suggestion sheet
  now shows "Pending catalog approval" instead of "Not in skill catalog"
  for an unmatched suggestion, and disables selecting it exactly as
  before. `OrganizationApplicationDetailsScreen` gained a compact
  "Skills" card showing each applicant skill's evidence label.
  `StudentCvProvider.addSelectedSkills()` always claims `source: 'cv_ai'`
  with the just-analyzed CV's id as evidence — never a client-editable,
  spoofable value.
- **Tested via** `tests/Feature/Admin/BaselineSkillSeederTest.php` (new),
  `tests/Feature/Admin/SkillSuggestionManagementTest.php` (new),
  extensions to `tests/Feature/AI/AiSkillExtractionServiceTest.php` and
  `tests/Feature/AI/CvSkillExtractionEndpointTest.php`,
  `tests/Feature/Student/StudentSkillEvidenceTest.php` (new), and
  `tests/Feature/Organization/ApplicationSkillEvidenceTest.php` (new) —
  covering seeder idempotency/dedup, suggestion creation/reuse/dedup,
  Admin approve/reject including the duplicate-Skill race and the
  already-reviewed `409`, evidence-backed and spoofed `cv_ai` claims
  (including another student's CV/evidence and a deleted-CV cascade),
  `StudentSkill` uniqueness independent of `source`, an unchanged
  `match_score` across `source` values, and Organization response shape
  (evidence present, `parsed_text`/confidence/suggestion data absent).

## Student Education Verification Foundation (Phase 8B-1)

A minimal, Admin-reviewed trust signal: a Student uploads an education
proof document, an Admin approves or rejects it, and an Organization sees
only the resulting status next to an applicant. "Verified" means an Admin
reviewed and approved the document — never a direct university/government/
cryptographic check (see docs/BUSINESS_RULES.md section 9b). Account
registration and Applying are both completely unaffected by this phase.

- **`education_verifications` table / `EducationVerification` model
  (new)** — one row per student (`student_id` unique, referencing
  `student_profiles.id`, the same FK-naming convention as `cvs`/
  `student_skills`/`cv_skill_evidence`): `institution_name`,
  `degree_or_program`, `document_path`, `status` (enum
  `pending`/`verified`/`rejected` — `not_submitted` is never a stored
  value, only the computed default when no row exists),
  `rejection_reason`, `submitted_at`, `reviewed_at`,
  `reviewed_by_admin_id`. A rejected submission is resubmitted by
  updating this same row, not creating a new one — the smallest
  architecture consistent with "one active/latest verification per
  student" being sufficient for v1.
- **`document_path` is hidden at the model level (`$hidden`),
  mirroring `CV::$hidden` for `parsed_text`.** Nobody — Student, Admin,
  or Organization — ever needs the raw filesystem path in a JSON
  response; a document is only ever reached through one of the two
  dedicated, ownership-checked streaming endpoints below.
- **Storage mirrors CV upload exactly**: private `local` disk (never the
  public web root), `education-verifications/{student_id}/{uuid}.pdf`,
  server-generated filename (the client's original filename never
  influences the stored path), max 5 MB, PDF only (`mimes:pdf`, real
  content inspected, not just the extension).
- **`Student\EducationVerificationController`** — `store()` (create or
  resubmit), `show()` (own current state, `not_submitted` as a controlled
  200 rather than a 404 when nothing exists yet), `document()` (stream
  the student's own PDF; deliberately no `{id}` in the route at all,
  since a student only ever has one verification reached through their
  own profile — there is no ID a student could manipulate to reach
  someone else's document). `store()`'s "is the current verification
  already `verified`" check and `show()`/`document()`'s lookup all use a
  **fresh query** (`$studentProfile->educationVerification()->first()`),
  not Eloquent's cached `educationVerification` relation property — the
  cached property would silently return stale data if this student's
  profile object were reused across multiple actions (e.g. within one
  request-handling context, or as this project's own test suite
  discovered when two `post()`/`get()` calls in one test method shared
  the same `Sanctum::actingAs()` user object). This is a freshness-
  critical business check (blocking replacement of a verified document),
  so it can never rely on a cache that might not reflect a concurrent or
  externally-made change.
- **File cleanup mirrors `CVController::store()`'s exact pattern**: the
  new file is always stored and the DB row safely created/updated
  *before* anything else happens; if the DB write then fails, the
  just-stored file is deleted so it never becomes an orphan; on a
  successful resubmission, the *previous* managed file is deleted only
  afterward, and only if its path was actually generated by this
  application under this student's own folder (never an arbitrary/legacy
  path).
- **`Admin\EducationVerificationController`** — `index()` (every
  submission, pending first then oldest-submitted-first within each
  group, with `studentProfile.user` eager-loaded so an Admin never has to
  cross-reference a bare student id), `show()`, `document()`
  (Admin-only, no ownership check needed beyond the existing
  `role:admin` gate — any Admin may review any submission), `verify()`
  and `reject()`. Mirrors `Admin\SkillSuggestionController`'s exact
  review-workflow shape: an already-reviewed submission (`status !==
  'pending'`) always returns a controlled `409` from either action —
  never a silent overwrite of a prior Admin decision. `reject()` requires
  a non-empty `rejection_reason` (`RejectEducationVerificationRequest`).
- **Organization visibility is a single derived, status-only field —
  architecturally impossible to leak more.** `StudentProfile` gained an
  `educationVerification(): HasOne` relation plus an **appended**
  accessor, `education_verification_status`
  (`getEducationVerificationStatusAttribute()`), computed from that
  relation and defaulting to `'not_submitted'`. The raw relation itself
  is hidden (`protected $hidden = ['educationVerification']` — note this
  must be the relation's camelCase method name, not its snake_case
  output key: Eloquent's relation-hiding filters `$this->relations` by
  the original loaded-relation array key *before* the snake_case rename
  happens during serialization, unlike a normal hidden column). Because
  the raw relation can never be serialized, `rejection_reason`,
  `reviewed_by_admin_id`, and `document_path` are structurally
  unreachable from any response that includes a `StudentProfile` —
  including every Organization-facing Application response, which now
  eager-loads `studentProfile.educationVerification` (for the accessor's
  benefit, avoiding N+1) at all four of `Organization\ApplicationController`'s
  response sites (`index`, `indexForOpportunity`, `show`,
  `updateStatus`'s `fresh()`).
- **`MatchingService` is untouched.** Nothing about education verification
  is read by, written to, or influences `match_score` in any way.
- **No Apply gating in this phase.** `Student\ApplicationController::store()`
  is completely unmodified — education verification is introduced purely
  as an Organization-visible trust signal, not an eligibility check. See
  docs/BUSINESS_RULES.md section 9b for the explicit reasoning.
- **Flutter**: `EducationVerificationModel` (new, `lib/models/`) carries
  the Student-facing fields (`institutionName`, `degreeOrProgram`,
  `status`, `rejectionReason`, `submittedAt`, `reviewedAt`) plus
  `isNotSubmitted`/`isPending`/`isVerified`/`isRejected` helpers.
  `EducationVerificationRepository` (new, `lib/features/education_verification/data/`)
  reuses the exact multipart-upload pattern already established by
  `CvRepository.createCv()` (`FormData`/`MultipartFile`, `PickedCvFile`
  reused as-is for the picked PDF) for `submit()`, plus `getStatus()` and
  `downloadDocument()`. `StudentEducationVerificationProvider` (new)
  mirrors `StudentCvProvider`'s state shape (`isLoading`/`errorMessage`
  for the read, `isSubmitting`/`formErrorMessage` for the write).
  `StudentEducationVerificationScreen` (new, routed at
  `/student/education-verification`, reached from a new "Education
  Verification" entry on `StudentHomeScreen`) renders one of four states
  (not-submitted form / pending / verified / rejected-with-resubmit)
  entirely from `status`, reusing `AppTextField`/`SecondaryButton`/
  `PrimaryButton`/`StatusChip` — no new design system. `AdminEducationVerificationsRepository`/
  `AdminEducationVerificationsProvider`/a new Admin screen section follow
  the exact pattern already established by `AdminSkillSuggestionsRepository`/
  `AdminSkillSuggestionsProvider`/`AdminSkillsScreen`'s pending-suggestions
  section (list, per-row busy state, Approve/Reject — Reject here opens a
  small reason-entry dialog first, since a reason is required).
  `OrganizationApplicationDetailsScreen` gained one more compact status
  row next to the existing Applicant/Skills information, driven entirely
  by `ApplicantSummaryModel.educationVerificationStatus` — no document
  access, no rejection reason, from the Organization side.
- **Tested via** `tests/Feature/Student/StudentEducationVerificationTest.php`,
  `tests/Feature/Admin/EducationVerificationManagementTest.php`, and
  `tests/Feature/Organization/ApplicationEducationVerificationTest.php`
  (all new) — covering submission/validation, the spoofing-proof review
  fields, own-state/own-document access (including the discovered
  relation-caching staleness bug, now covered by
  `test_a_verified_verification_cannot_be_resubmitted` and the
  resubmission tests), resubmission-replaces-file and
  verified-blocks-resubmission, Admin list ordering/identity/document/
  verify/reject/already-reviewed-409/no-silent-overwrite, and
  Organization status-only visibility across all four states with
  explicit no-leak assertions for the document path, rejection reason,
  and reviewer id.

## Forgot Password + Reset Password + Email Verification (Phase 8B-2)

A production-style password-recovery flow, plus an audit-driven minimal
email-verification implementation — both built entirely on Laravel's own
framework infrastructure. No parallel auth system, no custom token code,
no second email subsystem.

**Audit findings before implementing anything:** `App\Models\User` already
extends `Illuminate\Foundation\Auth\User`, which unconditionally uses both
`Illuminate\Auth\Passwords\CanResetPassword` and `Illuminate\Auth\MustVerifyEmail`
as traits (confirmed directly from the installed framework source) — so
every method these two capabilities need
(`getEmailForPasswordReset()`/`sendPasswordResetNotification()`,
`hasVerifiedEmail()`/`markEmailAsVerified()`/`getEmailForVerification()`)
already existed before this phase, just unused. `config/auth.php`'s
`passwords` broker config, the `password_reset_tokens` table, and the
`users.email_verified_at` column were all already fully in place from the
original scaffolding. Nothing needed migrating — this phase is almost
entirely wiring, not schema.

### Password Reset

- **`Password::sendResetLink()` / `Password::reset()`** (Laravel's own
  password broker) do the actual work — token generation, hashing,
  storage, the 60-minute expiry, and the 60-second per-email throttle are
  all untouched framework behavior (`config/auth.php`). `AuthController`
  gained two thin methods, `forgotPassword()`/`resetPassword()`, that
  call the broker and translate its result into this project's
  `{success, message, data}` response shape — no token logic of any kind
  lives in application code.
- **`App\Notifications\ResetPasswordNotification`** — a ~15-line subclass
  of `Illuminate\Auth\Notifications\ResetPassword` adding only
  `ShouldQueue` (`onQueue('emails')`, matching every other outbound email
  in this project — see `App\Mail\QueuedTransactionalMail`). Everything
  else (mail copy, reset-URL building) is inherited unchanged.
  `User::sendPasswordResetNotification($token)` is overridden to dispatch
  this subclass instead of the framework's unqueued default.
- **The reset link is re-pointed at Flutter Web, not a Laravel page**, via
  `ResetPassword::createUrlUsing()` registered once in
  `AppServiceProvider::boot()` — the *only* customization to Laravel's
  reset-URL generation. Builds
  `{FRONTEND_URL}/reset-password?token=...&email=...` from
  `config('app.frontend_url')`, the same env var (and the same
  `rtrim(..., '/')` pattern) `EmailService` already uses for every other
  workflow email's CTA link — no hardcoded host anywhere.
- **`resetPassword()` assigns the plain password and lets `User`'s own
  `'password' => 'hashed'` cast do the hashing** — the exact same pattern
  registration already uses; `Hash::make()` is never called explicitly,
  avoiding any risk of double-hashing.
- **Every existing Sanctum personal access token for the user is deleted
  on a successful reset** (`$user->tokens()->delete()`) — the explicit
  security decision from docs/BUSINESS_RULES.md section 1a. A failed
  attempt (wrong/expired/reused token) never touches any token.
- **`ForgotPasswordRequest`/`ResetPasswordRequest`** — thin `FormRequest`s
  mirroring `RegisterStudentRequest`'s own validation shape (`email`
  required|email; `password` required|min:8|confirmed for reset).
- **Routes**: `POST /forgot-password`, `POST /reset-password`, both
  public and role-agnostic (registered alongside `/login`/`/register/*`,
  not inside any role-scoped group), both `throttle:5,1` — the same
  convention `/login` already uses.

### Email Verification

- **`User implements \Illuminate\Contracts\Auth\MustVerifyEmail`** (the
  interface only — the trait providing every method was already
  inherited, see the audit findings above). This single line is what
  makes `email_verified_at` mean anything.
- **`App\Notifications\VerifyEmailNotification`** — the same
  ~10-line-subclass-for-queuing pattern as `ResetPasswordNotification`,
  wrapping Laravel's own `Illuminate\Auth\Notifications\VerifyEmail`.
  `User::sendEmailVerificationNotification()` is overridden to dispatch
  this subclass. Sent once, right after registration —
  `AuthController::registerStudent()`/`registerOrganization()` both call
  it *after* the user (and, for organizations, the transaction creating
  both the user and its profile) is fully committed, exactly where
  `$token = $user->createToken(...)` already runs — never from inside
  `DB::transaction()`'s closure, which would risk a queue worker
  processing the job before the row it references is visible to other
  connections.
- **`App\Http\Controllers\Auth\EmailVerificationController::verify()`**
  handles the signed link a user clicks from their inbox. Deliberately
  **not** behind `auth:sanctum` — a browser navigating to an email link
  carries no Bearer token, so this stateless API can't rely on session
  auth here the way Laravel's default web-scaffolding verification route
  does. Instead, the action is authorized entirely by
  `$request->hasValidSignature()` (Laravel's own signed-URL mechanism,
  covering every route parameter — including `{id}`) plus a per-user
  `hash_equals(sha1($user->getEmailForVerification()), $hash)` check. This
  is exactly as secure as the framework-default `auth`+`signed` combo:
  tampering with `{id}` to target a different account breaks the
  signature, so a "wrong user" attempt fails at the same check as a
  forged/expired link. Always **redirects** (never a JSON error, never a
  raw Laravel error page) to `{FRONTEND_URL}/email-verified?status=success`
  or `...?status=invalid` — a safe UI state either way, matching this
  phase's own "safe UI, not a crash" requirement (the same posture
  `EducationVerificationController`/`CVController` already take for a
  missing physical file, applied here to a missing/invalid signature
  instead).
- **`resend()`** — authenticated (`auth:sanctum, active`), no body (always
  the current user), idempotent/safe if already verified (no email sent,
  still a 200). Throttled `throttle:6,1`.
- **`User::$appends = ['email_verified']`** — a plain boolean accessor
  (`hasVerifiedEmail()`) appended to every `User` JSON response, so
  Flutter never has to parse `email_verified_at`'s nullable timestamp
  itself. `email_verified_at` was already unhidden before this phase, so
  this adds no new exposure.

### Gating decision (deliberately not implemented)

**No route uses the `verified` middleware.** Registration and login both
succeed before verification, exactly as before this phase — Student
application submission, Organization opportunity creation, and every
other existing endpoint remain completely unaffected. This was an
explicit choice (see docs/BUSINESS_RULES.md section 1a): the platform is
near submission, every one of those flows is already complete and tested,
and adding a hard verification gate now would risk destabilizing them for
no corresponding urgent product need. The infrastructure this phase adds
(`email_verified`, resend, the signed link) is exactly what a future
phase would need to add gating on top of — nothing here needs to be
rebuilt to do so later.

### Flutter

- **`AuthRepository`** gained `forgotPassword(email)`,
  `resetPassword({email, token, password})`, and
  `resendVerificationEmail()` — same `POST`/error-handling shape as every
  existing method there. **`UserModel`** gained `emailVerified` (parsed
  from `email_verified`).
- **`AuthProvider`** gained independent loading/error/success state for
  each of the three new actions (`forgotPassword`/`resetPassword`/
  `resendVerificationEmail`), mirroring the existing `login`/
  `registerStudent` state shape — duplicate-submit guarded the same way
  `StudentCvProvider`/`AdminSkillsProvider` guard their own actions.
- **`ForgotPasswordScreen`** (new) — email field, Send Reset Link button,
  always shows the same safe success message regardless of backend
  status, reached from the "Forgot Password?" button already present
  (but previously a no-op) on `LoginScreen`.
- **`ResetPasswordScreen`** (new, routed at `/reset-password`) — reads
  `token`/`email` from the router's query parameters; a missing/malformed
  parameter shows a safe inline error state rather than crashing. New
  Password + Confirm Password fields (`min:8`, matching the backend and
  the existing registration screens' own local validation), Reset
  Password button. On success, shows a confirmation and a "Go to Login"
  action — **does not auto-login**, per this phase's explicit
  instruction.
- **`AppRoutes.forgotPassword`/`AppRoutes.resetPassword`** — both added to
  `AppRouter`'s `_publicPaths` (reachable while unauthenticated) and
  deliberately **not** added to any "authenticated user must leave" redirect
  set, unlike `/login` — an already-authenticated session must never
  bounce a user away from a reset link they opened from their inbox.
- **`EmailVerifiedScreen`** (new, routed at `/email-verified`) — the
  landing spot the backend's `verify()` redirect targets; reads the
  `status` query parameter and shows a static success or failure message
  plus a "Go to Login" action. No signed-link data is ever handled in
  Flutter itself — the backend has already fully verified (or rejected)
  the link by the time this screen renders, per this phase's preferred
  "verify on the signed backend URL, then redirect" architecture.
- **Email-verification UI** — a small, shared `EmailVerificationBanner`
  shown on each of `StudentHomeScreen`/`OrganizationHomeScreen`/
  `AdminHomeScreen` only when `!user.emailVerified`, with a "Resend
  Verification Email" action. No new account-settings screen.

### Mail configuration / real-SMTP status

`MAIL_MAILER=log` in this project's `.env.example` (unchanged by this
phase) — no real SMTP is configured. Every test uses `Notification::fake()`;
no real email was sent or manually verified against a live inbox during
this phase (see the Final Report's explicit manual-test-plan section for
exact steps to do so once real SMTP credentials are available).
`.env.example` needed no new variables — `FRONTEND_URL` already existed
(Phase 7A-4.1) and is reused as-is for the reset link.

---

## Candidate Search + Invitation-to-Apply (Phase 8B-3)

**Audit finding before any code was written**: neither Candidate Search nor
an Invitation concept existed anywhere in either repository — no
migration, model, controller, route, or Flutter file referenced
"invitation" or "candidate search" (confirmed with a project-wide
case-insensitive grep). This phase is a from-scratch build of Flow B, not
a completion of partial work.

**Schema decision — one new table, no changes to `applications`.**
`invitations` (`opportunity_id`, `student_id`, `status` enum
`pending`/`accepted`/`declined` default `pending`, `message` nullable,
timestamps) mirrors `applications`' own `student_id`/`opportunity_id`
shape exactly — no `organization_id` column, since ownership is always
derived through `opportunity.organization_id`, the same pattern
`Application` already uses. `unique(['opportunity_id', 'student_id'])`
deliberately is **not** scoped to `status`: a Student can only ever have
one `Invitation` row per Opportunity for its entire lifetime, which
collapses every duplicate/conflict case (second pending invite, re-invite
after acceptance, re-invite after decline) into one DB constraint plus a
`QueryException` catch — the exact same belt-and-suspenders pattern
`Student\ApplicationController::store()` already uses for its own
`unique(student_id, opportunity_id)`.

**Candidate Search (`Organization\CandidateController::index()`,
`GET /organization/candidates`) is deliberately filter/search-only** —
`StudentProfile::query()` filtered by `whereHas('user', status=active)`
plus optional `name`/`major`/`university`/`graduation_year`/`skill`
LIKE/exact matches. Never calls `MatchingService`; an optional
`opportunity_id` query param (ownership-checked, 404 otherwise) only adds
two booleans (`already_applied`/`already_invited`) computed from plain
`exists()`/`pluck()` queries, never a score. Every result is built as an
explicit, hand-assembled array — never the raw `StudentProfile`/`User`
models — so a future field added to either model can never leak through
this endpoint by accident; the safe-field list (name, university, major,
graduation_year, `education_verification_status`, skills with
`name`/`source`) is enforced by construction, not by a `$hidden` list
that has to be kept in sync.

**Invitation creation (`Organization\InvitationController::store()`,
`POST /organization/invitations`) never creates an `Application` and
never calls `MatchingService`** — it inserts exactly one `Invitation` row.
Checks run in this order: opportunity belongs to this organization (404)
→ opportunity is `open` (422 — a *business-rule* error, not a 404, since
the organization already owns and can see this Opportunity regardless of
its state, unlike a Student-facing enumeration-avoidance 404) → target
Student exists and is active (404, generic message — never reveals
"suspended" specifically) → not already applied (409, specific message)
→ insert (409 on the unique-constraint catch, generic message covering
every remaining duplicate case). A subtle Eloquent gotcha bit this
controller during implementation: `status` has no PHP-side default (only
the database schema default `'pending'`), so the in-memory model
`Invitation::create()` returns doesn't carry it until `->refresh()` is
called — the same class of "in-memory model doesn't reflect a DB-applied
column default" issue documented in an earlier phase for `User.status`.

**Accept (`Student\InvitationController::accept()`,
`PUT /student/invitations/{invitation}/accept`) is the crux design
decision of this phase.** `applications.cv_id` is a `NOT NULL` foreign
key and no CV is ever chosen at invitation time — inventing an
Application here would mean either guessing a CV on the Student's behalf
or inserting a row that violates the schema's own constraint. Neither is
acceptable, so accepting **only** flips `Invitation.status` to
`accepted`. Flutter then routes the Student to the exact same
`StudentOpportunityDetailsScreen`/Apply-flow Flow A already uses for
`invitation.opportunityId` (`POST /opportunities/{opportunity}/apply`,
unchanged), where they pick a CV exactly as a direct applicant would.
From that single point on, Flow B is indistinguishable from Flow A:
same controller, same `MatchingService::analyze()` call, same
organization-facing ranked applicant list (`Organization\ApplicationController`,
Phase 8A-1 ranking, unchanged), same shortlist/reject/assessment/offer
workflow. If a Student already applied independently before responding
to an invitation, accepting still succeeds — it only records consent; no
second Application is attempted, and none is needed. Decline
(`PUT /student/invitations/{invitation}/decline`) is symmetric and even
simpler: flips `status` to `declined`, creates nothing, calculates
nothing. Both endpoints reject a second response to the same invitation
(`409`) and use a controlled `404` — never `403` — for another Student's
invitation, so the response itself never confirms an invitation with that
ID exists.

**Notifications** reuse the existing, previously-unused `opportunity`
`notifications.type` enum value (no migration needed) via three new
`NotificationService` convenience methods —
`notifyInvitationReceived()` (student-facing, on send),
`notifyInvitationAccepted()`/`notifyInvitationDeclined()`
(organization-facing, on response) — following the exact same
"one service owns this concern" pattern every other workflow event
already uses. As of Phase 8B-3.1 (below), `notifyInvitationReceived()`
also queues an email; the accept/decline pair remains in-app only.

---

## Invitation Email Notification (Phase 8B-3.1)

**Reused the existing email architecture end-to-end — no second email
system was introduced.** `notifyInvitationReceived()` now calls a new
`EmailService::sendInvitationReceivedEmail()`, which queues a new
`InvitationReceivedMail extends QueuedTransactionalMail` on the `emails`
queue, rendered from a new `resources/views/emails/invitation_received.blade.php`
Markdown mail template — the exact same three-layer shape
(`NotificationService` convenience method → `EmailService` method →
`QueuedTransactionalMail` subclass) every other workflow email already
follows (see "Queued Email Foundation", Phase 7A-4.1). No changes to
`QUEUE_CONNECTION`, SMTP/mail configuration, or the queue worker command
— `php artisan queue:work --queue=emails,default --tries=3 --timeout=60`
still processes this job exactly like every other workflow email.

**Transaction/duplicate safety needed no new code.**
`Organization\InvitationController::store()` was never wrapped in an
explicit `DB::transaction()` to begin with (a single `Invitation::create()`
insert needs no multi-statement atomicity), and `notifyInvitationReceived()`
is only ever called after that insert succeeds — the pre-existing
try/catch around the unique-constraint `QueryException` (duplicate
invitation) and the explicit already-applied/not-open/wrong-owner checks
already return early, before `notifyInvitationReceived()` is reached, for
every failure/duplicate case. Combined with `QueuedTransactionalMail`'s
`ShouldQueueAfterCommit` mechanism (shared by every workflow email), this
means: a failed or duplicate invitation request queues no email, by
construction, without any new guard code. `tests/Feature/Notifications/WorkflowEmailTest.php`
proves this directly, including a dedicated rollback test (calling
`notifyInvitationReceived()` inside a manually-wrapped `DB::transaction()`
that then throws) mirroring the equivalent Offer-email rollback proof.

**CTA decision**: links to `{FRONTEND_URL}/student/invitations` — the
real, already-shipped (Phase 8B-3) Flutter Web route for the student's
invitation list. There is no single-invitation detail route in this app,
so — exactly like `notifyQuizPublished()`'s CTA pointing at the Quiz
route rather than a nonexistent "quiz details" page — this points at the
list, matching the in-app notification's own `action_url` for the same
event. No Flutter changes were needed or made.

**No changes to `MatchingService`, `CV`/Skills/Education-Verification
architecture, or Auth.** The only near-touch was reusing
`StudentProfile::educationVerification()`/`education_verification_status`
(already public, already safe — Phase 8B-1) for Candidate Search's
education-verification display; no new coupling was introduced.

---

## Multi-Major Opportunity Eligibility (Phase 8B-3.2)

**Schema decision: a dedicated join-style table, not a global Major
catalog.** `opportunity_eligible_majors` (`id`, `opportunity_id` FK
`cascadeOnDelete`, `major_name`, `normalized_major_name`, timestamps,
`unique(opportunity_id, normalized_major_name)` as the explicitly-named
index `opp_eligible_majors_unique` — MySQL's 64-char identifier limit
rejects the Laravel-default auto-generated name for this column pair, so
the short name is required, not stylistic) stores each accepted major as
plain text per Opportunity, deduplicated case/whitespace-insensitively.
No repo-wide Major catalog/lookup table existed before this phase and the
task scope explicitly excluded introducing one — this stays text-based,
matching how `field_of_study` already worked, just now one-to-many. The
legacy single `field_of_study` column on `opportunities` is untouched and
still fully populated/returned as before; nothing was backfilled into the
new table.

**`App\Support\MajorNormalizer`** is a byte-for-byte mirror of the
pre-existing `SkillNameNormalizer` (trim → collapse internal whitespace →
lowercase), reused rather than reinvented, so the two "normalize a
free-text catalog-ish string for comparison" concepts in this codebase
stay consistent.

**`App\Services\OpportunityEligibilityService::isStudentEligible()`** is
the single implementation of the eligibility rule (rule A/B/C — see
docs/BUSINESS_RULES.md section 5b) and is injected into and called from
three controllers — `Organization\CandidateController` (opportunity-scoped
search filtering), `Organization\InvitationController` (invitation guard),
and `Student\ApplicationController` (apply guard) — with zero duplicated
normalization/matching logic in any of them.

**Two Eloquent bugs surfaced and fixed while building this:**

1. **Relation/accessor studly-case collision.** The relation was
   originally named `Opportunity::eligibleMajors()`, alongside an
   `$appends`-based `eligible_majors` attribute backed by
   `getEligibleMajorsAttribute()`. Both `$this->eligibleMajors` (relation
   access) and the `eligible_majors` attribute mutator lookup studly-case
   to the same PHP method-resolution key (`EligibleMajors`), so Eloquent's
   `getAttribute()` routed relation access through the accessor path
   instead, throwing `ErrorException: Undefined property`. Fixed by
   renaming the relation to `eligibleMajorRecords()` everywhere
   (`Opportunity`, `OpportunityEligibilityService`, `CandidateController`,
   `InvitationController`, `OpportunityController`,
   `Public\OpportunityController`), keeping the public JSON shape as
   `eligible_majors` via the accessor only. **Any future relation whose
   name studly-cases to the same string as an `$appends` attribute on the
   same model will hit this same failure mode** — give one of the two a
   different name up front.
2. **Relation-hiding leak.** Once `eligibleMajorRecords` was eager-loaded
   for the eligibility checks above, Eloquent auto-serialized the loaded
   relation (including the internal `normalized_major_name`) into every
   Opportunity JSON response, alongside the intended `eligible_majors`
   accessor array. This is the exact same pattern already documented for
   `StudentProfile.educationVerification` (Phase 8B-1): a loaded relation must
   be explicitly hidden via `$hidden`, and `$hidden` must reference the
   relation's camelCase method name (`eligibleMajorRecords`), not the
   snake_case derived attribute. Fixed with
   `protected $hidden = ['eligibleMajorRecords'];` on `Opportunity`; caught
   by a dedicated test (`test_normalized_values_are_never_exposed_in_the_response`)
   before it could ship.

**One real test regression, found and fixed, not deferred.** Gating
direct Apply (`Student\ApplicationController::store()`) on eligibility
broke exactly one pre-existing test —
`ApplicationAutoMatchingTest::test_a_sparse_profile_with_no_skills_or_major_still_gets_a_real_calculated_score()`
— which applied, via real HTTP, a no-major student to an Opportunity with
`field_of_study => 'Computer Science'` (now correctly rejected by rule B).
A full grep of every `field_of_study`-setting test file and every
`.../apply` HTTP call site confirmed this was the *only* at-risk test —
every other `field_of_study`-setting test creates its `Application` via
direct `Eloquent::create()`, bypassing the controller (and the new guard)
entirely. Fixed by dropping the `field_of_study` override so the
Opportunity is unrestricted, which preserves the test's real intent
(proving `MatchingService` handles a sparse, no-skills-no-major profile)
without weakening the new eligibility guard.

**Apply-side gating was a deliberate consistency decision, not an
optional add-on.** Without it, Invitation would reject an ineligible
Student while the same Student could still walk in through direct Apply —
defeating the purpose of the guard. Both paths call the one shared
`OpportunityEligibilityService`.

**Scope confirmation**: no change to `MatchingService`'s scoring formula
or `match_score`, no AI/embedding-based major matching, no global Major
catalog, no CV/Skills/Education-Verification/Auth changes, no UI redesign
beyond the minimal chip-list addition to the existing Opportunity form, no
retroactive changes to pre-existing Invitations.

---

## Talent Directory + Opportunity Recommended Candidates (Phase O8.1)

**Problem**: Candidate Search (Phase 8B-3) conflated two different jobs
behind one screen — general profile discovery, and deciding who to invite
to one specific Opportunity — with no ranking, and an Invite flow that
made the Organization pick the Opportunity manually after already having
picked the candidate. This phase splits the UI along that seam without
touching the underlying `applications`/`invitations` pipeline: Candidate
Search stays as the "Talent Directory" (pure browsing, Invite action
removed from its UI), and a new endpoint adds real, ranked, per-Opportunity
recommendations with Invite-in-context.

**`MatchingService` extraction, not a rewrite.** `analyze(Application
$application)` only ever read `$application->opportunity` and
`$application->studentProfile` — never any Application-specific column
(`id`, `status`, etc.) — confirmed by a full read before touching it. This
made the refactor mechanical and behavior-preserving: `analyzeSkills()`,
`analyzeField()`, and `analyzeExperience()` all changed signature from
`(Application $application)` to `(StudentProfile $studentProfile,
Opportunity $opportunity)`, `analyze()` became a thin wrapper that
loads-missing the two relations and delegates to a new private
`score(StudentProfile, Opportunity)`, and a new public
`scoreCandidate(StudentProfile $studentProfile, Opportunity $opportunity)`
calls the same `score()` directly — no `Application` required. The full
pre-existing 22-test `MatchingBehaviorTest` suite passed unchanged after
the refactor, proving the formula (Skills 60/Field 20/Experience 20,
proportional redistribution, no fake placeholder) is identical for both
call paths. **There is one scoring implementation, reused by both**, per
the explicit constraint that this phase must never insert a temporary
Application row or fork a second, competing algorithm to calculate a
recommendation score.

**New endpoint, no new tables.** `Organization\OpportunityRecommendationController::index()`
(`GET /organization/opportunities/{opportunity}/recommended-candidates`)
is a pure read: ownership-check the Opportunity (404 pattern, matching
every other Organization-owned-resource endpoint) → eager-load
`opportunitySkills.skill` once → query active `StudentProfile`s with
`studentSkills.skill` eager-loaded once (avoiding the N+1 that would
otherwise come from each candidate's own `scoreCandidate()` call) → filter
through `OpportunityEligibilityService::isStudentEligible()` (the same
gate section 5b already established, reused verbatim, not
reimplemented) → score each survivor via `MatchingService::scoreCandidate()`
→ look up this Opportunity's Applications and Invitations in two total
queries (`keyBy('student_id')`), not one query per candidate → sort by
`match_score` descending, `id` ascending for a deterministic tie-break.
Nothing in this path calls `Application::create()`, `Invitation::create()`,
or mutates any Student/Opportunity attribute — proven directly in
`tests/Feature/Candidates/OpportunityRecommendationTest.php` by comparing
`->fresh()->getAttributes()` before and after the request.

**Invite-from-Recommended-Candidates reuses the existing Invitation
endpoint unchanged.** There is no second "send an invitation" code path —
the Flutter `InviteToApplySheet` (replacing the old opportunity-picker
`InviteBottomSheet` for this context) calls the same
`POST /organization/invitations` as the Talent Directory always did, just
with the Opportunity already known from screen context instead of asked
for via a picker dialog. Every existing duplicate/eligibility/ownership
rule from Phase 8B-3/8B-3.2 applies identically; this phase adds no new
Invitation business rule.

**Access control mirrors the rest of the Organization-owned-resource
surface, deliberately not inventing a new pattern**: `role:organization`
middleware, then an ownership check that 404s (never 403s) for another
organization's Opportunity — the exact convention `CandidateController`'s
own `opportunity_id` filter and every Opportunity-scoped Organization
route already use. A Student hitting this route is rejected by the
role middleware before any Opportunity lookup runs.

**Flutter split**: `CandidateSearchScreen` (kept as the Talent Directory)
lost its Invite button, its opportunity-picker trigger, and the now-fully
orphaned `InviteBottomSheet` (deleted — grep confirmed it had exactly one
caller, this screen) — replaced with a "View Profile" action into a new,
read-only `OrganizationCandidateProfileScreen`. A new
`RecommendedCandidateModel` (deliberately separate from `CandidateModel`,
not an extension of it — it carries match-score/factor data and
this-Opportunity relationship state that only ever exists in a
recommendations context) and `OpportunityRecommendationsProvider` back a
new `OrganizationRecommendedCandidatesScreen`, reached from a new
"Recommended Candidates" entry point on Opportunity Details. No existing
route, provider, or screen for Quiz/Interview/Offer/Notifications/Skills/
Education Verification was touched.

**Scope confirmation**: no ML model training, no new AI provider, no
automatic invitation of top candidates, no automatic shortlist, no bulk
Invite, no recruiter messaging, and no change to the Application pipeline,
`MatchingService`'s formula/weights, `OpportunityEligibilityService`'s
rule, or any pre-existing Candidate Search/Invitation test's expected
behavior — every regression test from Phase 8B-3/8B-3.2 continued to pass
unchanged.

---

## Canonical Location Catalog & Location Eligibility (Phase O8.2)

**Problem**: location existed only as free text — a Student had no
location field at all, and an Opportunity's `location` was a plain
string. "Nablus", "Nablus, Palestine", and "نابلس" could never be
recognized as the same place, and nothing let Work Mode meaningfully
gate location relevance. This phase adds a canonical, ID-based Location
Catalog and reuses this app's own established eligibility-gate pattern
(section 5b's major eligibility) rather than inventing string-matching
heuristics.

**Schema: one new lookup table, one new pivot, one new nullable FK —
nothing destructive.** `locations` (`id`, `canonical_name` unique) is the
fixed catalog, seeded via `BaselineLocationSeeder` (mirrors
`BaselineSkillSeeder`'s own `firstOrCreate`-based idempotency exactly).
`student_available_locations` (`student_profile_id`, `location_id`,
`unique(student_profile_id, location_id)` under an explicit short
constraint name — the same MySQL 64-char identifier limit already
documented for `opp_eligible_majors_unique` hit this table too) is a
plain many-to-many pivot. `opportunities.location_id` (nullable,
`nullOnDelete`) is purely additive: the pre-existing free-text `location`
column is never touched or backfilled for any existing row — a forward
migration, not a rewrite. `Organization\OpportunityController::mirrorLocationName()`
keeps `location` in sync with `location_id`'s canonical name whenever a
*new or edited* Opportunity sets one, so every existing consumer of the
plain-text field (Flutter display, in particular) keeps working
unchanged for both historical and new Opportunities without needing to
know about `location_id` at all. `location_id` is deliberately **not**
`required_if:work_mode,onsite,hybrid` at the validation layer — making it
required would force every existing On-site/Hybrid Opportunity to gain a
canonical location the instant an Organization edits it for any unrelated
reason, which is a bigger, more disruptive change than this phase asked
for; the Flutter form nudges it as effectively required client-side
instead (see below).

**`Opportunity::locationRecord()`, not `location()`.** The relation to
`Location` is deliberately named `locationRecord`, exactly mirroring why
`eligibleMajorRecords()` isn't named `eligibleMajors()` (Phase 8B-3.2) —
the legacy string column already occupies the `location` attribute name,
and Eloquent studly-cases a relation and an `$appends` accessor to the
same PHP method-resolution key. Unlike `eligibleMajorRecords`, though,
`locationRecord` needed no `$hidden`/derived-accessor dance at all:
`canonical_name` has no internal-only field to hide (nothing like
`normalized_major_name`), so it's simply never eager-loaded in a response
path that would leak it — `Organization\OpportunityRecommendationController`
is the one place it's loaded, and only to hand-assemble the two safe
fields (`work_mode`, `location.canonical_name`) the response actually
needs.

**Location eligibility is a second, independent method — deliberately
not folded into `isStudentEligible()`.** `OpportunityEligibilityService::isLocationEligible()`
is new, separate from the major-eligibility method it sits beside, and is
called from exactly one place:
`Organization\OpportunityRecommendationController`. This was a deliberate
scope decision, not an oversight (see docs/BUSINESS_RULES.md section
5c for the full reasoning): folding location into the same gate that
already blocks Apply and Invitation creation would have made every
existing Student — none of whom have any `available_location_ids`
configured yet, since the field is brand new — immediately unable to
apply to or be invited to any On-site/Hybrid Opportunity. Being excluded
from a *recommendation* list carries no equivalent risk (Candidate Search
and direct Apply remain unaffected), so the location gate is scoped
narrowly to where introducing it is safe. The rule itself: Remote never
consults location at all; On-site/Hybrid with a real `location_id`
requires it to appear in the Student's own available locations; On-site/
Hybrid with no `location_id` set is treated as unrestricted (mirrors rule
B of major eligibility); a Student with zero available locations is
excluded, never guessed into either outcome.

**Response shape change on Recommended Candidates (Phase O8.2) — the
first breaking change to that endpoint since Phase O8.1 shipped it.**
`GET .../recommended-candidates`'s `data` changed from a bare array to
`{opportunity: {work_mode, location}, candidates: [...]}`. This was
judged worth doing (rather than smuggling the context in elsewhere)
because the Flutter Recommended Candidates screen needs `work_mode` to
render a *truthful* transparency explanation — the addendum's explicit
requirement that the UI never claim location was considered for a Remote
Opportunity. The backend already loads the Opportunity for the ownership
check, so surfacing two extra hand-assembled fields alongside the
existing ranked array was the natural, minimal-risk place to put this,
rather than relying on a client-side `extra` value that could be stale or
missing on a direct link. Both `CandidateRepository.getRecommendedCandidates()`
(Flutter) and every test asserting on `data` were updated in the same
phase — there was no intermediate state where the two disagreed.

**Required Skills reuse the existing canonical Skill Catalog and
matching formula — no second scoring system.** `opportunity_skills.skill_id`
already referenced `skills.id`; there was never a free-text Skill name on
an Opportunity, so this phase's actual gap was UI-only — Create/Edit
Opportunity had no Required Skills control at all before this phase (a
grep confirmed zero Flutter callers of `OpportunitySkillController`'s
existing `index`/`store`/`destroy` endpoints). Rather than wiring N
individual add/remove calls into the form, a new `PUT .../opportunities/{opportunity}/skills`
sync endpoint (`OpportunitySkillController::sync()`) replaces the whole
Required/Preferred set in one atomic delete-then-reinsert call — the same
"sync the set cleanly" convention `eligible_majors` already established.
A new `GET /organization/skills/catalog` mirrors the Student's own
`GET /student/skills/catalog` so the multi-select offers identical
canonical IDs. `MatchingService` itself was not touched — `Organization\OpportunityRecommendationController`
already called `scoreCandidate()`, which already weighted Required skills
double over Preferred (Phase 8A-2); this phase only makes that
pre-existing weighting reachable from the Organization's own UI for the
first time.

**Flutter**: a new `LocationModel` + `LocationRepository` + a shared
`LocationCatalogProvider` (mirrors `StudentSkillProvider`'s own
cached-catalog-loading shape) back two new pieces of UI — a multi-select
of `FilterChip`s on Student Profile Edit ("Available Work Locations") and
a single-select `DropdownButtonFormField` on Create/Edit Opportunity,
disabled with a real explanatory message (not just greyed out) for a
Remote Opportunity. `OrganizationOpportunitiesProvider` gained the
Required Skills catalog/sync methods directly (no separate provider —
this state is Opportunity-form-scoped, unlike locations which are shared
across two unrelated features). `OrganizationRecommendedCandidatesScreen`
gained a `_MatchingExplanation` widget reading the provider's new
`workMode`/`locationName` fields to render the two-line transparency copy
the addendum specified verbatim, adapted truthfully per Opportunity
configuration.

**Scope confirmation**: no fuzzy/string-similarity location matching (the
canonical-ID model resolves the "Nablus" ambiguity by construction, so
none was needed), no location-based scoring/percentage inside
`match_score` (location is a filter, never a scoring factor), no change
to Apply or Invitation creation's eligibility rules, no admin CRUD UI for
the Location Catalog (seeded only, matching how `BaselineSkillSeeder`
originally shipped before any Admin Skill management UI existed), and no
retroactive rewrite of any historical Opportunity's free-text `location`.

---

## Student Location Profile Patch: Current Location + Multiple Available Work Locations

**Problem**: the previous phase (Phase O8.2) gave a Student multiple
Available Work Locations, but no way to record where they actually live.
The two are genuinely different facts — a Student may live in Jenin but
be willing to work in Nablus and Ramallah — and conflating them (e.g.
treating "lives in" as "willing to work in") would have been actively
misleading. This patch adds the missing "Current Location" as a second,
independent field, and wires both into every place a Student's profile
is created, edited, and displayed.

**Schema: one nullable FK, no new table.** `student_profiles.current_location_id`
(nullable, `nullOnDelete`, references `locations.id`) is purely additive —
an existing Student profile has `current_location_id = null` and remains
fully valid; nothing about login, profile loading, or any other feature
changes for them. No new table was needed (unlike Available Work
Locations, which is genuinely many-to-many and needed the
`student_available_locations` pivot) — Current Location is single-valued,
so a plain FK column on `student_profiles` is the correct, minimal shape.

**`StudentProfile::currentLocation(): BelongsTo`, no relation-naming
collision to work around.** Unlike `Opportunity::locationRecord()` (which
had to avoid colliding with the legacy free-text `location` string
attribute — see the Phase O8.2 section above), `current_location_id` has
no pre-existing `current_location` attribute to collide with, so the
relation is named directly and plainly.

**Both Current Location and Available Work Locations are now settable at
three points, not one.** Phase O8.2 only wired Available Work Locations
into Edit Profile. This patch adds both fields to:
- **Student Profile Setup** (`POST /student/profile`, the Step 2
  onboarding screen reached right after the minimal Step 1 account
  form) — a new "Work Location Preferences" `AppCard` section, entirely
  optional, so a Student can finish onboarding without configuring
  either and add them later.
- **Edit Profile** (`PUT /student/profile`, already handling Available
  Work Locations) — gained the Current Location dropdown alongside it,
  under the same renamed "Work Location Preferences" section header.
- **`StudentProfileController::store()`** needed a genuine change here
  (not just `update()`): it previously mass-assigned `$request->validated()`
  directly with no pivot-sync step at all, since Available Work Locations
  didn't exist at creation time before this patch. It now strips
  `available_location_ids` the same way `update()` already does and syncs
  it after `create()`, while `current_location_id` needs no special
  handling — it's a plain fillable column, so ordinary mass assignment on
  `create()` already covers it.

**Deliberately kept off the minimal Step 1 account form.** Step 1 collects
only name/email/password — the account-creation essentials. Location
preferences are profile data, not account data, so they only ever appear
starting at Step 2 (Profile Setup) — consistent with every other
profile-only field (university, major, graduation year) already being
Step-2-only, not Step-1.

**Recommendation eligibility is entirely unaffected.** `OpportunityEligibilityService::isLocationEligible()`
(Phase O8.2) already read `StudentProfile::availableLocations` exclusively
— `current_location_id` is never consulted there, by design (see
docs/BUSINESS_RULES.md section 5c). Adding Current Location required zero
changes to `MatchingService`, the recommendation controller, or any
eligibility test from the previous phase — all continued to pass
unchanged, confirming the two concerns stayed genuinely decoupled.

**Flutter: one new shared widget file, not a third copy of the same
control.** `CurrentLocationField` and `AvailableLocationsField`
(`work_location_fields.dart`) were extracted from what was previously a
private `_LocationMultiSelect` living only inside
`StudentProfileEditScreen`, so Student Profile Setup (onboarding) and Edit
Profile share the exact same loading/error/empty handling and the exact
same canonical-ID-only selection behavior — never two independently
drifting implementations of "pick a location from the catalog."
`StudentProfileModel` gained `currentLocation` (a nullable `LocationModel`,
parsed from the new `current_location` response field) alongside the
pre-existing `availableLocations`.

**Student Profile display gained a "Work Preferences" section and a new
Profile Readiness item.** Unlike `_ContactSection` (which renders nothing
at all when phone/bio are both unset), the new `_WorkPreferencesSection`
always renders — showing "Not specified" / "Work locations not added"
truthfully rather than disappearing, since the point is for the Student
to discover this data exists and can be configured. A new "Work locations
added" `_ReadinessItem` (pending when `availableLocations` is empty, done
otherwise) follows the same real-signal-not-fabricated-percentage
convention every other readiness row already uses.

**Scope confirmation**: no fuzzy/string-similarity location matching
(the canonical-ID model already solved that in Phase O8.2, and Current
Location never needed matching logic in the first place — it is purely
informational), no change to `isLocationEligible()`'s existing rule or to
which field it reads, no default/inferred location for an existing
Student (never derived from university, phone, email, or nationality), no
new theme system (both new sections render through the existing
persisted `ThemeProvider`, exercised via each screen's pre-existing
dark-mode/theme-toggle tests), and no change to the minimal Step 1
account-registration form.

---

## Interview Contact Details by Type (Phase Final-QA-1)

**Root gap**: `interviews.meeting_link`/`interviews.location` already
existed and were already conditionally *required_if* in
`InteractsWithInterviewRules` for `online`/`onsite` — but no equivalent
field or requirement existed for `phone`, so a Phone interview could be
scheduled and shown to a Student with literally no way to know how to
attend it. This phase closes that one remaining gap and hardens the
existing conditional requirement rather than redesigning anything.

**Schema decision: reuse, plus one new nullable column.** `meeting_link`
(online) and `location` (onsite) were reused as-is — no redundant
columns. Only `interviews.contact_phone` (nullable string) was added
(`2026_08_20_164118_add_contact_phone_to_interviews_table`), after
`location`. Nullable at the DB level like its two siblings: only one of
the three is ever populated for a given interview, and every pre-existing
row must remain valid with none of them set.

**Validation stays in the one existing shared source.**
`App\Http\Requests\Organization\Concerns\InteractsWithInterviewRules::interviewCreationRules()`
already generated both `StoreInterviewRequest` (legacy, unprefixed) and
`StoreAssessmentRequest` (generic, `interview.*`-prefixed) — this phase
only added a `contact_phone` rule beside the pre-existing
`meeting_link`/`location` ones (`required_if:interview_type,phone`,
`url:http,https` added to `meeting_link`), and refactored
`UpdateInterviewRequest` to consume the same trait instead of hand-copying
an equivalent rules array that had silently drifted out of having
`contact_phone` at all. Three requests, one rule source, exactly as the
codebase's own existing `AssessmentCreationParityTest` already asserts by
comparing legacy vs. generic rule arrays directly.

**`App\Support\InterviewContactDetailNormalizer`** is the one place that
keeps only the type-relevant detail populated at persistence time —
called from both `AssessmentService::createInterviewAssessment()`
(create) and `Organization\InterviewController::update()` (full-replace
update). This was necessary, not just tidy: Laravel's
`FormRequest::validated()` only ever contains keys that were actually
present in the request body, so a full-replace `PUT` that switches
`interview_type` from `phone` to `online` and (correctly) omits the now-
irrelevant `contact_phone` would otherwise leave the old phone number
sitting in the database untouched by a plain `$interview->update(...)`.
The normalizer forces the two non-matching fields to `null` unconditionally,
closing that gap for every write path at once instead of duplicating a
type-change check per controller.

**Email/notification plumbing extended, not restructured.** `contact_phone`
was threaded through the exact same chain every other Interview scheduling
field already used — `Interview` model → `NotificationService::notifyInterviewScheduled()`/
`notifyInterviewRescheduled()` → `EmailService::sendInterviewScheduledEmail()`/
`sendInterviewRescheduledEmail()` → `InterviewScheduledMail`/`InterviewRescheduledMail`
→ `emails/interview_scheduled.blade.php`/`emails/interview_rescheduled.blade.php`.
No new Mailable, no new template, no SMTP/queue configuration change — one
new conditional `@if ($interviewType === 'phone' && $contactPhone)` block
was added to each of the two existing Blade templates, mirroring the
`online`/`onsite` blocks already there.

**Flutter: one shared display helper, not three duplicated conditionals.**
`interviewContactDetailLabel()`/`interviewContactDetailValue()`
(`assessment_display.dart`) map an `InterviewModel`'s `interview_type` to
the single relevant label/value pair, reused by both
`organization_application_details_screen.dart` and
`student_application_details_screen.dart` — each screen renders exactly
one attendance-detail row (falling back to `'Not specified'` for a legacy
interview with no value), instead of the pre-existing pattern of
conditionally showing `meeting_link`/`location` as two independent,
possibly-both-empty rows. The Student screen's existing selectable
`_MeetingLinkRow` widget is kept for the online-with-a-real-link case only
(useful specifically because a link is copyable); phone/onsite, and a
missing online link, fall through to the same shared helper as the
Organization screen.

**Scope confirmation**: no new video-calling/Zoom/Google Meet
integration, no automatic phone dialing, no maps/geocoding, no calendar
integration, no `url_launcher` (or any other) dependency added — the
Meeting Link remains display/copy-only, matching the project's existing
"no large dependency for this" convention documented on
`_MeetingLinkRow`'s own doc comment. No change to Candidate Search,
eligible majors, Invitations, Application eligibility, `MatchingService`,
`match_score`, Skills, CV AI, Education Verification, Auth, Quiz
architecture, Offer architecture, SMTP/queue configuration, role
permissions, or general UI design. No pre-existing Interview rows were
invalidated or backfilled.

---

## Matching Formula Audit & Unified Recommendation Score (Phase O8.2 audit)

**Problem this closes**: a Recommended Candidates row could show `Major is
eligible`, a partial Skills match, and still land on `0%` overall — with
nothing in the response explaining why, making "Match" look like it might
secretly mean "Skills Match" only, or like eligibility passing should have
guaranteed a nonzero score.

**Audit finding (this phase's actual work was the audit, not a rewrite)**:
Recommended Candidates already called the exact same
`MatchingService::scoreCandidate()` core Application Match Analysis uses
(`OpportunityRecommendationController::index()`, wired since Phase O8.1 —
see that section above). There was no second, simplified, skills-only
formula anywhere in the codebase. The `0%` result was the real, correctly
computed output of the genuine 60/20/20 formula (see "MatchingService v1.1"
above): Skills scored `0` (the required skill was missing), Field/Major was
`null`/unavailable (no `field_of_study` configured on the Opportunity, so
its weight redistributed away rather than defaulting to anything), and
Experience scored a genuine `0` (no `years_of_experience` on file against a
real requirement) — a weighted average of two real zeros is `0`, correctly.
**No weight was changed, no factor was added or removed, and no second
formula was created.** The only real gap was transparency: the response's
`match_breakdown` object (added in the Recommendation Accuracy Patch)
exposed `major_eligibility` (the pre-ranking gate) but never the real,
*scored* Field/Major or Experience factors — so there was no way to see
*why* a major-eligible candidate still scored low.

**Eligibility vs. score — the actual distinction that was under-explained**:
- `OpportunityEligibilityService::isStudentEligible()` /
  `isLocationEligible()` are gates. They decide whether a candidate is
  scored/shown at all. `MatchingService` never reads either result and
  never awards points for passing them.
- `MatchingService::analyzeField()` is a genuinely different, already-
  existing *scored* factor (worth 20 of 100): it compares `major` against
  `field_of_study` — "how relevant is their academic field" — not "are
  they allowed to apply". A candidate can pass the eligibility gate and
  still have this factor be `0` (mismatched) or `null` (unavailable,
  because `field_of_study` is optional and often simply not set — see
  "Multi-Major Opportunity Eligibility" above on why `field_of_study` is
  deliberately never an eligibility fallback).
- These were already architecturally separate before this phase (see
  Phase 8B-3.2's own note on this in docs/BUSINESS_RULES.md section 5b).
  What was missing was surfacing the *second* one (the real score factor)
  in the recommendation explanation, alongside the first (the gate).

**The fix — `OpportunityRecommendationController`, purely additive**:
`match_breakdown` gained `major_field_match` (`matched`/`not_matched`/
`not_applicable`, a truthful label over the real `field_match_score` —
`fieldFactorStatus()`) and `experience_match` (`full_match`/
`partial_match`/`no_match`/`not_applicable`, over the real
`experience_match_score` — `experienceFactorStatus()`), plus the raw
`field_match_score`/`skills_match_score`/`experience_match_score` repeated
inside the breakdown object so it is self-contained for the UI. Both
classifier methods are pure presentation over values `MatchingService`
already computed — neither method performs a new comparison or
introduces a weight. `major_eligibility` is retained, now with an explicit
doc comment on why it must never be conflated with `major_field_match`.

**Null vs. zero (audited, not changed)**: `skills_match_score`/
`field_match_score` were already correctly nullable (unavailable ≠ zero) —
verified by pre-existing tests. `overall_match_score`/`match_score` can
never genuinely be "unavailable" in the current architecture: `MatchingService`
returns a real `0.0` fallback only when *literally nothing* is scoreable,
and `experience_level` is a required, non-nullable Opportunity column, so
the Experience factor is always scoreable — there is no code path today
where the top-level Match is null. A `match_available` field was
deliberately **not** added, since it would always be `true` today and adding
one anyway would misleadingly imply a state the current formula cannot
actually produce; this is documented rather than fabricated.

**Application Match consistency**: `MatchingBehaviorTest`/the new
`MatchingFormulaAuditTest` confirm Recommendation and Application Match
Analysis produce byte-identical scores for the same Student/Opportunity
pair when their real inputs (major, `studentSkills`, `field_of_study`,
`experience_level`) are equal — because both call paths funnel through the
one private `score()` method. The two CAN legitimately differ only if the
underlying data changes between the two calls (e.g. the Student adds a
skill, or the Organization edits the Opportunity's Required Skills) —
never because of two different formulas.

**Scope confirmation**: no change to `MatchingService`'s weights, formula,
or any existing factor's mechanics; no fake `Application` created to
compute a Recommendation score (unchanged since Phase O8.1); no change to
`OpportunityEligibilityService`; no change to the Remote/On-site/Hybrid
location rule (section 5c/9); Flutter's `MatchBreakdownModel` and the "Why
X%?" expansion were extended additively (new fields, new lines), the
existing card layout/collapsed state is unchanged.

---

## Opportunity Academic Matching Cleanup

**Problem this closes**: a Recommended Candidates row could read "Major /
Field: does not match this opportunity's field of study" for a candidate
whose major was a genuine, exact entry in the Opportunity's own Eligible
Majors list — because the Matching Formula Audit's `major_field_match`
factor (above) compared `major` against the legacy free-text
`opportunity.field_of_study`, an independent, unvalidated field that can
contain anything (the reported case: `field_of_study = "ccc"`) regardless
of what `eligible_majors` actually says. The eligibility gate
(`major_eligibility: "eligible"`) was correctly passing, but the adjacent
scored factor was contradicting it in the same response — confusing and,
for a genuinely well-matched candidate, actively wrong.

**Product decision: remove `field_of_study` from active product logic
entirely, rather than repair the comparison.** Two repair options were
considered and rejected: (a) compare `major` against `eligible_majors`
instead of `field_of_study` inside the scoring factor, or (b) keep both
signals but make eligibility win on conflict. Both were rejected for the
same reason spelled out in this phase's own instructions: **every
candidate reaching `MatchingService::score()` has already passed
`OpportunityEligibilityService::isStudentEligible()`** — the real,
canonical, `eligible_majors`-backed gate (section 5b). Rebuilding the
scored factor on that same canonical data would make it a constant `100`
whenever `eligible_majors` is configured (nothing left to differentiate
between two already-eligible candidates) or have nothing to compare when
it isn't — i.e. a disguised, double-counted restatement of a decision
already made, not a genuine second signal. The instruction was explicit:
*"Do NOT blindly double-count Major. Eligible Major is already an
eligibility gate."* So the Field/Major **scoring** factor is removed
outright, and `major_eligibility` (the real gate every listed candidate
already passed) becomes the sole academic signal, both for eligibility
(unchanged, section 5b) and for explanation (changed, see below).

**`opportunities.field_of_study` itself is not dropped.** The migration/
column, and every historical Opportunity's stored value, are left exactly
as they were — this phase is a matching/UI cleanup, not a data migration.
`Opportunity::$fillable` carries a new `@deprecated` doc comment marking
the column legacy, and `eligibleMajorRecords()`'s own doc comment now
states it is the sole authoritative academic signal. The one legitimate
remaining reader of `field_of_study` is the entirely separate, unrelated
Student-facing public "browse opportunities by field of study" search
filter (`GET /api/opportunities?field_of_study=...`,
`StudentOpportunitiesProvider.fieldOfStudy`,
`opportunity_filter_sheet.dart`) — a keyword/discovery feature, not a
matching input, and explicitly out of scope here; it was left completely
untouched.

**`MatchingService` v1.1 → v1.2**: `FIELD_WEIGHT`, `analyzeField()`,
`normalizeForComparison()`, and `containsAsWholeSegment()` are deleted
outright (not deprecated/dead code left in place). `score()` now computes
only Skills (60) and Experience (20) and calls the same, unmodified
`weightedAverage()` redistribution helper over just those two — so
`overall = (skills*60 + experience*20) / 80` when both are scoreable,
falling back to whichever single factor is scoreable (weight 60 or 20)
exactly as the pre-existing redistribution logic already handled any
other unscoreable factor, with no new branch added for this change.
**Weight change, stated exactly**: v1.1 was Skills 60 / Field-Major 20 /
Experience 20 (a 100-weight denominator when everything was scoreable);
v1.2 is Skills 60 / Experience 20 (an 80-weight denominator). A candidate
who is Skills-100/Experience-100 now scores `100` exactly as before
(ratios are unaffected by the shared denominator canceling out); a
candidate who was previously being pulled toward/away from 100 by the
Field/Major factor's `100`/`0`/redistributed-away behavior now has one
fewer term altogether — verified in `MatchingBehaviorTest`/
`MatchingFormulaAuditTest` (see below) rather than left as an unverified
claim.

**`OpportunityRecommendationController`**: `match_breakdown` drops
`major_field_match`/`field_match_score` entirely (`fieldFactorStatus()`,
the classifier the Matching Formula Audit added, is deleted along with
them) — the response no longer has either key at any level.
`major_eligibility` is unchanged and remains the sole academic field in
the breakdown. `experienceFactorStatus()`/`location_eligibility` are
untouched. The Application Match Analysis endpoints
(`POST/GET .../analyze`, `.../analysis`) lose `field_match_score` the same
way, for the same reason — one shared `MatchingService::score()`
implementation, one shared removal.

**Flutter**: `OpportunityModel.fieldOfStudy` is deleted (the model no
longer parses `field_of_study` from any Opportunity response at all —
a legacy response that still includes the key simply has it ignored, not
an error). `OpportunityRepository.createOpportunity()`/
`updateOpportunity()`/`_buildPayload()` no longer accept or send
`field_of_study` — Create/Edit Opportunity forms cannot write it even if
a field for it existed, and no field for it exists any more:
`OpportunityFormScreen` had its "Field of Study (optional)" input removed
outright, and both the Organization and Student Opportunity Details
screens dropped their Field of Study display row. `MatchBreakdownModel`
loses `majorFieldMatch`/`fieldMatchScore`; the Recommended Candidates "Why
X%?" expansion's first line changed from a Field/Major comparison to a
flat "Major is eligible" positive fact sourced from `majorEligibility` —
matching the backend's own re-framing of that field as a gate, never a
score. `MatchAnalysisModel`/`RecommendedCandidateModel` lose
`fieldMatchScore`, and the Application Details screen's factor grid drops
its "Field / Major Match" row (`matchFactorLabels` no longer has a
`'field'` entry) for the same reason — the backend simply stopped sending
the field there is nothing left to render.

**Eligible Major chip contrast fix (Organization Opportunity Details,
`_RequirementsCard`)**: reported as "too dark / low contrast" in dark
theme. Root cause, found by reading `AppColors`' light/dark token
definitions: the chip used `AppStatusType.primary`
(`AppColors.primaryContainer`/`AppColors.primaryDark`), whose dark-theme
pair (`#1E3A5F` background / `#1E3A8A` foreground) is two near-identical
dark navy blues — near-zero contrast — while its light-theme pair is fine.
`AppStatusType.info` (`AppColors.infoBackground`/`AppColors.info`) has a
genuinely contrast-safe pair in **both** themes (already proven correct on
the Student-facing Eligible Majors chip, which was left unmodified as
precedent). Fixed by switching only the Organization-side Eligible Major
chip's `type` from `primary` to `info` — no hardcoded one-theme-only
color, reusing the existing `StatusChip`/`AppStatusType` design system
exactly as instructed. The adjacent Required Skill chip on the same card
already used `info` and needed no change.

**A known, deliberately out-of-scope finding**: `AppStatusType.primary`'s
dark-theme contrast defect is systemic, not local to this one chip — it is
used in 18+ other call sites app-wide (admin screens, application details,
organization home, opportunity cards, etc.), and the Student-facing
Opportunity Details screen's Required Skill chip has the identical
unfixed issue. Fixing only the specifically reported chip (and its direct
sibling on the same card) was a deliberate scope decision to avoid an
unreviewed, untested blast radius across many unrelated screens — flagged
here, and in this phase's own final report, rather than silently
ignored or unilaterally fixed.

**Scope confirmation**: Required Skills logic is completely unchanged
(same canonical `opportunity_skills` relation, same `OpportunitySkillSyncService`);
Remote/On-site/Hybrid location eligibility (section 5c/9) is unaffected;
`eligible_majors`/`OpportunityEligibilityService::isStudentEligible()`
(section 5b) is unaffected — this phase only removed a *scoring* factor
that sat downstream of that already-passed gate; no `opportunities` table
migration was run and no historical Opportunity's `field_of_study` value
was touched, cleared, or backfilled.

---

## Recommendation Match: Major Must Contribute to Total Score (v1.3)

**Problem this closes**: the Opportunity Academic Matching Cleanup (above)
correctly removed the false-negative-prone `field_of_study` Field/Major
factor, but its replacement -- a flat, unscored `major_eligibility:
"eligible"` fact -- left a genuinely major-eligible candidate with zero
matched Skills and zero Experience scoring a contradictory `0%`: "✓ Major
is eligible" sitting right next to a `0%` "Match" the eligibility fact
played no part in. Product decision: the percentage labeled "Match" must
represent **total** candidate compatibility, of which academic fit is a
genuine pillar, not a fact that vanishes from the number the instant it
clears a gate.

**Explicit, deliberate exception to the Cleanup's own reasoning -- not a
reversal of its `field_of_study` finding.** The Cleanup's core finding
still holds and is unchanged: `field_of_study` is unvalidated free text,
independent of `eligible_majors`, and must never drive eligibility,
scoring, or explanation. What changes is the "don't score Major at all"
conclusion drawn from *that* finding. The instruction for this phase was
explicit: audit the existing canonical formula, do not invent arbitrary
weights, and do not blindly double-count Major as a fresh, unrelated
concern -- rebuild it as a real, deliberate exception to the "a gate and
its score must never overlap" principle, understanding exactly what that
overlap means.

**`MatchingService` v1.2 → v1.3**: a new private `analyzeMajor()` method
and `MAJOR_WEIGHT = 20` constant. Weights: Skills 60 / Major 20 /
Experience 20 (the same 60/20/20 split v1.1 had, but Major's *data
source* is now canonical, not `field_of_study`) -- literally the "audit
the existing formula, don't invent a new weight" instruction taken
seriously: 20 is not picked arbitrarily; it is the weight the Cleanup
zeroed out, restored to its own factor's slot now that a real,
non-double-counting replacement exists. `analyzeMajor()` compares
`studentProfile.major` against `opportunity.eligibleMajorRecords`
(reusing `MajorNormalizer`, the exact same normalization
`OpportunityEligibilityService::isStudentEligible()` already uses) --
`null` (unavailable, weight redistributes) when the Opportunity has no
Eligible Majors configured at all (rule B, unrestricted -- nothing to
compare against); `100.0` when the Opportunity has Eligible Majors
configured (rule A) and the Student's major matches one; `0.0` when
configured but the major is blank or matches none. The `0.0` branch is
defensive/unreachable through Recommended Candidates or a new
Application (the identical comparison already gated entry as
eligibility) -- it is reachable only for a stale Application Match
Analysis recalculated after the Student's major changed after they
applied, which is the honest, correct behavior for that edge case, not a
bug.

**The gate and the score are still two separately-computed things --
this is the crux of why this isn't the "disguised double-count" the
Cleanup ruled out.** `OpportunityEligibilityService::isStudentEligible()`
is checked *before* `MatchingService::score()` is ever called, gates
whether a candidate is scored/shown at all, and is never read by
`MatchingService`. `analyzeMajor()` is a separate computation inside
`score()` that happens to compare the same two pieces of data. For a
candidate reached through the normal, gated path, both will agree
("eligible" / "matched", `100.0`) -- that overlap is now an accepted,
explicit product tradeoff (major compatibility genuinely counts toward
total Match, even though every listed candidate already cleared it as a
prerequisite), not an oversight the way a `field_of_study`-based factor
independent of the real gate was.

**Weight change, stated exactly**: v1.2 was Skills 60 / Experience 20 (an
80-weight denominator when both scoreable); v1.3 is Skills 60 / Major 20
/ Experience 20 (a 100-weight denominator when all three are scoreable,
80 when Major alone is unavailable on an unrestricted Opportunity). A
candidate who is Skills-100/Experience-100 on a restricted, matched
Opportunity still scores `100` (ratios unaffected). A candidate with
Skills-0/Experience-0 on a restricted, matched Opportunity -- the exact
"Allam" case that originally motivated the Matching Formula Audit -- now
scores `(0*60 + 100*20 + 0*20) / 100 = 20.0`, not `0.0`: the real,
calculated weighted average now genuinely reflects that Major
contributed real, positive compatibility, verified in
`MatchingFormulaAuditTest` rather than left as an unverified claim.

**`OpportunityRecommendationController`**: `match_breakdown` gains
`academic_match` (`matched`/`not_matched`/`not_applicable`, a truthful
label over `major_match_score` -- `academicMatchFactorStatus()`, mirroring
`experienceFactorStatus()`'s pattern) and repeats the raw
`major_match_score` inside the breakdown for self-containment, alongside
the unchanged `major_eligibility`. Both endpoints (`GET
.../recommended-candidates` and `POST/GET .../analyze`/`.../analysis`)
gain a top-level `major_match_score` the same way `skills_match_score`/
`experience_match_score` already existed -- one shared `MatchingService`
factor, two consuming endpoints, never two formulas.

**Flutter**: `MatchBreakdownModel` gains `academicMatch`/`majorMatchScore`
alongside the unchanged `majorEligibility`. The Recommended Candidates
"Why X%?" expansion's first line changed from a flat "Major is eligible"
fact to "Major matches an eligible major" -- sourced from the real,
scored `academicMatch`, so the line now genuinely reconciles with the
number next to it, rather than sitting beside a score it played no part
in. `MatchAnalysisModel`/`RecommendedCandidateModel` gain
`majorMatchScore`; the Application Details screen's factor grid gains a
"Major Match" row (`matchFactorLabels['major']`, deliberately not
`'field'`, to avoid ever implying a `field_of_study` dependency) between
Skills Match and Experience Match.

**Scope confirmation**: Required Skills logic is completely unchanged;
Remote/On-site/Hybrid location eligibility (section 5c/9) is unaffected
and still contributes nothing to the score; `field_of_study` remains
completely unused by any factor -- this phase never reads it, and no
`opportunities` table migration was run; `OpportunityEligibilityService`
itself is byte-for-byte unchanged (still the sole eligibility authority,
still checked independently of and before `MatchingService`).

---

## Candidate Opportunity Preferences + Final Recommendation Match Formula (v2.0)

**Problem this closes**: OpportunityHub had no way for a Student to say
*which kind* of Opportunity (Job vs. Internship vs. Volunteer vs.
Scholarship vs. Competition) they actually want to be recommended for --
a Student only interested in internships could be recommended for a
full-time Job with no way to express that mismatch. Separately, the
formula's product-approved final weights needed to be fixed exactly
(never invented ad hoc), Experience needed to be removed entirely, and
Location needed to become a genuine scoring factor for On-site/Hybrid --
not just an eligibility gate.

**1. `StudentProfile.interested_in`** -- a nullable JSON array column
(`2026_08_30_090000_add_interested_in_to_student_profiles_table`),
validated against a new single shared vocabulary,
`App\Support\OpportunityType::ALL` (`job`/`internship`/`volunteer`/
`scholarship`/`competition` -- the exact same values
`Opportunity.opportunity_type` already validated against inline; both
`StoreOpportunityRequest`/`UpdateOpportunityRequest` and
`StoreStudentProfileRequest`/`UpdateStudentProfileRequest` now reference
this one class via `Rule::in()`, so the vocabulary can never drift
between the two models). Required (`min:1`) on Student Profile Setup;
`sometimes`+`min:1` on Edit Profile (existing selection untouched if
omitted, never clearable to empty once present) -- the identical
`eligible_majors` convention. Never backfilled onto an existing profile;
`null` remains a fully valid, readable state (`getProfile()` and every
existing profile-loading path are otherwise untouched).

**2. `OpportunityEligibilityService::isTypeInterestEligible()`** -- a new
method, styled identically to the pre-existing `isLocationEligible()`:
Recommendation-only (never gates Apply or Invitation creation, per this
phase's own instruction to preserve those flows), and a Student with no
`interested_in` recorded (`null` or `[]`) is treated as unrestricted,
mirroring every other "nothing configured" rule already in this service
(`isStudentEligible()` rule B, `isLocationEligible()`'s unconfigured-
location case). `OpportunityRecommendationController::index()`'s
candidate filter now checks all three axes together: type interest, then
the pre-existing major and location gates.

**3. `MatchingService` v1.3 -> v2.0**: the weight table is no longer a
single flat set of constants -- `WORK_MODE_WEIGHTS` maps `work_mode` to
its own nominal weights (`remote => [skills: 70, major: 30]`,
`onsite`/`hybrid => [skills: 60, major: 25, location: 15]`), matching the
spec's literal product-approved numbers exactly (not invented). A new
`analyzeLocation()` -- styled identically to `analyzeMajor()` -- compares
the Opportunity's `location_id` against the Student's own
`availableLocations`; for Remote, it is never even called, so Location
can never enter the weighted average, be redistributed into, or appear
as a phantom `0`. `analyzeExperience()`/`EXPERIENCE_LEVEL_YEARS`/
`EXPERIENCE_WEIGHT` are deleted outright (the same "delete, don't
deprecate" precedent every prior factor removal in this project has
followed) -- `opportunities.experience_level` remains an untouched,
readable column, simply never read by this class again.

**4. Points, not raw percentages, are the client contract.** A new
private `weightedContributions()` replaces the old `weightedAverage()`:
it returns, per component, its already-weighted point contribution
(`score * weight / totalWeight`, rounded to 2dp) alongside the total
weight actually used (post-redistribution, so it only differs from the
nominal weight when a sibling factor was unscoreable). `overall_match_score`
is defined as the literal sum of these already-rounded contributions --
not an independently-rounded weighted average -- which is what guarantees,
by construction rather than convention, that a client's "Why this
match?" breakdown can never display numbers that fail to add up to the
displayed total (verified directly in
`MatchingFormulaAuditTest::test_..._reconciles_with_the_reported_total_match_score`,
for both an On-site case with all three factors and a Remote case with
two).

**5. `OpportunityRecommendationController`/`ApplicationAnalysisController`**:
`match_breakdown` (and the top-level candidate/analysis row) gains
`major_weight`/`major_contribution`, `skills_weight`/`skills_contribution`,
and `location_match_score`/`location_weight`/`location_contribution`
(replacing the deleted `experience_match`/`experience_match_score`
entirely). `location_eligibility`'s existing three-state label
(`not_considered`/`unrestricted`/`matched`) is unchanged and now sits
alongside the new location score/weight/contribution triple. The
Recommended Candidates response's `data.opportunity` object gains
`opportunity_type`, so the Flutter header can render real, dynamic
"these candidates are interested in {type} opportunities" copy without
depending on the caller to have separately passed it through navigation.

**6. `Organization\CandidateController::index()`** (Talent Directory) now
also returns each candidate's own `interested_in` -- purely informational
(this endpoint is profile-browsing only and never itself filters by it),
consistent with every other already-safe field this endpoint exposes.

**7. Flutter**: `InterestedInField` (new, shared) is a plain `Wrap` of
`FilterChip`s over the fixed 5-value vocabulary (`opportunityTypeLabels`,
already the single source of truth for Opportunity Type labels) -- used
identically by Student Profile Setup, Edit Profile, and (read-only,
via `StatusChip`) the Student Profile display, Talent Directory candidate
cards, and the Organization Candidate Profile screen. `MatchBreakdownModel`/
`RecommendedCandidateModel`/`MatchAnalysisModel` all gain the new
weight/contribution/location fields and lose every experience field.
The Recommended Candidates "Why X%?" expansion is restructured into three
titled factor sections (Major/Skills/Location), each showing its real
"points / weight" label (e.g. "25 / 25") pulled directly from the
backend response -- Flutter performs zero Match arithmetic anywhere,
matching this phase's explicit "Flutter must never calculate Match
itself" instruction. The screen's header gained a real Opportunity Type
badge and dynamic type-context copy (`_MatchingExplanation`), replacing
the previous fixed-text "Candidates shown here meet this opportunity's
eligibility criteria..." sentence.

**8. Opportunity Type visual contrast (same root cause the Eligible
Major chip was already fixed for)**: the Organization Opportunity
Details Identity card's Opportunity Type chip, and the Student-facing
Opportunity Details Required Skill chip, both switched from
`AppStatusType.primary` (near-unreadable in Dark mode -- see the
Opportunity Academic Matching Cleanup section above for the root-cause
analysis) to `AppStatusType.info`. The Student-facing hero's own
Opportunity Type chip (rendered over a colored gradient background, a
materially different visual context never verified to share the same
defect) was deliberately left untouched, and is flagged as a remaining
gap rather than changed on assumption.

**Scope confirmation**: `OpportunityEligibilityService::isStudentEligible()`/
`isLocationEligible()` are otherwise byte-for-byte unchanged; Required
Skills mechanics (`analyzeSkills()`) are completely unchanged; no
`opportunities`/`applications` table migration was run; Invitation and
direct Apply business rules are completely unchanged (Type interest is
Recommendation-only, exactly like Location); the canonical Location
Catalog architecture (search/create picker, alias resolution) is
unchanged and unreplaced.

---

## Organization Public Profile + Company Updates/Achievements

**Problem this closes**: an Organization had a self-view/edit profile
endpoint (`GET/PUT /organization/profile`) but no way for a Student to
open it, and no way for an Organization to post a professional company
update. OpportunityHub therefore looked like a bare collection of job
forms rather than a real recruitment platform with a company presence.

**1. `organization_profiles.location_id`** (new forward migration,
`2026_09_01_090000_add_location_id_to_organization_profiles_table`) --
a nullable FK into the existing canonical Location Catalog
(`constrained('locations')->nullOnDelete()`), mirroring
`opportunities.location_id`/`student_profiles.current_location_id`
exactly. `OrganizationProfile::$appends = ['location']` exposes it
everywhere this model is serialized as `{id, canonical_name}` (never a
raw ID), via a `getLocationAttribute()` accessor -- the same
hand-built-array pattern `OpportunityRecommendationController` already
uses for its own `location` field. `Admin\OrganizationController::index()`
eager-loads `locationRecord` alongside `user` to avoid an N+1 across the
full organization list.

**2. `organization_posts`** (new table,
`2026_09_01_090100_create_organization_posts_table`) -- `id`,
`organization_id` (FK `organization_profiles`, `cascadeOnDelete()` --
the owning-parent cascade, matching `opportunities.organization_id`),
`title` (nullable string), `body` (required text), timestamps, indexed
on `(organization_id, created_at)` for the newest-first list query.
Deliberately no `image_path` column: audited first, and no *working*
public image-upload pipeline exists anywhere in this app (the `public`
disk is declared in `config/filesystems.php` but was never wired to a
controller and `storage:link` was never run; the only proven upload
pattern, CV/education-verification documents, is *private*
authenticated-download storage, not a public one). Text-only posts ship
this phase; image support is a genuine, reported gap for a later phase.
No likes/comments/followers/shares columns exist anywhere in this
schema -- this is a professional update feed, not a social feed.

**3. `App\Models\OrganizationPost`** -- `belongsTo` `OrganizationProfile`.
`OrganizationProfile::posts()` is a plain `hasMany` (ordering applied by
the controller's own `->latest()`, not baked into the relation, matching
every other ordered `HasMany` in this app).

**4. `Public\OrganizationController`** (new, unauthenticated --
registered alongside `Public\OpportunityController` with no
`auth:sanctum` middleware) -- `show()` and `posts()`, both 404 for an
Organization that isn't `approval_status === 'approved'` (the same gate
`Public\OpportunityController` already applies before showing any of
that Organization's Opportunities, so a pending/rejected Organization
can never be browsed publicly either way). `show()` returns an explicit,
hand-built safe-field array (`id`, `organization_name`,
`organization_type`, `industry`, `description`, `website`, `phone`,
`location`) -- never the raw model, never `user_id`/`approval_status`,
mirroring `Organization\CandidateController`'s own "never serialize the
raw model" doctrine for the analogous Student-facing safety concern.

**5. `Organization\OrganizationPostController`** (new, `role:organization`)
-- `store()`/`update()`/`destroy()` only; there is no owner-facing
*read* endpoint here at all, since `Public\OrganizationController::posts()`
already serves the owner's own posts identically to how a Student sees
them (audited and reused rather than duplicated, per this phase's own
instruction). Ownership is enforced as 404 (never 403) on
`update()`/`destroy()` for a post that exists but isn't the
authenticated Organization's own -- the same "don't confirm another
organization's resource exists" convention every other owned-resource
endpoint in this API already uses. `store()` requires
`org.approved` (mirroring `POST /organization/opportunities`'s own
gate); `update()`/`destroy()` deliberately do not (mirroring that same
route's own asymmetry, so an Organization that was later un-approved can
still manage its own already-published content).

**6. `GET /opportunities?organization_id=X`** -- one new optional filter
on the pre-existing public Opportunity list endpoint
(`IndexPublicOpportunityRequest`/`Public\OpportunityController::index()`),
reusing its existing open+approved-only query rather than a second,
duplicate "this organization's open opportunities" endpoint. This is
what both the owner's and the public Company Profile screen's "Open
Opportunities" section call.

**7. Flutter**: `CompanyProfileScreen` (new,
`lib/features/organization_profile/`) is one shared screen for both the
Organization owner viewing/managing their own profile
(`/organization/profile`, wrapped by `OrganizationOwnProfileScreen`,
which resolves the signed-in organization's own ID from the existing
`OrganizationProfileProvider`) and a Student (or any authenticated role)
viewing any organization publicly (`/organizations/:id`, reachable with
no extra role guard, same as `/notifications`). `isOwner` is computed
client-side purely for which controls to *show*
(Edit Profile/Create/Edit/Delete Update) -- the backend independently
re-enforces the same ownership on every mutation regardless. A new
`OrganizationPublicProfileProvider` (role-agnostic, plain
`ChangeNotifierProvider`, deliberately separate from the router-critical
`OrganizationProfileProvider`) holds whichever organization's profile/
posts/open-opportunities are currently being *viewed* by ID, reset on
screen dispose so a later visit to a different organization never shows
stale data. `student_opportunity_details_screen.dart`'s existing
`_OrganizationCard` (previously explicitly non-tappable, "no such route
exists yet") and the Opportunity Details hero's own organization
identity row are both now tap targets to the new public route.
`OrganizationProfileEditScreen` (new) is the first real write path this
app has ever had for `PUT /organization/profile` (the endpoint already
existed; nothing in Flutter had ever called it before this phase) --
`logo` is deliberately not offered in the form, for the same no-working-
upload-pipeline reason `organization_posts.image_path` doesn't exist.

**Scope confirmation**: no existing Organization/Opportunity/Application/
Invitation/Assessment/Offer endpoint, model, or business rule was
changed; `organization_profiles`' seven pre-existing fields
(`organization_name`, `organization_type`, `industry`, `description`,
`website`, `logo`, `phone`) and their validation are untouched; Admin
organization approval/rejection is untouched.

---

## Company Profile Polish (Opportunity Organization + Safe Closed
Management + Company Logo + Post Images)

**Problem this closes**: the previous phase's Company Profile rendered
every Open Opportunity permanently, pushing Updates & Achievements far
below the fold for any Organization with more than a handful; Closed
Opportunities had no owner-facing home at all; there was no safe way to
permanently remove an old, disposable Closed Opportunity (the one
existing delete path had a real, undiscovered gap -- see point 1); and
neither a real Company Logo nor a post image were possible, since `logo`
was an unused, unvalidated string column and `organization_posts` had no
image field.

**1. Opportunity deletion safety, tightened.** Audited every FK
`opportunities.id` is referenced by: `applications` (`cascadeOnDelete`,
already blocked by the pre-existing `destroy()` check), `invitations`
(`cascadeOnDelete`, **was NOT blocked** -- an Opportunity with
Invitations but zero Applications could silently cascade-delete them),
and `quizzes.opportunity_id` (the Phase 10A.4B shared Quiz Template,
`cascadeOnDelete`, **was NOT blocked** either -- an authored template
with zero Applications so far could vanish silently too). Assessments/
Interviews/Offers/quiz_attempts are all keyed via `application_id`, so
they were already transitively safe once Applications are blocked.
`conversations.opportunity_id` is deliberately excluded from this check
-- it's `nullOnDelete()` by the Messaging MVP's own design (message
history survives regardless), so a Conversation existing is not treated
as a reason to block deletion. `Opportunity::hasRecruitmentHistory()`
(new) is the single source of truth for all of this, also exposed as a
derived `can_delete` boolean (`Opportunity::$appends`) so Flutter can
show an explanatory state up front rather than only reacting to a failed
delete attempt.

**2. Two delete endpoints, deliberately kept separate.** The pre-existing
`DELETE /organization/opportunities/{opportunity}` (still used,
unchanged in every other way, by the Organization Opportunities
management screen) now also enforces `hasRecruitmentHistory()` -- a pure
safety tightening, never a status restriction; it still deletes a
`draft`/`open`/`closed` Opportunity with zero history exactly as before.
A NEW `DELETE /organization/opportunities/{opportunity}/closed`
additionally requires `status === 'closed'` server-side -- this is what
the Company Profile's own "Closed Opportunities" cleanup UI calls, and
is the only place that formal status rule is enforced; adding it to the
generic endpoint instead would have changed a currently-reachable,
legitimate flow (deleting a mistaken `draft`/`open` row) that this phase
must not touch.

**3. `ImageStorageService`** (new) -- the one shared store/url/delete
implementation both the Company Logo and post-image features use, on the
`public` disk (`storage/app/public`, served via the `public/storage`
symlink -- which this phase actually created via `php artisan
storage:link`; the disk was configured but never activated before).
Every filename is server-generated (`Str::uuid()`), matching
`CVController::store()`'s own "the client never chooses where its file
ends up" doctrine; this is also what rules out path traversal, since no
client-controlled path segment exists anywhere in a stored path.

**4. Company Logo.** `organization_profiles.logo` (the pre-existing,
never-used string column) is now a managed path: `POST
/organization/profile/logo` (real multipart upload, `image` + `mimes:`
+ `max:2048`) and `DELETE /organization/profile/logo` (revert to the
initials fallback). `logo` is hidden from serialization and `logo_url`
(the real, full public URL) is appended instead -- and `logo` was
removed from the plain-JSON `PUT /organization/profile` body entirely
(it's no longer a freely-settable string). Replacing a logo deletes the
old managed file only *after* the new one is safely persisted, so a
mid-request failure can never leave the profile pointing at a file that
no longer exists.

**5. Post images.** `organization_posts.image_path` (new nullable
column, new forward migration) -- ONE optional image per post, never a
gallery. `OrganizationPostController::store()`/`update()` accept an
optional `image` file; `update()` additionally accepts `remove_image`
(boolean, only consulted when `image` is absent) for the three-state
"keep existing / replace / remove" UX the spec asks for. Deleting a post
cleans up its managed image; a failed image validation on update leaves
the existing post *and* its existing image completely untouched (the new
file is validated by the FormRequest before the controller ever runs, so
there's nothing to roll back).

**6. Flutter**: the Company Profile's "Open Opportunities" section is now
a real collapsible accordion (`AnimatedSize` + `AppMotion`) showing at
most 4 rows initially with a "View all N" / "Show less" toggle when
there are more; a new, owner-only "Closed Opportunities" section
(collapsed by default) reuses the existing
`GET /organization/opportunities` list (client-side filtered to
`status == 'closed'`, avoiding any change to that shared endpoint), and
renders each row's real `can_delete` state -- a genuinely deletable
closed Opportunity gets a working "Delete Permanently" confirmation
dialog calling the new `/closed` endpoint; one with real history gets a
plain explanatory message instead, never a dead/hidden button pretending
the action doesn't exist. `file_picker` (already a dependency, previously
only used for CV upload) is reused for both the logo picker and the post
image picker -- no new picker dependency added. Public and owner Company
Profile rendering is otherwise identical; owner-only controls (Edit
Profile, Closed Opportunities, Create/Edit/Delete Update, image pickers)
are still purely a client-side `isOwner` render decision, independently
re-enforced server-side exactly as the previous phase already
established.

**Scope confirmation**: Dashboard, Opportunities List, Create/Edit
Opportunity, Opportunity Details, Applicants, Application Details,
Candidate Profile, Recommended Candidates, Quiz, Interview scheduling,
Offer, Notifications, and Admin UI are all unchanged; no Application/
Invitation/Assessment/Quiz/Interview/Offer/notification-history row was
ever deleted or altered by this phase's own testing; match scores are
untouched.

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