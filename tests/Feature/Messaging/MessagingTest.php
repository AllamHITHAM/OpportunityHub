<?php

namespace Tests\Feature\Messaging;

use App\Models\Application;
use App\Models\Conversation;
use App\Models\Invitation;
use App\Models\Notification;
use App\Models\Opportunity;
use App\Models\OrganizationProfile;
use App\Models\StudentProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Messaging MVP -- covers the 19-item focused test list from the phase
 * spec: starting/reusing a Conversation, who may start one, ownership
 * enforcement on both sides, sending/replying, sender-spoofing
 * protection, body validation, chronological ordering, read/unread
 * state, the once-per-message notification, and that messaging never
 * mutates Application/Invitation state.
 */
class MessagingTest extends TestCase
{
    use RefreshDatabase;

    // -----------------------------------------------------------------
    // 1-3: starting/reusing a conversation, who may start one.
    // -----------------------------------------------------------------

    public function test_organization_can_start_conversation_with_eligible_candidate(): void
    {
        $org = $this->approvedOrganization();
        $opportunity = $this->opportunityFor($org, ['work_mode' => 'remote']);
        $student = $this->studentWithProfile();

        Sanctum::actingAs($org->user);

        $response = $this->postJson("/api/organization/candidates/{$student->id}/conversation", [
            'opportunity_id' => $opportunity->id,
        ]);

        $response->assertStatus(201);
        $this->assertDatabaseHas('conversations', [
            'organization_id' => $org->profile->id,
            'student_id' => $student->id,
            'opportunity_id' => $opportunity->id,
        ]);
    }

    public function test_duplicate_start_reuses_same_conversation(): void
    {
        $org = $this->approvedOrganization();
        $opportunity = $this->opportunityFor($org, ['work_mode' => 'remote']);
        $student = $this->studentWithProfile();

        Sanctum::actingAs($org->user);

        $first = $this->postJson("/api/organization/candidates/{$student->id}/conversation", [
            'opportunity_id' => $opportunity->id,
        ])->assertStatus(201)->json('data.conversation_id');

        $second = $this->postJson("/api/organization/candidates/{$student->id}/conversation", [
            'opportunity_id' => $opportunity->id,
        ])->assertStatus(201)->json('data.conversation_id');

        $this->assertSame($first, $second);
        $this->assertSame(1, Conversation::count());
    }

    public function test_unauthorized_organization_is_blocked_with_no_recruiting_relationship(): void
    {
        $org = $this->approvedOrganization();
        // On-site with a real location -- the Student below has no
        // available_locations, so isLocationEligible() fails, and there is
        // no Application/Invitation either. No legitimate reason to
        // message this Student about this Opportunity.
        $location = \App\Models\Location::create(['canonical_name' => 'Ramallah']);
        $opportunity = $this->opportunityFor($org, ['work_mode' => 'on_site', 'location_id' => $location->id]);
        $student = $this->studentWithProfile();

        Sanctum::actingAs($org->user);

        $this->postJson("/api/organization/candidates/{$student->id}/conversation", [
            'opportunity_id' => $opportunity->id,
        ])->assertStatus(403);

        $this->assertSame(0, Conversation::count());
    }

    public function test_organization_cannot_start_conversation_via_unowned_opportunity(): void
    {
        $orgA = $this->approvedOrganization();
        $orgB = $this->approvedOrganization();
        $opportunityB = $this->opportunityFor($orgB, ['work_mode' => 'remote']);
        $student = $this->studentWithProfile();

        Sanctum::actingAs($orgA->user);

        $this->postJson("/api/organization/candidates/{$student->id}/conversation", [
            'opportunity_id' => $opportunityB->id,
        ])->assertStatus(404);
    }

    // -----------------------------------------------------------------
    // 4-5: ownership on the shared show()/index() endpoints.
    // -----------------------------------------------------------------

    public function test_student_can_view_own_conversation(): void
    {
        [$org, $student, $conversation] = $this->conversationBetween();

        Sanctum::actingAs($student->user);

        $this->getJson("/api/conversations/{$conversation->id}")
            ->assertStatus(200)
            ->assertJsonPath('data.id', $conversation->id);
    }

    public function test_unrelated_student_is_blocked(): void
    {
        [, , $conversation] = $this->conversationBetween();
        $otherStudent = $this->studentWithProfile(name: 'Someone Else');

        Sanctum::actingAs($otherStudent->user);

        $this->getJson("/api/conversations/{$conversation->id}")->assertStatus(404);
    }

    public function test_organization_cannot_access_another_organizations_conversation(): void
    {
        [, , $conversation] = $this->conversationBetween();
        $otherOrg = $this->approvedOrganization();

        Sanctum::actingAs($otherOrg->user);

        $this->getJson("/api/conversations/{$conversation->id}")->assertStatus(404);
    }

    // -----------------------------------------------------------------
    // 6-10: sending, replying, spoofing, validation.
    // -----------------------------------------------------------------

    public function test_organization_can_send_message(): void
    {
        [$org, $student, $conversation] = $this->conversationBetween();

        Sanctum::actingAs($org->user);

        $response = $this->postJson("/api/conversations/{$conversation->id}/messages", [
            'body' => 'Hello, are you still interested?',
        ]);

        $response->assertStatus(201)->assertJsonPath('data.is_own_message', true);
        $this->assertDatabaseHas('messages', [
            'conversation_id' => $conversation->id,
            'sender_user_id' => $org->user->id,
            'body' => 'Hello, are you still interested?',
        ]);
    }

    public function test_student_can_reply(): void
    {
        [$org, $student, $conversation] = $this->conversationBetween();

        Sanctum::actingAs($student->user);

        $this->postJson("/api/conversations/{$conversation->id}/messages", [
            'body' => 'Yes, very interested!',
        ])->assertStatus(201);

        $this->assertDatabaseHas('messages', [
            'conversation_id' => $conversation->id,
            'sender_user_id' => $student->user->id,
            'body' => 'Yes, very interested!',
        ]);
    }

    public function test_sender_id_cannot_be_spoofed(): void
    {
        [$org, $student, $conversation] = $this->conversationBetween();

        Sanctum::actingAs($student->user);

        $response = $this->postJson("/api/conversations/{$conversation->id}/messages", [
            'body' => 'Pretending to be the org.',
            'sender_user_id' => $org->user->id,
        ]);

        $response->assertStatus(201);
        $this->assertDatabaseHas('messages', [
            'conversation_id' => $conversation->id,
            'sender_user_id' => $student->user->id,
        ]);
        $this->assertDatabaseMissing('messages', [
            'conversation_id' => $conversation->id,
            'sender_user_id' => $org->user->id,
        ]);
    }

    public function test_empty_message_is_rejected(): void
    {
        [$org, , $conversation] = $this->conversationBetween();

        Sanctum::actingAs($org->user);

        $this->postJson("/api/conversations/{$conversation->id}/messages", ['body' => ''])
            ->assertStatus(422);
    }

    public function test_whitespace_only_message_is_rejected(): void
    {
        [$org, , $conversation] = $this->conversationBetween();

        Sanctum::actingAs($org->user);

        $this->postJson("/api/conversations/{$conversation->id}/messages", ['body' => '     '])
            ->assertStatus(422);
    }

    public function test_overlong_message_is_rejected(): void
    {
        [$org, , $conversation] = $this->conversationBetween();

        Sanctum::actingAs($org->user);

        $this->postJson("/api/conversations/{$conversation->id}/messages", ['body' => str_repeat('a', 2001)])
            ->assertStatus(422);
    }

    // -----------------------------------------------------------------
    // 11: chronological ordering.
    // -----------------------------------------------------------------

    public function test_messages_are_ordered_chronologically(): void
    {
        [$org, $student, $conversation] = $this->conversationBetween();

        Sanctum::actingAs($org->user);
        $this->postJson("/api/conversations/{$conversation->id}/messages", ['body' => 'First'])->assertStatus(201);

        Sanctum::actingAs($student->user);
        $this->postJson("/api/conversations/{$conversation->id}/messages", ['body' => 'Second'])->assertStatus(201);

        Sanctum::actingAs($org->user);
        $this->postJson("/api/conversations/{$conversation->id}/messages", ['body' => 'Third'])->assertStatus(201);

        $response = $this->getJson("/api/conversations/{$conversation->id}");
        $bodies = collect($response->json('data.messages'))->pluck('body')->all();

        $this->assertSame(['First', 'Second', 'Third'], $bodies);
    }

    // -----------------------------------------------------------------
    // 12-14: read/unread state.
    // -----------------------------------------------------------------

    public function test_unread_state_and_count_are_correct_before_reading(): void
    {
        [$org, $student, $conversation] = $this->conversationBetween();

        Sanctum::actingAs($org->user);
        $this->postJson("/api/conversations/{$conversation->id}/messages", ['body' => 'One'])->assertStatus(201);
        $this->postJson("/api/conversations/{$conversation->id}/messages", ['body' => 'Two'])->assertStatus(201);

        Sanctum::actingAs($student->user);
        $list = $this->getJson('/api/conversations')->assertStatus(200)->json('data');

        $this->assertSame(2, $list[0]['unread_count']);
    }

    public function test_opening_conversation_marks_recipient_messages_as_read(): void
    {
        [$org, $student, $conversation] = $this->conversationBetween();

        Sanctum::actingAs($org->user);
        $this->postJson("/api/conversations/{$conversation->id}/messages", ['body' => 'Please read me'])
            ->assertStatus(201);

        Sanctum::actingAs($student->user);
        $this->getJson("/api/conversations/{$conversation->id}")->assertStatus(200);

        $message = $conversation->messages()->first();
        $this->assertNotNull($message->fresh()->read_at);
    }

    public function test_opening_conversation_never_marks_own_messages_as_unread_or_touches_them(): void
    {
        [$org, $student, $conversation] = $this->conversationBetween();

        Sanctum::actingAs($org->user);
        $this->postJson("/api/conversations/{$conversation->id}/messages", ['body' => 'From org'])
            ->assertStatus(201);

        // The sender opening their own conversation must not affect their
        // own message's read_at (it was never unread to them).
        $this->getJson("/api/conversations/{$conversation->id}")->assertStatus(200);

        $message = $conversation->messages()->first();
        $this->assertNull($message->fresh()->read_at);
    }

    public function test_unread_count_reflects_only_the_other_partys_unread_messages(): void
    {
        [$org, $student, $conversation] = $this->conversationBetween();

        Sanctum::actingAs($org->user);
        $this->postJson("/api/conversations/{$conversation->id}/messages", ['body' => 'From org'])
            ->assertStatus(201);

        Sanctum::actingAs($student->user);
        $this->getJson("/api/conversations/{$conversation->id}")->assertStatus(200);

        $this->postJson("/api/conversations/{$conversation->id}/messages", ['body' => 'Reply'])
            ->assertStatus(201);

        Sanctum::actingAs($org->user);
        $list = $this->getJson('/api/conversations')->assertStatus(200)->json('data');
        $this->assertSame(1, $list[0]['unread_count']);
    }

    // -----------------------------------------------------------------
    // 15: notification.
    // -----------------------------------------------------------------

    public function test_notification_is_created_once_per_message_for_the_recipient_only(): void
    {
        [$org, $student, $conversation] = $this->conversationBetween();

        Sanctum::actingAs($org->user);
        $this->postJson("/api/conversations/{$conversation->id}/messages", ['body' => 'Hello'])
            ->assertStatus(201);

        $this->assertSame(1, Notification::where('user_id', $student->user->id)->where('type', 'message')->count());
        $this->assertSame(0, Notification::where('user_id', $org->user->id)->where('type', 'message')->count());

        $notification = Notification::where('user_id', $student->user->id)->where('type', 'message')->firstOrFail();
        $this->assertStringNotContainsString('Hello', $notification->message);
        $this->assertSame("/student/messages/{$conversation->id}", $notification->action_url);
    }

    // -----------------------------------------------------------------
    // 16-18: messaging never mutates Application/Invitation state.
    // -----------------------------------------------------------------

    public function test_messaging_does_not_change_application_status(): void
    {
        $org = $this->approvedOrganization();
        $opportunity = $this->opportunityFor($org, ['work_mode' => 'remote']);
        $student = $this->studentWithProfile();
        $cv = $student->cvs()->create(['title' => 'CV', 'file_path' => 'cvs/a.pdf']);
        $application = $opportunity->applications()->create(['student_id' => $student->id, 'cv_id' => $cv->id]);
        $statusBefore = $application->fresh()->status;

        Sanctum::actingAs($org->user);
        $conversationId = $this->postJson("/api/organization/candidates/{$student->id}/conversation", [
            'opportunity_id' => $opportunity->id,
        ])->assertStatus(201)->json('data.conversation_id');

        $this->postJson("/api/conversations/{$conversationId}/messages", ['body' => 'Hi there'])
            ->assertStatus(201);

        $this->assertSame($statusBefore, $application->fresh()->status);
    }

    public function test_messaging_does_not_create_an_application(): void
    {
        $org = $this->approvedOrganization();
        $opportunity = $this->opportunityFor($org, ['work_mode' => 'remote']);
        $student = $this->studentWithProfile();

        Sanctum::actingAs($org->user);
        $conversationId = $this->postJson("/api/organization/candidates/{$student->id}/conversation", [
            'opportunity_id' => $opportunity->id,
        ])->assertStatus(201)->json('data.conversation_id');

        $this->postJson("/api/conversations/{$conversationId}/messages", ['body' => 'Hi there'])
            ->assertStatus(201);

        $this->assertSame(0, Application::where('opportunity_id', $opportunity->id)->count());
    }

    public function test_messaging_does_not_change_invitation_status(): void
    {
        $org = $this->approvedOrganization();
        $opportunity = $this->opportunityFor($org, ['work_mode' => 'remote']);
        $student = $this->studentWithProfile();
        $invitation = $opportunity->invitations()->create(['student_id' => $student->id]);
        $statusBefore = $invitation->fresh()->status;

        Sanctum::actingAs($org->user);
        $conversationId = $this->postJson("/api/organization/candidates/{$student->id}/conversation", [
            'opportunity_id' => $opportunity->id,
        ])->assertStatus(201)->json('data.conversation_id');

        $this->postJson("/api/conversations/{$conversationId}/messages", ['body' => 'Hi there'])
            ->assertStatus(201);

        $this->assertSame($statusBefore, $invitation->fresh()->status);
    }

    // -----------------------------------------------------------------
    // 19: FK strategy -- deleting an unrelated business record does not
    // corrupt the conversation.
    // -----------------------------------------------------------------

    public function test_deleting_the_opportunity_preserves_the_conversation_and_its_messages(): void
    {
        [$org, $student, $conversation] = $this->conversationBetween();

        Sanctum::actingAs($org->user);
        $this->postJson("/api/conversations/{$conversation->id}/messages", ['body' => 'Keep me'])
            ->assertStatus(201);

        $conversation->opportunity->forceDelete();

        $conversation->refresh();
        $this->assertNull($conversation->opportunity_id);
        $this->assertSame(1, $conversation->messages()->count());
        $this->assertDatabaseHas('messages', ['conversation_id' => $conversation->id, 'body' => 'Keep me']);
    }

    // -----------------------------------------------------------------
    // Helpers.
    // -----------------------------------------------------------------

    /**
     * @return array{0: object, 1: StudentProfile, 2: Conversation}
     */
    private function conversationBetween(): array
    {
        $org = $this->approvedOrganization();
        $opportunity = $this->opportunityFor($org, ['work_mode' => 'remote']);
        $student = $this->studentWithProfile();

        Sanctum::actingAs($org->user);
        $conversationId = $this->postJson("/api/organization/candidates/{$student->id}/conversation", [
            'opportunity_id' => $opportunity->id,
        ])->assertStatus(201)->json('data.conversation_id');

        return [$org, $student, Conversation::findOrFail($conversationId)];
    }

    private function approvedOrganization(): object
    {
        $user = User::factory()->create(['role' => 'organization', 'status' => 'active']);

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

    private function studentWithProfile(array $overrides = [], string $name = 'Jane Student'): StudentProfile
    {
        $user = User::factory()->create([
            'role' => 'student',
            'status' => 'active',
            'name' => $name,
        ]);

        return StudentProfile::create(array_merge(['user_id' => $user->id], $overrides));
    }
}
