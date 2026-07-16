<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * The complaints table was originally created with ENUM columns for `type`
     * and `status`.  New cases were subsequently added to the PHP-side
     * ComplaintType and ComplaintStatus enums without a matching schema change,
     * so MySQL rejects values like 'financial_issue', 'trip_safety',
     * 'escalated', etc. with a "Data truncated" error in strict mode.
     *
     * Widening both columns to VARCHAR is the safest fix: it handles all
     * current values, accommodates future additions, and does not require
     * doctrine/dbal.
     */
    public function up(): void
    {
        // VARCHAR(50) easily covers every ComplaintType value (longest is
        // 'passenger_behavior' = 19 chars).
        DB::statement("ALTER TABLE `complaints`
            MODIFY COLUMN `type` VARCHAR(50) NOT NULL");

        // VARCHAR(20) covers every ComplaintStatus value (longest is
        // 'in_review' = 9 chars, plus room to grow).
        DB::statement("ALTER TABLE `complaints`
            MODIFY COLUMN `status` VARCHAR(20) NOT NULL DEFAULT 'pending'");
    }

    public function down(): void
    {
        // Restore the original restricted ENUMs (values that existed before
        // 'escalated' and certain type values were added).
        DB::statement("ALTER TABLE `complaints`
            MODIFY COLUMN `type`
            ENUM(
                'trip_safety',
                'driver_behavior',
                'passenger_behavior',
                'ride_cancellation',
                'financial_issue',
                'account_issue',
                'technical_issue',
                'other'
            ) NOT NULL");

        DB::statement("ALTER TABLE `complaints`
            MODIFY COLUMN `status`
            ENUM('pending','in_review','resolved','closed')
            NOT NULL DEFAULT 'pending'");
    }
};
