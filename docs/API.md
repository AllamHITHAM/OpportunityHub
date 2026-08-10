# OpportunityHub API Reference

## API Style
- REST API, JSON responses only.
- Laravel 12, Sanctum token-based authentication.
- All routes are defined in `routes/api.php`, prefixed with `/api`.
- Flutter will consume these APIs.

## Response Shape

Almost every endpoint returns:
```json
{
  "success": true,
  "message": "Human-readable message",
  "data": { }
}
```

**Known inconsistency (not fixed as part of this documentation pass):** validation failures (HTTP 422) are thrown automatically by Form Requests *before* reaching a controller, and are rendered using **Laravel's default exception shape**, not the `{success, message, data}` envelope above:
```json
{
  "message": "The given data was invalid.",
  "errors": {
    "field_name": ["The field_name field is required."]
  }
}
```
Every other error (401/403/404/409) uses the standard `{success: false, message: "...", data: null}` shape. A frontend must handle both shapes. Flagging this as a real, current behavior — not something this documentation pass changes.

## Common Errors (apply across most protected routes)
- **401 Unauthenticated** — missing/invalid Sanctum token: `{"success": false, "message": "Unauthenticated", "data": null}`.
- **403 Forbidden (role)** — wrong role for the route: `{"success": false, "message": "This action is unauthorized for your account type", "data": null}`.
- **403 Forbidden (inactive account)** — `status != active`: `{"success": false, "message": "Account is not active", "data": null}`.
- **404 Not Found** — resource doesn't exist *or* belongs to another user/organization (deliberately indistinguishable, to avoid leaking existence of other users' data).
- **422 Unprocessable** — validation failure (see shape above) or a business-rule precondition failure using the `{success, message, data}` shape (e.g. deadline passed).
- **409 Conflict** — a state conflict (duplicate, already exists, blocked delete).

---

## 1. Auth

### POST /api/register/student
- Auth: none · Role: none · Middleware: none
- Body: `name` (required, string, max:255), `email` (required, email, unique), `password` (required, min:8, confirmed)
- Success: 201 — `{"data": {"user": {...}, "token": "..."}}`. `role` is always forced to `student` server-side.
- Errors: 422 (validation)

### POST /api/register/organization
- Auth: none · Role: none · Middleware: none
- Body: `name`, `email`, `password` (as above) + `organization_name` (required, max:255), `organization_type` (required, in: company, university, ngo, training_center, government, other), `industry`/`description`/`website`/`logo`/`phone` (nullable)
- Success: 201 — `{"data": {"user": {..., "organizationProfile": {...}}, "token": "..."}}`. `role` forced to `organization`; `organization_profiles.approval_status` defaults to `pending` (never client-settable).
- Errors: 422 (validation)

### POST /api/login
- Auth: none · Role: none · Middleware: `throttle:5,1`
- Body: `email` (required, email), `password` (required)
- Success: 200 — `{"data": {"user": {...}, "token": "..."}}`
- Errors: 401 (invalid credentials), 403 (`status != active`), 422 (validation), 429 (rate-limited after 5 attempts/minute)

### POST /api/logout
- Auth: required · Middleware: `auth:sanctum`
- Success: 200 — revokes only the current token
- Errors: 401

### GET /api/me
- Auth: required · Middleware: `auth:sanctum`
- Success: 200 — `{"data": <authenticated user>}`
- Errors: 401

---

## 2. Student

### GET /api/student/profile
- Auth: required · Role: student · Middleware: `auth:sanctum, active, role:student`
- Success: 200 — the student's own profile
- Errors: 401, 403, 404 ("Student profile not found")

### POST /api/student/profile
- Same middleware as above
- Body: `phone` (nullable, max:20), `university` (nullable, max:255), `major` (nullable, max:255), `graduation_year` (nullable, integer, between:1950,2100), `bio` (nullable, max:2000), `profile_image` (nullable, max:255)
- Success: 201
- Errors: 401, 403, 409 ("Profile already exists"), 422

### PUT /api/student/profile
- Same middleware and body as POST
- Success: 200
- Errors: 401, 403, 404, 422

### GET /api/student/cvs
- Middleware: `auth:sanctum, active, role:student, profile.exists`
- Success: 200 — list of the student's own CVs
- Errors: 401, 403, 404 ("You must create a student profile first")

### POST /api/student/cvs
- Same middleware
- Body: `title` (required, max:255), `file_path` (required, max:2048 — plain string path, no real file upload yet)
- Success: 201
- Errors: 401, 403, 404, 422

### DELETE /api/student/cvs/{cv}
- Same middleware
- Success: 200
- Errors: 401, 403, 404 (not found / not yours), 409 ("Cannot delete a CV that has been used in an application")

### PUT /api/student/cvs/{cv}/default
- Same middleware
- Success: 200 — unsets `is_default` on all the student's other CVs, sets it on this one (only code path allowed to do so)
- Errors: 401, 403, 404

### GET /api/student/skills
- Same middleware
- Success: 200 — list with `skill` relation loaded
- Errors: 401, 403, 404

### POST /api/student/skills
- Same middleware
- Body: `skill_id` (required, integer, exists:skills,id), `level` (required, in: beginner, intermediate, advanced, expert), `years_of_experience` (nullable, numeric, between:0,60)
- Success: 201
- Errors: 401, 403, 404, 409 ("You have already added this skill"), 422

### DELETE /api/student/skills/{studentSkill}
- Same middleware
- Success: 200
- Errors: 401, 403, 404

### GET /api/student/applications
- Same middleware
- Success: 200 — list with `opportunity`/`cv` loaded
- Errors: 401, 403, 404

### GET /api/student/interviews
- Same middleware
- Success: 200 — interviews scoped to the student's own applications
- Errors: 401, 403, 404

### GET /api/student/dashboard
- Same middleware
- Success: 200 — `{"data": {"total_applications": n, "pending_applications": n, "reviewed_applications": n, "shortlisted_applications": n, "accepted_applications": n, "rejected_applications": n, "total_cvs": n, "total_skills": n, "total_interviews": n}}`. Unchanged contract as of Phase 6C-0 — `accepted_applications` still counts `status = accepted` rows exactly as before; only what a *new* `accepted` row means has shifted (see docs/BUSINESS_RULES.md section 5). No `offer_sent_applications` field yet — deferred to Phase 6C-4.
- Errors: 401, 403, 404

---

## 3. Organization

### GET /api/organization/profile
- Middleware: `auth:sanctum, active, role:organization`
- Success: 200
- Errors: 401, 403, 404 ("Organization profile not found")

### PUT /api/organization/profile
- Same middleware
- Body: `organization_name` (required, max:255), `organization_type` (required, in: ...), `industry` (nullable, max:255), `description` (nullable), `website` (nullable, url, max:255), `logo` (nullable, max:255), `phone` (nullable, max:30)
- Success: 200. **`approval_status` is never accepted here** — admin-only.
- Errors: 401, 403, 404, 422

### POST /api/organization/opportunities
- Middleware: `auth:sanctum, active, role:organization, org.approved` (blocks unapproved organizations — 403 "Organization is not approved to publish opportunities")
- Body: `title` (required), `description` (required), `opportunity_type` (required, in: job, internship, volunteer, scholarship, competition), `employment_type` (required, in: full_time, part_time, contract), `work_mode` (required, in: remote, hybrid, onsite), `experience_level` (required, in: no_experience, junior, mid, senior, expert), `education_level` (nullable, in: high_school, diploma, bachelor, master, phd), `field_of_study`/`location` (nullable), `salary_min`/`salary_max` (nullable, numeric, `salary_max >= salary_min`), `application_deadline` (nullable, date, must be today or later), `positions_available` (nullable, integer, min:1), `status` (nullable, in: draft, open, closed)
- Success: 201
- Errors: 401, 403, 422

### GET /api/organization/opportunities
- Middleware: `auth:sanctum, active, role:organization`
- Success: 200 — only this organization's own opportunities
- Errors: 401, 403

### GET /api/organization/opportunities/{opportunity}
- Same middleware
- Success: 200
- Errors: 401, 403, 404 (not found / not yours)

### PUT /api/organization/opportunities/{opportunity}
- Same middleware
- Body: same as POST, except `application_deadline` has **no** "must be in the future" restriction (an already-passed deadline can still be edited/closed)
- Success: 200
- Errors: 401, 403, 404, 422

### DELETE /api/organization/opportunities/{opportunity}
- Same middleware
- Success: 200
- Errors: 401, 403, 404, 409 ("Cannot delete an opportunity that has applications")

### GET /api/organization/opportunities/{opportunity}/skills
- Same middleware
- Success: 200 — skill requirements with `skill` loaded
- Errors: 401, 403, 404

### POST /api/organization/opportunities/{opportunity}/skills
- Same middleware
- Body: `skill_id` (required, integer, exists:skills,id), `is_required` (required, boolean)
- Success: 201
- Errors: 401, 403, 404, 409 ("This skill has already been added to this opportunity"), 422

### DELETE /api/organization/opportunities/{opportunity}/skills/{opportunitySkill}
- Same middleware
- Success: 200
- Errors: 401, 403, 404 (opportunity not yours, or the skill-requirement row doesn't belong to that opportunity)

### GET /api/organization/dashboard
- Same middleware
- Success: 200 — `{"data": {"total_opportunities": n, "open_opportunities": n, "closed_opportunities": n, "draft_opportunities": n, "total_applications": n, "pending_applications": n, "shortlisted_applications": n, "accepted_applications": n, "rejected_applications": n, "total_interviews": n, "completed_interviews": n}}`. Unchanged contract as of Phase 6C-0 — `accepted_applications` still counts `status = accepted` rows exactly as before; only what a *new* `accepted` row means has shifted (see docs/BUSINESS_RULES.md section 5). No `offer_sent_applications` field yet — deferred to Phase 6C-4.
- Errors: 401, 403

---

## 4. Public Opportunities

### GET /api/opportunities
- Auth: none · Role: none · Middleware: none
- Query filters (all optional): `opportunity_type`, `employment_type`, `work_mode`, `experience_level` (enums), `location`, `field_of_study`, `keyword` (partial-match strings), `per_page` (integer, between:1,100, default 15)
- Only returns opportunities where `status = open` AND the owning organization's `approval_status = approved`.
- Success: 200 — Laravel paginator object nested under `data` (i.e. `data.data` is the array of opportunities, alongside `data.current_page`, `data.total`, etc.), each with `organizationProfile` and `opportunitySkills.skill` loaded.
- Errors: 422 (invalid filter value)

### GET /api/opportunities/{opportunity}
- Same auth (none)
- Success: 200
- Errors: 404 (opportunity is draft/closed, or its organization isn't approved — same response as a truly nonexistent ID)

---

## 5. Applications

### POST /api/opportunities/{opportunity}/apply
- Middleware: `auth:sanctum, active, role:student, profile.exists`
- Body: `cv_id` (required, integer, must belong to the authenticated student — fails validation exactly like a nonexistent ID otherwise), `cover_letter` (nullable, max:2000)
- Checks in order: opportunity must be open + organization approved (404 otherwise) → deadline not passed (422) → student must have ≥1 CV (422) → not already applied (409) → create.
- Success: 201 — `status` defaults to `pending`, `applied_at` auto-set by the database.
- Errors: 401, 403, 404, 409, 422

### GET /api/student/applications
- See section 2.

### GET /api/organization/applications
- Middleware: `auth:sanctum, active, role:organization`
- Success: 200 — every application across all of this organization's opportunities
- Errors: 401, 403

### GET /api/organization/opportunities/{opportunity}/applications
- Same middleware
- Success: 200
- Errors: 401, 403, 404 (opportunity not yours)

### GET /api/organization/applications/{application}
- Same middleware
- Success: 200
- Errors: 401, 403, 404

### PUT /api/organization/applications/{application}/status
- Same middleware
- Body: `status` (required, in: reviewed, shortlisted, rejected — **not** `pending`/`withdrawn`). **As of Phase 6B-0, `in_assessment` and `interview_scheduled` are no longer accepted here**, and **as of Phase 6C-0, `accepted` and `offer_sent` are no longer accepted here either** — `in_assessment` and `offer_sent` must only ever be reached through their real domain workflow (Assessment creation, section 7; the future Offer workflow, Phase 6C-1), `interview_scheduled` (deprecated legacy value) can no longer be fabricated with no assessment behind it, and `accepted` now means specifically "the student accepted the Offer" (only the future `Student\OfferController::accept()` may write it — see docs/BUSINESS_RULES.md section 5). Any of these four values in the request body now fails standard `in:` validation (422). Existing rows may still legitimately hold any of them — this restriction is on input only, never on what's stored or returned (see the response note below). `accepted` is consequently unreachable through any current API action until the Offer workflow (Phase 6C-1) exists — intentional, not a bug.
- Success: 200 — also sets `reviewed_at = now()` on every successful call, even if re-setting the same status.
- Errors: 401, 403, 404, 409 ("Cannot change the status of a withdrawn application"), 422

---

## 6. Interviews

**As of Phase 4A-1, an Interview is a detail record hanging off a generic `Assessment` (see section 7) rather than being attached to `Application` directly.** Every route, request body, status code, and business rule documented below is unchanged from before Phase 4A-1 — this phase is a backend restructuring only.

**This route remains fully supported after Phase 4A-2.** `POST .../applications/{application}/interview` internally calls the exact same `App\Services\AssessmentService::createInterviewAssessment()` method the new generic endpoint (section 7) calls — there is no HTTP redirect, no internal self-request, and no second database transaction; the controller only translates the service's outcome into this route's historical response shape/messages, which are unchanged.

**Response shape is fully backward compatible.** Every endpoint below still returns the pre-Phase-4A-1 top-level `data.application` object exactly as before (an `Interview` model's `application` is now a computed/appended attribute resolved through `assessment`, not a real column — see `app/Models/Interview.php`), and **additively** returns a new `data.assessment` object alongside it:

```json
{
  "id": 1,
  "assessment_id": 1,
  "interview_type": "phone",
  "scheduled_at": "...",
  "status": "scheduled",
  "decision": "pending",
  "application": { "id": 5, "status": "in_assessment", "...": "..." },
  "assessment": {
    "id": 1,
    "application_id": 5,
    "type": "interview",
    "status": "scheduled",
    "result": null,
    "application": { "id": 5, "...": "..." }
  }
}
```

`data.application.id` and `data.assessment.application_id` (and `data.assessment.application.id`) always refer to the same application. `data.assessment` never nests an `interview` back inside itself — only `application` is loaded on it, so there is no `Interview → Assessment → Interview` cycle in any response.

### POST /api/organization/applications/{application}/interview
- Middleware: `auth:sanctum, active, role:organization`
- Body: `interview_type` (required, in: onsite, online, phone), `scheduled_at` (required, date), `duration_minutes` (nullable, integer, min:1, default 60), `meeting_link` (required if `interview_type=online`), `location` (required if `interview_type=onsite`), `interviewer_name`/`interviewer_email`/`notes` (nullable)
- Preconditions: application must be `shortlisted` or (legacy) `interview_scheduled` (422 otherwise; `in_assessment` is never an allowed source — see docs/BUSINESS_RULES.md section 5); one interview per application max (409 if one already exists) — enforced via the one-`Assessment`-per-`Application` rule (section 7), not directly on `interviews` any more.
- Success: 201 — in one DB transaction: creates an `Assessment` (`type=interview`, `status=scheduled`), creates the `Interview` under it, and sets the application's **`status = in_assessment`** (Phase 6B-0 — previously `interview_scheduled`; see docs/BUSINESS_RULES.md section 5) and `reviewed_at = now()`. Response `data` is the interview in the shape above.
- Errors: 401, 403, 404, 409, 422

### GET /api/organization/interviews
- Same middleware
- Success: 200 — every interview across this organization's applications, each in the shape above
- Errors: 401, 403

### GET /api/organization/interviews/{interview}
- Same middleware
- Success: 200
- Errors: 401, 403, 404

### PUT /api/organization/interviews/{interview}
- Same middleware
- Body: same schedulable fields as POST (never `decision`/`rating`/`company_feedback`/`status`/`completed_at`)
- Success: 200
- Errors: 401, 403, 404, 422

### PUT /api/organization/interviews/{interview}/complete
- Same middleware
- Body: `decision` (nullable, in: passed, failed, waiting — no `pending`), `rating` (nullable, integer, min:1 only — no fixed upper bound), `company_feedback` (nullable, max:2000)
- Success: 200 — sets `status = completed`, `completed_at = now()`, plus any provided outcome fields, exactly as before. Also mirrors the outcome onto the parent `Assessment`: `status = completed`, `completed_at` copied, `result` set from `decision` (`passed`/`failed`/`waiting`; no decision provided leaves `result = null`). Still does **not** change the related application's status — the organization always makes that call separately via the status endpoint.
- Errors: 401, 403, 404, 422

### DELETE /api/organization/interviews/{interview}
- Same middleware
- Success: 200 — hard delete. Deletes the parent `Assessment` (which cascades to the `Interview` at the database level). If the application's status was `in_assessment` (or, for legacy rows, `interview_scheduled`), it is reverted to `shortlisted` in the same operation, so an application is never left at either of those statuses with no assessment behind it. If the application's status is anything else (e.g. an organization already moved it on to `accepted`/`rejected` independently), it is left untouched.
- Errors: 401, 403, 404, 409 ("Completed interviews cannot be deleted")

### GET /api/student/interviews
- See section 2. Response is in the same shape as above — `data[].application.opportunity` (legacy path, unchanged) and, additively, `data[].assessment.application.opportunity` — **except** each Interview object omits `interviewer_email`, `company_feedback`, `rating`, and `decision` (organization-internal fields; unaffected on the Organization endpoints above). See "Student-visible Interview fields" note under section 7.

---

## 7. Assessments

Added in Phase 4A-1 as the generic entity that lets an organization choose **Interview or Quiz** (or no formal assessment) once an application is shortlisted. **As of Phase 4A-2, `type=interview` can be created through the generic endpoint below**, using the exact same shared workflow (`App\Services\AssessmentService`) as the legacy Interview endpoint (section 6). **As of Phase 6B-1, `type=quiz` creates a real `Assessment` + `Quiz` the same way**, via the sibling `AssessmentService::createQuizAssessment()` — neither type duplicates the other's transaction, status, duplicate, or validation logic. Organization Quiz authoring (adding/editing/removing questions, publishing) and, as of Phase 6B-3, Student Quiz taking (start/submit/auto-grading) are both documented in section 7a below.

An application has at most one `Assessment`. An `Assessment` has `type` (`interview` or `quiz`), `status` (`pending, scheduled, in_progress, completed, declined, cancelled`), and `result` (`null`, `pending`, `passed`, `failed`, or `waiting` — a decision not yet recorded is represented as `null`, not the string `"pending"`).

### POST /api/organization/applications/{application}/assessments
- Middleware: `auth:sanctum, active, role:organization`
- Body (nested, type-specific):
  ```json
  {
    "type": "interview",
    "interview": {
      "interview_type": "online",
      "scheduled_at": "2026-08-10 10:00:00",
      "duration_minutes": 60,
      "meeting_link": "https://meet.example.com/...",
      "location": null,
      "interviewer_name": "Jane Recruiter",
      "interviewer_email": "jane@example.com",
      "notes": "..."
    }
  }
  ```
  or
  ```json
  {
    "type": "quiz",
    "quiz": {
      "title": "Backend Fundamentals",
      "instructions": "Choose the best answer.",
      "time_limit_minutes": 30,
      "passing_score": 70
    }
  }
  ```
  `type` is required (`in:interview,quiz`). `interview` is required when `type=interview`; its fields are validated by the exact same shared rule source as the legacy endpoint's body (`interview_type`, `scheduled_at` required; `meeting_link` required when `interview_type=online`; `location` required when `interview_type=onsite`; `duration_minutes`/`interviewer_name`/`interviewer_email`/`notes` optional) — see `App\Http\Requests\Organization\Concerns\InteractsWithInterviewRules`. `quiz` is required when `type=quiz`: `quiz.title` required, string, max 255; `quiz.instructions` nullable string; `quiz.time_limit_minutes` nullable integer, min 1; `quiz.passing_score` required, integer, 0–100. `questions` is **not** accepted here at all — see section 7a for adding them afterward.
- Preconditions (both types): application must be `shortlisted` or (legacy) `interview_scheduled` (422 otherwise; `in_assessment` is never an allowed source, since a real assessment already exists for it by construction); one assessment per application max (409 if one already exists) — identical rules for both types, enforced by the same service (`AssessmentService`).
- **Unknown `type`** (anything other than `interview`/`quiz`): standard Laravel validation failure (422, `{message, errors}` shape).
- Success (`type=interview`): 201 — creates an `Assessment` (`type=interview`, `status=scheduled`, `result=null`) and its `Interview` in one transaction, and sets the application's **`status = in_assessment`** and `reviewed_at = now()`. Response `data` is the `Assessment`, with `application` and `interview` nested — **not** `data.interview.application` (deliberately hidden at this response's call site via `makeHidden('application')`, since it would just duplicate `data.application` one level down; the legacy Interview endpoints are unaffected and keep exposing it).
- Success (`type=quiz`): 201 — creates an `Assessment` (`type=quiz`, `status=pending`, `result=null`) and its `Quiz` (`status=draft`, no questions) in one transaction, and sets the application's **`status = in_assessment`** and `reviewed_at = now()` — the exact same Application-status side effect as `type=interview`, from the same `AssessmentService` transition helper. Response `data` is the `Assessment`, with `application` and `quiz` (with `quiz.questions`, always `[]` at creation) nested. See section 7a for the full quiz-lifecycle shape.
- Errors: 401, 403, 404 (`"Application not found"`, application not owned by this organization), 409 (`"An assessment already exists for this application"`), 422 (invalid source status: `"An assessment can only be created for shortlisted applications"`; validation failures).

### GET /api/organization/applications/{application}/assessment
- Middleware: `auth:sanctum, active, role:organization`
- Success: 200 — `{"data": null}` if the (owned) application has no assessment yet; otherwise the assessment with `application` and `interview`/`quiz.questions` (whichever matches `type`) nested.
- Errors: 401, 403, 404 (application not owned by this organization)

### GET /api/organization/assessments/{assessment}
- Same middleware
- Success: 200 — the assessment with `application` and `interview`/`quiz.questions` nested
- Errors: 401, 403, 404 (assessment not owned by this organization)

### GET /api/student/assessments
- Middleware: `auth:sanctum, active, role:student`
- Success: 200 — every assessment belonging to the authenticated student's own applications, each with `application` and `interview`/`quiz.questions` (whichever matches `type`) nested. The nested `interview` is filtered — see "Student-visible Interview fields" below; the nested `quiz.questions` is filtered the same way — see "Student-visible Quiz fields" in section 7a.
- Errors: 401, 403

### GET /api/student/assessments/{assessment}
- Same middleware
- Success: 200 — same filtered `interview`/`quiz.questions` shape as the index above.
- Errors: 401, 403, 404 (assessment does not belong to this student)

**Student-visible Interview fields**: `GET /api/student/assessments`, `GET /api/student/assessments/{assessment}`, and `GET /api/student/interviews` (section 6) all return `Interview` with `interviewer_email`, `company_feedback`, `rating`, and `decision` omitted — these are organization-internal (post-interview evaluation data, and a staff member's email), applied per-response via `App\Http\Controllers\Student\Concerns\HidesInternalInterviewFields`, not a model-level `$hidden`. The Organization-facing Interview/Assessment endpoints above are unaffected and continue to return every field. A student's own outcome is `assessment.result` (`null` until a real decision is recorded), not `interview.decision`.

**Quiz-type assessments on the Student endpoints above**: as of Phase 6B-3, both `GET /api/student/assessments` and `GET /api/student/assessments/{assessment}` eager-load `quiz.questions` for a `type=quiz` assessment, with `correct_answer` stripped from every question the exact same way `GET /api/student/assessments/{assessment}/quiz` (section 7a) already does — see "Student-visible Quiz fields" there. A `type=interview` assessment's `quiz` key is simply absent (not an error).

---

## 7a. Quizzes (Organization Authoring — Phase 6B-1; Student Taking — Phase 6B-3)

Organization authoring: viewing a quiz, adding/updating/deleting its questions while still a draft, and publishing it. Student taking (Phase 6B-3): viewing a *published* quiz with the answer key stripped, starting the one attempt v1 allows, and submitting it for immediate auto-grading. No retake endpoint, no manual-grading endpoint, and no answer-review endpoint exist for either role.

A quiz belongs to one assessment (`type=quiz`, created via section 7's generic endpoint); an assessment can have at most one quiz. A quiz has `title`, `instructions` (nullable), `time_limit_minutes` (nullable), `passing_score` (integer, 0–100), and its own `status` (`draft`/`published`, independent of `Assessment.status`). A question belongs to one quiz; it has `prompt`, `type` (`multiple_choice`/`true_false`), `options` (JSON array for `multiple_choice`, always `null` for `true_false`), `correct_answer`, `points` (default 1), and `position` (default 0).

**`correct_answer` is organization-internal** — every Organization response below includes it because the organization authored it, but it is stripped from every Student response — see "Student-visible Quiz fields" below.

### GET /api/organization/assessments/{assessment}/quiz
- Middleware: `auth:sanctum, active, role:organization`
- Success: 200 — `{"data": null}` if the (owned) assessment has no quiz yet (e.g. `type=interview`); otherwise the quiz with `questions` nested (ordered by `position`, then `id`).
- Errors: 401, 403, 404 (`"Assessment not found"`, assessment not owned by this organization)

### POST /api/organization/quizzes/{quiz}/questions
- Same middleware
- Body:
  ```json
  {
    "prompt": "What is the capital of France?",
    "type": "multiple_choice",
    "options": ["Paris", "London", "Berlin"],
    "correct_answer": "Paris",
    "points": 1,
    "position": 0
  }
  ```
  `prompt` required, string, max 2000. `type` required, `in:multiple_choice,true_false`. `options` required (array, min 2 entries, each a non-empty string) when `type=multiple_choice`; not required for `true_false` — whatever is submitted for `true_false` is ignored, the server always persists `options=null` for that type. `correct_answer` required, string; for `multiple_choice` it must exactly match one submitted option (case-sensitive); for `true_false` it is canonicalized to exactly `"True"`/`"False"` before validation (`"true"`, `"TRUE"`, `"1"`, etc. all normalize the same way) and must resolve to one of those two values. `points` nullable integer, min 1 (defaults to 1 when omitted). `position` nullable integer, min 0 (defaults to 0 when omitted).
- Preconditions: quiz belongs to the requesting organization; `quiz.status = draft`.
- Success: 201 — the created question.
- Errors: 401, 403, 404 (`"Quiz not found"`, quiz not owned by this organization), 422 (`"Published quizzes cannot be modified"`; validation failures)

### PUT /api/organization/quizzes/{quiz}/questions/{question}
- Same middleware and body/validation as POST above (full replacement, not a partial update)
- Preconditions: quiz belongs to the requesting organization; `question.quiz_id` matches the route's `{quiz}`; `quiz.status = draft`
- Success: 200 — the updated question.
- Errors: 401, 403, 404 (`"Quiz not found"` for a wrong-owner quiz; `"Question not found"` when the question doesn't belong to `{quiz}`), 422 (`"Published quizzes cannot be modified"`; validation failures)

### DELETE /api/organization/quizzes/{quiz}/questions/{question}
- Same middleware and ownership/draft preconditions as PUT above
- Success: 200 — hard delete, `data: null`
- Errors: 401, 403, 404 (`"Quiz not found"` / `"Question not found"`), 422 (`"Published quizzes cannot be modified"`)

### PUT /api/organization/quizzes/{quiz}/publish
- Same middleware
- Preconditions: quiz belongs to the requesting organization; `quiz.status = draft` (422 `"Only draft quizzes can be published"` otherwise); at least one question exists (422 `"A quiz must have at least one question before it can be published"` otherwise). Every question is already structurally valid by construction (both create and update are gated by the same validation), so no further per-question re-validation happens here.
- Success: 200 — sets `quiz.status = published` and `assessment.status = scheduled` in one transaction. **Does not touch `application.status`** — it is already `in_assessment` from quiz creation and stays there. Response `data` is the quiz with `questions` nested, plus `assessment.application` loaded.
- Errors: 401, 403, 404 (`"Quiz not found"`), 422 (see preconditions above)

**Student-visible Quiz fields**: every Student Quiz response below (and the nested `quiz.questions` on `GET /student/assessments`/`GET /student/assessments/{assessment}`, section 7) returns a `Question` with `correct_answer` omitted — applied per-response via `App\Http\Controllers\Student\Concerns\HidesInternalQuestionFields`, not a model-level `$hidden`, the exact same convention `HidesInternalInterviewFields` already uses for Interview. Every other Question field (`prompt`, `type`, `options`, `points`, `position`) is returned as-is. `true_false` questions return `options: null` — the same representation the Organization side already uses; the client is expected to know the two fixed choices rather than read them from the response. `Quiz.passing_score` **is** included (a deliberate v1 product decision: the passing threshold is a transparent, known-in-advance assessment rule, not a grading internal).

### GET /api/student/assessments/{assessment}/quiz
- Middleware: `auth:sanctum, active, role:student`
- Preconditions: the assessment belongs to one of the student's own applications, `assessment.type = quiz`, a quiz exists, and `quiz.status = published`.
- Success: 200 — the quiz with `questions` nested (ordered by `position`, then `id`), `correct_answer` omitted from each.
- Errors: 401, 403, 404 (`"Quiz not found"` — used uniformly for "not your application", "not a quiz assessment", "no quiz yet", and "quiz still draft", deliberately never distinguishing which case applies)

### POST /api/student/quizzes/{quiz}/start
- Same middleware
- Preconditions: the quiz belongs to one of the student's own applications; `quiz.status = published`; no *submitted* attempt already exists.
- Success: 201 (new attempt) or 200 (an unsubmitted attempt already existed — see below) — `{"data": {"id", "quiz_id", "application_id", "started_at", "submitted_at": null, "score": null}}`.
- **Idempotent for an in-progress attempt**: calling this again while the existing attempt is still unsubmitted returns that *same* attempt (200, message `"Quiz attempt resumed"`) rather than creating a duplicate — `started_at` is never reset and no extra time is granted. A genuine concurrent double-start race is resolved the same way, via the `quiz_attempts` unique constraint.
- Sets `assessment.status = in_progress`. **Does not touch `application.status`**, which is already `in_assessment` and stays there.
- Errors: 401, 403, 404 (`"Quiz not found"`), 409 (`"Quiz has already been submitted"` — a submitted attempt can never restart; no retakes in v1)

### POST /api/student/quizzes/{quiz}/submit
- Same middleware
- Body:
  ```json
  {
    "answers": [
      { "question_id": 10, "answer": "Option A" },
      { "question_id": 11, "answer": "True" }
    ]
  }
  ```
  Every quiz question must appear exactly once (`answers` required array; `question_id` required integer; `answer` required string). Rejected (422) for: a missing question, a duplicate `question_id`, a `question_id` not belonging to this quiz, an `answer` that isn't one of the question's own `options` (`multiple_choice`), or an `answer` that isn't `"True"`/`"False"` after canonicalization (`true_false` — `"true"`/`"TRUE"`/`"1"` and the false equivalents all normalize the same way `Organization` question authoring already does). `points`/`score`/`correct_answer` are never accepted as input.
- Preconditions: the quiz belongs to one of the student's own applications; an attempt must already exist (`422` `"Start the quiz before submitting."` otherwise — Submit never implicitly starts); the attempt must not already be submitted; if `quiz.time_limit_minutes` is set, `now()` must not be past `attempt.started_at + time_limit_minutes` (no grace period).
- Success: 200 — grades server-side (see docs/BUSINESS_RULES.md for the exact scoring formula and rounding rule), then atomically: `quiz_attempts.answers`/`score`/`submitted_at` are saved, and `assessment.status = completed`, `assessment.result = passed|failed`, `assessment.completed_at = now()`. **`application.status` is never touched** — it remains `in_assessment`; the organization's own accept/reject decision stays separate. Response `data` is the attempt, including `score` (now non-null) — never the correct answers.
- Errors: 401, 403, 404 (`"Quiz not found"`), 409 (`"Quiz has already been submitted"`), 422 (validation failures above, `"Start the quiz before submitting."`, or `"Quiz time limit has expired"`)

---

## 8. AI Matching

Both endpoints run the same rule-based `MatchingService` (skills compared against opportunity requirements, a rough experience heuristic, and a fixed neutral placeholder for education, weighted 62.5/25/12.5). **Not** triggered automatically on application submission — both are explicit, organization-triggered, on-demand calls.

### POST /api/organization/applications/{application}/analyze
- Middleware: `auth:sanctum, active, role:organization`
- Success: 200 — `{"data": {"overall_match_score": n, "skills_match_score": n, "education_match_score": n, "experience_match_score": n, "strengths": [...], "weaknesses": [...], "recommendation": "..."}}`. Persists `overall_match_score` into `applications.match_score`. Does not touch `status`, does not create `Interview`/`Notification` records.
- Errors: 401, 403, 404

### GET /api/organization/applications/{application}/analysis
- Same middleware
- Success: 200 — same shape, but `overall_match_score` is forced to the **stored** `applications.match_score` (not a fresh recomputation) so it never drifts from what `analyze` last saved. Purely read-only — writes nothing.
- Errors: 401, 403, 404 (also returned if `analyze` was never called yet, i.e. `match_score` is `null`, message: "Application analysis not found")

---

## 9. Notifications

**Not yet created by any endpoint as of Phase 4A-1.** The routes below can read/mark-as-read existing rows, but nothing in the application writes a `Notification` row — not for applications, not for interviews/assessments. This is deferred to a later phase (see docs/BUSINESS_RULES.md).

### GET /api/notifications
- Middleware: `auth:sanctum, active` (any role)
- Success: 200 — all of the authenticated user's notifications, newest first
- Errors: 401, 403 (inactive account)

### PUT /api/notifications/read-all
- Same middleware
- Success: 200 — `{"data": {"updated_count": n}}`. Only flips currently-unread notifications; already-read ones are untouched.
- Errors: 401, 403

### PUT /api/notifications/{notification}/read
- Same middleware
- Success: 200
- Errors: 401, 403, 404 (not found / not yours)

---

## 10. Admin

### GET /api/admin/organizations
- Middleware: `auth:sanctum, active, role:admin`
- Success: 200 — every organization profile, with `user` loaded
- Errors: 401, 403

### GET /api/admin/organizations/{organizationProfile}
- Same middleware
- Success: 200
- Errors: 401, 403, 404

### PUT /api/admin/organizations/{organizationProfile}/approval
- Same middleware
- Body: `approval_status` (required, in: pending, approved, rejected)
- Success: 200
- Errors: 401, 403, 404, 422

### GET /api/admin/users
- Same middleware
- Success: 200 — every user
- Errors: 401, 403

### PUT /api/admin/users/{user}/status
- Same middleware
- Body: `status` (required, in: active, suspended — no `pending`)
- Success: 200
- Errors: 401, 403 (including 403 "You cannot change your own account status" if targeting yourself), 404, 422

### GET /api/admin/skills
- Same middleware
- Success: 200
- Errors: 401, 403

### POST /api/admin/skills
- Same middleware
- Body: `name` (required, max:255, unique), `category` (nullable, max:255)
- Success: 201
- Errors: 401, 403, 409 (race-condition fallback for the unique check), 422 (normal duplicate-name case)

### PUT /api/admin/skills/{skill}
- Same middleware
- Body: same as POST, uniqueness check ignores the skill's own current name
- Success: 200
- Errors: 401, 403, 404, 409, 422

### DELETE /api/admin/skills/{skill}
- Same middleware
- Success: 200
- Errors: 401, 403, 404, 409 ("Cannot delete a skill that is currently in use")

### GET /api/admin/dashboard
- Same middleware
- Success: 200 — `{"data": {"total_users": n, "total_students": n, "total_organizations": n, "pending_organizations": n, "approved_organizations": n, "rejected_organizations": n, "total_opportunities": n, "open_opportunities": n, "closed_opportunities": n, "total_applications": n, "total_interviews": n}}`
- Errors: 401, 403

---

## 11. Dashboard

Dashboard routes are listed under their respective role sections above (`/api/student/dashboard`, `/api/organization/dashboard`, `/api/admin/dashboard`) since each is protected by that role's middleware group, not a separate one.
