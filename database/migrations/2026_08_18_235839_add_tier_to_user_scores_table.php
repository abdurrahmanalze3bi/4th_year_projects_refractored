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
            $table->string('tier', 20)->default('bronze')->after('cancel_rate');
        });
    }

    public function down(): void
    {
        Schema::table('user_scores', function (Blueprint $table) {
            $table->dropColumn('tier');
        });
    }
};
