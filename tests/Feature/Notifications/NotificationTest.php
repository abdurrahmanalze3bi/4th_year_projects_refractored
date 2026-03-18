<?php

namespace Tests\Feature\Notifications;

use App\Models\Notification;
use App\Models\User;
use App\Models\UserNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NotificationTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user  = User::factory()->create(['password' => bcrypt('password123')]);
        $this->token = $this->getToken($this->user);
    }

    public function test_can_get_notifications(): void
    {
        $this->createNotificationForUser();
        $response = $this->withToken($this->token)->getJson('/api/notifications');
        $response->assertStatus(200)->assertJsonStructure(['data']);
    }

    public function test_can_get_unread_count(): void
    {
        $this->createNotificationForUser();
        $response = $this->withToken($this->token)->getJson('/api/notifications/unread-count');
        $response->assertStatus(200)->assertJsonStructure(['unread_count']);
    }

    public function test_can_mark_notification_as_read(): void
    {
        $userNotif = $this->createNotificationForUser();
        $response = $this->withToken($this->token)->postJson("/api/notifications/{$userNotif->id}/read");
        $response->assertStatus(200);
        $this->assertNotNull(UserNotification::find($userNotif->id)->read_at);
    }

    public function test_can_mark_all_as_read(): void
    {
        $this->createNotificationForUser();
        $this->createNotificationForUser();
        $response = $this->withToken($this->token)->postJson('/api/notifications/read-all');
        $response->assertStatus(200)->assertJsonPath('unread_count', 0);
    }

    public function test_can_delete_notification(): void
    {
        $userNotif = $this->createNotificationForUser();
        $response = $this->withToken($this->token)->deleteJson("/api/notifications/{$userNotif->id}");
        $response->assertStatus(200);
        $this->assertNull(UserNotification::find($userNotif->id));
    }

    public function test_can_get_notification_categories(): void
    {
        $response = $this->withToken($this->token)->getJson('/api/notifications/categories');
        $response->assertStatus(200)->assertJsonStructure(['data']);
    }

    public function test_notifications_require_auth(): void
    {
        $this->getJson('/api/notifications')->assertStatus(401);
    }

    public function test_bulk_action_mark_read(): void
    {
        $n1 = $this->createNotificationForUser();
        $n2 = $this->createNotificationForUser();

        $response = $this->withToken($this->token)->postJson('/api/notifications/bulk-action', [
            'action'           => 'mark_read',
            'notification_ids' => [$n1->id, $n2->id],
        ]);

        $response->assertStatus(200);
    }

    private function createNotificationForUser(): UserNotification
    {
        $notification = Notification::create([
            'title'   => 'Test',
            'message' => 'Test message',
            'type'    => 'general',
            'sent_at' => now(),
        ]);
        return UserNotification::create([
            'user_id'         => $this->user->id,
            'notification_id' => $notification->id,
        ]);
    }

    private function getToken(User $user): string
    {
        $response = $this->postJson('/api/auth/login', [
            'email'    => $user->email,
            'password' => 'password123',
        ]);
        return $response->json('tokens.access_token');
    }
}
