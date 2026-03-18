<?php

namespace Tests\Unit\Domain;

use App\Domain\ValueObjects\Location;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class LocationTest extends TestCase
{
    public function test_can_create_from_coordinates(): void
    {
        $loc = Location::fromCoordinates(33.5138, 36.2765);
        $this->assertEquals(33.5138, $loc->latitude());
        $this->assertEquals(36.2765, $loc->longitude());
    }

    public function test_can_create_from_array(): void
    {
        $loc = Location::fromArray(['lat' => 33.5138, 'lng' => 36.2765]);
        $this->assertEquals(33.5138, $loc->latitude());
    }

    public function test_invalid_array_missing_key_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Location::fromArray(['lat' => 33.5138]);
    }

    public function test_invalid_latitude_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Location::fromCoordinates(91.0, 36.0);
    }

    public function test_invalid_negative_latitude_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Location::fromCoordinates(-91.0, 36.0);
    }

    public function test_invalid_longitude_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Location::fromCoordinates(33.0, 181.0);
    }

    public function test_invalid_negative_longitude_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Location::fromCoordinates(33.0, -181.0);
    }

    public function test_boundary_latitude_valid(): void
    {
        $loc = Location::fromCoordinates(90.0, 0.0);
        $this->assertEquals(90.0, $loc->latitude());
    }

    public function test_boundary_longitude_valid(): void
    {
        $loc = Location::fromCoordinates(0.0, 180.0);
        $this->assertEquals(180.0, $loc->longitude());
    }

    public function test_to_array(): void
    {
        $loc = Location::fromCoordinates(33.5138, 36.2765);
        $arr = $loc->toArray();
        $this->assertArrayHasKey('lat', $arr);
        $this->assertArrayHasKey('lng', $arr);
        $this->assertEquals(33.5138, $arr['lat']);
    }

    public function test_to_point_string(): void
    {
        $loc = Location::fromCoordinates(33.5138, 36.2765);
        $this->assertStringContainsString('POINT', $loc->toPointString());
    }

    public function test_distance_to_same_point_is_zero(): void
    {
        $loc = Location::fromCoordinates(33.5138, 36.2765);
        $this->assertEquals(0.0, $loc->distanceTo($loc));
    }

    public function test_distance_between_damascus_and_aleppo(): void
    {
        // Damascus approx coords
        $damascus = Location::fromCoordinates(33.5138, 36.2765);
        // Aleppo approx coords
        $aleppo = Location::fromCoordinates(36.2021, 37.1343);
        $distance = $damascus->distanceTo($aleppo);
        // Should be roughly 300-320 km
        $this->assertGreaterThan(280_000, $distance); // > 280 km
        $this->assertLessThan(360_000, $distance);    // < 360 km
    }

    public function test_equals_same_location(): void
    {
        $a = Location::fromCoordinates(33.5138, 36.2765);
        $b = Location::fromCoordinates(33.5138, 36.2765);
        $this->assertTrue($a->equals($b));
    }

    public function test_not_equals_different_location(): void
    {
        $a = Location::fromCoordinates(33.5138, 36.2765);
        $b = Location::fromCoordinates(36.2021, 37.1343);
        $this->assertFalse($a->equals($b));
    }
}
