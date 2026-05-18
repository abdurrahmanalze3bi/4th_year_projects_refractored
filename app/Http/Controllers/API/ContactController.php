<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Interfaces\ChatRepositoryInterface;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ContactController extends Controller
{
    public function __construct(
        private readonly ChatRepositoryInterface $chatRepository,
    ) {}

    // POST /api/contact
    public function __invoke(Request $request): JsonResponse
    {
        // Find first active support agent
        $agent = Employee::where('role', 'support_agent')
            ->where('is_active', true)
            ->first();

        if (!$agent) {
            return response()->json([
                'status'  => 'error',
                'message' => 'No support agents are available at the moment.',
            ], 503);
        }

        // Find the agent's User account by email
        $agentUser = User::where('email', $agent->email)->first();

        if (!$agentUser) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Support is currently unavailable.',
            ], 503);
        }

        $currentUser = $request->user();

        // Prevent chatting with yourself
        if ($currentUser->id === $agentUser->id) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Cannot open support chat.',
            ], 422);
        }

        // Find existing conversation or create new one
        $existing = $this->chatRepository->findPrivateConversation($currentUser, $agentUser);

        if ($existing) {
            return response()->json([
                'status'          => 'success',
                'conversation_id' => $existing->id,
                'message'         => 'Support chat ready.',
                'agent'           => [
                    'name' => $agent->first_name . ' ' . $agent->last_name,
                ],
            ]);
        }

        $conversation = $this->chatRepository->createConversation(
            [$currentUser->id, $agentUser->id],
            'private'
        );

        return response()->json([
            'status'          => 'success',
            'conversation_id' => $conversation->id,
            'message'         => 'Support chat started.',
            'agent'           => [
                'name' => $agent->first_name . ' ' . $agent->last_name,
            ],
        ], 201);
    }
}
