<?php

namespace Tests\Unit\Jobs;

use App\Jobs\SendPushNotification;
use App\Jobs\SendScheduledNotification;
use App\Models\User;
use App\Models\UserNotification;
use App\Services\NotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

// ════════════════════════════════════════════════════════════════════════════
// SendPushNotification
// PushNotificationService is marked `final` so Mockery cannot mock it.
// We use the real service — it won't actually send (no FCM credentials in tests).
// ════════════════════════════════════════════════════════════════════════════

class SendPushNotificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_job_can_be_instantiated(): void
    {
        $job = new SendPushNotification(1, ['title' => 'Test', 'body' => 'Hello']);
        $this->assertInstanceOf(SendPushNotification::class, $job);
    }

    public function test_handle_runs_without_exception_when_user_exists(): void
    {
        $user        = User::factory()->create();
        $pushService = app(\App\Services\PushNotification\PushNotificationService::class);

        $job = new SendPushNotification($user->id, [
            'title' => 'Hello',
            'body'  => 'World',
        ]);

        // Should not throw — user exists, push service just won't send (no FCM configured)
        $job->handle($pushService);
        $this->assertTrue(true);
    }

    public function test_handle_runs_without_exception_when_user_not_found(): void
    {
        $pushService = app(\App\Services\PushNotification\PushNotificationService::class);

        $job = new SendPushNotification(99999, ['title' => 'Test', 'body' => 'Test']);

        // Should not throw — user not found branch is handled silently
        $job->handle($pushService);
        $this->assertTrue(true);
    }
}

// ════════════════════════════════════════════════════════════════════════════
// SendScheduledNotification
// NotificationService is NOT final — can be mocked normally.
// ════════════════════════════════════════════════════════════════════════════

class SendScheduledNotificationTest extends TestCase
{
    public function test_job_can_be_instantiated(): void
    {
        $job = new SendScheduledNotification([
            'title'   => 'Scheduled',
            'message' => 'Hello',
            'type'    => 'test',
            'user_id' => 1,
        ]);

        $this->assertInstanceOf(SendScheduledNotification::class, $job);
    }

    public function test_handle_calls_notification_service_create(): void
    {
        $data = [
            'title'   => 'Test',
            'message' => 'Body',
            'type'    => 'test',
            'user_id' => 1,
        ];

        $notifService = Mockery::mock(NotificationService::class);
        $notifService->shouldReceive('create')->once()->with($data)->andReturn(new UserNotification());

        $job = new SendScheduledNotification($data);
        $job->handle($notifService);
    }

    public function test_handle_rethrows_exception_on_failure(): void
    {
        $notifService = Mockery::mock(NotificationService::class);
        $notifService->shouldReceive('create')->once()->andThrow(new \Exception('Failed'));

        $job = new SendScheduledNotification(['title' => 'Test', 'message' => 'Body']);

        $this->expectException(\Exception::class);
        $job->handle($notifService);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
