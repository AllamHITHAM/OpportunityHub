<?php

namespace Tests\Feature\Interviews;

use App\Models\Application;
use App\Models\Interview;
use App\Models\Opportunity;
use App\Models\OrganizationProfile;
use App\Models\StudentProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class OrganizationInterviewTest extends TestCase
{
    use RefreshDatabase;

    public function test_organization_can_list_interviews_belonging_to_its_own_applications(): void
    {
        $org = $this->approvedOrganization();
        $opportunity = $this->opportunityFor($org);
        $application = $this->applicationFor($opportunity, 'shortlisted');
        $interview = $this->interviewFor($application);

        Sanctum::actingAs($org->user);

        $response = $this->getJson('/api/organization/interviews');

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $interview->id);
    }

    public function test_organization_can_view_an_interview_belonging_to_its_own_application(): void
    {
        $org = $this->approvedOrganization();
        $opportunity = $this->opportunityFor($org);
        $application = $this->applicationFor($opportunity, 'shortlisted');
        $interview = $this->interviewFor($application);

        Sanctum::actingAs($org->user);

        $response = $this->getJson("/api/organization/interviews/{$interview->id}");

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.id', $interview->id);
    }

    /**
     * Regression guard for the Phase 6A-Backend student privacy hotfix:
     * hiding internal Interview fields from Student responses must not
     * affect the Organization's own contract, which still needs them.
     */
    public function test_organization_interview_response_still_includes_internal_fields(): void
    {
        $org = $this->approvedOrganization();
        $opportunity = $this->opportunityFor($org);
        $application = $this->applicationFor($opportunity, 'shortlisted');
        $interview = $this->interviewFor($application, [
            'interviewer_email' => 'jane@hiring.example',
        ]);
        $interview->rating = 4;
        $interview->company_feedback = 'Strong technical answers.';
        $interview->save();

        Sanctum::actingAs($org->user);

        $showResponse = $this->getJson("/api/organization/interviews/{$interview->id}");

        $showResponse->assertStatus(200)
            ->assertJsonPath('data.interviewer_email', 'jane@hiring.example')
            ->assertJsonPath('data.rating', 4)
            ->assertJsonPath('data.company_feedback', 'Strong technical answers.')
            ->assertJsonPath('data.decision', 'pending');

        $indexResponse = $this->getJson('/api/organization/interviews');

        $indexResponse->assertStatus(200)
            ->assertJsonPath('data.0.interviewer_email', 'jane@hiring.example')
            ->assertJsonPath('data.0.rating', 4)
            ->assertJsonPath('data.0.company_feedback', 'Strong technical answers.')
            ->assertJsonPath('data.0.decision', 'pending');
    }

    public function test_organization_cannot_view_an_interview_belonging_to_another_organization(): void
    {
        $orgA = $this->approvedOrganization();
        $opportunity = $this->opportunityFor($orgA);
        $application = $this->applicationFor($opportunity, 'shortlisted');
        $interview = $this->interviewFor($application);

        $orgB = $this->approvedOrganization();
        Sanctum::actingAs($orgB->user);

        $response = $this->getJson("/api/organization/interviews/{$interview->id}");

        $response->assertStatus(404)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Interview not found');
    }

    public function test_organization_can_create_an_interview_for_its_own_application(): void
    {
        $org = $this->approvedOrganization();
        $opportunity = $this->opportunityFor($org);
        $application = $this->applicationFor($opportunity, 'shortlisted');

        Sanctum::actingAs($org->user);

        $response = $this->postJson(
            "/api/organization/applications/{$application->id}/interview",
            $this->validInterviewPayload()
        );

        $response->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.assessment.application_id', $application->id);

        $this->assertDatabaseHas('assessments', [
            'application_id' => $application->id,
            'type' => 'interview',
            'status' => 'scheduled',
        ]);
        $this->assertDatabaseCount('interviews', 1);
    }

    public function test_creating_an_interview_also_creates_an_assessment(): void
    {
        $org = $this->approvedOrganization();
        $opportunity = $this->opportunityFor($org);
        $application = $this->applicationFor($opportunity, 'shortlisted');

        Sanctum::actingAs($org->user);

        $response = $this->postJson(
            "/api/organization/applications/{$application->id}/interview",
            $this->validInterviewPayload()
        );

        $response->assertStatus(201);

        $application->refresh();
        $this->assertNotNull($application->assessment);
        $this->assertSame('interview', $application->assessment->type);
        $this->assertSame($application->assessment->id, $response->json('data.assessment_id'));
    }

    public function test_organization_cannot_create_an_interview_for_another_organizations_application(): void
    {
        $orgA = $this->approvedOrganization();
        $opportunity = $this->opportunityFor($orgA);
        $application = $this->applicationFor($opportunity, 'shortlisted');

        $orgB = $this->approvedOrganization();
        Sanctum::actingAs($orgB->user);

        $response = $this->postJson(
            "/api/organization/applications/{$application->id}/interview",
            $this->validInterviewPayload()
        );

        $response->assertStatus(404)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Application not found');

        $this->assertDatabaseCount('interviews', 0);
        $this->assertDatabaseCount('assessments', 0);
    }

    public function test_organization_cannot_create_a_second_interview_for_the_same_application(): void
    {
        $org = $this->approvedOrganization();
        $opportunity = $this->opportunityFor($org);
        $application = $this->applicationFor($opportunity, 'shortlisted');
        $this->interviewFor($application);

        Sanctum::actingAs($org->user);

        $response = $this->postJson(
            "/api/organization/applications/{$application->id}/interview",
            $this->validInterviewPayload()
        );

        $response->assertStatus(409)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'An interview already exists for this application');

        $this->assertDatabaseCount('interviews', 1);
        $this->assertDatabaseCount('assessments', 1);
    }

    public function test_creating_an_interview_updates_the_application_status_to_in_assessment(): void
    {
        $org = $this->approvedOrganization();
        $opportunity = $this->opportunityFor($org);
        $application = $this->applicationFor($opportunity, 'shortlisted');

        Sanctum::actingAs($org->user);

        $this->postJson(
            "/api/organization/applications/{$application->id}/interview",
            $this->validInterviewPayload()
        )->assertStatus(201);

        $this->assertDatabaseHas('applications', [
            'id' => $application->id,
            'status' => 'in_assessment',
        ]);
        $this->assertDatabaseMissing('applications', [
            'id' => $application->id,
            'status' => 'interview_scheduled',
        ]);
    }

    public function test_organization_can_update_its_own_interview(): void
    {
        $org = $this->approvedOrganization();
        $opportunity = $this->opportunityFor($org);
        $application = $this->applicationFor($opportunity, 'shortlisted');
        $interview = $this->interviewFor($application);

        Sanctum::actingAs($org->user);

        $response = $this->putJson(
            "/api/organization/interviews/{$interview->id}",
            $this->validInterviewPayload(['interviewer_name' => 'Jane Recruiter'])
        );

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.interviewer_name', 'Jane Recruiter');

        $this->assertDatabaseHas('interviews', [
            'id' => $interview->id,
            'interviewer_name' => 'Jane Recruiter',
        ]);
    }

    public function test_organization_cannot_update_another_organizations_interview(): void
    {
        $orgA = $this->approvedOrganization();
        $opportunity = $this->opportunityFor($orgA);
        $application = $this->applicationFor($opportunity, 'shortlisted');
        $interview = $this->interviewFor($application);

        $orgB = $this->approvedOrganization();
        Sanctum::actingAs($orgB->user);

        $response = $this->putJson(
            "/api/organization/interviews/{$interview->id}",
            $this->validInterviewPayload(['interviewer_name' => 'Hijacker'])
        );

        $response->assertStatus(404)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Interview not found');

        $this->assertDatabaseMissing('interviews', [
            'id' => $interview->id,
            'interviewer_name' => 'Hijacker',
        ]);
    }

    public function test_organization_can_complete_its_own_interview(): void
    {
        $org = $this->approvedOrganization();
        $opportunity = $this->opportunityFor($org);
        $application = $this->applicationFor($opportunity, 'shortlisted');
        $interview = $this->interviewFor($application);

        Sanctum::actingAs($org->user);

        $response = $this->putJson("/api/organization/interviews/{$interview->id}/complete", [
            'decision' => 'passed',
            'rating' => 5,
            'company_feedback' => 'Great candidate.',
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.decision', 'passed');
    }

    public function test_completing_an_interview_sets_status_to_completed(): void
    {
        $org = $this->approvedOrganization();
        $opportunity = $this->opportunityFor($org);
        $application = $this->applicationFor($opportunity, 'shortlisted');
        $interview = $this->interviewFor($application);

        Sanctum::actingAs($org->user);

        $this->putJson("/api/organization/interviews/{$interview->id}/complete", [])
            ->assertStatus(200);

        $this->assertDatabaseHas('interviews', [
            'id' => $interview->id,
            'status' => 'completed',
        ]);
    }

    public function test_completing_an_interview_sets_completed_at(): void
    {
        $org = $this->approvedOrganization();
        $opportunity = $this->opportunityFor($org);
        $application = $this->applicationFor($opportunity, 'shortlisted');
        $interview = $this->interviewFor($application);

        $this->assertNull($interview->completed_at);

        Sanctum::actingAs($org->user);

        $this->putJson("/api/organization/interviews/{$interview->id}/complete", [])
            ->assertStatus(200);

        $interview->refresh();
        $this->assertNotNull($interview->completed_at);
    }

    public function test_completing_an_interview_updates_the_assessment_status_result_and_completed_at(): void
    {
        $org = $this->approvedOrganization();
        $opportunity = $this->opportunityFor($org);
        $application = $this->applicationFor($opportunity, 'shortlisted');
        $interview = $this->interviewFor($application);

        $this->assertNull($interview->assessment->completed_at);
        $this->assertNull($interview->assessment->result);
        $this->assertSame('scheduled', $interview->assessment->status);

        Sanctum::actingAs($org->user);

        $this->putJson("/api/organization/interviews/{$interview->id}/complete", [
            'decision' => 'passed',
        ])->assertStatus(200);

        $interview->assessment->refresh();
        $this->assertSame('completed', $interview->assessment->status);
        $this->assertSame('passed', $interview->assessment->result);
        $this->assertNotNull($interview->assessment->completed_at);
    }

    /**
     * Completing an interview never touches the application's recruitment
     * status -- the organization always makes the accept/reject call
     * separately, through the existing status endpoint.
     */
    public function test_completing_an_interview_does_not_change_the_application_status(): void
    {
        $org = $this->approvedOrganization();
        $opportunity = $this->opportunityFor($org);
        $application = $this->applicationFor($opportunity, 'shortlisted');
        $interview = $this->interviewFor($application);

        // `interviewFor()` creates the Assessment/Interview directly,
        // bypassing the store() endpoint's `interview_scheduled` side
        // effect -- set it explicitly so this test reflects the real
        // post-creation state it's asserting stays unchanged.
        $application->status = 'interview_scheduled';
        $application->save();

        Sanctum::actingAs($org->user);

        $this->putJson("/api/organization/interviews/{$interview->id}/complete", [
            'decision' => 'passed',
        ])->assertStatus(200);

        $this->assertDatabaseHas('applications', [
            'id' => $application->id,
            'status' => 'interview_scheduled',
        ]);
    }

    public function test_organization_can_delete_its_own_interview(): void
    {
        $org = $this->approvedOrganization();
        $opportunity = $this->opportunityFor($org);
        $application = $this->applicationFor($opportunity, 'shortlisted');
        $interview = $this->interviewFor($application);

        Sanctum::actingAs($org->user);

        $response = $this->deleteJson("/api/organization/interviews/{$interview->id}");

        $response->assertStatus(200)
            ->assertJsonPath('success', true);

        $this->assertDatabaseMissing('interviews', ['id' => $interview->id]);
    }

    public function test_deleting_a_scheduled_interview_removes_the_assessment_and_restores_application_to_shortlisted(): void
    {
        $org = $this->approvedOrganization();
        $opportunity = $this->opportunityFor($org);
        $application = $this->applicationFor($opportunity, 'shortlisted');

        Sanctum::actingAs($org->user);

        $this->postJson(
            "/api/organization/applications/{$application->id}/interview",
            $this->validInterviewPayload()
        )->assertStatus(201);

        $this->assertDatabaseHas('applications', [
            'id' => $application->id,
            'status' => 'in_assessment',
        ]);

        $interview = Interview::firstOrFail();

        $this->deleteJson("/api/organization/interviews/{$interview->id}")
            ->assertStatus(200);

        $this->assertDatabaseMissing('interviews', ['id' => $interview->id]);
        $this->assertDatabaseCount('assessments', 0);
        $this->assertDatabaseHas('applications', [
            'id' => $application->id,
            'status' => 'shortlisted',
        ]);
    }

    /**
     * A legacy application that reached `interview_scheduled` before this
     * phase (or via the now-closed manual-input gap) must still revert to
     * `shortlisted` when its assessment is deleted, exactly like a current
     * `in_assessment` application does above.
     */
    public function test_deleting_an_interview_reverts_a_legacy_interview_scheduled_application_to_shortlisted(): void
    {
        $org = $this->approvedOrganization();
        $opportunity = $this->opportunityFor($org);
        $application = $this->applicationFor($opportunity, 'shortlisted');
        $interview = $this->interviewFor($application);

        $application->status = 'interview_scheduled';
        $application->save();

        Sanctum::actingAs($org->user);

        $this->deleteJson("/api/organization/interviews/{$interview->id}")
            ->assertStatus(200);

        $this->assertDatabaseMissing('interviews', ['id' => $interview->id]);
        $this->assertDatabaseCount('assessments', 0);
        $this->assertDatabaseHas('applications', [
            'id' => $application->id,
            'status' => 'shortlisted',
        ]);
    }

    /**
     * If the application's status was moved on to something else
     * independently (e.g. an organization used the generic status
     * endpoint), deleting the interview must not clobber that decision.
     */
    #[DataProvider('statusesThatMustNotBeReverted')]
    public function test_deleting_an_interview_does_not_touch_an_application_status_other_than_in_assessment_or_interview_scheduled(string $status): void
    {
        $org = $this->approvedOrganization();
        $opportunity = $this->opportunityFor($org);
        $application = $this->applicationFor($opportunity, 'shortlisted');
        $interview = $this->interviewFor($application);

        $application->status = $status;
        $application->save();

        Sanctum::actingAs($org->user);

        $this->deleteJson("/api/organization/interviews/{$interview->id}")
            ->assertStatus(200);

        $this->assertDatabaseHas('applications', [
            'id' => $application->id,
            'status' => $status,
        ]);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function statusesThatMustNotBeReverted(): array
    {
        return [
            'accepted' => ['accepted'],
            'rejected' => ['rejected'],
            'withdrawn' => ['withdrawn'],
        ];
    }

    public function test_organization_cannot_delete_another_organizations_interview(): void
    {
        $orgA = $this->approvedOrganization();
        $opportunity = $this->opportunityFor($orgA);
        $application = $this->applicationFor($opportunity, 'shortlisted');
        $interview = $this->interviewFor($application);

        $orgB = $this->approvedOrganization();
        Sanctum::actingAs($orgB->user);

        $response = $this->deleteJson("/api/organization/interviews/{$interview->id}");

        $response->assertStatus(404)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Interview not found');

        $this->assertDatabaseHas('interviews', ['id' => $interview->id]);
    }

    public function test_a_completed_interview_cannot_be_deleted(): void
    {
        $org = $this->approvedOrganization();
        $opportunity = $this->opportunityFor($org);
        $application = $this->applicationFor($opportunity, 'shortlisted');
        $interview = $this->interviewFor($application);

        Sanctum::actingAs($org->user);

        $this->putJson("/api/organization/interviews/{$interview->id}/complete", [])
            ->assertStatus(200);

        $response = $this->deleteJson("/api/organization/interviews/{$interview->id}");

        $response->assertStatus(409)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Completed interviews cannot be deleted');

        $this->assertDatabaseHas('interviews', ['id' => $interview->id]);
        $this->assertDatabaseCount('assessments', 1);
    }

    public function test_invalid_interview_data_is_rejected(): void
    {
        $org = $this->approvedOrganization();
        $opportunity = $this->opportunityFor($org);
        $application = $this->applicationFor($opportunity, 'shortlisted');

        Sanctum::actingAs($org->user);

        $response = $this->postJson("/api/organization/applications/{$application->id}/interview", [
            'interview_type' => 'not-a-real-type',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['interview_type', 'scheduled_at']);

        $this->assertDatabaseCount('interviews', 0);
    }

    public function test_online_interview_requires_meeting_link(): void
    {
        $org = $this->approvedOrganization();
        $opportunity = $this->opportunityFor($org);
        $application = $this->applicationFor($opportunity, 'shortlisted');

        Sanctum::actingAs($org->user);

        $response = $this->postJson("/api/organization/applications/{$application->id}/interview", [
            'interview_type' => 'online',
            'scheduled_at' => now()->addDays(2)->toDateTimeString(),
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['meeting_link']);
    }

    public function test_onsite_interview_requires_location(): void
    {
        $org = $this->approvedOrganization();
        $opportunity = $this->opportunityFor($org);
        $application = $this->applicationFor($opportunity, 'shortlisted');

        Sanctum::actingAs($org->user);

        $response = $this->postJson("/api/organization/applications/{$application->id}/interview", [
            'interview_type' => 'onsite',
            'scheduled_at' => now()->addDays(2)->toDateTimeString(),
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['location']);
    }

    /**
     * Phase Final-QA-1: a Phone interview must not be schedulable without a
     * contact number the Student can actually use to attend — the root bug
     * this phase closes.
     */
    public function test_phone_interview_requires_contact_phone(): void
    {
        $org = $this->approvedOrganization();
        $opportunity = $this->opportunityFor($org);
        $application = $this->applicationFor($opportunity, 'shortlisted');

        Sanctum::actingAs($org->user);

        $response = $this->postJson("/api/organization/applications/{$application->id}/interview", [
            'interview_type' => 'phone',
            'scheduled_at' => now()->addDays(2)->toDateTimeString(),
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['contact_phone']);

        $this->assertDatabaseCount('interviews', 0);
    }

    public function test_phone_interview_does_not_require_meeting_link_or_location(): void
    {
        $org = $this->approvedOrganization();
        $opportunity = $this->opportunityFor($org);
        $application = $this->applicationFor($opportunity, 'shortlisted');

        Sanctum::actingAs($org->user);

        $response = $this->postJson("/api/organization/applications/{$application->id}/interview", [
            'interview_type' => 'phone',
            'scheduled_at' => now()->addDays(2)->toDateTimeString(),
            'contact_phone' => '+1 555-0100',
        ]);

        $response->assertStatus(201);
        $this->assertDatabaseHas('interviews', [
            'interview_type' => 'phone',
            'contact_phone' => '+1 555-0100',
            'meeting_link' => null,
            'location' => null,
        ]);
    }

    public function test_online_interview_does_not_require_contact_phone_or_location(): void
    {
        $org = $this->approvedOrganization();
        $opportunity = $this->opportunityFor($org);
        $application = $this->applicationFor($opportunity, 'shortlisted');

        Sanctum::actingAs($org->user);

        $response = $this->postJson("/api/organization/applications/{$application->id}/interview", [
            'interview_type' => 'online',
            'scheduled_at' => now()->addDays(2)->toDateTimeString(),
            'meeting_link' => 'https://meet.example.com/room',
        ]);

        $response->assertStatus(201);
        $this->assertDatabaseHas('interviews', [
            'interview_type' => 'online',
            'meeting_link' => 'https://meet.example.com/room',
            'contact_phone' => null,
            'location' => null,
        ]);
    }

    public function test_onsite_interview_does_not_require_contact_phone_or_meeting_link(): void
    {
        $org = $this->approvedOrganization();
        $opportunity = $this->opportunityFor($org);
        $application = $this->applicationFor($opportunity, 'shortlisted');

        Sanctum::actingAs($org->user);

        $response = $this->postJson("/api/organization/applications/{$application->id}/interview", [
            'interview_type' => 'onsite',
            'scheduled_at' => now()->addDays(2)->toDateTimeString(),
            'location' => 'HQ, Room 4',
        ]);

        $response->assertStatus(201);
        $this->assertDatabaseHas('interviews', [
            'interview_type' => 'onsite',
            'location' => 'HQ, Room 4',
            'contact_phone' => null,
            'meeting_link' => null,
        ]);
    }

    public function test_online_interview_rejects_a_non_http_meeting_link(): void
    {
        $org = $this->approvedOrganization();
        $opportunity = $this->opportunityFor($org);
        $application = $this->applicationFor($opportunity, 'shortlisted');

        Sanctum::actingAs($org->user);

        $response = $this->postJson("/api/organization/applications/{$application->id}/interview", [
            'interview_type' => 'online',
            'scheduled_at' => now()->addDays(2)->toDateTimeString(),
            'meeting_link' => 'not-a-url',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['meeting_link']);
    }

    public function test_phone_interview_response_exposes_contact_phone(): void
    {
        $org = $this->approvedOrganization();
        $opportunity = $this->opportunityFor($org);
        $application = $this->applicationFor($opportunity, 'shortlisted');

        Sanctum::actingAs($org->user);

        $response = $this->postJson("/api/organization/applications/{$application->id}/interview", [
            'interview_type' => 'phone',
            'scheduled_at' => now()->addDays(2)->toDateTimeString(),
            'contact_phone' => '+1 555-0100',
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.contact_phone', '+1 555-0100');
    }

    /**
     * Phase Final-QA-1 stale-data safety: switching an interview from
     * phone to online must clear the now-irrelevant `contact_phone`, even
     * though the update request never mentions it.
     */
    public function test_changing_interview_type_from_phone_to_online_clears_contact_phone(): void
    {
        $org = $this->approvedOrganization();
        $opportunity = $this->opportunityFor($org);
        $application = $this->applicationFor($opportunity, 'shortlisted');
        $interview = $this->interviewFor($application, [
            'interview_type' => 'phone',
            'contact_phone' => '+1 555-0100',
        ]);

        Sanctum::actingAs($org->user);

        $response = $this->putJson("/api/organization/interviews/{$interview->id}", [
            'interview_type' => 'online',
            'scheduled_at' => now()->addDays(3)->toDateTimeString(),
            'meeting_link' => 'https://meet.example.com/new-room',
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.contact_phone', null)
            ->assertJsonPath('data.meeting_link', 'https://meet.example.com/new-room');

        $this->assertDatabaseHas('interviews', [
            'id' => $interview->id,
            'interview_type' => 'online',
            'contact_phone' => null,
            'meeting_link' => 'https://meet.example.com/new-room',
        ]);
    }

    public function test_changing_interview_type_from_online_to_onsite_clears_meeting_link(): void
    {
        $org = $this->approvedOrganization();
        $opportunity = $this->opportunityFor($org);
        $application = $this->applicationFor($opportunity, 'shortlisted');
        $interview = $this->interviewFor($application, [
            'interview_type' => 'online',
            'meeting_link' => 'https://meet.example.com/room',
        ]);

        Sanctum::actingAs($org->user);

        $response = $this->putJson("/api/organization/interviews/{$interview->id}", [
            'interview_type' => 'onsite',
            'scheduled_at' => now()->addDays(3)->toDateTimeString(),
            'location' => 'HQ, Room 4',
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.meeting_link', null)
            ->assertJsonPath('data.location', 'HQ, Room 4');

        $this->assertDatabaseHas('interviews', [
            'id' => $interview->id,
            'interview_type' => 'onsite',
            'meeting_link' => null,
            'location' => 'HQ, Room 4',
        ]);
    }

    public function test_changing_interview_type_from_onsite_to_phone_clears_location(): void
    {
        $org = $this->approvedOrganization();
        $opportunity = $this->opportunityFor($org);
        $application = $this->applicationFor($opportunity, 'shortlisted');
        $interview = $this->interviewFor($application, [
            'interview_type' => 'onsite',
            'location' => 'HQ, Room 4',
        ]);

        Sanctum::actingAs($org->user);

        $response = $this->putJson("/api/organization/interviews/{$interview->id}", [
            'interview_type' => 'phone',
            'scheduled_at' => now()->addDays(3)->toDateTimeString(),
            'contact_phone' => '+1 555-0199',
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.location', null)
            ->assertJsonPath('data.contact_phone', '+1 555-0199');

        $this->assertDatabaseHas('interviews', [
            'id' => $interview->id,
            'interview_type' => 'phone',
            'location' => null,
            'contact_phone' => '+1 555-0199',
        ]);
    }

    /**
     * Backward compatibility (Phase Final-QA-1): a legacy interview created
     * before this phase, with no contact/access detail at all, must still
     * be fetchable without error.
     */
    public function test_a_legacy_interview_with_no_contact_detail_can_still_be_fetched(): void
    {
        $org = $this->approvedOrganization();
        $opportunity = $this->opportunityFor($org);
        $application = $this->applicationFor($opportunity, 'shortlisted');
        $interview = $this->interviewFor($application, ['interview_type' => 'phone', 'contact_phone' => null]);

        Sanctum::actingAs($org->user);

        $response = $this->getJson("/api/organization/interviews/{$interview->id}");

        $response->assertStatus(200)
            ->assertJsonPath('data.contact_phone', null);
    }

    public function test_interview_rating_accepts_only_valid_values(): void
    {
        $org = $this->approvedOrganization();
        $opportunity = $this->opportunityFor($org);
        $application = $this->applicationFor($opportunity, 'shortlisted');
        $interview = $this->interviewFor($application);

        Sanctum::actingAs($org->user);

        $response = $this->putJson("/api/organization/interviews/{$interview->id}/complete", [
            'rating' => 0,
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['rating']);
    }

    /**
     * "pending" is a valid `interviews.decision` database value, but it is
     * deliberately excluded from CompleteInterviewRequest's allowed values —
     * an interview outcome can't be reset to "not yet decided" at completion.
     */
    public function test_interview_decision_accepts_only_valid_enum_values(): void
    {
        $org = $this->approvedOrganization();
        $opportunity = $this->opportunityFor($org);
        $application = $this->applicationFor($opportunity, 'shortlisted');
        $interview = $this->interviewFor($application);

        Sanctum::actingAs($org->user);

        $response = $this->putJson("/api/organization/interviews/{$interview->id}/complete", [
            'decision' => 'pending',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['decision']);
    }

    private function approvedOrganization(): object
    {
        $user = User::factory()->create([
            'role' => 'organization',
            'status' => 'active',
        ]);

        $profile = OrganizationProfile::create([
            'user_id' => $user->id,
            'organization_name' => 'Hiring Co',
            'organization_type' => 'company',
        ]);
        $profile->approval_status = 'approved';
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

    private function applicationFor(Opportunity $opportunity, string $status = 'pending'): Application
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

        $application = Application::create([
            'student_id' => $studentProfile->id,
            'opportunity_id' => $opportunity->id,
            'cv_id' => $cv->id,
        ]);

        $application->status = $status;
        $application->save();

        return $application;
    }

    private function validInterviewPayload(array $overrides = []): array
    {
        return array_merge([
            'interview_type' => 'phone',
            'scheduled_at' => now()->addDays(3)->toDateTimeString(),
            'contact_phone' => '+1 555-0100',
        ], $overrides);
    }

    /**
     * Creates an Assessment (type=interview) and its Interview directly,
     * bypassing the HTTP endpoint -- the equivalent of the old
     * `$application->interview()->create(...)` shortcut, which no longer
     * works now that `interviews` has no direct `application_id` column.
     */
    private function interviewFor(Application $application, array $overrides = []): Interview
    {
        $assessment = $application->assessment()->create([
            'type' => 'interview',
            'status' => 'scheduled',
            'result' => null,
        ]);

        return $assessment->interview()->create($this->validInterviewPayload($overrides));
    }
}
