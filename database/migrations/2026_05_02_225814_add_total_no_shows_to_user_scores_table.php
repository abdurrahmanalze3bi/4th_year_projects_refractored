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
            $table->unsignedInteger('total_no_shows')->default(0)->after('total_cancellations');
        });
    }

    public function down(): void
    {
        Schema::table('user_scores', function (Blueprint $table) {
            $table->dropColumn('total_no_shows');
        });
    }
};
