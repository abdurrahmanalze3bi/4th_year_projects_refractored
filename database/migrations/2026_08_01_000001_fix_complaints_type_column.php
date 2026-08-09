<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE complaints MODIFY COLUMN `type` VARCHAR(50) NOT NULL");
        DB::statement("ALTER TABLE complaints MODIFY COLUMN `status` VARCHAR(50) NOT NULL");
    }

    public function down(): void {}
};
