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

## 1b. Locations (Phase O8.2)

### GET /api/locations
- Auth: required · Role: none · Middleware: `auth:sanctum, active` — role-agnostic, like `GET /api/notifications` (section 9): both a Student and an Organization pick from this exact same list.
- Success: 200 — the real, full canonical Location Catalog (`{id, canonical_name}`), ordered alphabetically by name. There is no free-text location input anywhere in this app — a Student's available work locations and an Opportunity's own location both reference this catalog by ID.
- Errors: 401

---

## 2. Student

### GET /api/student/profile
- Auth: required · Role: student · Middleware: `auth:sanctum, active, role:student`
- Success: 200 — the student's own profile
- Errors: 401, 403, 404 ("Student profile not found")

### POST /api/student/profile
- Same middleware as above
- Body: `phone` (nullable, max:20), `university` (nullable, max:255), `major` (nullable, max:255), `graduation_year` (nullable, integer, between:1950,2100), `bio` (nullable, max:2000), `profile_image` (nullable, max:255), `current_location_id` (**Student Location Profile Patch**, nullable, integer, `exists:locations,id`), `available_location_ids` (nullable, array, max:20, each `exists:locations,id`), `interested_in` (**Candidate Opportunity Preferences patch**, required, array, `min:1`, each one of `job`/`internship`/`volunteer`/`scholarship`/`competition` — the exact same canonical values `opportunity_type` uses) — see the notes below for what these fields mean.
- Success: 201 — `data.current_location` (`{id, canonical_name}` or `null`), `data.available_locations` (array, possibly empty), and `data.interested_in` (array) are always included.
- Errors: 401, 403, 409 ("Profile already exists"), 422

### PUT /api/student/profile
- Same middleware and body as POST, except `current_location_id`, `available_location_ids`, and `interested_in` follow different absence semantics from each other (see below) and from every other field: an absent field here is simply left untouched (this endpoint has always been effectively partial-update for every field, not just these).
- **Current Location vs. Available Work Locations (Student Location Profile Patch)** — two deliberately distinct, independent concepts, never conflated:
  - `current_location_id` — a single, optional canonical Location Catalog ID: where the Student lives. Sent as a plain field like any other; `null` clears it, an ID sets/replaces it, and the key being absent from the request leaves the existing value untouched.
  - `available_location_ids` (`sometimes`, array, max:20, each `exists:locations,id`) — the Student's own available/preferred *work* locations — real canonical Location Catalog IDs only, never free text, never comma-separated. Absent entirely leaves the existing selection untouched; present (even `[]`) replaces the whole set — same "sync the set cleanly" convention `eligible_majors` (section 5b) uses.
  - A Student may live in one city (`current_location_id`) but be willing to work in several others (`available_location_ids`) — the two are stored, validated, and updated completely independently, and neither is ever inferred from the other.
- **`interested_in` (Candidate Opportunity Preferences patch, `sometimes`, array, `min:1`, same canonical values as POST)**: absent entirely leaves the Student's existing preference untouched; present, it must be non-empty (an explicitly empty array is rejected `422` — a Student cannot clear this down to zero once actively setting it, unlike `available_location_ids`).
- Success: 200 — `data.current_location`, `data.available_locations`, and `data.interested_in` are always included (on both this endpoint and `GET /api/student/profile`), reflecting the real, current state of each — never a fabricated default when unset. `data.interested_in` is `null` for a profile created before this patch (never backfilled).
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

### PATCH /api/student/cvs/{cv}
- Same middleware
- **New in Phase 8A-6.2.** Renames a CV — `title` only. Body: `title` (required, string, max:255). The PDF file itself is never replaced or re-parsed by this endpoint, and no other field (`file_path`, `parsed_text`, `version`, `is_default`, `created_by_ai`) can be changed through it, regardless of what the request body contains — the request only ever recognizes `title`. Renaming a CV that is already the default, or already referenced by an Application (`cv_id`), is always allowed — a CV is identified by id everywhere else in the system, never by its title, so there is no integrity rule for renaming to violate. This is a completely separate rule from `DELETE`'s "used in an Application" conflict below.
- Success: 200 — the updated CV (same response shape as every other CV endpoint).
- Errors: 401, 403, 404 (not found / not yours), 422 (missing/blank/over-255 `title`)

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
- **Phase 8A-6.2: two validation gates now run before any skill extraction — a document-type check, not just a text-presence check.** (1) A deterministic minimum-readable-text-length check runs first (catches an empty or near-empty PDF cheaply, without any AI call). (2) The remaining text is then classified by a bounded, second AI call (`CvDocumentClassifierService`, same Groq provider/config/timeout pattern as extraction itself) that judges whether the text is genuinely CV/resume-like — a personal education/experience/skills history — as opposed to a textbook chapter, lecture notes, an article, or any other document that merely happens to mention technical terms. Only once both gates pass does the existing extraction flow (described above) run. See docs/BUSINESS_RULES.md section 9a for the full rationale.
- Success: 200 — `data: {"skills": [{"name": string, "confidence": number (0.0–1.0), "skill_id": number|null, "is_available": boolean, "already_added": boolean, "suggestion_id": number|null, "suggestion_status": string|null}]}`. `skill_id`/`is_available` reflect whether the suggested name matches an existing Admin-owned `Skill` catalog entry by normalized (trim/lowercase/whitespace-collapsed) match — no new `Skill` record is ever created directly from an AI suggestion. `already_added` is `true` when the current student already has that skill. At most 20 suggestions, deduplicated case-insensitively, ordered by confidence descending.
- **Phase 8A-6.1: an unmatched name now also creates or reuses a pending catalog suggestion**, returned as `skill_id: null, is_available: false, suggestion_id: <id>, suggestion_status: "pending"` — see `GET/PUT /api/admin/skill-suggestions/*` below for how an Admin reviews it. Repeated extraction of the same unmatched name (by this or another student) reuses the same pending suggestion rather than creating duplicates. A matched suggestion instead has `suggestion_id: null, suggestion_status: null`. Once an Admin approves a suggestion, a later extraction call resolves that name as a normal catalog match.
- To accept a matched suggestion, call the **existing** `POST /api/student/skills` (below) with the suggestion's `skill_id` — there is no separate "accept AI suggestion" endpoint. A `pending`/`rejected` suggestion (`skill_id: null`) cannot be added yet.
- Errors: 401, 403, 404 (not found / not yours), 422 — two distinct business reasons, both before any skill extraction runs: **"We couldn't find enough readable text in this PDF to analyze it."** (empty/near-empty `parsed_text`, e.g. a scanned PDF, a legacy unparsed row, or a PDF with only a stray watermark/heading) or **"This document doesn't appear to be a CV or resume. Upload a CV to use AI Skill Analysis."** (Phase 8A-6.2: readable text exists but the classifier determined it isn't CV/resume-like) — 503 (AI provider — extraction **or** classification — unavailable or returned an unusable response; missing configuration, timeout, non-2xx response, or malformed structured output; the message is always a safe, generic one, never the provider's raw error or API key. A classification-provider failure is always this 503, never the "not a CV" 422 — a provider outage must never be reported to the student as their document being rejected.)

### GET /api/student/skills
- Same middleware
- Success: 200 — list with `skill` relation loaded. **Phase 8A-6.1:** each row also carries `source` (`manual` or `cv_ai`) — see docs/BUSINESS_RULES.md section 9a for what each value means.
- Errors: 401, 403, 404

### GET /api/student/skills/catalog
- Same middleware
- **New in Phase 8A-6.3 — Manual Add Skill.** Returns the full Skill catalog (`id`, `name`, `category`), ordered by `name`, for a student to pick from when manually adding a skill. Every `skills` row is Admin-owned/approved by construction (there is no separate approval flag on `Skill`), so this is simply the complete catalog — never filtered to opportunity-derived or partial data.
- Success: 200
- Errors: 401, 403

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
- Body: `organization_name` (required, max:255), `organization_type` (required, in: ...), `industry` (nullable, max:255), `description` (nullable), `website` (nullable, url, max:255), `phone` (nullable, max:30), `location_id` (**Organization Public Profile phase**, nullable, integer, `exists:locations,id` — the same canonical Location Catalog reference `opportunities.location_id` uses, never a free-text location string). **`logo` is NOT accepted here as of the Company Profile Polish phase** — see the dedicated logo endpoints below.
- Success: 200. **`approval_status` is never accepted here** — admin-only. The response now always includes a derived `location` field (`{id, canonical_name}` or `null`, via `OrganizationProfile::$appends`) and a derived `logo_url` field (full public URL, or `null` — never the raw storage path, which is hidden), on this endpoint and every other place an `OrganizationProfile` is serialized (Admin endpoints, the new public profile endpoint below).
- Errors: 401, 403, 404, 422

### POST /api/organization/profile/logo
- Same middleware (**Company Profile Polish phase**) — real multipart Company Logo upload/replace.
- Body: `logo` (required, file, real image data, `mimes:jpg,jpeg,png,webp`, `max:2048` KB)
- Server generates a random filename and stores it on the `public` disk under `organization-logos/{organizationId}/`; the previous logo file (if any) is deleted only after the new one is safely persisted.
- Success: 200 — the full updated profile, including the new `logo_url`.
- Errors: 401, 403, 404, 422 (missing/invalid/oversized file)

### DELETE /api/organization/profile/logo
- Same middleware — removes the Company Logo (reverts to the existing initials fallback client-side). A no-op (still 200) if no logo was set.
- Success: 200 — the full updated profile, `logo_url` now `null`.
- Errors: 401, 403, 404

### POST /api/organization/posts
- Middleware: `auth:sanctum, active, role:organization, org.approved` (**Organization Public Profile phase**) — "Updates & Achievements" posts, blocked for a not-yet-approved organization the same way Opportunity creation is.
- Body: `title` (nullable, max:255), `body` (required, non-blank, max:5000), `image` (**Company Profile Polish phase**, nullable, file, real image data, `mimes:jpg,jpeg,png,webp`, `max:2048` KB — ONE optional image, never a gallery)
- Success: 201 — includes a derived `image_url` (full public URL, or `null`)
- Errors: 401, 403, 422

### PUT /api/organization/posts/{organizationPost}
- Middleware: `auth:sanctum, active, role:organization` (no `org.approved` — an organization can still manage its own already-published posts even if later un-approved)
- Body: `title`, `body` as POST, plus the three-state image control (**Company Profile Polish phase**): send `image` (a new file) to replace the existing image (or add one if there was none); send `remove_image` (boolean `true`, only consulted when `image` is absent) to clear the existing image; send neither to leave whatever image already exists untouched.
- Success: 200
- Errors: 401, 403, 404 (not found / not this organization's own), 422

### DELETE /api/organization/posts/{organizationPost}
- Same middleware as PUT
- Success: 200
- Errors: 401, 403, 404 (not found / not this organization's own)

**Reading posts (owner or public) — Organization Public Profile phase:** there is no separate owner-facing "list my posts" endpoint. The owner's own Company Profile screen reuses the same public `GET /api/organizations/{organizationProfile}/posts` documented under section 4a below.

### POST /api/organization/opportunities
- Middleware: `auth:sanctum, active, role:organization, org.approved` (blocks unapproved organizations — 403 "Organization is not approved to publish opportunities")
- Body: `title` (required), `description` (required), `opportunity_type` (required, in: job, internship, volunteer, scholarship, competition), `employment_type` (required, in: full_time, part_time, contract), `work_mode` (required, in: remote, hybrid, onsite), `experience_level` (required, in: no_experience, junior, mid, senior, expert), `education_level` (nullable, in: high_school, diploma, bachelor, master, phd), `field_of_study` (nullable), `location_id` (**Phase O8.2**, nullable, integer, `exists:locations,id` — see below), `salary_min`/`salary_max` (nullable, numeric, `salary_max >= salary_min`), `application_deadline` (nullable, date, must be today or later), `positions_available` (nullable, integer, min:1), `status` (nullable, in: draft, open, closed), `eligible_majors` (**Phase 8B-3.2**, optional, array of up to 10 non-blank strings, max:255 each — see below), `recruitment_process` (**Phase 10A.4B**, optional (`sometimes`), in: none, interview, quiz — see below)
- Success: 201
- Errors: 401, 403, 422
- **`closed_at` (Closed Opportunities Scalability Polish):** the rare direct-create-as-`closed` case sets `closed_at` to the current backend timestamp immediately; any other `status` leaves it `null`.

**Recruitment process (Phase 10A.4B):** `recruitment_process` declares, up front, how shortlisted candidates for this Opportunity are evaluated — `none` (the DB column's own default; the pre-10A.4B unrestricted ad-hoc "Choose Assessment" flow, Interview or a private Quiz freely chosen per candidate), `interview` (candidates go straight to Interview), or `quiz` (candidates are advanced to this Opportunity's own shared Quiz template — see "Shared Quiz Template" under section 7a). Deliberately not 4 values — Phase 10A.4A already made a real Organization decision mandatory before any Quiz result can reach a Student, so a "Quiz Only, no decision needed" mode doesn't structurally exist. `sometimes`, not `required`, on both create and update, for the same backward-compatibility reason `eligible_majors` already established: every existing caller that doesn't send it keeps working exactly as before (falls back to `none` on create; left untouched on update).

**Canonical location (Phase O8.2):** `location` is no longer accepted as free text from the client — `location_id` (a real Location Catalog ID) replaces it going forward. The response still includes a plain-text `location` field for backward compatibility, but it is now derived server-side: whenever `location_id` is sent, `location` is mirrored to that Location's `canonical_name` automatically. A historical Opportunity created before this phase keeps its original free-text `location` and a `null` `location_id` forever — this phase never rewrites existing rows. `location_id` is nullable and not required even for an On-site/Hybrid Opportunity (a stricter requirement here would be a new business rule, not a purely additive one — same reasoning as `eligible_majors` never being required); see docs/BUSINESS_RULES.md section 5a-i for how a missing `location_id` is handled truthfully in Recommended Candidates.

**Multi-major eligibility (Phase 8B-3.2):** `eligible_majors` is an optional array of plain major names (e.g. `["Computer Engineering", "Computer Science", "Software Engineering"]`) stored in a dedicated `opportunity_eligible_majors` table, deduplicated case/whitespace-insensitively (see docs/BUSINESS_RULES.md). Omitting the field entirely on create means no explicit majors — the Opportunity is unrestricted, open to every major (see the eligibility rule below). Every Opportunity response now also includes a derived `eligible_majors: string[]` field (never the internal normalized values), alongside the pre-existing `field_of_study`, which is untouched and still returned as before but is descriptive metadata only — it never restricts eligibility.

**`field_of_study` is deprecated/legacy (Opportunity Academic Matching Cleanup).** The field, and this endpoint's acceptance of it, are kept purely for backward compatibility with historical Opportunities and any caller that still sends it — it is not removed or rejected. But `eligible_majors` is now the sole canonical academic-eligibility/matching source: `field_of_study` is never consulted by `OpportunityEligibilityService` (unchanged — see section 5b of docs/BUSINESS_RULES.md) or by `MatchingService` (changed — see section 5's Match Analysis notes below and docs/BUSINESS_RULES.md section 9), never shown in the Organization-facing Create/Edit/Details UI, and Flutter's Create/Edit Opportunity forms no longer send it at all.

### GET /api/organization/opportunities
- Middleware: `auth:sanctum, active, role:organization`
- Success: 200 — only this organization's own opportunities, every status at once, unpaginated (still used by the Organization Opportunities management screen, unchanged)
- **Final Company Profile Manual-E2E Bug Fix:** lazily runs `OpportunityExpirationService::closeExpired()` scoped to this organization before listing, so any Opportunity whose `application_deadline` has passed is already `status = 'closed'` in this response, with no dependency on the scheduled sweep having run recently.
- Errors: 401, 403

### GET /api/organization/opportunities/closed
- Same middleware (**Closed Opportunities Scalability Polish**) — the Company Profile's own "Closed Opportunities" section's real, backend-paginated/filtered/sorted endpoint. Deliberately separate from the endpoint above: `closed`-only by construction (never a `status` query param that could widen it), and never returns more than one page at a time.
- Query params: `date_preset` (`all` default, `last_30_days`, `last_3_months`, `last_6_months`, `this_year`, `older`), `closed_from`/`closed_to` (nullable dates — when either is present, they take priority over `date_preset`, filtering strictly against `closed_at`, never `created_at`), `sort` (`newest` default, `oldest`), `per_page` (1–50, default 15), `page`.
- A `null` `closed_at` (a legacy row with no reliable closure-time signal — see the `closed_at` migration's own doc comment) is excluded from every date-bounded preset/range, since it can never be proven to fall inside one — except `all` (no filter — includes it) and `older` (also includes it: at minimum, an unknown-date row is definitely not known to have closed recently).
- Sort: newest/oldest by `closed_at`; a row with unknown `closed_at` always sorts LAST regardless of direction — its true position relative to known dates is genuinely unproven.
- Success: 200 — `data` is the standard Laravel-paginator shape (same as `GET /api/opportunities`); a sibling top-level `total_closed` is the organization's real, TOTAL closed count, unaffected by whatever filter is applied (the Company Profile "Closed Opportunities (N)" header always reads this, never the filtered page's own `data.total`).
- Same lazy expiration sweep as the endpoint above.
- Errors: 401, 403

### GET /api/organization/opportunities/{opportunity}
- Same middleware
- Success: 200
- Same lazy expiration sweep.
- Errors: 401, 403, 404 (not found / not yours)

### PUT /api/organization/opportunities/{opportunity}
- Same middleware
- Body: same as POST, except `application_deadline` has **no** "must be in the future" restriction (an already-passed deadline can still be edited/closed)
- **`eligible_majors` sync behavior (Phase 8B-3.2):** when the key is present in the request — including an explicit empty array `[]` — the Opportunity's entire eligible-majors set is replaced with exactly that list. When the key is absent entirely, the existing eligible majors are left untouched.
- **`closed_at` bookkeeping (Closed Opportunities Scalability Polish):** when `status` is present in the request AND actually changes the stored value, `closed_at` is updated in the same write — transitioning to `closed` sets it to the real current backend timestamp (`now()`, never backdated); transitioning away from `closed` (a genuine reopen — this endpoint has always allowed freely changing `status`, this phase doesn't invent that) clears it to `null`. Omitting `status`, or re-sending the same value the row already has, never touches `closed_at`.
- Success: 200
- Errors: 401, 403, 404, 422

### DELETE /api/organization/opportunities/{opportunity}
- Same middleware
- Success: 200
- Errors: 401, 403, 404, 409 ("Cannot delete an opportunity that has recruitment history" — **Company Profile Polish phase**: tightened from "has applications" to also cover Invitations and an authored shared Quiz Template, both of which used to cascade-delete silently since their FKs are `cascadeOnDelete()`)
- Every Opportunity response now also includes a derived `can_delete` boolean (**Company Profile Polish phase**, via `Opportunity::$appends`) — `true` only when it has none of the above (status-independent; see the dedicated closed-only delete endpoint below for the separate "must be closed" rule).

### DELETE /api/organization/opportunities/{opportunity}/closed
- Same middleware (**Company Profile Polish phase**) — the Company Profile's own "Closed Opportunities" permanent-delete action. Deliberately separate from the endpoint above (which the pre-existing Organization Opportunities management screen still uses, unrestricted by status).
- Requires `status === 'closed'` server-side, in addition to the exact same recruitment-history check as the endpoint above.
- Success: 200
- Errors: 401, 403, 404, 409 ("Only a closed opportunity can be permanently deleted this way" for a non-closed status, or "Cannot delete an opportunity that has recruitment history")

### GET /api/organization/skills/catalog
- Same middleware (**Phase O8.2**)
- Success: 200 — the real, full canonical Skill Catalog (`{id, name, category}`), ordered by name — an Organization-side mirror of `GET /api/student/skills/catalog`, so Create/Edit Opportunity's Required Skills multi-select offers the exact same Skill IDs a Student picks their own skills from.
- Errors: 401, 403

### GET /api/organization/opportunities/{opportunity}/skills
- Same middleware
- Success: 200 — skill requirements with `skill` loaded
- Errors: 401, 403, 404

### POST /api/organization/opportunities/{opportunity}/skills
- Same middleware
- Body: `skill_id` (required, integer, exists:skills,id), `is_required` (required, boolean)
- Success: 201
- Errors: 401, 403, 404, 409 ("This skill has already been added to this opportunity"), 422

### PUT /api/organization/opportunities/{opportunity}/skills
- Same middleware (**Phase O8.2**)
- Body: `skills` (required — `present`, array, max:30), each entry `{skill_id (required, integer, exists:skills,id), is_required (required, boolean)}`
- Replaces the Opportunity's entire Required/Preferred Skill set in one atomic call — delete-then-reinsert, the same "sync the set cleanly" convention `eligible_majors` uses — so Create/Edit Opportunity's multi-select can save its whole selection with one request. Every entry references a real, canonical `skills.id`; there is no free-text Skill name accepted anywhere in this flow. The single-skill `POST`/`DELETE` endpoints above are unchanged and still work independently.
- Success: 200 — the Opportunity's full skill list after the sync, each with `skill` loaded
- Errors: 401, 403, 404, 422

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
- Query filters (all optional): `opportunity_type`, `employment_type`, `work_mode`, `experience_level` (enums), `location`, `field_of_study`, `keyword` (partial-match strings), `organization_id` (**Organization Public Profile phase**, integer, `exists:organization_profiles,id` — scopes to one organization's own open opportunities, for the Company Profile screen's "Open Opportunities" section), `per_page` (integer, between:1,100, default 15)
- Only returns opportunities where `status = open` AND the owning organization's `approval_status = approved`.
- Success: 200 — Laravel paginator object nested under `data` (i.e. `data.data` is the array of opportunities, alongside `data.current_page`, `data.total`, etc.), each with `organizationProfile` and `opportunitySkills.skill` loaded.
- Errors: 422 (invalid filter value)

### GET /api/opportunities/{opportunity}
- Same auth (none)
- Success: 200
- Errors: 404 (opportunity is draft/closed, or its organization isn't approved — same response as a truly nonexistent ID)

---

## 4a. Public Organization Profile (Organization Public Profile phase)

### GET /api/organizations/{organizationProfile}
- Auth: none · Role: none · Middleware: none — reachable exactly like the public Opportunity endpoints above.
- Success: 200 — explicit, narrow safe-field object: `id`, `organization_name`, `organization_type`, `industry`, `description`, `website`, `phone`, `location` (`{id, canonical_name}` or `null`). Never `user_id` or `approval_status`.
- Errors: 404 (not found, or exists but `approval_status !== 'approved'` — indistinguishable, same convention as the public Opportunity 404)

### GET /api/organizations/{organizationProfile}/posts
- Same auth (none)
- Success: 200 — this organization's own "Updates & Achievements" posts, newest first: `{id, organization_id, title, body, created_at, updated_at}[]`. Used identically by a public/Student viewer and by the organization owner viewing their own Company Profile screen.
- Errors: 404 (same rule as GET /api/organizations/{organizationProfile})

**"Open Opportunities" on a Company Profile** is not a separate endpoint — it's `GET /api/opportunities?organization_id={id}` (section 4 above), reused as-is.

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

## 5a. Talent Directory, Recommended Candidates & Invitations (Phase 8B-3, extended Phase O8.1)

Flow B: an Organization discovers Students and invites them to apply, instead of only ever seeing Students who apply on their own (Flow A, section 5). Both flows converge into the exact same `applications` pipeline — see docs/ARCHITECTURE.md.

**Phase O8.1 split this flow's UI into two endpoints with two distinct jobs** — the underlying `applications`/`invitations` pipeline and the eligibility/matching services are unchanged:
- `GET /api/organization/candidates` (below) — general profile browsing/search across all active Students ("Talent Directory"). No ranking, no match score.
- `GET /api/organization/opportunities/{opportunity}/recommended-candidates` (new, below) — real, eligible candidates for one specific Opportunity, ranked by match score highest-first ("Recommended Candidates").

### GET /api/organization/candidates
- Middleware: `auth:sanctum, active, role:organization`
- Query params (all optional): `name`, `major`, `university`, `graduation_year`, `skill`, `opportunity_id`
- Filter/search only over structured `student_profiles`/`student_skills` data — no semantic search, no AI ranking, no `MatchingService` call, no `interested_in` filtering. Only Students whose `user.status = 'active'` are returned.
- When `opportunity_id` is given, it must belong to the authenticated organization (404 otherwise) and each result additionally carries `already_applied`/`already_invited` booleans for that specific opportunity — no match score is calculated or included. **Phase 8B-3.2**: the result set is also filtered down to only Students eligible for that opportunity's accepted majors (see the eligibility rule below) — the general (no `opportunity_id`) search is unaffected and still returns all active Student profiles.
- Success: 200 — an array of `{id, name, university, major, graduation_year, education_verification_status, skills: [{name, source}], interested_in, already_applied?, already_invited?}`. `id` is the `student_profiles.id` to pass as `student_id` below. `interested_in` (**Candidate Opportunity Preferences patch**) is the Student's own canonical Opportunity Type preference array, or `null` for a profile from before this patch — purely informational here, this endpoint never filters by it. Never includes email, phone, bio, profile image, raw CV text, or any education-verification document path.
- Errors: 401, 403, 404 (`opportunity_id` not found / not this organization's)

### GET /api/organization/opportunities/{opportunity}/recommended-candidates
- Middleware: `auth:sanctum, active, role:organization` (Phase O8.1)
- The opportunity must belong to the authenticated organization — 404 ("Opportunity not found") otherwise, same convention as the `opportunity_id` filter above. A Student, or a request for another organization's Opportunity, is rejected the same way (401/403/404) as every other Organization-only, own-Opportunity-scoped endpoint in this API.
- Eligibility is applied **before** ranking, on three independent axes — a candidate must pass all three:
  - `OpportunityEligibilityService::isTypeInterestEligible()` (**Candidate Opportunity Preferences patch**) — the Student's `interested_in` must include this Opportunity's exact `opportunity_type`, unless `interested_in` is `null`/`[]` (no preference recorded — unrestricted, mirroring every other "nothing configured" rule in this service). Recommendation-only, exactly like the location check below — it never gates Apply or Invitation creation.
  - `OpportunityEligibilityService::isStudentEligible()` — the same major-eligibility gate used by the Apply endpoint, `POST /organization/invitations`, and the opportunity-scoped Candidate Search filter above.
  - `OpportunityEligibilityService::isLocationEligible()` (**Phase O8.2**) — canonical, ID-based location eligibility. `work_mode = remote` ignores location entirely; `onsite`/`hybrid` requires the Opportunity's `location_id` to appear in the Student's own `available_location_ids`. A Student with zero available locations configured is excluded (never guessed into eligibility), and an Opportunity with no `location_id` set (legacy/unconfigured) is treated as unrestricted — see docs/BUSINESS_RULES.md section 5a-i for the full table. This location check is scoped to this endpoint only — it does **not** gate Apply or Invitation creation (see that same doc section for why).
  - An ineligible Student never appears in the response on any axis, regardless of match score.
- Ranking calls `MatchingService::scoreCandidate(StudentProfile, Opportunity)` — the same weighted formula (**v2.0, "Candidate Opportunity Preferences + Final Recommendation Match Formula"**: On-site/Hybrid = Skills 60/Major 25/Location 15; Remote = Skills 70/Major 30, Location never considered at all; proportionally redistributed among the *scoreable* factors of that work mode's table when one is unscoreable) an `Application`'s own Match Analysis (section 5) uses, computed live for the Student↔Opportunity pair. **This never creates an `Application` row, a temporary/placeholder record, or any other side effect** — it is a pure read. Results are sorted by `match_score` descending, tie-broken by `id` ascending for a deterministic order.
- Success: 200 — **(Phase O8.2: response shape changed)** `data` is now `{opportunity: {opportunity_type, work_mode, location: {id, canonical_name} | null}, candidates: [...]}`, not a bare array. `data.opportunity` carries only the real context the Recommended Candidates UI needs to render a truthful explanation of what was filtered/ranked — `opportunity_type` (**Candidate Opportunity Preferences patch**) for the "these candidates are interested in X" copy, `work_mode` so the UI can never claim location was considered for a Remote Opportunity — hand-assembled, never the raw model. `data.candidates` is the array of `{id, name, university, major, graduation_year, education_verification_status, skills: [{name, source}], match_score, skills_match_score, major_match_score, location_match_score, already_applied, application_id, invitation_status, match_breakdown}`. **There is no top-level `experience_match_score`/`field_match_score`** (Experience was removed from the formula entirely; the Major factor is computed from canonical `eligibleMajorRecords`, never `field_of_study`). Each factor score is `null` only when genuinely unscoreable (e.g. no skills configured on the Opportunity, no Eligible Majors configured for `major_match_score`, or `location_match_score` on a Remote Opportunity/an unconfigured On-site/Hybrid one), never a misleading `0`; `match_score` itself is always a real, computed float (never `null`).
  - **`match_breakdown` (Matching Formula Audit, tightened by the Opportunity Academic Matching Cleanup, extended by "Recommendation Match: Major Must Contribute to Total Score" and again by "Candidate Opportunity Preferences + Final Recommendation Match Formula")** — a deterministic explanation object built entirely from the same values above, never a second calculation. Every `*_weight`/`*_contribution` pair is already expressed in the exact points that sum to `match_score` — the client never multiplies a raw score by a weight itself:
    - `major_eligibility`: always `"eligible"` here — the pre-ranking *gate* (`OpportunityEligibilityService::isStudentEligible()`) every listed candidate already passed. Distinct from the next field: this is the gate, never itself a score input.
    - `academic_match`/`major_match_score`/`major_weight`/`major_contribution`: the real, *scored* `MatchingService::analyzeMajor()` factor — `"matched"` (`major_match_score` is `100`), `"not_matched"` (`0` — defensive/rare here, reachable only for a stale Application Match Analysis), or `"not_applicable"` (`null` — the Opportunity has no Eligible Majors configured, and `major_weight`/`major_contribution` are also `null`). Computed from the same canonical `eligibleMajorRecords` the gate above uses — **never** `opportunity.field_of_study` (deprecated/legacy, unused by any factor). For a listed candidate this normally agrees with `major_eligibility` — a deliberate, accepted overlap between gate and score, not a bug — but only `major_contribution` actually contributes to `match_score`'s total.
    - `required_skills_total`, `required_skills_matched`, `matched_required_skills`, `missing_required_skills`: the real counts/names behind `skills_match_score` (required skills only; a preferred/optional skill match still contributes to `skills_match_score` but is not named here).
    - `skills_match_score`/`skills_weight`/`skills_contribution`: the raw factor score, its real weight for this work mode, and its real points contribution.
    - `location_eligibility`: `"not_considered"` (Remote — location never consulted, `location_match_score`/`location_weight`/`location_contribution` all `null`), `"unrestricted"` (On-site/Hybrid but the Opportunity has no canonical `location_id` set — nothing to compare against, same three keys `null`), or `"matched"` (On-site/Hybrid with a real location — every listed candidate's `available_location_ids` genuinely includes it, since the location gate already filtered on it — `location_match_score` is `100`, `location_weight` is `15`, `location_contribution` is `15.0`).
    - `location_match_score`/`location_weight`/`location_contribution`: repeated here for self-containment, identical to the top-level/computed values above.
  - `already_applied`/`application_id`/`invitation_status` reflect this Student's real, current relationship to this specific Opportunity (`invitation_status` is `null`, `pending`, `accepted`, or `declined`) — never fabricated. Same privacy boundary as Candidate Search: never phone, email, bio, raw CV text, or education-verification document path.
- Errors: 401, 403, 404 (not this organization's opportunity)
- Invite-from-here still goes through the unchanged `POST /api/organization/invitations` below — this endpoint only ranks and reports state, it never sends an invitation itself.

### POST /api/organization/invitations
- Middleware: `auth:sanctum, active, role:organization`
- Body: `student_id` (required, integer, must exist), `opportunity_id` (required, integer, must exist), `message` (nullable, string, max:1000)
- Checks in order: opportunity must belong to this organization (404) → opportunity must be `open` (422) → student must exist and be active (404) → student must not have already applied to this opportunity (409) → no existing invitation for this student/opportunity pair, in any status (409) → **student's major must be eligible for this opportunity (422, "Student major is not eligible for this opportunity" — Phase 8B-3.2)**.
- Success: 201 — the created invitation, `status` always `pending`.
- Errors: 401, 403, 404, 409, 422
- Never creates an `Application`. Never calls `MatchingService`.
- **Phase 8B-3.1**: on success, the invited student receives both the existing in-app notification and a queued "You have been invited to apply" email (subject, organization name, opportunity title, the invitation `message` if one was given, and a "View Invitation" link to `{FRONTEND_URL}/student/invitations`). Only a genuinely created invitation queues this email — a 404/409/422 response queues nothing. See section 9.
- **Phase 8B-3.2 — major eligibility rule** (shared by this endpoint, the Apply endpoint in section 5, and the opportunity-scoped Candidate Search above, via one `OpportunityEligibilityService`): (A) if the opportunity has explicit, non-empty `eligible_majors`, the student's `major` must normalize-match one of them; else (B) the opportunity is unrestricted and any student is eligible — the legacy `field_of_study` is descriptive metadata only and is never consulted as an eligibility fallback. Normalization is trim + lowercase + collapse-whitespace (`App\Support\MajorNormalizer`, mirrors `SkillNameNormalizer`). This is strictly a pre-application eligibility guard — it never uses Skills or `match_score`, which continue to drive suitability ranking only after an `Application` already exists.

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
- Preconditions: application must be `shortlisted`, (legacy) `interview_scheduled`, or, **as of Phase 10A.3**, `in_assessment` with no currently-active Assessment (422 otherwise — see docs/BUSINESS_RULES.md section 5); no *active* Assessment already exists for the application (409 if one does) — enforced via the active-Assessment invariant (section 7), not a one-`Assessment`-per-`Application` rule any more (that changed in Phase 10A.3 — see section 7's "Assessment History & the Active-Assessment Invariant").
- Success: 201 — in one DB transaction: creates a **new** `Assessment` (`type=interview`, `status=scheduled`), creates the `Interview` under it, and sets the application's **`status = in_assessment`** (Phase 6B-0 — previously `interview_scheduled`; see docs/BUSINESS_RULES.md section 5) — a no-op write if the application is already `in_assessment` (the Phase 10A.3 "Advance to Interview" case) — and `reviewed_at = now()`. Response `data` is the interview in the shape above, including `contact_phone` alongside the pre-existing `meeting_link`/`location`.
- **"Advance to Interview" (Phase 10A.3) is this exact endpoint**, called for an `in_assessment` application whose most recent Assessment (typically a completed Quiz) has already been finalized — no separate endpoint exists for it. See section 7's "Assessment History & the Active-Assessment Invariant" for the full mechanism, and docs/BUSINESS_RULES.md section 7a's "Post-Quiz Organization Actions" for the product-level rule this implements.
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

**As of Phase 10A.3, an application may have *more than one* `Assessment` over its lifetime** (`assessments.application_id` is no longer unique — see that migration's own doc comment) — a completed Quiz followed by a real "Advance to Interview" Assessment both exist, permanently, in the same application's history. At any given moment, however, an application has **at most one *active* (non-final) Assessment** — see "Assessment History & the Active-Assessment Invariant" below for the full rule. An `Assessment` has `type` (`interview` or `quiz`), `status` (`pending, scheduled, in_progress, completed, declined, cancelled`), `result` (`null`, `pending`, `passed`, `failed`, or `waiting` — a decision not yet recorded is represented as `null`, not the string `"pending"`), and, as of Phase 10A.2, `result_released_at` (nullable timestamp — see "Quiz Result Release" in section 7a for what it means and why it exists; always `null` for `type=interview` until the Interview is completed, at which point it is set equal to `completed_at` so Interview results stay immediately visible exactly as before this phase).

**As of Phase 10A.4A**, a `type=quiz` Assessment additionally carries: `next_action` (nullable, `interview`/`offer`/`reject` — the Organization's staged post-Quiz decision), `next_action_data` (nullable JSON — staged Offer terms, only ever present while `next_action=offer` and still unreleased), `next_action_prepared_at` (nullable timestamp — when the decision was staged), `next_action_assessment_id` (nullable self-FK — the real, already-created follow-up Interview `Assessment` when `next_action=interview`), `origin_assessment_id` (nullable self-FK, set on the *follow-up* Interview Assessment created this way, pointing back at the Quiz that staged it), and `decision_reminder_sent_at` (nullable timestamp — dedup marker for the Organization "Decision Required" reminder). See "Decision-Aware Quiz Result Release" below for the full mechanism these fields support. All six are Organization-only — `App\Http\Controllers\Student\Concerns\HidesUnreleasedQuizResult` strips every one of them from every Student-facing response, unconditionally, regardless of release state.

**`Assessment.result` vs. `Application.status`, restated (Phase 10A.2, extended 10A.3):** a Quiz `result` of `passed` is a *technical grading outcome*, never a recruitment decision. Nothing in this backend automatically advances `Application.status`, schedules an Interview, or sends an Offer because a quiz was passed — the organization always takes an explicit, separate action (send an Offer via section 7b, or, as of Phase 10A.3, a real "Advance to Interview" action — `POST .../applications/{application}/interview` or the generic endpoint below with `type=interview`, now legal for a completed-Quiz application — see below) after reviewing the result. See docs/BUSINESS_RULES.md section 7a for the full rule.

### Assessment History & the Active-Assessment Invariant (Phase 10A.3)

- **At most one active Assessment per application, at all times.** "Active" means `status` in `pending`/`scheduled`/`in_progress`; `completed` (and the schema-allowed but not-yet-used-anywhere `declined`/`cancelled`) is final and never blocks a new Assessment. Enforced inside `AssessmentService`'s creation transaction by row-locking the parent `Application` first (`lockForUpdate()`), then checking the full history for an active row — not a database unique constraint, since one can no longer exist once history is allowed. See `AssessmentService`'s own doc comment for the full mechanism.
- **"Advance to Interview" is not a new endpoint.** It is exactly the existing `POST /api/organization/applications/{application}/interview` (or the generic endpoint above with `type=interview`) — the same `AssessmentService::createInterviewAssessment()` every Interview creation already uses. What changed is the guard: `in_assessment` is now an allowed source `Application.status` (previously excluded, since before this phase it could only ever mean "an Assessment already exists" — now it can also mean "the evaluation chain is ongoing, but the most recent Assessment is already finalized"), and the duplicate check now inspects *active* Assessments only, not *any* Assessment ever.
- **Nothing is ever deleted or overwritten.** A completed Quiz stays a completed Quiz, forever, once a follow-up Interview Assessment is created for the same application — see "Assessment history is preserved" under `GET .../applications/{application}/assessment` below.
- **Offer eligibility follows the current (latest) Assessment only** — see docs/BUSINESS_RULES.md section 7b for the full worked examples (a completed Quiz with no follow-up can still receive a direct Offer; once a follow-up Interview exists and is not yet completed, Offer is blocked again until *that* Assessment completes).
- **As of Phase 10A.4A, a staged (unreleased) "Advance to Interview" decision's real follow-up Assessment counts as active too** — it is a genuine `status=scheduled` row, just Student-invisible until release (see "Quiz Result Release" in section 7a). Switching that decision to Offer/Reject, or re-staging Interview with corrected details, therefore first hard-deletes the abandoned staged Interview Assessment (`Organization\QuizController::discardStaleInterviewDecision()`) — otherwise it would stay active forever and permanently block this application from ever getting a new Assessment, even though it was never released or seen by the Student.

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
      "passing_score": 70,
      "display_mode": "paginated",
      "questions_per_page": 5,
      "result_release_mode": "scheduled",
      "result_release_at": "2026-09-05 09:00:00"
    }
  }
  ```
  `type` is required (`in:interview,quiz`). `interview` is required when `type=interview`; its fields are validated by the exact same shared rule source as the legacy endpoint's body (`interview_type`, `scheduled_at` required; `meeting_link` required when `interview_type=online`; `location` required when `interview_type=onsite`; `contact_phone` required when `interview_type=phone`, Phase Final-QA-1; `duration_minutes`/`interviewer_name`/`interviewer_email`/`notes` optional) — see `App\Http\Requests\Organization\Concerns\InteractsWithInterviewRules`. `quiz` is required when `type=quiz`: `quiz.title` required, string, max 255; `quiz.instructions` nullable string; `quiz.time_limit_minutes` nullable integer, min 1; `quiz.passing_score` required, integer, 0–100. `questions` is **not** accepted here at all — see section 7a for adding them afterward.

  **Phase 10A.2 additions, all optional with safe backward-compatible defaults** (a request that omits them behaves exactly as it did before this phase): `quiz.display_mode` — `in:single,paginated,all`, defaults to `all` (every question on one page, the pre-10A.2 behavior). `quiz.questions_per_page` — nullable integer, min 1; **required when `display_mode=paginated`** (422 otherwise), ignored for the other two modes. `quiz.result_release_mode` — `in:manual,immediate,scheduled`, defaults to `immediate` (the pre-10A.2 behavior: the graded result is visible to the Student the instant they submit). `quiz.result_release_at` — nullable datetime, must be in the future; **required when `result_release_mode=scheduled`** (422 otherwise). See "Quiz Result Release" below for what each mode actually does.
- Preconditions (both types): application must be `shortlisted`, (legacy) `interview_scheduled`, or, **as of Phase 10A.3**, `in_assessment` with no currently-active Assessment (422 otherwise); no *active* Assessment already exists for the application (409 if one does) — identical rules for both types, enforced by the same service (`AssessmentService`). Before Phase 10A.3, `in_assessment` could only ever mean "an Assessment already exists", so it was excluded from the allowed-status list entirely; now that a finalized (`completed`) Assessment can coexist with an `in_assessment` application, that status is allowed too, with the active-Assessment check doing the real gating.
- **Unknown `type`** (anything other than `interview`/`quiz`): standard Laravel validation failure (422, `{message, errors}` shape).
- Success (`type=interview`): 201 — creates an `Assessment` (`type=interview`, `status=scheduled`, `result=null`) and its `Interview` in one transaction, and sets the application's **`status = in_assessment`** and `reviewed_at = now()`. Response `data` is the `Assessment`, with `application` and `interview` nested — **not** `data.interview.application` (deliberately hidden at this response's call site via `makeHidden('application')`, since it would just duplicate `data.application` one level down; the legacy Interview endpoints are unaffected and keep exposing it).
- Success (`type=quiz`): 201 — creates an `Assessment` (`type=quiz`, `status=pending`, `result=null`) and its `Quiz` (`status=draft`, no questions) in one transaction, and sets the application's **`status = in_assessment`** and `reviewed_at = now()` — the exact same Application-status side effect as `type=interview`, from the same `AssessmentService` transition helper. Response `data` is the `Assessment`, with `application` and `quiz` (with `quiz.questions`, always `[]` at creation) nested. See section 7a for the full quiz-lifecycle shape.
- Errors: 401, 403, 404 (`"Application not found"`, application not owned by this organization), 409 (`"An assessment already exists for this application"` — as of Phase 10A.3, this now specifically means an *active* one), 422 (invalid source status: `"An assessment can only be created for shortlisted applications"`; validation failures).

### POST /api/organization/applications/{application}/quiz-assessment
- Middleware: `auth:sanctum, active, role:organization` — **Phase 10A.4B**
- No body. Advances this candidate straight to the Opportunity's shared Quiz template (`AssessmentService::advanceToSharedQuiz()`) instead of authoring a brand-new private Quiz — deliberately a **separate** endpoint from the one above, never an overload of its `type=quiz` payload, so this endpoint's behavior never depends on hidden Opportunity configuration the request itself doesn't state. See "Shared Quiz Template" under section 7a for the full template lifecycle.
- Preconditions: same active-Assessment/source-status rules as the endpoint above; additionally, the Opportunity must have a shared Quiz template and it must be `published` (422 `"This opportunity's quiz is not published yet."` otherwise).
- Success: 201 — creates only a new `Assessment` (`type=quiz`, `quiz_id` referencing the shared template, `status=scheduled` — the template is already published, so there's no candidate-specific draft/authoring step left) and sets the application's `status = in_assessment`, exactly like the endpoint above. **Never creates a new `Quiz` row or duplicates Questions.** Sends the Student the real "Quiz Available" notification/email at this exact moment (never when the template itself was published, and never for every applicant just because the Opportunity has a Quiz). Response `data` is the `Assessment`, with `application`/`quiz` nested — `data.quiz` is the *shared* template (resolved via `Assessment::resolvedQuiz()`/`withResolvedQuizRelation()`; the response shape is identical to the ad-hoc endpoint's, so no Flutter-side branching is needed to render it).
- Errors: 401, 403, 404 (`"Application not found"`), 409 (an active Assessment already exists), 422 (invalid source status, or the shared Quiz isn't published yet).

### GET /api/organization/applications/{application}/assessment
- Middleware: `auth:sanctum, active, role:organization`
- **As of Phase 10A.3, `data` is always an array** — the full Assessment *history* for this application, oldest first (ordered `created_at` then `id`), never just the latest one. `{"data": []}` when the (owned) application has no assessment yet. Before this phase, `data` was a single nullable object; this is a deliberate breaking response-shape change, made together with the Flutter client in the same phase — there was never any other real consumer of this endpoint. Each element is loaded exactly as before: `application` and `interview`/`quiz.questions` (whichever matches that element's `type`) nested; for `type=quiz` elements, `quiz.attempts` is also eager-loaded (Phase 10A.2) — the Organization always sees the Student's real, ungated `score` here regardless of `result_release_mode`/`result_released_at`.
- **Assessment history is preserved.** A completed Quiz `Assessment` and a later "Advance to Interview" `Assessment` for the same application both appear here, as separate array elements, forever — neither is ever deleted or overwritten to make room for the other.
- Errors: 401, 403, 404 (application not owned by this organization)

### GET /api/organization/assessments/{assessment}
- Same middleware
- Success: 200 — a single Assessment addressed by its own ID (unaffected by the history change above — this endpoint was always singular-by-ID and stays that way): `application` and `interview`/`quiz.questions` nested; `quiz.attempts` also eager-loaded for `type=quiz` (Phase 10A.2), same as above.
- Errors: 401, 403, 404 (assessment not owned by this organization)

### PUT /api/organization/assessments/{assessment}/release-result
- Same middleware — **Phase 10A.2, decision-gated as of Phase 10A.4A**
- Manually releases an already-graded Quiz result (plus, as of Phase 10A.4A, its Organization-selected next-step decision) to the Student, regardless of the quiz's configured `result_release_mode` — see "Decision-Aware Quiz Result Release" below for why an early release is always allowed once a decision is ready. Only meaningful in practice for `result_release_mode=manual` or a still-future `scheduled` release, but this endpoint does not itself check the mode.
- Preconditions: the assessment belongs to the requesting organization; `assessment.completed_at` is not null (422 `"This assessment has not been completed yet"` otherwise); `assessment.result_released_at` is still null (409 `"This result has already been released"` otherwise); **as of Phase 10A.4A**, a next-step decision must already be selected and ready (422 `"A next-step decision (Advance to Interview, Proceed to Offer, or Reject) must be selected and ready before releasing this result."` otherwise — see `QuizResultReleaseService::isNextActionReady()`).
- Success: 200 — sets `result_released_at = now()`, applies the real next-step transition (creates the real Offer, or the real Application rejection — an already-staged Interview needs no further transition, it already exists), and sends the Student exactly one combined notification/email for whichever decision was made (see "Decision-Aware Quiz Result Release" below). Returns the updated `Assessment` (`data: assessment.fresh()`). **Never itself touches `application.status`** for the Interview path (the follow-up Assessment already carries that); the Offer/Reject paths change it exactly the same way their normal, non-staged flows already do (section 7b, section 5).
- Errors: 401, 403, 404 (`"Assessment not found"`, not owned by this organization), 409 (see above), 422 (see above)

### POST /api/organization/assessments/{assessment}/next-action/interview
- Same middleware — **Phase 10A.4A**
- Body: identical to `POST /api/organization/applications/{application}/interview` (section 6) — `interview_type`, `scheduled_at`, `duration_minutes`, `meeting_link`/`location`/`contact_phone` (conditionally required per `interview_type`), `interviewer_name`/`interviewer_email`/`notes`. Validated by the exact same shared rule source (`InteractsWithInterviewRules`) — no separate, weaker validation path for a staged Interview.
- Stages "Advance to Interview" as this Quiz's next-step decision: creates a **real** follow-up `Assessment` (`type=interview`) + `Interview` via the same `AssessmentService::createInterviewAssessment()` every other Interview creation uses, with `origin_assessment_id` set to this Quiz's Assessment id. The Student is **not** notified yet and cannot see this Interview through any Student-facing endpoint (see "Student-visibility gate" below) — it becomes real and visible only once the Quiz result is released (this endpoint or the release-result endpoint above/the scheduled job).
- Preconditions: the assessment belongs to the requesting organization, is `type=quiz`, `completed_at` is not null (422 otherwise), and `result_released_at` is still null (409 `"This result has already been released"` otherwise) — the same active-Assessment invariant from section 7 applies to the created follow-up Interview.
- Success: 200 — sets `next_action = interview`, `next_action_assessment_id`, `next_action_prepared_at = now()` on the Quiz Assessment; returns the updated Quiz `Assessment`, with `next_action_assessment.interview` nested so the Organization can immediately see what was staged.
- Errors: 401, 403, 404, 409, 422

### POST /api/organization/assessments/{assessment}/next-action/offer
- Same middleware — **Phase 10A.4A**
- Body: identical to `POST /api/organization/applications/{application}/offer` (section 7b) — `title`, `salary_amount`/`salary_currency`/`salary_period`, `start_date`, `message`.
- Stages "Proceed to Offer" as this Quiz's next-step decision. **No real `Offer` row is created yet** — the validated terms are stored as JSON on `next_action_data` and nothing is sent to the Student. The real `Offer` (and its own notification/email) is created for the first time at release, by calling the exact same `OfferService::sendOffer()` every direct Offer already uses — there is no separate "staged Offer" code path to keep in sync.
- Preconditions: same as the interview variant above (owned, `type=quiz`, completed, not yet released).
- Success: 200 — sets `next_action = offer`, `next_action_data`, `next_action_prepared_at = now()` on the Quiz Assessment; returns the updated Assessment. `next_action_data` is Organization-only (never reaches any Student response).
- Errors: 401, 403, 404, 409, 422

### POST /api/organization/assessments/{assessment}/next-action/reject
- Same middleware — **Phase 10A.4A**
- No body.
- Stages "Reject" as this Quiz's next-step decision. `application.status` is **not** changed yet and the Student is **not** notified yet — the real rejection (status transition + the existing respectful rejection notification/email, unchanged from their normal path) happens only at release.
- Preconditions: same as the interview/offer variants above.
- Success: 200 — sets `next_action = reject`, `next_action_prepared_at = now()` on the Quiz Assessment; returns the updated Assessment.
- Errors: 401, 403, 404, 409, 422

All three `next-action` endpoints may be called again before release to correct an earlier decision (e.g. Interview staged, then changed to Reject, or Interview re-staged with a corrected date/time) — each call overwrites `next_action`/`next_action_data`/`next_action_prepared_at`. If the *previous* decision was `interview`, the abandoned staged Interview `Assessment` is hard-deleted first (`QuizController::discardStaleInterviewDecision()`) — otherwise it would stay `status=scheduled` (an "active" Assessment) forever, permanently blocking the active-assessment invariant (section 7) for this application even though it was never released or seen by the Student. None of the three ever change `application.status`, and none are reachable once `result_released_at` is set (409) — a decision is final and immutable the instant it's released, matching every other post-release invariant in this document.

### GET /api/student/assessments
- Middleware: `auth:sanctum, active, role:student`
- Success: 200 — every assessment belonging to the authenticated student's own applications, each with `application` and `interview`/`quiz.questions` (whichever matches `type`) nested. The nested `interview` is filtered — see "Student-visible Interview fields" below; the nested `quiz.questions` is filtered the same way — see "Student-visible Quiz fields" in section 7a.
- Errors: 401, 403

### GET /api/student/assessments/{assessment}
- Same middleware
- Success: 200 — same filtered `interview`/`quiz.questions` shape as the index above.
- Errors: 401, 403, 404 (assessment does not belong to this student)

**Student-visible Interview fields**: `GET /api/student/assessments`, `GET /api/student/assessments/{assessment}`, and `GET /api/student/interviews` (section 6) all return `Interview` with `interviewer_email`, `company_feedback`, `rating`, and `decision` omitted — these are organization-internal (post-interview evaluation data, and a staff member's email), applied per-response via `App\Http\Controllers\Student\Concerns\HidesInternalInterviewFields`, not a model-level `$hidden`. The Organization-facing Interview/Assessment endpoints above are unaffected and continue to return every field. A student's own outcome is `assessment.result` (`null` until a real decision is recorded), not `interview.decision`. **`meeting_link`/`location`/`contact_phone` are never hidden from the student** — the whole point of Phase Final-QA-1 is that the Student needs whichever one is relevant to actually attend the interview.

**Quiz-type assessments on the Student endpoints above**: as of Phase 6B-3, both `GET /api/student/assessments` and `GET /api/student/assessments/{assessment}` eager-load `quiz.questions` for a `type=quiz` assessment, with `correct_answer` stripped from every question the exact same way `GET /api/student/assessments/{assessment}/quiz` (section 7a) already does — see "Student-visible Quiz fields" there. A `type=interview` assessment's `quiz` key is simply absent (not an error). **As of Phase 10A.2**, both endpoints also strip `assessment.result` from the response whenever `assessment.isResultReleased()` is false (`completed_at` null, or `result_released_at` still null) — applied via `App\Http\Controllers\Student\Concerns\HidesUnreleasedQuizResult`, the same per-response `makeHidden` convention as the other `Hides...` traits. This never applies to `type=interview` (its `result_released_at` is always set the instant it's completed — see section 7's Assessment field note), so a Student's Interview result is never delayed.

---

## 7a. Quizzes (Organization Authoring — Phase 6B-1; Student Taking — Phase 6B-3)

Organization authoring: viewing a quiz, adding/updating/deleting its questions while still a draft, and publishing it. Student taking (Phase 6B-3): viewing a *published* quiz with the answer key stripped, starting the one attempt v1 allows, and submitting it for immediate auto-grading. No retake endpoint, no manual-grading endpoint, and no answer-review endpoint exist for either role.

A quiz belongs to one assessment (`type=quiz`, created via section 7's generic endpoint); an assessment can have at most one quiz. A quiz has `title`, `instructions` (nullable), `time_limit_minutes` (nullable), `passing_score` (integer, 0–100), its own `status` (`draft`/`published`, independent of `Assessment.status`), and, as of Phase 10A.2, `display_mode`/`questions_per_page`/`result_release_mode`/`result_release_at` (see the field descriptions on the create-assessment body above, and "Quiz Result Release" below). A question belongs to one quiz; it has `prompt`, `type` (`multiple_choice`/`true_false`), `options` (JSON array for `multiple_choice`, always `null` for `true_false`), `correct_answer`, `points` (default 1), and `position` (default 0).

**`position` is not a reliable display number.** The Organization question-authoring UI never sends an explicit `position` (it always defaults to `0` server-side), so in practice every question on a real quiz typically has the same `position` value. Question lists are always returned `orderBy('position')->orderBy('id')` (a stable, deterministic order even when every `position` ties), and consuming clients are expected to derive a 1-based display number from that returned order — never from the raw `position` field. This is a client-side rendering concern only; the backend does not renumber `position` itself.

**`correct_answer` is organization-internal** — every Organization response below includes it because the organization authored it, but it is stripped from every Student response — see "Student-visible Quiz fields" below.

### GET /api/organization/assessments/{assessment}/quiz
- Middleware: `auth:sanctum, active, role:organization`
- Success: 200 — `{"data": null}` if the (owned) assessment has no quiz yet (e.g. `type=interview`); otherwise the quiz with `questions` nested (ordered by `position`, then `id`) and, as of Phase 10A.2, `attempts` also eager-loaded — the Student's own attempt(s), with the real `score` always visible here regardless of `result_release_mode`.
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
- Success: 200 — sets `quiz.status = published` and `assessment.status = scheduled` in one transaction. **Does not touch `application.status`** — it is already `in_assessment` from quiz creation and stays there. Response `data` is the quiz with `questions` nested, plus `assessment.application` loaded. **As of Phase 10A.2**: if `result_release_mode = scheduled`, this also dispatches `ReleaseQuizResultJob` delayed until `result_release_at` (see "Quiz Result Release" below) — queued via `ShouldQueueAfterCommit`, so the job is never enqueued if the surrounding transaction rolls back.
- Errors: 401, 403, 404 (`"Quiz not found"`), 422 (see preconditions above)

### PUT /api/organization/assessments/{assessment}/release-result

See section 7 above — documented there since it addresses an `Assessment`, not a `Quiz`, route.

**Quiz Result Release (Phase 10A.2, made decision-aware in Phase 10A.4A)** — when a graded Quiz result becomes visible to the Student. Releasing a result never itself changes `application.status` beyond whatever the selected next-step decision's own normal flow already does (Offer/Reject) — see section 7's restated rule.

- **The score is always computed immediately at submission** (`POST /api/student/quizzes/{quiz}/submit`, below) — `result_release_mode` only ever gates *Student-facing visibility* of the already-graded result. The Organization can always see the real score via any Organization-facing endpoint in section 7/7a, from the moment the quiz is submitted.
- **As of Phase 10A.4A, grading readiness alone is never enough to release.** A Student-facing release additionally requires a real, ready next-step decision (`next_action` set — `interview` with its real follow-up Interview already scheduled, `offer` with its validated terms staged, or `reject` explicitly confirmed — see the `next-action` endpoints in section 7 above). The three release modes below now describe *when*, given both are true; none of them can release a technical result on its own anymore. `App\Services\QuizResultReleaseService::isNextActionReady()` is the single readiness check every path below shares.
- `result_release_mode = immediate` (default): released the instant **both** the Quiz is graded **and** a decision is ready — whichever happens second. Before Phase 10A.4A this released unconditionally at submit; now, if the Student submits before a decision exists, the result stays pending and releases automatically, immediately, the moment the Organization's decision becomes ready (no separate manual step).
- `result_release_mode = manual`: never released automatically, regardless of readiness. The organization calls `PUT /api/organization/assessments/{assessment}/release-result` (section 7) whenever it decides to — that call itself now 422s if no ready decision exists yet (see that endpoint's own doc above).
- `result_release_mode = scheduled`: released no earlier than `result_release_at`, **and** only once a decision is ready. Implemented via a real delayed queue job (`App\Jobs\ReleaseQuizResultJob`, dispatched with `->delay($quiz->result_release_at)` at publish time), backed by the existing `QUEUE_CONNECTION=database` + `jobs` table infrastructure — not a cron/`Schedule::` job (this codebase has none). If the scheduled time arrives with no decision yet, the job does **not** release (see "the forgotten-decision case" below); once the Organization's decision later becomes ready, that decision-completing request itself detects the scheduled time has already passed and releases immediately, without waiting for another job run.
- **The forgotten-decision case**: if `result_release_at` passes (or, for `immediate` mode, the Student submits) with no ready decision, the Student's result stays exactly "Assessment Submitted / Result Pending" — no score, pass/fail, Interview, or Offer ever leaks, and no email/notification is sent to the Student. For `scheduled` mode specifically, `QuizResultReleaseService::sendDecisionReminder()` sends the Organization a one-time "Decision Required" in-app notification instead (`NotificationService::notifyDecisionRequired()`, deduplicated via `decision_reminder_sent_at` so a re-run/retried job never sends it twice).
- Whichever mode/path releases it, the single place `result_released_at` is ever set is the private `release()` method inside `QuizResultReleaseService`, reached only through its three public entry points: `attemptRelease()` (submit, a decision-completion call, and the scheduled job all funnel through this — it is mode/timing-aware and implements the three bullets above via one `match` on `result_release_mode`), `releaseManually()` (the organization's explicit release endpoint — bypasses timing but still requires a ready decision), and the shared readiness check `isReadyToRelease()` they both build on. `release()` re-locks the Assessment row (`lockForUpdate()`) inside its own `DB::transaction()` and re-checks readiness before writing anything, so a delayed job racing an Organization action (or a double-click on Release, or a retried queue job) can only ever actually release once — the loser silently no-ops.
- **Exactly one Student-facing communication per release, matching the decision.** `release()` dispatches to `releaseInterviewDecision()`/`releaseOfferDecision()`/`releaseRejectDecision()` based on `next_action`: Interview sends one new combined notification/email (`NotificationService::notifyQuizDecisionInterview()`, `App\Mail\AssessmentDecisionInterviewMail`) rather than a separate "you passed" plus a separate "interview scheduled" pair; Offer calls the exact same `OfferService::sendOffer()` a direct Offer already uses (its own existing notification/email fires, once); Reject applies the same rejection transition and reuses the existing respectful rejection notification/email, once. The pre-10A.4A `QuizResultMail`/`NotificationService::notifyQuizResultAvailable()` (a standalone "here's your quiz result" message) is no longer reachable from any of these three paths — it remains defined, and its own unit tests still pass, but is dead code in the standard release flow as of this phase.
- The Student never sees a `null`/hidden `result` and a non-null `score` at the same time on **their own submit response** — see the submit endpoint below for exactly what a Student's submit response contains in each mode.
- **Backward compatibility**: a Quiz Assessment released before this phase (`result_released_at` already set, `next_action` still `null`) is treated as a completed legacy release and stays fully readable exactly as before — nothing retroactively hides it or requires a missing `next_action` to be backfilled. `isPendingDecisionRelease()`/`isNextActionReady()` are only ever consulted for a *still-unreleased* Assessment.
- **Student-visibility gate for the staged next-step Assessment/data**: while a Quiz's decision is staged but unreleased, `Assessment::isPendingDecisionRelease()` (`origin_assessment_id !== null && ! originAssessment.isResultReleased()`) hides the real follow-up Interview `Assessment` from every Student-facing endpoint — `GET /api/student/assessments` (filtered out of the list), `GET /api/student/assessments/{assessment}` (404, the same "not found" a wrong-owner request gets), `GET /api/student/interviews` (filtered out), and the Student dashboard's `total_interviews` count (excluded). A staged Offer never reaches the Student at all before release, since no real `Offer` row exists yet. `next_action`/`next_action_data`/`next_action_prepared_at`/`next_action_assessment_id`/`origin_assessment_id`/`decision_reminder_sent_at` are additionally stripped, unconditionally, from every Student-facing Quiz Assessment response by `HidesUnreleasedQuizResult`.

**Student-visible Quiz fields**: every Student Quiz response below (and the nested `quiz.questions` on `GET /student/assessments`/`GET /student/assessments/{assessment}`, section 7) returns a `Question` with `correct_answer` omitted — applied per-response via `App\Http\Controllers\Student\Concerns\HidesInternalQuestionFields`, not a model-level `$hidden`, the exact same convention `HidesInternalInterviewFields` already uses for Interview. Every other Question field (`prompt`, `type`, `options`, `points`, `position`) is returned as-is. `true_false` questions return `options: null` — the same representation the Organization side already uses; the client is expected to know the two fixed choices rather than read them from the response. `Quiz.passing_score` **is** included (a deliberate v1 product decision: the passing threshold is a transparent, known-in-advance assessment rule, not a grading internal).

### GET /api/student/assessments/{assessment}/quiz
- Middleware: `auth:sanctum, active, role:student`
- Preconditions: the assessment belongs to one of the student's own applications, `assessment.type = quiz`, a quiz exists, and `quiz.status = published`.
- Success: 200 — the quiz with `questions` nested (ordered by `position`, then `id`), `correct_answer` omitted from each. **Phase 10A.4B addendum**: also returns `available_at`/`due_at` (this candidate's own frozen window, both `null` for no gating); while the window is upcoming (`now < available_at`), `questions` is an empty array instead — see "Candidate Availability Window" above.
- Errors: 401, 403, 404 (`"Quiz not found"` — used uniformly for "not your application", "not a quiz assessment", "no quiz yet", and "quiz still draft", deliberately never distinguishing which case applies)

### POST /api/student/quizzes/{quiz}/start
- Same middleware
- Preconditions: the quiz belongs to one of the student's own applications; `quiz.status = published`; no *submitted* attempt already exists. **Phase 10A.4B addendum**: for a genuinely new attempt (never for a resumed, already-started one), `now >= available_at` and `now <= due_at` (both skipped when `null` — no gating).
- Success: 201 (new attempt) or 200 (an unsubmitted attempt already existed — see below) — `{"data": {"id", "quiz_id", "application_id", "started_at", "submitted_at": null, "score": null}}`.
- **Idempotent for an in-progress attempt**: calling this again while the existing attempt is still unsubmitted returns that *same* attempt (200, message `"Quiz attempt resumed"`) rather than creating a duplicate — `started_at` is never reset and no extra time is granted. A genuine concurrent double-start race is resolved the same way, via the `quiz_attempts` unique constraint.
- Sets `assessment.status = in_progress`. **Does not touch `application.status`**, which is already `in_assessment` and stays there.
- Errors: 401, 403, 404 (`"Quiz not found"`), 409 (`"Quiz has already been submitted"` — a submitted attempt can never restart; no retakes in v1), 422 (**Phase 10A.4B addendum**: `"This assessment is not available yet."` before `available_at`; `"The submission deadline for this assessment has passed."` after `due_at`)

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
- Preconditions: the quiz belongs to one of the student's own applications; an attempt must already exist (`422` `"Start the quiz before submitting."` otherwise — Submit never implicitly starts); the attempt must not already be submitted; `now()` must not be past the *effective* cutoff — **Phase 10A.4B addendum**: whichever of `attempt.started_at + quiz.time_limit_minutes` and `due_at` is earlier (previously: only the time limit). See "Candidate Availability Window" above for the worked example.
- Success: 200 — grades server-side (see docs/BUSINESS_RULES.md for the exact scoring formula and rounding rule), then atomically: `quiz_attempts.answers`/`score`/`submitted_at` are saved, and `assessment.status = completed`, `assessment.result = passed|failed`, `assessment.completed_at = now()`. **`application.status` is never touched** — it remains `in_assessment`; the organization's own next-step decision stays separate. Grading alone never releases anything: `QuizResultReleaseService::attemptRelease()` is called in the same transaction, but (as of Phase 10A.4A) it only actually releases if the Organization *already* has a ready next-step decision waiting **and** the mode/timing rule is satisfied — see "Quiz Result Release" above. In the common case (no decision made yet), submit leaves the result pending; it releases automatically, later, the instant the Organization's decision becomes ready. Response `data` is the attempt: `score` is included only when the result was actually released in this same request — otherwise `score` is stripped from this response (`makeHidden(['score'])`) even though it was already computed and saved. `correct_answer`/per-question correctness are never included either way.
- Errors: 401, 403, 404 (`"Quiz not found"`), 409 (`"Quiz has already been submitted"`), 422 (validation failures above, `"Start the quiz before submitting."`, `"Quiz time limit has expired"` when the personal timer was the binding constraint, or **Phase 10A.4B addendum** `"The submission deadline for this assessment has passed."` when `due_at` was the binding constraint)

### Shared Quiz Template (Phase 10A.4B)

Lets ONE Quiz definition (title/instructions/settings/questions) be authored once for an Opportunity and reused by every candidate's own Assessment, instead of one private Quiz per candidate (the "legacy" ad-hoc shape above, fully preserved and unchanged). A Quiz row is always exactly one of the two shapes — `assessment_id` set (legacy) or `opportunity_id` set (shared template) — never both, never neither. See docs/ARCHITECTURE.md's "Decision-Aware Quiz Result Release Architecture" section's sibling for the full schema/relationship rationale.

`GET`/`POST`/`PUT /organization/opportunities/{opportunity}/quiz` and `PUT .../quiz/publish` manage the template itself; the existing `POST/PUT/DELETE /organization/quizzes/{quiz}/questions[/{question}]` and `PUT /organization/quizzes/{quiz}/publish`-*sibling* rules (question CRUD) are **reused unchanged** for a template's `{quiz}` id — the same endpoints already used for a legacy Quiz's questions, since question authoring only ever needs `quiz.id`, never `assessment_id`. (The legacy `PUT /organization/quizzes/{quiz}/publish` route itself, unlike question CRUD, explicitly 404s for a template id — see below.)

### GET /api/organization/opportunities/{opportunity}/quiz
- Middleware: `auth:sanctum, active, role:organization`
- Success: 200 — `data: null` (never a 404) when the Opportunity has no template yet, matching the existing "no quiz yet" convention for a single Assessment; otherwise the template with `questions` nested.
- Errors: 401, 403, 404 (opportunity not owned/missing)

### POST /api/organization/opportunities/{opportunity}/quiz
- Same middleware
- Body: `title` (required), `instructions`/`time_limit_minutes` (nullable), `passing_score` (required, 0–100), `display_mode`/`questions_per_page`/`result_release_mode`/`result_release_at` (all optional, same cross-field rules as the ad-hoc `quiz.*` payload above — see `App\Http\Requests\Organization\StoreOpportunityQuizRequest`) — flat, never nested under a `quiz` key (unlike the generic assessments endpoint's body). **Phase 10A.4B addendum**: `availability_delay_days` (required, integer, min:0), `availability_time` (required, `"H:i"` 24-hour string, e.g. `"10:00"`), `submission_window_hours` (required, integer, min:1) — the shared candidate-availability policy, see "Candidate Availability Window" below. These three fields are **unconditionally required** on this endpoint (unlike every other field above) — a shared template always needs a real policy, since every candidate advanced to it gets a computed window. They are never accepted on the legacy ad-hoc `quiz.*` payload (`POST /organization/applications/{application}/assessments`) at all.
- One shared template per Opportunity — `quizzes.opportunity_id` is unique.
- Success: 201 — `status=draft`, no questions yet, exactly like the legacy ad-hoc creation.
- Errors: 401, 403, 404 (opportunity not owned/missing), 409 (a template already exists), 422 (field validation)

### PUT /api/organization/opportunities/{opportunity}/quiz
- Same middleware, same body shape as POST — full replacement, including the three availability-policy fields (still required).
- Preconditions: a template must already exist; `status = draft` (422 `"Published quizzes cannot be modified"` otherwise — the identical immutability rule question CRUD already enforces, applied here to the template's own settings too).
- Success: 200
- Errors: 401, 403, 404 (opportunity not owned/missing, or no template yet), 422 (already published, or field validation)

### PUT /api/organization/opportunities/{opportunity}/quiz/publish
- Same middleware
- Unlike the legacy `PUT /organization/quizzes/{quiz}/publish`, **never touches any Assessment/Application/notification** — no candidate has been advanced yet at template-publish time. A candidate only gets their own Assessment (and their own "Quiz Available" notification/email) later, via `POST /organization/applications/{application}/quiz-assessment` (section 7).
- Preconditions: `status = draft` (422 otherwise); at least one question (422 otherwise) — same two guards as the legacy publish endpoint.
- Success: 200 — `status=published`.
- Errors: 401, 403, 404 (opportunity not owned/missing, or no template yet), 422 (already published, or zero questions)

### GET /api/organization/opportunities/{opportunity}/quiz/results
- Same middleware
- Every candidate advanced to the shared Quiz, in one deliberately query-conscious pass (one query for the Assessments, one for their `QuizAttempt`s keyed by `application_id` — never N+1 per candidate; see `OpportunityQuizController::results()`).
- Success: 200 — `{"data": {"quiz": {...}, "candidates": [{assessment_id, application_id, student_name, status, score, result, result_released_at, next_action, next_action_prepared_at, interview, available_at, due_at, timing_status}]}}`. `score`/`result`/`next_action` are `null` until that candidate has submitted/decided, exactly matching the per-candidate endpoints' own null-until-ready convention. **Phase 10A.4B addendum**: `available_at`/`due_at` are this candidate's own frozen window (both `null` for a legacy/no-policy assignment); `timing_status` is a derived, never-stored label — one of `upcoming`/`available`/`in_progress`/`submitted`/`deadline_passed` — computed fresh on every request by `Assessment::quizTimingStatus()` (see "Candidate Availability Window" below). **Never includes `answers` or `correct_answer`** — this is a score/result/decision summary only, matching the single-candidate view's own scope; a full answer review stays out of scope for this phase.
- Errors: 401, 403, 404 (opportunity not owned/missing)

**Ownership resolution for a template's questions.** `Organization\QuizController::ownsQuiz()` branches on which of the two Quiz shapes `{quiz}` is: a template resolves ownership through `quiz.opportunity.organization_id`; a legacy Quiz resolves it through `quiz.assessment.application.opportunity.organization_id` exactly as before. Every question CRUD endpoint reuses this transparently — no endpoint-level change was needed for them to already work correctly against a template id.

**Candidate isolation on a shared Quiz.** `quiz_attempts` was already correctly shaped for this before this phase (unique on `quiz_id` + `application_id`, not `assessment_id`) — no schema change needed there. `Student\QuizController::start()`/`submit()`/`show()` now resolve "this candidate's own Assessment for this Quiz" via a query (`Assessment::where('quiz_id', ...)->where('application_id', ...)`) rather than assuming `quiz->assessment` is a single unambiguous row — every candidate advanced to the same shared `{quiz}` id gets their own independent Assessment/Attempt/score/answers, and a Student can never start/submit/view a shared Quiz they weren't personally advanced to (the same ownership check as the legacy path, just resolved the other direction). The single-candidate Organization views (`GET .../assessments/{assessment}/quiz`, `GET .../assessments/{assessment}`) narrow `attempts` down to that one candidate's own `application_id` — a shared Quiz's `attempts()` relation spans every candidate, and these must never leak another candidate's attempt into a one-candidate response.

### Candidate Availability Window (Phase 10A.4B addendum)

Every candidate advanced to a shared Quiz template gets their **own** frozen availability window, computed once at the moment they're advanced (`AssessmentService::advanceToSharedQuiz()`) from the shared policy configured on the template (`availability_delay_days`/`availability_time`/`submission_window_hours` — see the `POST`/`PUT .../quiz` body above). The policy lives on the Quiz; the computed dates (`available_at`/`due_at`) live on the candidate's own `Assessment` row, **never recalculated on read** — a later edit to the template's policy (while still `draft`) never retroactively moves an already-advanced candidate's dates, since editing a published template is already blocked entirely.

**Calculation**: given the assignment moment `now`, `available_at = now->addDays(availability_delay_days)->setTimeFromTimeString(availability_time)` (an overwrite of the time-of-day, not an addition), and `due_at = available_at->addHours(submission_window_hours)`. Worked example: assigned 2026-08-26 15:00, policy `(2, "10:00", 48)` → `available_at` 2026-08-28 10:00, `due_at` 2026-08-30 10:00. Both `null`/`null` for every legacy Assessment (ad-hoc Quiz, or a candidate that predates this addendum) — `null` means "no gating", i.e. immediately available with no deadline, exactly the pre-addendum behavior, with zero backfill/migration needed.

**Timezone**: unchanged from the rest of the app — `config('app.timezone') === 'UTC'`, no custom date serialization, every timestamp above is a plain UTC ISO-8601 string. This addendum does not introduce a second timezone convention.

**Assignment is the only trigger.** A Student gets nothing merely by applying, and nothing at shared-template *publish* time either — only `POST /organization/applications/{application}/quiz-assessment` (section 7) computes a window and sends the "Quiz Available" notification/email (reusing the existing `NotificationService::notifyQuizPublished()`/`QuizAvailableMail` — no second email is ever sent when the window later opens), now with truthful `available_at`/`due_at`/time-limit content when they're set (byte-for-byte the original message when they're `null`, i.e. the legacy ad-hoc path).

**`GET /api/student/assessments/{assessment}/quiz`** (and the generic `GET /api/student/assessments[/{assessment}]`, section 7): now also returns `available_at`/`due_at` as response-shaping extras attached from the candidate's own Assessment (never real `Quiz` columns). **While the candidate's window is upcoming (`now < available_at`), `questions` is returned as an empty array** — the question text/options are never leaked ahead of the real availability moment, on any endpoint that nests `quiz.questions`, matching the addendum's explicit "don't leak Upcoming quiz content" requirement. Once available, `questions` (with `correct_answer` stripped, as always) returns normally.

**`POST /api/student/quizzes/{quiz}/start`**: now additionally rejects (never a 500) —
- `now < available_at` → 422, `"This assessment is not available yet."`
- `now > due_at` (and no attempt yet — a resumed, already-started attempt is never blocked by this) → 422, `"The submission deadline for this assessment has passed."`

Both checks are skipped entirely for a legacy Assessment (`available_at`/`due_at` both `null`) — no gating, exactly pre-addendum behavior.

**`POST /api/student/quizzes/{quiz}/submit`**: the real submission cutoff is now whichever of two independent constraints comes first — the candidate's personal timer (`attempt.started_at + quiz.time_limit_minutes`, if set) and their own `due_at` (if set). Worked example (the addendum's own): `due_at` 18:00, started 17:50, a 30-minute time limit (which alone would allow until 18:20) — the cutoff stays 18:00, not 18:20, because `due_at` is the earlier constraint. The 422 message reflects whichever constraint actually bound: `"The submission deadline for this assessment has passed."` when `due_at` is earlier or the only constraint, or the existing `"Quiz time limit has expired"` when the personal timer is earlier. A quiz with no time limit and no `due_at` never expires, exactly as before.

**No automatic rejection or invented result.** A deadline passing with no submission is purely a timing state — it never automatically sets `assessment.result`, never marks the Application rejected, and never fabricates a "failed" outcome. The Organization retains full recruitment authority over what happens next, exactly as it already did for every other stage of this flow.

**Result release stays completely independent.** `available_at`/`due_at`/the submission deadline are entirely separate from `result_released_at` (Phase 10A.2/10A.4A) — a candidate can submit well within their window and still have their result released later, on whatever schedule the Organization's decision/`result_release_mode` produces; nothing about this addendum changes that release logic.

**Backward compatibility.** Every Assessment created before this addendum (and every legacy ad-hoc Quiz Assessment created after it) has `available_at`/`due_at` both `null` — fully readable, no retroactive invalidation, no gating applied anywhere this addendum reads those columns.

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

Both this section's `analyze` endpoint and `GET .../recommended-candidates` (section 5a) run the exact same rule-based `MatchingService` v2.0, "Candidate Opportunity Preferences + Final Recommendation Match Formula" (Phase 8A-2, tightened by the Opportunity Academic Matching Cleanup, extended by "Recommendation Match: Major Must Contribute to Total Score", extended again by this phase) — one shared implementation, confirmed by the Matching Formula Audit. **Final, product-approved weights — Claude/any future change must not alter these without a new explicit product decision:**
- **On-site / Hybrid**: Required Skills 60%, Major 25%, Location 15%.
- **Remote**: Required Skills 70%, Major 30%, Location **not considered at all** (never computed, never in the denominator, never a phantom `0`).

**Experience is removed from the formula entirely** (previously 20% in v1.1–v1.3) — `opportunity.experience_level` is left in the database untouched for backward compatibility, but is never read by `MatchingService` any more, never excludes a candidate, and never appears in any response.

**The Major factor is never `opportunity.field_of_study`** — v1.1 originally compared student major vs. that legacy free-text column; the Opportunity Academic Matching Cleanup (v1.2) removed the factor entirely, reasoning that every candidate reaching this formula had already passed the canonical `eligible_majors` eligibility gate (section 5b), so scoring it too would be a disguised, constant-100 double-count. "Recommendation Match: Major Must Contribute to Total Score" was an explicit product decision that Major must nonetheless genuinely contribute to the total "Match" percentage — a `0%` next to "Major is eligible" no longer represented total compatibility. The factor was rebuilt on the same canonical `eligibleMajorRecords` the gate already used (never `field_of_study`, which remains unused by any factor) — the resulting overlap between gate and score, for a candidate reached through the normal path, is an accepted, explicit tradeoff, not a double-count; see `MatchingService`'s own doc comment on the backend for the full reasoning. **Location joins Major as a second such factor** (new in this phase) — `MatchingService::analyzeLocation()` compares the Opportunity's canonical `location_id` against the Student's `available_location_ids`, the same canonical IDs `isLocationEligible()` already gates Recommended Candidates with, for the identical reason.

No factor is ever given a fake placeholder value — a factor that can't be genuinely scored (e.g. the opportunity has no skills listed, no Eligible Majors configured, or — On-site/Hybrid only — no canonical location configured) is excluded and its weight is redistributed proportionally across the remaining scoreable factors of that work mode's own weight table, so `overall_match_score`/`match_score` always normalizes to 0–100 and is always a real, computed number (never `null`). **Every factor contribution returned is already expressed in the exact points that sum to the total** (`*_weight`/`*_contribution` alongside each raw `*_match_score`) — `overall_match_score` is literally defined as the sum of these already-rounded contributions, so a client displaying them can never see numbers that fail to add up, and never computes a Match score itself.

**Eligibility (type interest, major, location) is a gate checked before, and independently of, Match — but Major's and Location's underlying comparisons legitimately serve both roles.** `OpportunityEligibilityService` gates who is scored/shown at all (section 5a-i/5b/5c), computed and checked separately from `MatchingService`; a candidate who fails any eligibility check is excluded outright, never shown with a docked score. `MatchingService::analyzeMajor()`/`analyzeLocation()` perform the identical canonical comparisons as genuine, separately-computed scoring factors, so a major-/location-eligible candidate's real fit now visibly contributes to their total. A candidate can still score low if Skills is genuinely unmatched or unavailable (and, On-site/Hybrid, if Location happens to be the only other scoreable factor and is also low) — this is the correct, honest output of the real formula. Opportunity Type interest, by contrast, is a pure eligibility filter and never scores anything — see docs/BUSINESS_RULES.md section 9.

**Auto-calculated on application submission (Phase 8A-2):** `POST /api/opportunities/{opportunity}/apply` now calculates and persists `match_score` synchronously, inside the same transaction as the Application insert — no queue, no external call, no extra request needed. `POST .../analyze` below remains the explicit manual **recalculation** endpoint (same formula, no duplicate implementation) — useful after the student's profile/skills or the opportunity's requirements change. Applications created before Phase 8A-2 are not backfilled and keep `match_score: null` until an organization explicitly calls `analyze`.

**`match_score` is organization-internal** — see section 5's "Organization applicant ranking" and "Student-visible Application fields" notes (Phase 8A-1) for exactly where it's used to rank applicants and exactly which Student-facing responses omit it. This still holds for auto-calculated scores.

### POST /api/organization/applications/{application}/analyze
- Middleware: `auth:sanctum, active, role:organization`
- Success: 200 — `{"data": {"overall_match_score": n, "skills_match_score": n|null, "skills_weight": n|null, "skills_contribution": n|null, "major_match_score": n|null, "major_weight": n|null, "major_contribution": n|null, "location_match_score": n|null, "location_weight": n|null, "location_contribution": n|null, "strengths": [...], "weaknesses": [...], "recommendation": "..."}}`. `major_match_score` is computed from canonical `eligibleMajorRecords`, `location_match_score` from canonical `available_location_ids` — neither ever from `opportunity.field_of_study` (see section 8's own note above). `location_*` are always `null` for a Remote Opportunity. There is no `experience_match_score` any more. A per-factor score is `null` when that factor was unavailable (not scoreable), not a fake neutral value. Persists `overall_match_score` into `applications.match_score`. Does not touch `status`, does not create `Interview`/`Notification` records.
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

### DELETE /api/notifications/{notification}
- Same middleware
- **New in Phase 9.1.** Permanently removes one notification from the caller's own inbox. Safe by construction — no other table has a foreign key onto `notifications` (the only relationship is `User hasMany Notification`), so this can never affect an Application, Invitation, Assessment, Interview, Offer, or EducationVerification row. Inbox cleanup only, never a reversal of the underlying business event.
- Success: 200
- Errors: 401, 403, 404 (not found / not yours)

### DELETE /api/notifications/read
- Same middleware
- **New in Phase 9.1.** Permanently removes every currently-*read* notification from the caller's own inbox — unread notifications are never touched or affected. Registered before `DELETE /api/notifications/{notification}` so the literal `read` path is never swallowed by that route's `{notification}` wildcard.
- Success: 200 — `{"data": {"deleted_count": n}}`
- Errors: 401, 403

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
