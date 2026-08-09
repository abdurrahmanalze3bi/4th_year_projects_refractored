<?php

namespace App\Services;

use App\Models\User;
use App\Models\UserNotification;
use App\Models\Notification;
use App\Events\NotificationSent;
use App\Services\PushNotification\PushNotificationService;
use Illuminate\Pagination\LengthAwarePaginator;

class NotificationService
{
    public function __construct(
        private readonly PushNotificationService $pushService
    ) {}

    public function getUserNotifications(
        User $user,
        array $filters = [],
        int $perPage = 15
    ): LengthAwarePaginator {
        $query = UserNotification::with('notification')
            ->where('user_id', $user->id)
            ->orderBy('created_at', 'desc');

        if (!empty($filters['category'])) {
            $query->whereHas('notification', function ($q) use ($filters) {
                $q->where('type', $filters['category']);
            });
        }

        if (isset($filters['is_read'])) {
            if ($filters['is_read']) {
                $query->whereNotNull('read_at');
            } else {
                $query->whereNull('read_at');
            }
        }

        return $query->paginate($perPage);
    }

    public function createNotification(
        User $user,
        string $type,
        string $title,
        string $message,
        array $data = [],
        string $priority = 'normal',
        string $category = 'general'
    ): UserNotification {
        // FIX: was using SendPushNotificationJob::dispatch() which does not exist
        // → caused every createNotification() call to throw a fatal error

        $notification = Notification::create([
            'title'   => $title,
            'message' => $message,
            'type'    => $type,
            'data'    => $data,
            'sent_at' => now(),
        ]);

        $userNotification = UserNotification::create([
            'user_id'         => $user->id,
            'notification_id' => $notification->id,
        ]);

        // Push notification — wrapped in try/catch so a failed push never
        // blocks the in-app notification from being saved and returned.
        try {
            $this->pushService->sendToUser($user, [
                'title' => $title,
                'body'  => $message,
                'data'  => array_merge($data, [
                    'notification_id' => $notification->id,
                    'type'            => $type,
                    'category'        => $category,
                    'priority'        => $priority,
                ]),
            ]);
        } catch (\Throwable) {
            // Push failure must never break notification creation
        }

        try {
            broadcast(new NotificationSent($user, $notification));
        } catch (\Throwable) {
            // Broadcast failure must never break notification creation
        }

        return $userNotification->load('notification');
    }

    // FIX: was missing entirely — NotificationController::markAsRead() called
    // this method, got "Call to undefined method" → 500 HTML response
    public function markAsRead(UserNotification $notification): void
    {
        if (!$notification->read_at) {
            $notification->update(['read_at' => now()]);
        }
    }

    public function markAllAsRead(User $user): void
    {
        UserNotification::where('user_id', $user->id)
            ->whereNull('read_at')
            ->update(['read_at' => now()]);
    }

    public function deleteNotification(UserNotification $notification): void
    {
        $notification->delete();
    }
}
