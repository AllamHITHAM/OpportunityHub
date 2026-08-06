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

Assessment
  belongsTo Application
  hasOne Interview
  (future) hasOne Quiz
```

- `Application` owns recruitment lifecycle only (`status`: pending,
  reviewed, shortlisted, interview_scheduled, accepted, rejected,
  withdrawn — unchanged by this phase).
- `Assessment` owns the shared assessment lifecycle: `type` (`interview` |
  `quiz`), `status`, `result`, `completed_at`.
- `Interview` owns interview-specific scheduling/outcome detail
  (`interview_type`, `scheduled_at`, `decision`, `rating`, etc.) and
  belongs to `Assessment`, not directly to `Application`.
- An application has at most one assessment today. `quiz` is a valid
  `assessment.type` value in the schema, but no `quizzes` table, quiz
  controller, or quiz UI exists yet — schema-ready only.
- This is a backend-only restructuring: all pre-existing Interview API
  routes, request/response bodies, and status codes are unchanged (see
  docs/API.md section 6); `application.status` is unchanged (see
  docs/BUSINESS_RULES.md section 5).

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
```

- **`App\Services\AssessmentService`** is the single shared write layer,
  following this codebase's existing controller+service convention (see
  `MatchingService` / `ApplicationAnalysisController`). It owns:
  the allowed-source-status check (`shortlisted`/`interview_scheduled`),
  the duplicate-assessment pre-check, the one database transaction,
  `Assessment` + `Interview` creation, and the
  `application.status = interview_scheduled` / `reviewed_at = now()` side
  effect. It does not perform HTTP response construction, does not return
  a `JsonResponse`, and does not perform organization-ownership
  authorization — those stay in each controller, exactly like every other
  controller in this codebase (no Policy classes are used here).
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