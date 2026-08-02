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
- An organization can schedule interviews for applications that are `shortlisted` or already `interview_scheduled` (re-scheduling is blocked separately by the one-assessment-per-application rule, not by this status check).

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
  - interview_scheduled
  - accepted
  - rejected
  - withdrawn
- Match score is calculated after application submission.
- A rejected application cannot be accepted unless admin/organization updates it manually — this is a stated rule, not one enforced by the API: the status endpoint currently accepts any of `reviewed, shortlisted, interview_scheduled, accepted, rejected` in any order, with no transition state machine.
- A withdrawn application is controlled by the student.
- **`application.status` owns recruitment progress only** (Phase 4A-1 decision). It does not, and must not, describe assessment-path or assessment-outcome detail — that belongs to `assessment.status`/`assessment.result` (see section 7). `interview_scheduled` remains a valid `application.status` value for backward compatibility with existing records, existing Interview APIs, and existing tests; it is not removed or replaced by this phase, and no new generic "in progress" status value has been added — this phase intentionally leaves the completed application workflow unchanged.

## 6. Interview Rules

- Every interview belongs to one **assessment** (Phase 4A-1) rather than directly to an application — an interview is reached via `application → assessment → interview`. An application can still, in effect, have at most one interview, because it can have at most one assessment (see section 7) and an assessment can have at most one interview.
- Interviews can be online, phone, or on-site.
- Interview details must include date and time.
- Interview location or link is required depending on meeting type.
- Each interview can record a decision (pending, passed, failed, waiting) and an optional rating after it is completed. Completing an interview also updates its assessment's `status`/`result`/`completed_at`, but never changes the application's status — that remains a separate, organization-triggered decision.
- All existing Interview API routes (`/api/organization/applications/{application}/interview`, `/api/organization/interviews*`, `/api/student/interviews`) continue to work exactly as before; this phase is a backend restructuring, not a behavior change to any of them (see docs/API.md section 6).

## 7. Assessment Rules

Added in Phase 4A-1 as the shared foundation for future Interview/Quiz assessment paths.

- An assessment belongs to one application; an application can have **at most one assessment**.
- An assessment has a `type`: `interview` or `quiz`. **Only `interview` is implemented in this phase** — the `quiz` value is schema-ready only; there is no quiz table, no quiz creation path, and no quiz UI.
- An assessment has a `status` (`pending, scheduled, in_progress, completed, declined, cancelled`) and a `result` (`passed, failed, waiting`, or no result yet — represented as `null`, not the string `pending`).
- Today, an assessment (and its interview) is only ever created as a side effect of the existing "schedule interview" endpoint — there is no standalone "create assessment" or "organization chooses assessment type" endpoint yet. Only read endpoints exist for assessments (`GET /api/organization/applications/{application}/assessment`, `GET /api/organization/assessments/{assessment}`, `GET /api/student/assessments`, `GET /api/student/assessments/{assessment}`) — see docs/API.md section 7.
- An assessment's own `status`/`result` never changes `application.status`; only the organization's explicit status-update action does that.

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