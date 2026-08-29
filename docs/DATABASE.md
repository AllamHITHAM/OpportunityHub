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
- Foreign Keys: user_id → users.id; current_location_id → locations.id (nullable — Student Location Profile Patch)
- Purpose: Extended student profile data, including the Student's own optional current/home location.

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
- Foreign Keys: organization_id → organization_profiles.id; location_id → locations.id (nullable — Phase O8.2)
- Purpose: Job/internship/opportunity posts created by organizations. Legacy free-text `location` is preserved for historical rows alongside the canonical `location_id` (see `locations` below).

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

12. locations (Phase O8.2)
- Primary Key: id
- Foreign Keys: none
- Purpose: The canonical Location Catalog — a fixed, admin-curated set of real places (`canonical_name`, unique), each with one stable ID. Every location reference elsewhere in the schema (a Student's current/available locations, an Opportunity's location) goes through this table's `id`, never a raw display string — this is what lets "Nablus", "Nablus, Palestine", and "نابلس" never become three unrelated logical locations.

13. student_available_locations (Phase O8.2)
- Primary Key: id
- Foreign Keys:
  - student_profile_id → student_profiles.id
  - location_id → locations.id
- Purpose: A Student's own multiple available/preferred *work* locations (many-to-many) — deliberately separate from `student_profiles.current_location_id` (a single, optional "where they live" fact). This table, not `current_location_id`, is what On-site/Hybrid Recommended Candidates location eligibility actually checks.

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
12. locations
13. student_available_locations

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
- locations
- student_available_locations

All 13 tables listed above have been created. Several later phases not covered
by this roadmap's original scope (invitations, opportunity_eligible_majors,
assessments, quizzes, offers, education_verifications, cv_skill_evidence,
skill_suggestions, and others) have also shipped and are documented in
docs/ARCHITECTURE.md and docs/BUSINESS_RULES.md instead — this file was never
extended to track every table added since the original 11, and this patch
only adds the two tables (plus the two new FK columns above) it actually
introduced rather than attempting to backfill the rest.