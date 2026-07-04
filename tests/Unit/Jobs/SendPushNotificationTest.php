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
 * The job's only real logic is:
 *   1. Look the user up by id.
 *   2. If found, delegate to PushNotificationService::sendToUser().
 *   3. If not found, do nothing (no exception).
 *   4. Any exception from the service is logged and re-thrown so the
 *      queue worker's retry/failed-job handling kicks in.
 */
class SendPushNotificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_handle_sends_notification_when_user_exists(): void
    {
        $user = User::factory()->create();

        $notificationData = [
            'title' => 'Ride confirmed',
            'body'  => 'Your ride has been confirmed.',
        ];

        $pushService = $this->mock(PushNotificationService::class);
        $pushService->shouldReceive('sendToUser')
            ->once()
            ->withArgs(function (User $passedUser, array $passedData) use ($user, $notificationData) {
                return $passedUser->id === $user->id && $passedData === $notificationData;
            });

        $job = new SendPushNotification($user->id, $notificationData);
        $job->handle($pushService);

        // If we got here without an exception, and the mock expectation
        // above was satisfied, the job did the right thing.
        $this->assertTrue(true);
    }

    public function test_handle_does_nothing_when_user_does_not_exist(): void
    {
        $nonExistentUserId = 999999;

        $pushService = $this->mock(PushNotificationService::class);
        $pushService->shouldReceive('sendToUser')->never();

        $job = new SendPushNotification($nonExistentUserId, ['title' => 'Hello']);

        // Should not throw even though the user can't be found.
        $job->handle($pushService);

        $this->assertTrue(true);
    }

    public function test_handle_logs_and_rethrows_when_service_fails(): void
    {
        $user = User::factory()->create();

        $pushService = $this->mock(PushNotificationService::class);
        $pushService->shouldReceive('sendToUser')
            ->once()
            ->andThrow(new \RuntimeException('FCM endpoint unreachable'));

        Log::shouldReceive('error')
            ->once()
            ->withArgs(function (string $message) {
                return str_contains($message, 'Failed to send push notification')
                    && str_contains($message, 'FCM endpoint unreachable');
            });

        $job = new SendPushNotification($user->id, ['title' => 'Hello']);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('FCM endpoint unreachable');

        $job->handle($pushService);
    }

    public function test_constructor_stores_user_id_and_notification_data(): void
    {
        $user = User::factory()->create();
        $data = ['title' => 'Test', 'body' => 'Body'];

        $job = new SendPushNotification($user->id, $data);

        // Properties are protected, so we assert indirectly via handle().
        $pushService = $this->mock(PushNotificationService::class);
        $pushService->shouldReceive('sendToUser')
            ->once()
            ->withArgs(function (User $passedUser, array $passedData) use ($user, $data) {
                return $passedUser->id === $user->id && $passedData === $data;
            });

        $job->handle($pushService);

        $this->assertTrue(true);
    }
}
