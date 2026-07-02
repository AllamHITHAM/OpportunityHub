# OpportunityHub Coding Standards

## Laravel

- Use Laravel 12 conventions.
- Use singular Model names.
- Use plural table names.
- Use snake_case for database columns.
- Use camelCase for PHP methods.
- Use PascalCase for class names.

Examples:
- Model: StudentProfile
- Table: student_profiles
- Controller: StudentProfileController
- Method: updateProfile()
- Column: graduation_year

## Migrations

- Never create tables manually in phpMyAdmin.
- Every database change must use a migration.
- Do not create duplicate migrations.
- Foreign keys must use `foreignId`.
- Use `nullable()` only when the field is optional.
- Use `cascadeOnDelete()` only when deleting the parent should delete the child.

## Models

- Every main table should have a Model.
- Define relationships inside Models.
- Use `$fillable` for mass assignment.
- Keep business logic out of Models unless it is simple.

## Controllers

- Controllers should stay small.
- Controllers should validate requests.
- Controllers should return JSON responses.
- Move complex logic to Services later.

## API Responses

Success response example:

```json
{
  "success": true,
  "message": "Operation completed successfully",
  "data": {}
}