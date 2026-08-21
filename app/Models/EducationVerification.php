<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Phase 8B-1: a Student's education proof submission and its Admin review
 * outcome. One row per student (`student_id` unique) -- a rejected
 * submission is resubmitted by updating this same row, not creating a new
 * one (see StudentEducationVerificationController::store()).
 *
 * "Verified" means an Admin reviewed the uploaded document and approved
 * it -- nothing more. It is not university/government/cryptographic
 * verification (see docs/BUSINESS_RULES.md).
 */
class EducationVerification extends Model
{
    protected $fillable = [
        'student_id',
        'institution_name',
        'degree_or_program',
        'document_path',
        'status',
        'rejection_reason',
        'submitted_at',
        'reviewed_at',
        'reviewed_by_admin_id',
    ];

    /**
     * Never returned in any API response -- nobody (Student, Admin, or
     * Organization) needs the raw filesystem path; a document is only ever
     * reached through the dedicated, ownership-checked streaming endpoints.
     * Mirrors `CV::$hidden` for `parsed_text`.
     */
    protected $hidden = ['document_path'];

    protected function casts(): array
    {
        return [
            'submitted_at' => 'datetime',
            'reviewed_at' => 'datetime',
        ];
    }

    public function studentProfile(): BelongsTo
    {
        return $this->belongsTo(StudentProfile::class, 'student_id');
    }

    public function reviewedByAdmin(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by_admin_id');
    }
}
