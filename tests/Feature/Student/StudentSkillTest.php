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

    public function test_an_unauthenticated_request_to_add_a_skill_is_rejected(): void
    {
        $skill = Skill::create(['name' => 'PHP']);

        $response = $this->postJson('/api/student/skills', [
            'skill_id' => $skill->id,
            'level' => 'advanced',
        ]);

        $response->assertStatus(401);
    }

    public function test_an_organization_cannot_add_a_student_skill(): void
    {
        $skill = Skill::create(['name' => 'PHP']);
        $org = User::factory()->create(['role' => 'organization', 'status' => 'active']);
        Sanctum::actingAs($org);

        $response = $this->postJson('/api/student/skills', [
            'skill_id' => $skill->id,
            'level' => 'advanced',
        ]);

        $response->assertStatus(403);
    }

    public function test_a_nonexistent_skill_id_is_rejected(): void
    {
        $student = $this->studentWithProfile();
        Sanctum::actingAs($student->user);

        $response = $this->postJson('/api/student/skills', [
            'skill_id' => 999999,
            'level' => 'advanced',
        ]);

        $response->assertStatus(422);
        $this->assertDatabaseCount('student_skills', 0);
    }

    public function test_an_invalid_level_is_rejected(): void
    {
        $student = $this->studentWithProfile();
        $skill = Skill::create(['name' => 'PHP']);
        Sanctum::actingAs($student->user);

        $response = $this->postJson('/api/student/skills', [
            'skill_id' => $skill->id,
            'level' => 'guru',
        ]);

        $response->assertStatus(422);
        $this->assertDatabaseCount('student_skills', 0);
    }

    public function test_a_client_supplied_student_id_can_never_attribute_the_skill_to_another_student(): void
    {
        $student = $this->studentWithProfile();
        $otherStudent = $this->studentWithProfile();
        $skill = Skill::create(['name' => 'PHP']);
        Sanctum::actingAs($student->user);

        $response = $this->postJson('/api/student/skills', [
            // A spoofed student_id must be silently ignored -- the row is
            // always attributed to the authenticated student, never this.
            'student_id' => $otherStudent->profile->id,
            'skill_id' => $skill->id,
            'level' => 'advanced',
        ]);

        $response->assertStatus(201);
        $this->assertDatabaseHas('student_skills', [
            'student_id' => $student->profile->id,
            'skill_id' => $skill->id,
        ]);
        $this->assertDatabaseMissing('student_skills', [
            'student_id' => $otherStudent->profile->id,
            'skill_id' => $skill->id,
        ]);
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
