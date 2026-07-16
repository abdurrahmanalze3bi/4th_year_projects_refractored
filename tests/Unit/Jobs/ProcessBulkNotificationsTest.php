<?php

namespace Tests\Unit\Jobs;

use App\Jobs\ProcessBulkNotifications;
use Illuminate\Contracts\Queue\ShouldQueue;
use PHPUnit\Framework\TestCase;

class ProcessBulkNotificationsTest extends TestCase
{
    // ─── Structure ────────────────────────────────────────────────────────────────

    public function test_job_class_exists(): void
    {
        $this->assertTrue(class_exists(ProcessBulkNotifications::class));
    }

    public function test_job_can_be_instantiated(): void
    {
        $job = new ProcessBulkNotifications();
        $this->assertInstanceOf(ProcessBulkNotifications::class, $job);
    }

    public function test_job_implements_should_queue(): void
    {
        $this->assertInstanceOf(ShouldQueue::class, new ProcessBulkNotifications());
    }

    public function test_job_has_handle_method(): void
    {
        $this->assertTrue(method_exists(ProcessBulkNotifications::class, 'handle'));
    }

    // ─── Traits ───────────────────────────────────────────────────────────────────

    public function test_job_uses_dispatchable_trait(): void
    {
        $this->assertTrue(
            in_array(
                \Illuminate\Foundation\Bus\Dispatchable::class,
                class_uses_recursive(ProcessBulkNotifications::class)
            )
        );
    }

    public function test_job_uses_interacts_with_queue_trait(): void
    {
        $this->assertTrue(
            in_array(
                \Illuminate\Queue\InteractsWithQueue::class,
                class_uses_recursive(ProcessBulkNotifications::class)
            )
        );
    }

    public function test_job_uses_queueable_trait(): void
    {
        $this->assertTrue(
            in_array(
                \Illuminate\Bus\Queueable::class,
                class_uses_recursive(ProcessBulkNotifications::class)
            )
        );
    }

    public function test_job_uses_serializes_models_trait(): void
    {
        $this->assertTrue(
            in_array(
                \Illuminate\Queue\SerializesModels::class,
                class_uses_recursive(ProcessBulkNotifications::class)
            )
        );
    }

    // ─── handle() ─────────────────────────────────────────────────────────────────

    public function test_handle_runs_without_throwing(): void
    {
        // handle() is currently an empty stub — this test documents that it
        // completes silently and is safe to call.
        $this->expectNotToPerformAssertions();
        (new ProcessBulkNotifications())->handle();
    }

    public function test_handle_returns_void(): void
    {
        $ref    = new \ReflectionClass(ProcessBulkNotifications::class);
        $method = $ref->getMethod('handle');
        $return = $method->getReturnType();

        $this->assertNotNull($return);
        $this->assertEquals('void', (string) $return);
    }

    public function test_handle_accepts_no_parameters(): void
    {
        $ref    = new \ReflectionClass(ProcessBulkNotifications::class);
        $method = $ref->getMethod('handle');

        $this->assertCount(0, $method->getParameters());
    }

    public function test_multiple_handle_calls_do_not_throw(): void
    {
        $this->expectNotToPerformAssertions();
        $job = new ProcessBulkNotifications();
        $job->handle();
        $job->handle();
        $job->handle();
    }
}
