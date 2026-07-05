<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE users MODIFY address VARCHAR(100) NULL");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE users MODIFY address ENUM(
            'دمشق','درعا','القنيطرة','السويداء','ريف دمشق',
            'حمص','حماة','اللاذقية','طرطوس','حلب',
            'ادلب','الحسكة','الرقة','دير الزور'
        ) NULL");
    }
};
