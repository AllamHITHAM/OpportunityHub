<?php

namespace App\Models;

use App\Support\SkillNameNormalizer;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Skill extends Model
{
    protected $fillable = [
        'name',
        'category',
    ];

    public function studentSkills(): HasMany
    {
        return $this->hasMany(StudentSkill::class);
    }

    public function opportunitySkills(): HasMany
    {
        return $this->hasMany(OpportunitySkill::class);
    }

    /**
     * Finds an existing Skill whose name normalizes (Phase 8A-6.1's
     * `SkillNameNormalizer`) to the same value as [$name] -- used by
     * Admin skill-suggestion approval to reuse an equivalent Skill that
     * may have appeared (via the baseline seeder or another Admin action)
     * after the suggestion was created, instead of creating a duplicate.
     * The catalog is small enough in this app for a single in-memory scan
     * to be simpler and clearer than a raw-SQL normalized comparison.
     */
    public static function findByNormalizedName(string $name): ?self
    {
        $normalized = SkillNameNormalizer::normalize($name);

        return static::all(['id', 'name'])
            ->first(fn (self $skill) => SkillNameNormalizer::normalize($skill->name) === $normalized);
    }
}
