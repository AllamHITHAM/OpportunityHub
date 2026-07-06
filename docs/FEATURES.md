

# OpportunityHub Feature Map

This file connects features with database tables, APIs, backend modules, and Flutter screens.

## Authentication

Tables:
- users

APIs:
- POST /api/register
- POST /api/login
- POST /api/logout
- GET /api/me

Backend:
- AuthController
- User model

Flutter:
- Login Screen
- Register Screen
- Splash Screen

---

## Student Profile

Tables:
- users
- student_profiles

APIs:
- GET /api/student/profile
- POST /api/student/profile
- PUT /api/student/profile

Backend:
- StudentProfileController
- StudentProfile model

Flutter:
- Student Profile Screen
- Edit Profile Screen

---

## Organization Profile

Tables:
- users
- organization_profiles

APIs:
- GET /api/organization/profile
- PUT /api/organization/profile

Backend:
- OrganizationProfileController
- OrganizationProfile model

Flutter:
- Organization Profile Screen
- Edit Organization Profile Screen

---

## Skills

Tables:
- skills
- student_skills
- opportunity_skills

APIs:
- GET /api/skills (planned, not yet implemented — no public skill-catalog browse endpoint exists today)
- GET /api/student/skills
- POST /api/student/skills
- DELETE /api/student/skills/{id}
- GET /api/admin/skills
- POST /api/admin/skills
- PUT /api/admin/skills/{id}
- DELETE /api/admin/skills/{id}

Backend:
- Admin\SkillController
- StudentSkillController
- Skill model

Flutter:
- Manage Skills Screen

---

## CV Upload

Tables:
- cvs
- student_profiles

APIs:
- GET /api/student/cvs
- POST /api/student/cvs
- DELETE /api/student/cvs/{id}
- PUT /api/student/cvs/{id}/default

Backend:
- CVController
- CV model

Flutter:
- CV Upload Screen
- My CVs Screen

---

## Opportunities

Tables:
- opportunities
- opportunity_skills
- organization_profiles

APIs:
- GET /api/opportunities
- GET /api/opportunities/{id}
- POST /api/organization/opportunities
- PUT /api/organization/opportunities/{id}
- DELETE /api/organization/opportunities/{id}

Backend:
- OpportunityController
- Opportunity model

Flutter:
- Opportunities List Screen
- Opportunity Details Screen
- Add Opportunity Screen

---

## Applications

Tables:
- applications
- opportunities
- student_profiles
- cvs

APIs:
- POST /api/opportunities/{id}/apply
- GET /api/student/applications
- GET /api/organization/opportunities/{id}/applications
- PUT /api/organization/applications/{id}/status

Backend:
- ApplicationController
- Application model

Flutter:
- My Applications Screen
- Applicants Screen

---

## Interviews

Tables:
- interviews
- applications

APIs:
- POST /api/organization/applications/{id}/interview
- GET /api/student/interviews
- GET /api/organization/interviews

Backend:
- InterviewController
- Interview model

Flutter:
- Interview Details Screen
- Schedule Interview Screen

---

## Notifications

Tables:
- notifications
- users

APIs:
- GET /api/notifications
- PUT /api/notifications/{id}/read

Backend:
- NotificationController
- Notification model

Flutter:
- Notifications Screen

---

## AI Matching

Tables:
- skills
- student_skills
- opportunities
- opportunity_skills
- applications

APIs:
- POST /api/organization/applications/{id}/analyze (organization-triggered, on demand — not automatic on application submission)
- GET /api/organization/applications/{id}/analysis (read-only, returns the stored result)

Backend:
- MatchingService
- ApplicationAnalysisController

Flutter:
- Organization Applicants Screen