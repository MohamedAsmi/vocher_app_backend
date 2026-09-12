<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('outlets', function (Blueprint $table) {
            $table->text('address')->nullable()->after('manager_name');
        });
        Schema::table('expenses', function (Blueprint $table) {
            // OCR values are retained separately from the manager-confirmed expense.
            $table->string('ocr_supplier')->nullable()->after('receipt_path');
            $table->bigInteger('ocr_total_minor')->nullable()->after('ocr_supplier');
            $table->string('ocr_category_id')->nullable()->after('ocr_total_minor');
            $table->longText('ocr_raw_text')->nullable()->after('ocr_category_id');
        });
    }

    public function down(): void
    {
        Schema::table('expenses', function (Blueprint $table) {
            $table->dropColumn(['ocr_supplier', 'ocr_total_minor', 'ocr_category_id', 'ocr_raw_text']);
        });
        Schema::table('outlets', function (Blueprint $table) {
            $table->dropColumn('address');
        });
    }
};
