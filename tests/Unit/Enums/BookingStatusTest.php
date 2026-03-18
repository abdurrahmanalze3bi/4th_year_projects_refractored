<?php

namespace Tests\Unit\Enums;

use App\Enums\BookingStatus;
use PHPUnit\Framework\TestCase;

class BookingStatusTest extends TestCase
{
    public function test_pending_can_be_cancelled(): void
    {
        $this->assertTrue(BookingStatus::PENDING->canBeCancelled());
    }

    public function test_confirmed_can_be_cancelled(): void
    {
        $this->assertTrue(BookingStatus::CONFIRMED->canBeCancelled());
    }

    public function test_completed_cannot_be_cancelled(): void
    {
        $this->assertFalse(BookingStatus::COMPLETED->canBeCancelled());
    }

    public function test_cancelled_cannot_be_cancelled_again(): void
    {
        $this->assertFalse(BookingStatus::CANCELLED->canBeCancelled());
    }

    public function test_pending_is_active(): void
    {
        $this->assertTrue(BookingStatus::PENDING->isActive());
    }

    public function test_confirmed_is_active(): void
    {
        $this->assertTrue(BookingStatus::CONFIRMED->isActive());
    }

    public function test_cancelled_is_not_active(): void
    {
        $this->assertFalse(BookingStatus::CANCELLED->isActive());
    }

    public function test_completed_is_not_active(): void
    {
        $this->assertFalse(BookingStatus::COMPLETED->isActive());
    }

    public function test_active_statuses_array(): void
    {
        $statuses = BookingStatus::activeStatuses();
        $this->assertContains('pending', $statuses);
        $this->assertContains('confirmed', $statuses);
        $this->assertNotContains('cancelled', $statuses);
        $this->assertNotContains('completed', $statuses);
    }

    public function test_labels(): void
    {
        $this->assertEquals('Pending Approval', BookingStatus::PENDING->label());
        $this->assertEquals('Confirmed', BookingStatus::CONFIRMED->label());
        $this->assertEquals('Cancelled', BookingStatus::CANCELLED->label());
        $this->assertEquals('Completed', BookingStatus::COMPLETED->label());
    }
}
