# OpportunityHub API Plan

## API Style
- REST API
- JSON responses
- Laravel 12
- Routes will be placed in routes/api.php
- Flutter will consume these APIs later

## Authentication APIs
- POST /api/register/student
- POST /api/register/organization
- POST /api/login
- POST /api/logout
- GET /api/me

## Student APIs
- GET /api/student/profile
- POST /api/student/profile
- PUT /api/student/profile
- GET /api/student/skills
- POST /api/student/skills
- DELETE /api/student/skills/{id}

## CV APIs
- GET /api/student/cvs
- POST /api/student/cvs
- DELETE /api/student/cvs/{id}
- PUT /api/student/cvs/{id}/default

## Opportunity APIs
- GET /api/opportunities
- GET /api/opportunities/{id}

## Company APIs
- GET /api/company/profile
- POST /api/company/profile
- PUT /api/company/profile
- GET /api/company/opportunities
- POST /api/company/opportunities
- PUT /api/company/opportunities/{id}
- DELETE /api/company/opportunities/{id}

## Application APIs
- POST /api/opportunities/{id}/apply
- GET /api/student/applications
- GET /api/company/opportunities/{id}/applications
- PUT /api/company/applications/{id}/status

## Interview APIs
- POST /api/company/applications/{id}/interview
- GET /api/student/interviews
- GET /api/company/interviews

## Notification APIs
- GET /api/notifications
- PUT /api/notifications/{id}/read

## Admin APIs
- GET /api/admin/users
- PUT /api/admin/companies/{id}/approve
- PUT /api/admin/users/{id}/suspend

## Response Rules
- Successful response should return JSON.
- Validation errors should return 422.
- Unauthorized access should return 401.
- Forbidden actions should return 403.
- Not found resources should return 404.