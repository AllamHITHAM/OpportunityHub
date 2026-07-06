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
- Success: 200 — `{"data": {"total_applications": n, "pending_applications": n, "reviewed_applications": n, "shortlisted_applications": n, "accepted_applications": n, "rejected_applications": n, "total_cvs": n, "total_skills": n, "total_interviews": n}}`
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
- Success: 200 — `{"data": {"total_opportunities": n, "open_opportunities": n, "closed_opportunities": n, "draft_opportunities": n, "total_applications": n, "pending_applications": n, "shortlisted_applications": n, "accepted_applications": n, "rejected_applications": n, "total_interviews": n, "completed_interviews": n}}`
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
- Body: `status` (required, in: reviewed, shortlisted, interview_scheduled, accepted, rejected — **not** `pending`/`withdrawn`)
- Success: 200 — also sets `reviewed_at = now()` on every successful call, even if re-setting the same status.
- Errors: 401, 403, 404, 409 ("Cannot change the status of a withdrawn application"), 422

---

## 6. Interviews

### POST /api/organization/applications/{application}/interview
- Middleware: `auth:sanctum, active, role:organization`
- Body: `interview_type` (required, in: onsite, online, phone), `scheduled_at` (required, date), `duration_minutes` (nullable, integer, min:1, default 60), `meeting_link` (required if `interview_type=online`), `location` (required if `interview_type=onsite`), `interviewer_name`/`interviewer_email`/`notes` (nullable)
- Preconditions: application must be `shortlisted` or `interview_scheduled` (422 otherwise); one interview per application max (409 if one already exists).
- Success: 201 — also sets the application's `status = interview_scheduled` and `reviewed_at = now()`, in one DB transaction.
- Errors: 401, 403, 404, 409, 422

### GET /api/organization/interviews
- Same middleware
- Success: 200 — every interview across this organization's applications
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
- Success: 200 — sets `status = completed`, `completed_at = now()`, plus any provided outcome fields. Does **not** change the related application's status.
- Errors: 401, 403, 404, 422

### DELETE /api/organization/interviews/{interview}
- Same middleware
- Success: 200 — hard delete; does not change the related application's status
- Errors: 401, 403, 404, 409 ("Completed interviews cannot be deleted")

### GET /api/student/interviews
- See section 2.

---

## 7. AI Matching

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

## 8. Notifications

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

## 9. Admin

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

## 10. Dashboard

Dashboard routes are listed under their respective role sections above (`/api/student/dashboard`, `/api/organization/dashboard`, `/api/admin/dashboard`) since each is protected by that role's middleware group, not a separate one.
