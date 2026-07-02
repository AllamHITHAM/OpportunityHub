# OpportunityHub Database Roadmap

## Approved Tables

1. users
- Primary Key: id
- Foreign Keys: none
- Purpose: Core account table for students, organizations, and admins.

2. skills
- Primary Key: id
- Foreign Keys: none
- Purpose: Master list of reusable skills.

3. student_profiles
- Primary Key: id
- Foreign Keys: user_id → users.id
- Purpose: Extended student profile data.

4. organization_profiles
- Primary Key: id
- Foreign Keys: user_id → users.id
- Purpose: Extended profile data for organizations posting opportunities (company, university, NGO, training center, government, or other), including approval status.

5. cvs
- Primary Key: id
- Foreign Keys: student_id → student_profiles.id
- Purpose: Uploaded CV files belonging to students.

6. student_skills
- Primary Key: id
- Foreign Keys:
  - student_id → student_profiles.id
  - skill_id → skills.id
- Purpose: Connect students with their skills.

7. opportunities
- Primary Key: id
- Foreign Keys: organization_id → organization_profiles.id
- Purpose: Job/internship/opportunity posts created by organizations.

8. opportunity_skills
- Primary Key: id
- Foreign Keys:
  - opportunity_id → opportunities.id
  - skill_id → skills.id
- Purpose: Connect opportunities with required skills.

9. applications
- Primary Key: id
- Foreign Keys:
  - student_id → student_profiles.id
  - opportunity_id → opportunities.id
  - cv_id → cvs.id
- Purpose: Student applications to opportunities.

10. interviews
- Primary Key: id
- Foreign Keys: application_id → applications.id
- Purpose: Interview scheduling linked to applications, including an outcome decision (pending, passed, failed, waiting) and an optional rating.

11. notifications
- Primary Key: id
- Foreign Keys: user_id → users.id
- Purpose: In-app notifications for users, including a priority level (low, normal, high) and the timestamp they were sent.

## Creation Order

1. users
2. skills
3. student_profiles
4. organization_profiles
5. cvs
6. student_skills
7. opportunities
8. opportunity_skills
9. applications
10. interviews
11. notifications

## Current Progress

Created:
- users
- skills
- student_profiles
- organization_profiles
- cvs
- student_skills
- opportunities
- opportunity_skills
- applications
- interviews
- notifications

All 11 approved tables have been created.