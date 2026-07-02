# CLAUDE.md — OpportunityHub AI Working Rules

## Project
OpportunityHub is a Laravel 12 + MySQL REST API project with a Flutter frontend planned later.

## Always Read First
Before doing any task, read:
- docs/PROJECT.md
- docs/DATABASE.md
- agents/00_global_rules.md

If the task is database-related, also read:
- agents/01_database_architect.md

If the task is backend/API-related, also read:
- agents/02_laravel_backend_engineer.md

If the task is review-only, also read:
- agents/03_code_reviewer.md

## Hard Rules
- Do not modify `.env`.
- Do not run `php artisan migrate:fresh` unless explicitly approved.
- Do not run `git push` unless explicitly approved.
- Do not delete files unless explicitly approved.
- Do not change database design without asking.
- Do not create duplicate migrations.
- Do not add packages unless necessary and approved.
- Work on one task at a time.
- Explain every changed file briefly.
- Use Laravel 12 conventions.
- Keep code beginner-readable.

## Current Phase
Phase 2: Database Migrations.

## Current Progress
Completed:
- Laravel project setup.
- GitHub connected.
- MySQL database connected.
- users table updated with role and status.
- student_profiles migration created and migrated.
- docs/PROJECT.md created.
- docs/DATABASE.md created.
- agents created.

Next task:
- Create the skills table migration.

## Development Style
For every task:
1. Read the relevant docs/agents.
2. State the plan briefly.
3. Change only the required files.
4. Explain changed files.
5. Tell whether it is safe to run the next command.
6. Wait for approval before destructive actions.

## Git Rules
- Use small commits.
- Never push without approval.
- Always check `git status` before suggesting a commit.