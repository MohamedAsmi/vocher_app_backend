<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE vouchers MODIFY status ENUM('open', 'pending_review', 'posted', 'variance') NOT NULL DEFAULT 'open'");
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::table('vouchers')->where('status', 'pending_review')->update(['status' => 'posted']);
            DB::statement("ALTER TABLE vouchers MODIFY status ENUM('open', 'posted', 'variance') NOT NULL DEFAULT 'open'");
        }
    }
};
