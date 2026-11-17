<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vouchers', function (Blueprint $table) {
            // Cash taken out of the counted till and deposited to the bank.
            // Closing till balance = counted cash - bank deposit.
            $table->bigInteger('bank_deposit_minor')->default(0)->after('counted_cash_minor');
        });
        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE journals MODIFY type ENUM('sales', 'expenses', 'deposit') NOT NULL");
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::table('journals')->where('type', 'deposit')->delete();
            DB::statement("ALTER TABLE journals MODIFY type ENUM('sales', 'expenses') NOT NULL");
        }
        Schema::table('vouchers', function (Blueprint $table) {
            $table->dropColumn('bank_deposit_minor');
        });
    }
};
