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
- Success: 201 — `{"data": {"user": {...}, "token": "..."}}`. `role` is always forced to `student` server-side. **Phase 8B-2:** a verification email is queued for the new account (see section 1a) — this never blocks or fails the registration response, and the account is fully usable (can log in, use every feature) before verifying.
- Errors: 422 (validation)

### POST /api/register/organization
- Auth: none · Role: none · Middleware: none
- Body: `name`, `email`, `password` (as above) + `organization_name` (required, max:255), `organization_type` (required, in: company, university, ngo, training_center, government, other), `industry`/`description`/`website`/`logo`/`phone` (nullable)
- Success: 201 — `{"data": {"user": {..., "organizationProfile": {...}}, "token": "..."}}`. `role` forced to `organization`; `organization_profiles.approval_status` defaults to `pending` (never client-settable). **Phase 8B-2:** same verification email as student registration — queued only after the account and its profile are both fully committed.
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
- Success: 200 — `{"data": <authenticated user>}`. **Phase 8B-2:** the user object now always includes an appended `email_verified` boolean (derived from `email_verified_at`) alongside the raw timestamp itself.
- Errors: 401

---

## 1a. Password Recovery & Email Verification (Phase 8B-2)

Built entirely on Laravel's own password-broker and signed-URL infrastructure — no custom token system, no second email subsystem. See docs/BUSINESS_RULES.md section 1a for the full privacy/security/gating rules behind these endpoints.

### POST /api/forgot-password
- Auth: none · Role: none (works for Student, Organization, and Admin accounts alike — password recovery is a User-level concern, not role-specific) · Middleware: `throttle:5,1`
- Body: `email` (required, email)
- Success: 200 — `{"success": true, "message": "If an account exists for this email, password reset instructions have been sent.", "data": null}`. **This exact response is returned whether or not the email belongs to a real account** — never used to enumerate registered accounts. Uses Laravel's `Password::sendResetLink()` broker: a hashed token is stored in `password_reset_tokens` (plaintext token only ever appears in the emailed link), respecting the broker's own 60-minute expiry and 60-second per-email resend throttle (`config/auth.php`).
- Errors: 422 (malformed/missing email), 429 (rate-limited after 5 requests/minute from the same client)

### POST /api/reset-password
- Auth: none · Role: none · Middleware: `throttle:5,1`
- Body: `email` (required, email), `token` (required, string — from the reset link), `password` (required, min:8, confirmed)
- Success: 200 — `{"success": true, "message": "Your password has been reset successfully.", "data": null}`. The new password is hashed via the same `'password' => 'hashed'` model cast registration uses (no separate hashing code). **Every existing Sanctum token for this user is revoked** — see docs/BUSINESS_RULES.md section 1a for this decision. The token is single-use: `Password::reset()` deletes the token row on success, so a second attempt with the same token always fails.
- Errors: 422 (validation, or an invalid/expired/already-used token — Laravel's own translated broker message, e.g. "This password reset token is invalid."), 429 (rate-limited)

### GET /api/email/verify/{id}/{hash}
- Auth: none (self-authorizing via a cryptographic signature — see below) · Role: none · Middleware: `throttle:6,1`
- The link a user clicks from their inbox. Not a JSON endpoint — always redirects (302) to `{FRONTEND_URL}/email-verified?status=success` or `...?status=invalid`, never a raw error page or crash. Deliberately not behind `auth:sanctum`: an email client navigating here carries no Bearer token, so the request is authorized purely by Laravel's own signed-URL signature (`$request->hasValidSignature()`) plus a per-user hash (`sha1(email)`) — both of which cover every route parameter, so tampering with `{id}` (e.g. trying to verify a different account) breaks the signature and is rejected the same as an expired or forged link.
- Already-verified is safe/idempotent — re-visiting a valid link after verifying does nothing further (no error, same success redirect).

### POST /api/email/verification-notification
- Auth: required · Role: none · Middleware: `auth:sanctum, active, throttle:6,1`
- No body — always resends to the authenticated user's own email. Safe/idempotent if already verified (no email sent, still 200).
- Success: 200 — `{"success": true, "message": "Verification link sent." | "Your email is already verified.", "data": null}`
- Errors: 401 (unauthenticated), 429 (rate-limited after 6 requests/minute)

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
- **Phase 8A-4: real multipart PDF upload** — `multipart/form-data` body: `title` (required, max:255), `file` (required, an actual uploaded file — `mimes:pdf`, `max:5120` KB / 5 MB). The client no longer supplies `file_path` at all; the server generates a random UUID filename and stores the file itself under `storage/app/private/cvs/{student_id}/{uuid}.pdf` (the `local` disk, never the public web root — see docs/ARCHITECTURE.md). Any `file_path` field sent in the request is ignored — it is not a validated/accepted input.
- **Phase 8A-5: the stored PDF's text is extracted server-side, deterministically, right after upload** (`App\Services\CvTextExtractor`, pure-PHP, no OCR, no AI/LLM call) and saved internally as `parsed_text`. This is backend-only processing data for a future AI-matching phase — **`parsed_text` is never included in this or any other API response** (hidden at the model level). Extraction never blocks or fails the upload: a scanned/image-only PDF (no OCR performed) or a PDF whose text genuinely can't be parsed both simply leave `parsed_text` null internally; the request still returns 201 either way.
- Success: 201 — same response shape as before (`id`, `title`, `file_path`, `version`, `is_default`, `created_by_ai`, timestamps); `file_path` is now always a safe server-managed relative path, never a client-chosen string.
- Errors: 401, 403, 404, 422 (missing/invalid `title`, missing `file`, non-PDF `file`, `file` over 5 MB)

### GET /api/student/cvs/{cv}/download
- Same middleware
- **New in Phase 8A-4.** Streams the CV's own PDF back to the student it belongs to (`Content-Type: application/pdf`, served inline). This is the only way a CV file is ever actually reachable — there is no public/static URL for it.
- Errors: 401, 403, 404 (not found / not yours, **or** the file no longer exists on disk — a legacy pre-8A-4 row with a fake string `file_path`, or a managed row whose physical file is missing, both return the same controlled 404 rather than a crash)

### DELETE /api/student/cvs/{cv}
- Same middleware
- Success: 200 — also deletes the physical file from disk, but **only** when `file_path` is a path this application itself generated (`cvs/{student_id}/...`); a legacy fake `file_path` (e.g. a local Windows path typed into the old text field, pre-8A-4) is left alone rather than attempting to delete an arbitrary, client-influenced path.
- Errors: 401, 403, 404 (not found / not yours), 409 ("Cannot delete a CV that has been used in an application" — neither the DB row nor any physical file is touched on this conflict)

### PUT /api/student/cvs/{cv}/default
- Same middleware
- Success: 200 — unsets `is_default` on all the student's other CVs, sets it on this one (only code path allowed to do so)
- Errors: 401, 403, 404

### POST /api/student/cvs/{cv}/extract-skills
- Same middleware
- **New in Phase 8A-6 — AI CV Skill Extraction, the first external-AI feature in this project.** Sends the CV's already-extracted `parsed_text` (Phase 8A-5) to the configured AI provider (Groq, called directly over HTTP — see docs/ARCHITECTURE.md) and returns structured skill suggestions. **Suggestion-only**: this endpoint never writes to `student_skills`, `skills`, or `cvs` — nothing is persisted by calling it. Only `parsed_text` is sent to the AI provider; the student's name, email, password, tokens, `match_score`, offers, application status, and interview/company feedback are never included. `parsed_text` itself is never returned in the response, exactly as with every other CV endpoint.
- Success: 200 — `data: {"skills": [{"name": string, "confidence": number (0.0–1.0), "skill_id": number|null, "is_available": boolean, "already_added": boolean, "suggestion_id": number|null, "suggestion_status": string|null}]}`. `skill_id`/`is_available` reflect whether the suggested name matches an existing Admin-owned `Skill` catalog entry by normalized (trim/lowercase/whitespace-collapsed) match — no new `Skill` record is ever created directly from an AI suggestion. `already_added` is `true` when the current student already has that skill. At most 20 suggestions, deduplicated case-insensitively, ordered by confidence descending.
- **Phase 8A-6.1: an unmatched name now also creates or reuses a pending catalog suggestion**, returned as `skill_id: null, is_available: false, suggestion_id: <id>, suggestion_status: "pending"` — see `GET/PUT /api/admin/skill-suggestions/*` below for how an Admin reviews it. Repeated extraction of the same unmatched name (by this or another student) reuses the same pending suggestion rather than creating duplicates. A matched suggestion instead has `suggestion_id: null, suggestion_status: null`. Once an Admin approves a suggestion, a later extraction call resolves that name as a normal catalog match.
- To accept a matched suggestion, call the **existing** `POST /api/student/skills` (below) with the suggestion's `skill_id` — there is no separate "accept AI suggestion" endpoint. A `pending`/`rejected` suggestion (`skill_id: null`) cannot be added yet.
- Errors: 401, 403, 404 (not found / not yours), 422 ("Text could not be extracted from this CV." — no `parsed_text` available, e.g. a scanned PDF or a legacy unparsed row), 503 (AI provider unavailable or returned an unusable response — missing configuration, timeout, non-2xx response, or malformed structured output; the message is always a safe, generic one, never the provider's raw error or API key)

### GET /api/student/skills
- Same middleware
- Success: 200 — list with `skill` relation loaded. **Phase 8A-6.1:** each row also carries `source` (`manual` or `cv_ai`) — see docs/BUSINESS_RULES.md section 9a for what each value means.
- Errors: 401, 403, 404

### POST /api/student/skills
- Same middleware
- Body: `skill_id` (required, integer, exists:skills,id), `level` (required, in: beginner, intermediate, advanced, expert), `years_of_experience` (nullable, numeric, between:0,60), `source` (**Phase 8A-6.1**, nullable, in: manual, cv_ai — defaults to `manual`), `cv_id` (**Phase 8A-6.1**, nullable, integer, exists:cvs,id, required when `source` is `cv_ai`)
- **Phase 8A-6.1:** when `source` is `cv_ai`, the backend independently verifies (never trusting the client claim alone) that this exact skill was actually identified from this exact CV for this exact student, via a server-written evidence record from the extract-skills flow above. If no matching evidence exists — including a spoofed skill/CV pairing or another student's CV — the request is rejected; the row is never stored with a false `cv_ai` source. When `source` is `manual` (or omitted), no evidence is required, exactly as before this phase.
- Success: 201
- Errors: 401, 403, 404, 409 ("You have already added this skill"), 422 (validation, **or Phase 8A-6.1:** "This skill could not be verified as CV-supported for the given CV." when a `cv_ai` claim has no matching evidence)

### DELETE /api/student/skills/{studentSkill}
- Same middleware
- Success: 200
- Errors: 401, 403, 404

### GET /api/student/education-verification
- Same middleware
- **New in Phase 8B-1.** Returns the authenticated student's current education-verification state. If the student has never submitted one, returns a controlled `{"status": "not_submitted", "institution_name": null, "degree_or_program": null, "rejection_reason": null, "submitted_at": null, "reviewed_at": null}` (still 200, not 404 — "nothing submitted yet" is a normal state here). `document_path` and the reviewing admin's id are never included in this response.
- Success: 200 — `data: {"institution_name", "degree_or_program", "status": "pending"|"verified"|"rejected"|"not_submitted", "rejection_reason", "submitted_at", "reviewed_at"}`
- Errors: 401, 403, 404 ("You must create a student profile first")

### POST /api/student/education-verification
- Same middleware
- **New in Phase 8B-1.** `multipart/form-data`: `institution_name` (required, max:255), `degree_or_program` (required, max:255), `file` (required, `mimes:pdf`, `max:5120` KB — same limits as CV upload). The stored document lives under `storage/app/private/education-verifications/{student_id}/{uuid}.pdf` (the `local` disk, same as CVs) — never a client-influenced path or filename. The request body can never set `status`, `reviewed_at`, or `reviewed_by_admin_id` — those three fields aren't read from the request at all; every new/resubmitted row is always forced to `status: "pending"` with review metadata cleared.
- **First submission** creates the row. **Resubmission after a `rejected` review** replaces the same row (`status` resets to `pending`, `rejection_reason`/`reviewed_at`/`reviewed_by_admin_id` are cleared) and safely swaps the physical file — the new file is stored and the DB row updated first; the previous managed file is deleted only after that succeeds. **Resubmission is blocked while `status` is `verified`** — see docs/BUSINESS_RULES.md.
- Success: 201 (first submission) or 200 (resubmission)
- Errors: 401, 403, 404 ("You must create a student profile first"), 409 ("Your education has already been verified and cannot be resubmitted." — attempting to replace a `verified` row), 422 (missing/invalid `institution_name`/`degree_or_program`, missing/non-PDF `file`, `file` over 5 MB)

### GET /api/student/education-verification/document
- Same middleware
- **New in Phase 8B-1.** Streams the student's own education-verification PDF back to them (`Content-Type: application/pdf`, served inline) — no `{id}` in the route at all, since a student only ever has one verification, reached through their own profile.
- Errors: 401, 403, 404 (no verification submitted yet, or the file no longer exists on disk)

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
- Success: 200 — `{"data": {"total_applications": n, "pending_applications": n, "reviewed_applications": n, "shortlisted_applications": n, "offer_sent_applications": n, "accepted_applications": n, "rejected_applications": n, "total_cvs": n, "total_skills": n, "total_interviews": n}}`. `accepted_applications` still counts `status = accepted` rows exactly as before; only what a *new* `accepted` row means has shifted (see docs/BUSINESS_RULES.md section 5). **`offer_sent_applications` (Phase 6C-4)** counts this student's own `status = offer_sent` rows — making the final Offer funnel (`offer_sent` → `accepted`/`rejected`) visible alongside the two terminal counts.
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
- Body: `title` (required), `description` (required), `opportunity_type` (required, in: job, internship, volunteer, scholarship, competition), `employment_type` (required, in: full_time, part_time, contract), `work_mode` (required, in: remote, hybrid, onsite), `experience_level` (required, in: no_experience, junior, mid, senior, expert), `education_level` (nullable, in: high_school, diploma, bachelor, master, phd), `field_of_study`/`location` (nullable), `salary_min`/`salary_max` (nullable, numeric, `salary_max >= salary_min`), `application_deadline` (nullable, date, must be today or later), `positions_available` (nullable, integer, min:1), `status` (nullable, in: draft, open, closed), `eligible_majors` (**Phase 8B-3.2**, optional, array of up to 10 non-blank strings, max:255 each — see below)
- Success: 201
- Errors: 401, 403, 422

**Multi-major eligibility (Phase 8B-3.2):** `eligible_majors` is an optional array of plain major names (e.g. `["Computer Engineering", "Computer Science", "Software Engineering"]`) stored in a dedicated `opportunity_eligible_majors` table, deduplicated case/whitespace-insensitively (see docs/BUSINESS_RULES.md). Omitting the field entirely on create means no explicit majors (falls back to `field_of_study`, or is unrestricted — see the eligibility rule below). Every Opportunity response now also includes a derived `eligible_majors: string[]` field (never the internal normalized values), alongside the pre-existing `field_of_study`, which is untouched and still returned as before.

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
- **`eligible_majors` sync behavior (Phase 8B-3.2):** when the key is present in the request — including an explicit empty array `[]` — the Opportunity's entire eligible-majors set is replaced with exactly that list. When the key is absent entirely, the existing eligible majors are left untouched.
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
- Success: 200 — `{"data": {"total_opportunities": n, "open_opportunities": n, "closed_opportunities": n, "draft_opportunities": n, "total_applications": n, "pending_applications": n, "shortlisted_applications": n, "offer_sent_applications": n, "accepted_applications": n, "rejected_applications": n, "total_interviews": n, "completed_interviews": n}}`. `accepted_applications` still counts `status = accepted` rows exactly as before; only what a *new* `accepted` row means has shifted (see docs/BUSINESS_RULES.md section 5). **`offer_sent_applications` (Phase 6C-4)** counts `status = offer_sent` rows across this organization's own opportunities — making the final Offer funnel visible alongside the two terminal counts.
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
- Checks in order: opportunity must be open + organization approved (404 otherwise) → deadline not passed (422) → student must have ≥1 CV (422) → not already applied (409) → **student's major must be eligible for this opportunity (422, "Your major is not eligible for this opportunity" — Phase 8B-3.2, see section 5a)** → create.
- Success: 201 — `status` defaults to `pending`, `applied_at` auto-set by the database.
- Errors: 401, 403, 404, 409, 422

### GET /api/student/applications
- See section 2.

### GET /api/organization/applications
- Middleware: `auth:sanctum, active, role:organization`
- Success: 200 — every application across all of this organization's opportunities, **ranked by `match_score` descending (Phase 8A-1)** — see the ranking note below
- Errors: 401, 403

### GET /api/organization/opportunities/{opportunity}/applications
- Same middleware
- Success: 200 — same ranking as above, scoped to this one opportunity's applicants
- Errors: 401, 403, 404 (opportunity not yours)

### GET /api/organization/applications/{application}
- Same middleware
- Success: 200
- Errors: 401, 403, 404

### PUT /api/organization/applications/{application}/status
- Same middleware
- Body: `status` (required, in: reviewed, shortlisted, rejected — **not** `pending`/`withdrawn`). **As of Phase 6B-0, `in_assessment` and `interview_scheduled` are no longer accepted here**, and **as of Phase 6C-0, `accepted` and `offer_sent` are no longer accepted here either** — `in_assessment` and `offer_sent` must only ever be reached through their real domain workflow (Assessment creation, section 7; the Offer workflow, section 7b), `interview_scheduled` (deprecated legacy value) can no longer be fabricated with no assessment behind it, and `accepted` now means specifically "the student accepted the Offer" (only `Student\OfferController::accept()`, section 7b, may write it — see docs/BUSINESS_RULES.md section 5). Any of these four values in the request body now fails standard `in:` validation (422). Existing rows may still legitimately hold any of them — this restriction is on input only, never on what's stored or returned (see the response note below).
- **As of Phase 6C-4, this endpoint is blocked entirely (409) once the application already has an Offer** — regardless of the Offer's own status (`sent`/`accepted`/`declined`) and regardless of which value the request body asks for (including `reviewed`/`shortlisted`/`rejected`, which are otherwise valid input). Once an Offer exists, only `OfferService` (via the Offer accept/decline endpoints) may move the Application again — see docs/BUSINESS_RULES.md section 7b.
- Success: 200 — also sets `reviewed_at = now()` on every successful call, even if re-setting the same status.
- Errors: 401, 403, 404, 409 ("Cannot change the status of a withdrawn application", or "This application already has an offer; its status can only change through the offer accept/decline endpoints." — Phase 6C-4), 422

### GET /api/organization/applications/{application}/cv
- Same middleware
- **New in Phase 8A-4.** Streams the CV attached to this application back to the organization that owns the opportunity it was submitted to (`Content-Type: application/pdf`, served inline) — "View CV". This is the *only* path an organization can ever reach a candidate's CV through; there is no `GET /organization/cvs/{cv}` route, so a CV can never be reached by guessing its ID directly, only through an application the requesting organization actually owns.
- Errors: 401, 403, 404 (application not owned/missing, **or** the CV file no longer exists on disk — both return the same controlled 404)

**Organization applicant ranking (Phase 8A-1):** both list endpoints above order results by `match_score` descending, applications with no score yet (`null`) always sorted after every calculated score regardless of value (including `0`), and `applied_at` ascending as the deterministic tie-breaker within a group of equal (or equally-null) scores. Uses only the already-stored `match_score` column — never calls `MatchingService`, never calculates or mutates a score as a side effect of listing. `GET /organization/applications/{application}` (single-item) and the Student endpoints are unaffected — this ordering applies to the two list endpoints only.

**Organization-visible skill evidence (Phase 8A-6.1):** all three response shapes above (both list endpoints and the single-item endpoint) now eager-load `student_profile.student_skills.skill`, so each applicant's skills are visible with their evidence `source` (`manual` or `cv_ai` — see docs/BUSINESS_RULES.md section 9a). `parsed_text`, the AI provider's raw request/response, extraction confidence, and any suggestion metadata are never included.

**Organization-visible education-verification status (Phase 8B-1):** all three response shapes above also carry `student_profile.education_verification_status` — one of `not_submitted`, `pending`, `verified`, `rejected`. This is a derived, status-only field; the underlying `EducationVerification` record (institution/degree, document path, rejection reason, reviewing admin id) is never included anywhere in an Organization-facing response — see docs/BUSINESS_RULES.md section 9b.

**Student-visible Application fields (Phase 8A-1):** `match_score` — an organization-internal matching/ranking aid (see section 8) — is omitted from every Student-facing response that returns an `Application`, directly or nested: `GET /api/student/applications`, the `POST /api/opportunities/{opportunity}/apply` success response, and the nested `application` on `GET /api/student/interviews`, `GET /api/student/assessments`, and `GET /api/student/assessments/{assessment}`. Applied per-response via `App\Http\Controllers\Student\Concerns\HidesInternalApplicationFields`, not a model-level `$hidden` — the same convention `HidesInternalInterviewFields`/`HidesInternalQuestionFields` already established for the identical class of problem. The Organization-facing endpoints above are unaffected and continue to return `match_score` exactly as before (`null` = not yet calculated, `0`–`100` = calculated).

---

## 5a. Candidate Search & Invitations (Phase 8B-3)

Flow B: an Organization discovers Students and invites them to apply, instead of only ever seeing Students who apply on their own (Flow A, section 5). Both flows converge into the exact same `applications` pipeline — see docs/ARCHITECTURE.md.

### GET /api/organization/candidates
- Middleware: `auth:sanctum, active, role:organization`
- Query params (all optional): `name`, `major`, `university`, `graduation_year`, `skill`, `opportunity_id`
- Filter/search only over structured `student_profiles`/`student_skills` data — no semantic search, no AI ranking, no `MatchingService` call. Only Students whose `user.status = 'active'` are returned.
- When `opportunity_id` is given, it must belong to the authenticated organization (404 otherwise) and each result additionally carries `already_applied`/`already_invited` booleans for that specific opportunity — no match score is calculated or included. **Phase 8B-3.2**: the result set is also filtered down to only Students eligible for that opportunity's accepted majors (see the eligibility rule below) — the general (no `opportunity_id`) search is unaffected and still returns all active Student profiles.
- Success: 200 — an array of `{id, name, university, major, graduation_year, education_verification_status, skills: [{name, source}], already_applied?, already_invited?}`. `id` is the `student_profiles.id` to pass as `student_id` below. Never includes email, phone, bio, profile image, raw CV text, or any education-verification document path.
- Errors: 401, 403, 404 (`opportunity_id` not found / not this organization's)

### POST /api/organization/invitations
- Middleware: `auth:sanctum, active, role:organization`
- Body: `student_id` (required, integer, must exist), `opportunity_id` (required, integer, must exist), `message` (nullable, string, max:1000)
- Checks in order: opportunity must belong to this organization (404) → opportunity must be `open` (422) → student must exist and be active (404) → student must not have already applied to this opportunity (409) → no existing invitation for this student/opportunity pair, in any status (409) → **student's major must be eligible for this opportunity (422, "Student major is not eligible for this opportunity" — Phase 8B-3.2)**.
- Success: 201 — the created invitation, `status` always `pending`.
- Errors: 401, 403, 404, 409, 422
- Never creates an `Application`. Never calls `MatchingService`.
- **Phase 8B-3.1**: on success, the invited student receives both the existing in-app notification and a queued "You have been invited to apply" email (subject, organization name, opportunity title, the invitation `message` if one was given, and a "View Invitation" link to `{FRONTEND_URL}/student/invitations`). Only a genuinely created invitation queues this email — a 404/409/422 response queues nothing. See section 9.
- **Phase 8B-3.2 — major eligibility rule** (shared by this endpoint, the Apply endpoint in section 5, and the opportunity-scoped Candidate Search above, via one `OpportunityEligibilityService`): (A) if the opportunity has explicit `eligible_majors`, the student's `major` must normalize-match one of them; else (B) if the opportunity has a non-blank legacy `field_of_study`, the student's `major` must normalize-match it; else (C) the opportunity is unrestricted and any student is eligible. Normalization is trim + lowercase + collapse-whitespace (`App\Support\MajorNormalizer`, mirrors `SkillNameNormalizer`). This is strictly a pre-application eligibility guard — it never uses Skills or `match_score`, which continue to drive suitability ranking only after an `Application` already exists.

### GET /api/student/invitations
- Middleware: `auth:sanctum, active, role:student, profile.exists`
- Success: 200 — only the authenticated student's own invitations, newest first, each with its nested `opportunity` (`id`, `title`) and `opportunity.organization_profile` (`organization_name`).
- Errors: 401, 403

### PUT /api/student/invitations/{invitation}/accept
- Same middleware
- Marks the invitation `accepted`. **Does not create an `Application`** — `applications.cv_id` is required and no CV is chosen at invitation time, so the Student is instead expected to complete the existing Apply flow (`POST /api/opportunities/{opportunity}/apply`, section 5) for `invitation.opportunity_id`, picking a CV exactly as a direct (Flow A) applicant would. If the student already applied independently before accepting, accepting still succeeds — it only ever records consent.
- Success: 200 — the updated invitation.
- Errors: 401, 403, 404 (not found / not this student's), 409 (already responded to)

### PUT /api/student/invitations/{invitation}/decline
- Same middleware
- Marks the invitation `declined`. No `Application` is created, no `match_score` is calculated.
- Success: 200 — the updated invitation.
- Errors: 401, 403, 404, 409 (already responded to)

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
  "contact_phone": "+1 555-0100",
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
- Body: `interview_type` (required, in: onsite, online, phone), `scheduled_at` (required, date), `duration_minutes` (nullable, integer, min:1, default 60), `meeting_link` (required if `interview_type=online`, must be a valid `http`/`https` URL, max:2048), `location` (required if `interview_type=onsite`, max:255), `contact_phone` (required if `interview_type=phone`, max:30 — Phase Final-QA-1), `interviewer_name`/`interviewer_email`/`notes` (nullable)
- **Conditional attendance detail (Phase Final-QA-1)**: exactly one of `meeting_link`/`location`/`contact_phone` is required, matching `interview_type` — a Phone interview can no longer be scheduled with no contact number, an Online interview with no meeting link, or an Onsite interview with no address, closing the gap where a Student could see a scheduled interview with no way to actually attend it. The other two fields are never required for a given type (e.g. a phone interview never requires `meeting_link`/`location`) and, if sent anyway, are silently dropped — see the "stale detail clearing" note below. Enforced once in `App\Http\Requests\Organization\Concerns\InteractsWithInterviewRules`, shared by this endpoint, `PUT .../interviews/{interview}`, and the generic `POST .../assessments` endpoint (section 7) — never duplicated.
- **Stale detail clearing (Phase Final-QA-1)**: at persistence time (`App\Support\InterviewContactDetailNormalizer`), only the field relevant to the interview's *current* `interview_type` is ever stored — the other two are always forced to `null`, even if a request body includes one. This matters most on update (see `PUT .../interviews/{interview}` below): changing `interview_type` (e.g. phone → online) clears the now-irrelevant old detail (`contact_phone`) even when the update request never mentions that field at all.
- Preconditions: application must be `shortlisted` or (legacy) `interview_scheduled` (422 otherwise; `in_assessment` is never an allowed source — see docs/BUSINESS_RULES.md section 5); one interview per application max (409 if one already exists) — enforced via the one-`Assessment`-per-`Application` rule (section 7), not directly on `interviews` any more.
- Success: 201 — in one DB transaction: creates an `Assessment` (`type=interview`, `status=scheduled`), creates the `Interview` under it, and sets the application's **`status = in_assessment`** (Phase 6B-0 — previously `interview_scheduled`; see docs/BUSINESS_RULES.md section 5) and `reviewed_at = now()`. Response `data` is the interview in the shape above, including `contact_phone` alongside the pre-existing `meeting_link`/`location`.
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
- Body: same schedulable fields as POST, full-replace semantics — `interview_type`/`scheduled_at` are required on every call, same conditional `meeting_link`/`location`/`contact_phone` requirement as POST (never `decision`/`rating`/`company_feedback`/`status`/`completed_at`)
- **Type-change stale-data safety (Phase Final-QA-1)**: if `interview_type` changes (e.g. a phone interview is switched to online), the now-irrelevant old detail is cleared even though the request only ever needs to send the new type's field — a request switching to `online` with only `meeting_link` set still results in `contact_phone = null` afterward, never a stale leftover value. See the stale detail clearing note under the POST endpoint above.
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
      "contact_phone": null,
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
  `type` is required (`in:interview,quiz`). `interview` is required when `type=interview`; its fields are validated by the exact same shared rule source as the legacy endpoint's body (`interview_type`, `scheduled_at` required; `meeting_link` required when `interview_type=online`; `location` required when `interview_type=onsite`; `contact_phone` required when `interview_type=phone`, Phase Final-QA-1; `duration_minutes`/`interviewer_name`/`interviewer_email`/`notes` optional) — see `App\Http\Requests\Organization\Concerns\InteractsWithInterviewRules`. `quiz` is required when `type=quiz`: `quiz.title` required, string, max 255; `quiz.instructions` nullable string; `quiz.time_limit_minutes` nullable integer, min 1; `quiz.passing_score` required, integer, 0–100. `questions` is **not** accepted here at all — see section 7a for adding them afterward.
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

**Student-visible Interview fields**: `GET /api/student/assessments`, `GET /api/student/assessments/{assessment}`, and `GET /api/student/interviews` (section 6) all return `Interview` with `interviewer_email`, `company_feedback`, `rating`, and `decision` omitted — these are organization-internal (post-interview evaluation data, and a staff member's email), applied per-response via `App\Http\Controllers\Student\Concerns\HidesInternalInterviewFields`, not a model-level `$hidden`. The Organization-facing Interview/Assessment endpoints above are unaffected and continue to return every field. A student's own outcome is `assessment.result` (`null` until a real decision is recorded), not `interview.decision`. **`meeting_link`/`location`/`contact_phone` are never hidden from the student** — the whole point of Phase Final-QA-1 is that the Student needs whichever one is relevant to actually attend the interview.

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

## 7b. Offers (Phase 6C-1)

The final hiring decision, owned end-to-end by `App\Services\OfferService`.
An application has at most one Offer, addressed either by Application ID
(`GET .../applications/{application}/offer`, both roles) or by its own
Offer ID (`PUT /api/student/offers/{offer}/accept|decline`). See
docs/BUSINESS_RULES.md section 7b for the full eligibility/response
narrative.

An Offer's fields: `id`, `application_id`, `title` (nullable string),
`salary_amount` (nullable, serializes as a two-decimal string, e.g.
`"90000.00"`), `salary_currency` (nullable string), `salary_period`
(nullable, one of `hourly`/`monthly`/`yearly`), `start_date` (nullable,
serializes as a full ISO datetime at midnight UTC, e.g.
`"2026-09-01T00:00:00.000000Z"` — a real Eloquent `date`-cast quirk, not a
bare `Y-m-d` string), `message` (nullable string), `status` (`sent`,
`accepted`, or `declined`), `sent_at`, `responded_at` (nullable until
responded to), `created_at`, `updated_at`. No `expires_at`, no
`internal_notes`, no cancellation-related field — see
docs/BUSINESS_RULES.md section 7b for why each was deliberately left out
of v1.

### POST /api/organization/applications/{application}/offer
- Middleware: `auth:sanctum, active, role:organization`
- Body: `title` (nullable, string, max:255); `salary_amount` (nullable,
  numeric, min:0 — required if `salary_currency` or `salary_period` is
  present); `salary_currency` (nullable, string, max:10 — required if
  `salary_amount` is present); `salary_period` (nullable, in:
  hourly,monthly,yearly — required if `salary_amount` is present);
  `start_date` (nullable, date, `after_or_equal:today`); `message`
  (nullable, string, max:2000). Every field may be omitted entirely — an
  Offer with no compensation terms at all is valid v1 data.
- Preconditions: the application belongs to this organization (404
  otherwise); `application.status === 'in_assessment'`, the application has
  an Assessment, and that Assessment's `status === 'completed'` (422
  otherwise — `assessment.result` is never checked, see
  docs/BUSINESS_RULES.md); no Offer already exists for this application
  (409 otherwise).
- Success: 201 — creates the Offer (`status = sent`, `sent_at = now()`) and
  sets `application.status = offer_sent`, in one transaction. Response
  `data` is the Offer, in the shape above.
- Errors: 401, 403, 404 (`"Application not found"`), 409 (`"An offer
  already exists for this application."`), 422 (validation failures above,
  or `"This application is not eligible to receive an offer."` /
  `"An offer can only be sent once the assessment is completed."`)

### GET /api/organization/applications/{application}/offer
- Same middleware
- Preconditions: the application belongs to this organization (404
  otherwise).
- Success: 200 — the Offer, in the shape above.
- Errors: 401, 403, 404 (`"Application not found"`, or `"This application
  has no offer yet"` if the application is owned but has none)

### GET /api/student/applications/{application}/offer
- Middleware: `auth:sanctum, active, role:student`
- Preconditions: the application belongs to this student.
- Success: 200 — the Offer, in the shape above (every field is
  student-visible — there is no organization-only field to hide, see
  docs/BUSINESS_RULES.md section 7b).
- Errors: 401, 403, 404 (`"Offer not found"` — used uniformly for "not your
  application" and "no offer yet", deliberately never distinguishing which
  case applies)

### PUT /api/student/offers/{offer}/accept
- Same middleware
- Preconditions: the Offer belongs to one of this student's own
  applications (404 otherwise, `"Offer not found"`); `offer.status ===
  'sent'` (409 otherwise, `"This offer has already been responded to."` —
  covers both a genuine repeat accept and a decline that already won a
  concurrent race).
- Success: 200 — sets `offer.status = accepted`, `offer.responded_at =
  now()`, and `application.status = accepted`, in one row-locked
  transaction. Response `data` is the updated Offer.
- Errors: 401, 403, 404 (`"Offer not found"`), 409

### PUT /api/student/offers/{offer}/decline
- Same middleware
- Preconditions: same as accept.
- Success: 200 — sets `offer.status = declined`, `offer.responded_at =
  now()`, and `application.status = rejected` (the same final status a
  direct organization rejection produces — see docs/BUSINESS_RULES.md
  section 5), in one row-locked transaction. Response `data` is the updated
  Offer.
- Errors: 401, 403, 404 (`"Offer not found"`), 409

---

## 8. AI Matching

Both endpoints run the same rule-based `MatchingService` v1.1 (Phase 8A-2): skills compared against opportunity requirements (60%), student major vs. opportunity field of study (20%), and an experience heuristic (20%). No education/location/work-mode factor exists in v1, and no factor is ever given a fake placeholder value — a factor that can't be genuinely scored (e.g. no major recorded, or the opportunity has no skills listed) is excluded and its weight is redistributed proportionally across the remaining scoreable factors, so `overall_match_score` always normalizes to 0–100.

**Auto-calculated on application submission (Phase 8A-2):** `POST /api/opportunities/{opportunity}/apply` now calculates and persists `match_score` synchronously, inside the same transaction as the Application insert — no queue, no external call, no extra request needed. `POST .../analyze` below remains the explicit manual **recalculation** endpoint (same formula, no duplicate implementation) — useful after the student's profile/skills or the opportunity's requirements change. Applications created before Phase 8A-2 are not backfilled and keep `match_score: null` until an organization explicitly calls `analyze`.

**`match_score` is organization-internal** — see section 5's "Organization applicant ranking" and "Student-visible Application fields" notes (Phase 8A-1) for exactly where it's used to rank applicants and exactly which Student-facing responses omit it. This still holds for auto-calculated scores.

### POST /api/organization/applications/{application}/analyze
- Middleware: `auth:sanctum, active, role:organization`
- Success: 200 — `{"data": {"overall_match_score": n, "skills_match_score": n|null, "field_match_score": n|null, "experience_match_score": n|null, "strengths": [...], "weaknesses": [...], "recommendation": "..."}}`. A per-factor score is `null` when that factor was unavailable (not scoreable), not a fake neutral value. Persists `overall_match_score` into `applications.match_score`. Does not touch `status`, does not create `Interview`/`Notification` records.
- Errors: 401, 403, 404

### GET /api/organization/applications/{application}/analysis
- Same middleware
- Success: 200 — same shape, but `overall_match_score` is forced to the **stored** `applications.match_score` (not a fresh recomputation) so it never drifts from what `analyze` (or auto-calculation on apply) last saved. Purely read-only — writes nothing.
- Errors: 401, 403, 404 (also returned if `match_score` is still `null` — never calculated, e.g. an application created before Phase 8A-2 that hasn't been recalculated — message: "Application analysis not found")

---

## 9. Notifications

**Actively created by the real workflow as of Phase 7A-2.** `App\Services\NotificationService` (Phase 7A-1) is now called from the business action itself, synchronously, inside the same DB transaction as the mutation it accompanies — see docs/BUSINESS_RULES.md section 8 for the exact event list and docs/ARCHITECTURE.md for the full integration-point-by-integration-point breakdown. Every notification is transition-based, not request-based (e.g. re-submitting an already-`shortlisted` status creates nothing) — no request-level or DB-level deduplication table exists; correctness comes entirely from each integration point only calling `NotificationService` on a genuine first-time transition.

**No Admin-facing notifications exist in this phase.** Only Student- and Organization-facing events are wired.

**No push notifications exist yet.** `action_url` is always an app-relative Flutter route path (e.g. `/student/applications/42`), matching the Flutter app's own `AppRoutes` constants exactly — never a full domain URL. Flutter's Notification Center (Phase 7A-3) is the primary way to observe a notification.

**Queued transactional email is enabled for eight of the fourteen events as of Phase 8B-3.1.** `App\Services\EmailService` (constructor-injected into `NotificationService`) queues the matching Mailable from inside eight `NotificationService` convenience methods — `notifyOfferSent()` → `OfferReceivedMail` (Phase 7A-4.1 pilot), `notifyApplicationRejected()` → `ApplicationRejectedMail`, `notifyInterviewScheduled()` → `InterviewScheduledMail`, `notifyInterviewRescheduled()` → `InterviewRescheduledMail`, `notifyQuizPublished()` → `QuizAvailableMail`, `notifyOfferAccepted()` → `OfferAcceptedMail`, `notifyOfferDeclined()` → `OfferDeclinedMail`, and `notifyInvitationReceived()` → `InvitationReceivedMail` (Phase 8B-3.1) — all using the same after-commit-safe mechanism (`App\Mail\QueuedTransactionalMail` — see docs/ARCHITECTURE.md). The remaining six events (Application Submitted, Application Shortlisted, Quiz Completed, Quiz Result Available, Invitation Accepted, Invitation Declined) are deliberately **in-app only** — see docs/BUSINESS_RULES.md section 8 for the full matrix and reasoning. A queue worker (`php artisan queue:work --queue=emails,default --tries=3 --timeout=60`) is required to process any queued email job.

**`notifications.type` supports 7 values** (widened in Phase 7A-1, `2026_08_11_090000_add_assessment_and_offer_types_to_notifications_table`): `system`, `application`, `interview`, `assessment`, `offer`, `organization`, `opportunity`. As of Phase 7A-2, `application`/`interview`/`assessment`/`offer` rows are now genuinely created by the workflow. As of Phase 8B-3, the three Invitation events (`notifyInvitationReceived()`, `notifyInvitationAccepted()`, `notifyInvitationDeclined()`) use the existing `opportunity` type — no new migration was needed. `organization` remains unused by any current event and `system` is the untouched generic fallback.

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

### GET /api/admin/skill-suggestions
- Same middleware
- **New in Phase 8A-6.1.** Lists pending catalog suggestions raised by the AI CV extraction flow (`GET /api/student/cvs/{cv}/extract-skills` above) awaiting Admin review. Only ever returns `status = pending` rows.
- Success: 200 — list of `{"id", "name", "source", "status"}`
- Errors: 401, 403

### PUT /api/admin/skill-suggestions/{suggestion}/approve
- Same middleware
- **New in Phase 8A-6.1.** Creates a new `Skill` from the suggestion's name — or, if an equivalent `Skill` (by normalized name) already exists by the time of approval, links to that existing `Skill` instead of creating a duplicate. Sets the suggestion's `status` to `approved`.
- Success: 200 — the updated suggestion, with the linked `Skill` loaded
- Errors: 401, 403, 404, 409 ("This suggestion has already been reviewed" — already approved or rejected; no state change)

### PUT /api/admin/skill-suggestions/{suggestion}/reject
- Same middleware
- **New in Phase 8A-6.1.** Sets the suggestion's `status` to `rejected`. Never creates a `Skill`.
- Success: 200 — the updated suggestion
- Errors: 401, 403, 404, 409 ("This suggestion has already been reviewed")

### GET /api/admin/education-verifications
- Same middleware
- **New in Phase 8B-1.** Every submission (not just pending), pending ones listed first, oldest-submitted first within each group. Each row includes `student_profile.user` (name/email) so an Admin never has to cross-reference a bare student id. `document_path` is never included — see `GET .../document` below.
- Success: 200

### GET /api/admin/education-verifications/{verification}
- Same middleware
- **New in Phase 8B-1.** Single-item detail, same shape as the list, with `student_profile.user` loaded.
- Errors: 401, 403, 404

### GET /api/admin/education-verifications/{verification}/document
- Same middleware
- **New in Phase 8B-1.** Streams the submission's PDF for Admin review (`Content-Type: application/pdf`, served inline). No public/static URL exists for it — this is the only way it's ever reachable.
- Errors: 401, 403, 404 (the file no longer exists on disk)

### PUT /api/admin/education-verifications/{verification}/verify
- Same middleware
- **New in Phase 8B-1.** Approves the submission: `status` → `verified`, `reviewed_at` → now, `reviewed_by_admin_id` → the acting admin, `rejection_reason` cleared. "Verified" means an Admin reviewed the document and approved it — see docs/BUSINESS_RULES.md section 9b for the full trust-model statement.
- Success: 200 — the updated verification
- Errors: 401, 403, 404, 409 ("This education verification has already been reviewed." — already `verified` or `rejected`; no state change)

### PUT /api/admin/education-verifications/{verification}/reject
- Same middleware
- **New in Phase 8B-1.** Body: `rejection_reason` (required, string, max:1000). Sets `status` → `rejected`, `reviewed_at` → now, `reviewed_by_admin_id` → the acting admin. Never mutates the stored document.
- Success: 200 — the updated verification
- Errors: 401, 403, 404, 409 ("This education verification has already been reviewed"), 422 (missing `rejection_reason`)

### GET /api/admin/dashboard
- Same middleware
- Success: 200 — `{"data": {"total_users": n, "total_students": n, "total_organizations": n, "pending_organizations": n, "approved_organizations": n, "rejected_organizations": n, "total_opportunities": n, "open_opportunities": n, "closed_opportunities": n, "total_applications": n, "total_interviews": n}}`
- Errors: 401, 403

---

## 11. Dashboard

Dashboard routes are listed under their respective role sections above (`/api/student/dashboard`, `/api/organization/dashboard`, `/api/admin/dashboard`) since each is protected by that role's middleware group, not a separate one.
