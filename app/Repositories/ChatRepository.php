<?php

namespace App\Repositories;

use App\Interfaces\ChatRepositoryInterface;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ChatRepository implements ChatRepositoryInterface
{
    public function createConversation(
        array   $participants,
        string  $type  = 'private',
        ?string $title = null,
        array   $roles = [],        // [userId => role]  — omitted keys → 'member'
    ): Conversation {
        return DB::transaction(function () use ($participants, $type, $title, $roles) {
            $conversation = Conversation::create([
                'type'  => $type,
                'title' => $title,
            ]);

            foreach ($participants as $userId) {
                $conversation->participants()->attach($userId, [
                    'role'      => $roles[$userId] ?? 'member',
                    'joined_at' => now(),
                ]);
            }

            Log::info('Conversation created', [
                'conversation_id' => $conversation->id,
                'type'            => $type,
            ]);

            return $conversation->load('participants');
        });
    }

    public function findConversation(int $conversationId): ?Conversation
    {
        return Conversation::with(['participants', 'messages.sender'])->find($conversationId);
    }

    public function findPrivateConversation(User $user1, User $user2): ?Conversation
    {
        return Conversation::where('type', 'private')
            ->whereHas('participants', fn ($q) => $q->where('user_id', $user1->id))
            ->whereHas('participants', fn ($q) => $q->where('user_id', $user2->id))
            ->first();
    }

    /**
     * Find an existing support conversation between a customer and an agent.
     * Looks for type = 'support' so it is never confused with private chats.
     */
    public function findSupportConversation(User $user, User $agent): ?Conversation
    {
        return Conversation::where('type', 'support')
            ->whereHas('participants', fn ($q) => $q->where('user_id', $user->id))
            ->whereHas('participants', fn ($q) => $q->where('user_id', $agent->id))
            ->first();
    }

    public function getUserConversations(User $user): Collection
    {
        return $user->conversations()
            ->with(['participants', 'lastMessage.sender'])
            ->orderBy('updated_at', 'desc')
            ->get();
    }

    public function sendMessage(
        int    $conversationId,
        int    $senderId,
        string $content,
        string $type     = 'text',
        ?array $metadata = null,
    ): Message {
        $message = Message::create([
            'conversation_id' => $conversationId,
            'sender_id'       => $senderId,
            'type'            => $type,
            'content'         => $content,
            'metadata'        => $metadata,
        ]);

        Conversation::where('id', $conversationId)->touch();

        Log::info('Message sent', ['message_id' => $message->id]);

        return $message->load('sender', 'conversation');
    }

    public function getMessages(int $conversationId, int $limit = 50, int $offset = 0): Collection
    {
        return Message::where('conversation_id', $conversationId)
            ->with('sender')
            ->orderBy('created_at', 'desc')
            ->limit($limit)
            ->offset($offset)
            ->get()
            ->reverse()
            ->values();
    }

    public function markMessageAsRead(int $messageId, int $userId): bool
    {
        $message = Message::find($messageId);
        if (!$message || $message->sender_id === $userId) {
            return false;
        }

        $message->markAsRead();
        return true;
    }

    public function deleteMessage(int $messageId, int $userId): bool
    {
        $message = Message::find($messageId);
        if (!$message || $message->sender_id !== $userId) {
            return false;
        }

        return $message->delete();
    }
}
