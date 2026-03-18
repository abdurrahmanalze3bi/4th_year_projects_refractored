<?php

namespace Tests\Feature\Chat;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ChatTest extends TestCase
{
    use RefreshDatabase;

    private User $user1;
    private User $user2;
    private string $token1;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user1 = User::factory()->create(['password' => bcrypt('password123')]);
        $this->user2 = User::factory()->create(['password' => bcrypt('password123')]);
        $this->token1 = $this->getToken($this->user1);
    }

    public function test_can_start_conversation(): void
    {
        $response = $this->withToken($this->token1)
            ->postJson('/api/chat/conversations', [
                'user_id' => $this->user2->id,
            ]);
        $response->assertStatus(201)->assertJsonStructure(['data' => ['conversation_id']]);
    }

    public function test_cannot_start_conversation_with_self(): void
    {
        $response = $this->withToken($this->token1)
            ->postJson('/api/chat/conversations', [
                'user_id' => $this->user1->id,
            ]);
        $response->assertStatus(422);
    }

    public function test_starting_existing_conversation_returns_same_id(): void
    {
        $r1 = $this->withToken($this->token1)
            ->postJson('/api/chat/conversations', ['user_id' => $this->user2->id]);
        $r2 = $this->withToken($this->token1)
            ->postJson('/api/chat/conversations', ['user_id' => $this->user2->id]);

        $this->assertEquals($r1->json('data.conversation_id'), $r2->json('data.conversation_id'));
    }

    public function test_can_get_conversations(): void
    {
        $this->withToken($this->token1)
            ->postJson('/api/chat/conversations', ['user_id' => $this->user2->id]);

        $response = $this->withToken($this->token1)->getJson('/api/chat/conversations');
        $response->assertStatus(200)->assertJsonStructure(['data']);
    }

    public function test_can_send_text_message(): void
    {
        $convResponse = $this->withToken($this->token1)
            ->postJson('/api/chat/conversations', ['user_id' => $this->user2->id]);
        $convId = $convResponse->json('data.conversation_id');

        $response = $this->withToken($this->token1)
            ->postJson("/api/chat/conversations/{$convId}/messages", [
                'type'    => 'text',
                'content' => 'Hello!',
            ]);
        $response->assertStatus(201);
    }

    public function test_can_get_messages(): void
    {
        $convResponse = $this->withToken($this->token1)
            ->postJson('/api/chat/conversations', ['user_id' => $this->user2->id]);
        $convId = $convResponse->json('data.conversation_id');

        $response = $this->withToken($this->token1)
            ->getJson("/api/chat/conversations/{$convId}/messages");
        $response->assertStatus(200)->assertJsonStructure(['data']);
    }

    public function test_chat_requires_auth(): void
    {
        $this->getJson('/api/chat/conversations')->assertStatus(401);
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
