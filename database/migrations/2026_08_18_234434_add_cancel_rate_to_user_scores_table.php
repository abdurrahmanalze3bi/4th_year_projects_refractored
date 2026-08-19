<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('user_scores', function (Blueprint $table) {
            $table->decimal('cancel_rate', 5, 2)->default(0)->after('total_rides');
        });
    }

    public function down(): void
    {
        Schema::table('user_scores', function (Blueprint $table) {
            $table->dropColumn('cancel_rate');
        });
    }
};
