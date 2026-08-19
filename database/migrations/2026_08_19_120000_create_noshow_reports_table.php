<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('noshow_reports', function (Blueprint $table) {
            $table->id();

            $table->foreignId('ride_id')
                ->constrained('rides')
                ->cascadeOnDelete();

            $table->foreignId('booking_id')
                ->constrained('bookings')
                ->cascadeOnDelete();

            // Who pressed the no-show button
            $table->foreignId('reporter_id')
                ->constrained('users')
                ->cascadeOnDelete();
            $table->enum('reporter_role', ['driver', 'passenger']);

            // Who is being accused of not showing up
            $table->foreignId('target_id')
                ->constrained('users')
                ->cascadeOnDelete();
            $table->enum('target_role', ['driver', 'passenger']);

            // Copied from the ride at report time so the scheduler needs no joins
            $table->string('payment_method', 20);

            $table->enum('status', [
                'pending',                   // waiting for counter-report or expiry
                'resolved_reporter_wins',    // 2h passed with no counter → penalty applied
                'disputed',                  // both pressed within 2h → auto-complaint created
            ])->default('pending');

            // The dispute window closes at created_at + 2 hours
            $table->timestamp('expires_at');
            $table->timestamp('resolved_at')->nullable();

            $table->timestamps();

            $table->index(['ride_id',     'status'], 'idx_noshow_ride_status');
            $table->index(['expires_at',  'status'], 'idx_noshow_expires_status');
            $table->index(['reporter_id', 'target_id'], 'idx_noshow_parties');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('noshow_reports');
    }
};
