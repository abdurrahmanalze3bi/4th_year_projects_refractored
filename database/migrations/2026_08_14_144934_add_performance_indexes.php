<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // rides: status first (equality), then departure_time (ORDER BY),
        // then available_seats — lets MySQL satisfy WHERE + ORDER BY from
        // the index alone, no filesort, no full scan
        Schema::table('rides', function (Blueprint $table) {
            $table->index(
                ['status', 'departure_time', 'available_seats'],
                'rides_status_departure_seats_index'
            );

            // Driver dashboard: "show my active/completed rides"
            $table->index(
                ['driver_id', 'status'],
                'rides_driver_status_index'
            );
        });

        // bookings: covers both "passenger's bookings" and
        // "bookings for a ride" filtered by status
        Schema::table('bookings', function (Blueprint $table) {
            $table->index(
                ['user_id', 'status'],
                'bookings_user_status_index'
            );

            $table->index(
                ['ride_id', 'status'],
                'bookings_ride_status_index'
            );
        });
    }

    public function down(): void
    {
        Schema::table('rides', function (Blueprint $table) {
            $table->dropIndex('rides_status_departure_seats_index');
            $table->dropIndex('rides_driver_status_index');
        });

        Schema::table('bookings', function (Blueprint $table) {
            $table->dropIndex('bookings_user_status_index');
            $table->dropIndex('bookings_ride_status_index');
        });
    }
};
