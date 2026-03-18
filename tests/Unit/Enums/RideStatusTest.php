<?php

namespace Tests\Unit\Enums;

use App\Enums\RideStatus;
use PHPUnit\Framework\TestCase;

class RideStatusTest extends TestCase
{
    public function test_active_can_be_booked(): void
    {
        $this->assertTrue(RideStatus::ACTIVE->canBeBooked());
    }

    public function test_full_can_be_booked(): void
    {
        $this->assertTrue(RideStatus::FULL->canBeBooked());
    }

    public function test_cancelled_cannot_be_booked(): void
    {
        $this->assertFalse(RideStatus::CANCELLED->canBeBooked());
    }

    public function test_finished_cannot_be_booked(): void
    {
        $this->assertFalse(RideStatus::FINISHED->canBeBooked());
    }

    public function test_awaiting_confirmation_cannot_be_booked(): void
    {
        $this->assertFalse(RideStatus::AWAITING_CONFIRMATION->canBeBooked());
    }

    public function test_cancelled_is_terminal(): void
    {
        $this->assertTrue(RideStatus::CANCELLED->isTerminal());
    }

    public function test_finished_is_terminal(): void
    {
        $this->assertTrue(RideStatus::FINISHED->isTerminal());
    }

    public function test_active_is_not_terminal(): void
    {
        $this->assertFalse(RideStatus::ACTIVE->isTerminal());
    }

    public function test_full_is_not_terminal(): void
    {
        $this->assertFalse(RideStatus::FULL->isTerminal());
    }

    public function test_awaiting_confirmation_is_not_terminal(): void
    {
        $this->assertFalse(RideStatus::AWAITING_CONFIRMATION->isTerminal());
    }

    public function test_label_returns_string(): void
    {
        $this->assertEquals('Active', RideStatus::ACTIVE->label());
        $this->assertEquals('Cancelled', RideStatus::CANCELLED->label());
        $this->assertEquals('Finished', RideStatus::FINISHED->label());
        $this->assertEquals('Full', RideStatus::FULL->label());
        $this->assertEquals('Awaiting Confirmation', RideStatus::AWAITING_CONFIRMATION->label());
    }

    public function test_bookable_statuses_array(): void
    {
        $statuses = RideStatus::bookableStatuses();
        $this->assertContains('active', $statuses);
        $this->assertContains('full', $statuses);
        $this->assertNotContains('cancelled', $statuses);
    }

    public function test_from_string_value(): void
    {
        $status = RideStatus::from('active');
        $this->assertEquals(RideStatus::ACTIVE, $status);
    }
}
