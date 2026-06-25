<?php

use App\Models\Conversation;
use Illuminate\Support\Facades\Broadcast;

// Default Laravel channel
Broadcast::channel('App.Models.User.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
});

// Private chat conversation channel
Broadcast::channel('conversation.{conversationId}', function ($user, $conversationId) {
    $conversation = Conversation::find($conversationId);
    return $conversation && $conversation->isParticipant($user);
});

// Private user channel (notifications)
Broadcast::channel('user.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
});

// Public rides channel (no auth needed)
Broadcast::channel('rides', function () {
    return true;
});
