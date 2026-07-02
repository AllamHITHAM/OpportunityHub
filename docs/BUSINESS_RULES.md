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
- An organization can schedule interviews for shortlisted or reviewed applications.

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
- A rejected application cannot be accepted unless admin/organization updates it manually.
- A withdrawn application is controlled by the student.

## 6. Interview Rules

- Every interview belongs to one application.
- An application can have one interview appointment.
- Interviews can be online, phone, or on-site.
- Interview details must include date and time.
- Interview location or link is required depending on meeting type.
- Each interview can record a decision (pending, passed, failed, waiting) and an optional rating after it is completed.

## 7. Notification Rules

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

## 8. AI Matching Rules

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

## 9. Security Rules

- Passwords must be hashed.
- Users cannot access data that does not belong to them.
- Admin-only actions must be protected.
- Organization-only actions must be protected.
- Student-only actions must be protected.
- API responses should not expose sensitive data.