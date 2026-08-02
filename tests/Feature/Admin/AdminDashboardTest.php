<?php

namespace Tests\Feature\Admin;

use App\Models\Application;
use App\Models\Opportunity;
use App\Models\OrganizationProfile;
use App\Models\StudentProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdminDashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_dashboard_returns_total_users_count(): void
    {
        $admin = $this->adminUser();
        User::factory()->create(['role' => 'student', 'status' => 'active']);
        User::factory()->create(['role' => 'organization', 'status' => 'active']);

        Sanctum::actingAs($admin);

        $response = $this->getJson('/api/admin/dashboard');

        // The admin itself + the 2 created users = 3.
        $response->assertStatus(200)
            ->assertJsonPath('data.total_users', 3);
    }

    public function test_dashboard_returns_students_count(): void
    {
        $admin = $this->adminUser();
        User::factory()->count(2)->create(['role' => 'student', 'status' => 'active']);
        User::factory()->create(['role' => 'organization', 'status' => 'active']);

        Sanctum::actingAs($admin);

        $response = $this->getJson('/api/admin/dashboard');

        $response->assertStatus(200)
            ->assertJsonPath('data.total_students', 2);
    }

    public function test_dashboard_returns_organizations_count(): void
    {
        $admin = $this->adminUser();
        $this->organizationWithApproval('approved');
        $this->organizationWithApproval('pending');

        Sanctum::actingAs($admin);

        $response = $this->getJson('/api/admin/dashboard');

        $response->assertStatus(200)
            ->assertJsonPath('data.total_organizations', 2);
    }

    public function test_dashboard_returns_opportunities_count(): void
    {
        $admin = $this->adminUser();
        $org = $this->organizationWithApproval('approved');
        $this->opportunityFor($org);
        $this->opportunityFor($org);

        Sanctum::actingAs($admin);

        $response = $this->getJson('/api/admin/dashboard');

        $response->assertStatus(200)
            ->assertJsonPath('data.total_opportunities', 2);
    }

    public function test_dashboard_returns_applications_count(): void
    {
        $admin = $this->adminUser();
        $org = $this->organizationWithApproval('approved');
        $opportunity = $this->opportunityFor($org);
        $this->applicationFor($opportunity);

        Sanctum::actingAs($admin);

        $response = $this->getJson('/api/admin/dashboard');

        $response->assertStatus(200)
            ->assertJsonPath('data.total_applications', 1);
    }

    public function test_dashboard_returns_interviews_count(): void
    {
        $admin = $this->adminUser();
        $org = $this->organizationWithApproval('approved');
        $opportunity = $this->opportunityFor($org);
        $application = $this->applicationFor($opportunity);
        $assessment = $application->assessment()->create([
            'type' => 'interview',
            'status' => 'scheduled',
            'result' => null,
        ]);
        $assessment->interview()->create([
            'interview_type' => 'phone',
            'scheduled_at' => now()->addDays(2),
        ]);

        Sanctum::actingAs($admin);

        $response = $this->getJson('/api/admin/dashboard');

        $response->assertStatus(200)
            ->assertJsonPath('data.total_interviews', 1);
    }

    public function test_dashboard_statistics_reflect_actual_database_data(): void
    {
        $admin = $this->adminUser();

        User::factory()->create(['role' => 'student', 'status' => 'active']);
        $this->organizationWithApproval('pending');
        $approvedOrg = $this->organizationWithApproval('approved');
        $this->organizationWithApproval('rejected');

        $opportunity = $this->opportunityFor($approvedOrg, ['status' => 'open']);
        $this->opportunityFor($approvedOrg, ['status' => 'closed']);
        $this->applicationFor($opportunity);

        Sanctum::actingAs($admin);

        $response = $this->getJson('/api/admin/dashboard');

        $response->assertStatus(200)
            ->assertJsonPath('data.pending_organizations', 1)
            ->assertJsonPath('data.approved_organizations', 1)
            ->assertJsonPath('data.rejected_organizations', 1)
            ->assertJsonPath('data.total_opportunities', 2)
            ->assertJsonPath('data.open_opportunities', 1)
            ->assertJsonPath('data.closed_opportunities', 1)
            ->assertJsonPath('data.total_applications', 1);
    }

    private function adminUser(): User
    {
        return User::factory()->create(['role' => 'admin', 'status' => 'active']);
    }

    private function organizationWithApproval(string $status): object
    {
        $user = User::factory()->create([
            'role' => 'organization',
            'status' => 'active',
        ]);

        $profile = OrganizationProfile::create([
            'user_id' => $user->id,
            'organization_name' => 'Test Organization',
            'organization_type' => 'company',
        ]);
        $profile->approval_status = $status;
        $profile->save();

        return (object) ['user' => $user, 'profile' => $profile];
    }

    private function opportunityFor(object $org, array $overrides = []): Opportunity
    {
        return $org->profile->opportunities()->create(array_merge([
            'title' => 'Backend Developer',
            'description' => 'Great role.',
            'opportunity_type' => 'job',
            'employment_type' => 'full_time',
            'work_mode' => 'remote',
            'experience_level' => 'junior',
            'status' => 'open',
        ], $overrides));
    }

    private function applicationFor(Opportunity $opportunity): Application
    {
        $studentUser = User::factory()->create([
            'role' => 'student',
            'status' => 'active',
        ]);

        $studentProfile = StudentProfile::create(['user_id' => $studentUser->id]);

        $cv = $studentProfile->cvs()->create([
            'title' => 'My CV',
            'file_path' => 'cvs/my-cv.pdf',
        ]);

        return Application::create([
            'student_id' => $studentProfile->id,
            'opportunity_id' => $opportunity->id,
            'cv_id' => $cv->id,
        ]);
    }
}
