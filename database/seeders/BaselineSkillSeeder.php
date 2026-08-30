<?php

namespace Database\Seeders;

use App\Models\Skill;
use App\Support\SkillNameNormalizer;
use Illuminate\Database\Seeder;

/**
 * Phase 8A-6.1: a realistic starter Skill catalog spanning the
 * disciplines this app's students actually come from, so the catalog
 * doesn't depend entirely on manual Admin entry from a completely empty
 * table. Deterministic and idempotent -- safe to run any number of times,
 * against a fresh database or one that already has Admin- or
 * previously-seeded Skill rows.
 *
 * Run explicitly (not part of a `migrate:fresh` cycle):
 *
 *     php artisan db:seed --class=Database\\Seeders\\BaselineSkillSeeder
 *
 * Also wired into DatabaseSeeder::run() -- it's fully idempotent, so
 * re-running the whole DatabaseSeeder never duplicates or destroys a
 * Skill row. See DatabaseSeeder's own doc comment for why demo/test data
 * and Admin bootstrap are deliberately kept out of that default run.
 */
class BaselineSkillSeeder extends Seeder
{
    /**
     * One entry per canonical skill. A name that would sensibly belong to
     * more than one discipline (e.g. AutoCAD, Microsoft Excel) appears
     * here exactly once, under the discipline it's listed first for --
     * `Skill.category` is a single nullable string, not a set, so there is
     * only ever one canonical row and one canonical category per name.
     *
     * @var array<int, array{name: string, category: string}>
     */
    private const SKILLS = [
        // Civil Engineering
        ['name' => 'AutoCAD', 'category' => 'Civil Engineering'],
        ['name' => 'Civil 3D', 'category' => 'Civil Engineering'],
        ['name' => 'Revit', 'category' => 'Civil Engineering'],
        ['name' => 'ETABS', 'category' => 'Civil Engineering'],
        ['name' => 'SAP2000', 'category' => 'Civil Engineering'],
        ['name' => 'Structural Analysis', 'category' => 'Civil Engineering'],
        ['name' => 'Quantity Surveying', 'category' => 'Civil Engineering'],
        ['name' => 'BOQ Preparation', 'category' => 'Civil Engineering'],
        ['name' => 'Construction Management', 'category' => 'Civil Engineering'],
        ['name' => 'Site Supervision', 'category' => 'Civil Engineering'],
        ['name' => 'Microsoft Excel', 'category' => 'Civil Engineering'],

        // Architecture (AutoCAD, Revit already listed above)
        ['name' => 'SketchUp', 'category' => 'Architecture'],
        ['name' => '3ds Max', 'category' => 'Architecture'],
        ['name' => 'Lumion', 'category' => 'Architecture'],
        ['name' => 'Enscape', 'category' => 'Architecture'],
        ['name' => 'Adobe Photoshop', 'category' => 'Architecture'],
        ['name' => 'Adobe Illustrator', 'category' => 'Architecture'],
        ['name' => 'Architectural Design', 'category' => 'Architecture'],
        ['name' => 'BIM', 'category' => 'Architecture'],

        // Computer / Software Engineering
        ['name' => 'Flutter', 'category' => 'Computer Science'],
        ['name' => 'Dart', 'category' => 'Computer Science'],
        ['name' => 'Laravel', 'category' => 'Computer Science'],
        ['name' => 'PHP', 'category' => 'Computer Science'],
        ['name' => 'JavaScript', 'category' => 'Computer Science'],
        ['name' => 'TypeScript', 'category' => 'Computer Science'],
        ['name' => 'React', 'category' => 'Computer Science'],
        ['name' => 'Node.js', 'category' => 'Computer Science'],
        ['name' => 'Python', 'category' => 'Computer Science'],
        ['name' => 'Java', 'category' => 'Computer Science'],
        ['name' => 'C++', 'category' => 'Computer Science'],
        ['name' => 'SQL', 'category' => 'Computer Science'],
        ['name' => 'MySQL', 'category' => 'Computer Science'],
        ['name' => 'Git', 'category' => 'Computer Science'],
        ['name' => 'REST APIs', 'category' => 'Computer Science'],
        ['name' => 'Docker', 'category' => 'Computer Science'],

        // Electrical / Electronics Engineering
        ['name' => 'MATLAB', 'category' => 'Electrical Engineering'],
        ['name' => 'Simulink', 'category' => 'Electrical Engineering'],
        ['name' => 'PLC', 'category' => 'Electrical Engineering'],
        ['name' => 'Arduino', 'category' => 'Electrical Engineering'],
        ['name' => 'Embedded Systems', 'category' => 'Electrical Engineering'],
        ['name' => 'Circuit Design', 'category' => 'Electrical Engineering'],
        ['name' => 'PCB Design', 'category' => 'Electrical Engineering'],
        ['name' => 'Verilog', 'category' => 'Electrical Engineering'],
        ['name' => 'VHDL', 'category' => 'Electrical Engineering'],

        // Mechanical Engineering (AutoCAD, MATLAB already listed above)
        ['name' => 'SolidWorks', 'category' => 'Mechanical Engineering'],
        ['name' => 'ANSYS', 'category' => 'Mechanical Engineering'],
        ['name' => 'CAD', 'category' => 'Mechanical Engineering'],
        ['name' => 'CAM', 'category' => 'Mechanical Engineering'],
        ['name' => 'Thermodynamics', 'category' => 'Mechanical Engineering'],
        ['name' => 'Manufacturing Processes', 'category' => 'Mechanical Engineering'],

        // Business / Accounting / Marketing (Microsoft Excel already listed above)
        ['name' => 'Financial Analysis', 'category' => 'Business'],
        ['name' => 'Accounting', 'category' => 'Business'],
        ['name' => 'Bookkeeping', 'category' => 'Business'],
        ['name' => 'Digital Marketing', 'category' => 'Business'],
        ['name' => 'Social Media Marketing', 'category' => 'Business'],
        ['name' => 'SEO', 'category' => 'Business'],
        ['name' => 'Market Research', 'category' => 'Business'],
        ['name' => 'Power BI', 'category' => 'Business'],

        // Pharmacy / Healthcare (Microsoft Excel already listed above)
        ['name' => 'Clinical Pharmacy', 'category' => 'Healthcare'],
        ['name' => 'Pharmacology', 'category' => 'Healthcare'],
        ['name' => 'Patient Counseling', 'category' => 'Healthcare'],
        ['name' => 'Drug Information', 'category' => 'Healthcare'],
        ['name' => 'Medication Review', 'category' => 'Healthcare'],
    ];

    public function run(): void
    {
        $seen = [];

        foreach (self::SKILLS as $entry) {
            $normalized = SkillNameNormalizer::normalize($entry['name']);

            // Defense-in-depth against an accidental duplicate in the
            // list above -- every name here is meant to appear once, but
            // this guarantees only one row is ever created per normalized
            // name regardless.
            if (isset($seen[$normalized])) {
                continue;
            }
            $seen[$normalized] = true;

            // firstOrCreate keyed on the exact `name` (matching the
            // `skills.name` unique constraint) -- an existing manually- or
            // previously-seeded row with this exact name is left
            // completely untouched, never overwritten, never duplicated.
            Skill::firstOrCreate(
                ['name' => $entry['name']],
                ['category' => $entry['category']],
            );
        }
    }
}
