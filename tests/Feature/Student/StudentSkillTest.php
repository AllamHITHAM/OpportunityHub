<?php

namespace Tests\Feature\Student;

use App\Models\Skill;
use App\Models\StudentProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class StudentSkillTest extends TestCase
{
    use RefreshDatabase;

    public function test_student_can_list_their_skills(): void
    {
        $student = $this->studentWithProfile();
        $skill = Skill::create(['name' => 'Laravel']);

        $student->profile->studentSkills()->create([
            'skill_id' => $skill->id,
            'level' => 'intermediate',
        ]);

        Sanctum::actingAs($student->user);

        $response = $this->getJson('/api/student/skills');

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonCount(1, 'data');
    }

    public function test_student_can_add_a_skill(): void
    {
        $student = $this->studentWithProfile();
        $skill = Skill::create(['name' => 'PHP']);

        Sanctum::actingAs($student->user);

        $response = $this->postJson('/api/student/skills', [
            'skill_id' => $skill->id,
            'level' => 'advanced',
            'years_of_experience' => 3,
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.level', 'advanced');

        $this->assertDatabaseHas('student_skills', [
            'student_id' => $student->profile->id,
            'skill_id' => $skill->id,
        ]);
    }

    public function test_student_cannot_add_the_same_skill_twice(): void
    {
        $student = $this->studentWithProfile();
        $skill = Skill::create(['name' => 'JavaScript']);

        $student->profile->studentSkills()->create([
            'skill_id' => $skill->id,
            'level' => 'beginner',
        ]);

        Sanctum::actingAs($student->user);

        $response = $this->postJson('/api/student/skills', [
            'skill_id' => $skill->id,
            'level' => 'intermediate',
        ]);

        $response->assertStatus(409)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'You have already added this skill');

        $this->assertDatabaseCount('student_skills', 1);
    }

    public function test_student_can_delete_their_own_skill(): void
    {
        $student = $this->studentWithProfile();
        $skill = Skill::create(['name' => 'Docker']);

        $studentSkill = $student->profile->studentSkills()->create([
            'skill_id' => $skill->id,
            'level' => 'beginner',
        ]);

        Sanctum::actingAs($student->user);

        $response = $this->deleteJson("/api/student/skills/{$studentSkill->id}");

        $response->assertStatus(200)
            ->assertJsonPath('success', true);

        $this->assertDatabaseMissing('student_skills', ['id' => $studentSkill->id]);
    }

    public function test_student_cannot_delete_another_students_skill(): void
    {
        $owner = $this->studentWithProfile();
        $skill = Skill::create(['name' => 'Kubernetes']);

        $studentSkill = $owner->profile->studentSkills()->create([
            'skill_id' => $skill->id,
            'level' => 'expert',
        ]);

        $otherStudent = $this->studentWithProfile();
        Sanctum::actingAs($otherStudent->user);

        $response = $this->deleteJson("/api/student/skills/{$studentSkill->id}");

        $response->assertStatus(404)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Skill not found');

        $this->assertDatabaseHas('student_skills', ['id' => $studentSkill->id]);
    }

    private function studentWithProfile(): object
    {
        $user = User::factory()->create([
            'role' => 'student',
            'status' => 'active',
        ]);

        $profile = StudentProfile::create(['user_id' => $user->id]);

        return (object) ['user' => $user, 'profile' => $profile];
    }
}
