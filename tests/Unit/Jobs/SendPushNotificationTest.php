<?php

namespace Tests\Unit\Jobs;

use App\Jobs\SendPushNotification;
use App\Models\User;
use App\Services\PushNotification\PushNotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * Unit tests for SendPushNotification.
 *
 * PushNotificationService (and its collaborators) are all `final`, so they
 * cannot be Mockery-doubled and still satisfy handle()'s type-hinted
 * parameter. These tests use the real service instead — it degrades safely
 * when FCM isn't configured (see FcmSenderServiceTest) — and use Log
 * expectations / Reflection where verification is needed.
 */
class SendPushNotificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_handle_sends_notification_when_user_exists(): void
    {
        $user = User::factory()->create();

        Log::shouldReceive('info')
            ->once()
            ->withArgs(fn (string $message) => str_contains($message, "Push notification sent to user {$user->id}"));

        $job = new SendPushNotification($user->id, ['title' => 'Ride confirmed', 'body' => 'Your ride has been confirmed.']);
        $job->handle(app(PushNotificationService::class));

        $this->assertTrue(true);
    }

    public function test_handle_does_nothing_when_user_does_not_exist(): void
    {
        $nonExistentUserId = 999999;

        Log::shouldReceive('info')->never();
        Log::shouldReceive('error')->never();

        $job = new SendPushNotification($nonExistentUserId, ['title' => 'Hello']);

        // Should not throw even though the user can't be found.
        $job->handle(app(PushNotificationService::class));

        $this->assertTrue(true);
    }

    public function test_handle_logs_and_rethrows_when_service_fails(): void
    {
        $user = User::factory()->create();

        // sendToUser() on the real service completes fine (no tokens → returns
        // false, no exception). We force the failure at the very next line in
        // the job instead — Log::info() — which sits inside the same try block
        // and lets us exercise the catch/rethrow path without touching the
        // final PushNotificationService class at all.
        Log::shouldReceive('info')
            ->once()
            ->andThrow(new \RuntimeException('FCM endpoint unreachable'));

        Log::shouldReceive('error')
            ->once()
            ->withArgs(fn (string $message) => str_contains($message, 'Failed to send push notification')
                && str_contains($message, 'FCM endpoint unreachable'));

        $job = new SendPushNotification($user->id, ['title' => 'Hello']);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('FCM endpoint unreachable');

        $job->handle(app(PushNotificationService::class));
    }

    public function test_constructor_stores_user_id_and_notification_data(): void
    {
        $user = User::factory()->create();
        $data = ['title' => 'Test', 'body' => 'Body'];

        $job = new SendPushNotification($user->id, $data);

        // Properties are protected — inspect directly instead of inferring
        // via a mocked collaborator (which isn't possible for this final class).
        $reflection = new \ReflectionClass($job);

        $userIdProperty = $reflection->getProperty('userId');
        $userIdProperty->setAccessible(true);
        $this->assertEquals($user->id, $userIdProperty->getValue($job));

        $dataProperty = $reflection->getProperty('notificationData');
        $dataProperty->setAccessible(true);
        $this->assertEquals($data, $dataProperty->getValue($job));
    }
}
