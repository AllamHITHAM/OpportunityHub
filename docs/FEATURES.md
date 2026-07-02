

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

## Company Profile

Tables:
- users
- company_profiles

APIs:
- GET /api/company/profile
- POST /api/company/profile
- PUT /api/company/profile

Backend:
- CompanyProfileController
- CompanyProfile model

Flutter:
- Company Profile Screen
- Edit Company Profile Screen

---

## Skills

Tables:
- skills
- student_skills
- opportunity_skills

APIs:
- GET /api/skills
- POST /api/student/skills
- DELETE /api/student/skills/{id}

Backend:
- SkillController
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
- company_profiles

APIs:
- GET /api/opportunities
- GET /api/opportunities/{id}
- POST /api/company/opportunities
- PUT /api/company/opportunities/{id}
- DELETE /api/company/opportunities/{id}

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
- GET /api/company/opportunities/{id}/applications
- PUT /api/company/applications/{id}/status

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
- POST /api/company/applications/{id}/interview
- GET /api/student/interviews
- GET /api/company/interviews

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
- triggered internally when applying

Backend:
- MatchingService
- ApplicationController

Flutter:
- Company Applicants Screen