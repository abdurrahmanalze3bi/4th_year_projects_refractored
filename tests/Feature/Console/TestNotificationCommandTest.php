<?php

namespace Tests\Feature\Console;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TestNotificationCommandTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->user->profile()->create([
            'full_name'       => 'Test User',
            'number_of_rides' => 0,
        ]);
    }

    public function test_command_sends_default_test_notification(): void
    {
        $this->artisan('notification:test', [
            'user_id' => $this->user->id,
        ])->assertExitCode(0);
    }

    public function test_command_uses_first_user_when_no_id_given(): void
    {
        $this->artisan('notification:test')
            ->assertExitCode(0);
    }

    public function test_command_fails_when_no_users_exist_and_no_id_given(): void
    {
        User::query()->delete();

        $this->artisan('notification:test')->assertExitCode(1);
    }

    public function test_command_fails_for_welcome_type_due_to_missing_method(): void
    {
        // FIX: Command was fixed to call createNotification() for all types
        // including 'welcome' — it now succeeds and returns exit code 0
        $this->artisan('notification:test', [
            'user_id' => $this->user->id,
            '--type'  => 'welcome',
        ])->assertExitCode(0);
    }

    public function test_command_fails_for_system_type_due_to_missing_method(): void
    {
        // FIX: Command was fixed to call createNotification() for all types
        // including 'system' — it now succeeds and returns exit code 0
        $this->artisan('notification:test', [
            'user_id' => $this->user->id,
            '--type'  => 'system',
        ])->assertExitCode(0);
    }
}
