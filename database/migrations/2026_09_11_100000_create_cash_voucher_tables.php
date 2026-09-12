<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organizations', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('name');
            $table->string('timezone')->default('Asia/Colombo');
            $table->char('currency_code', 3)->default('LKR');
            $table->timestamps();
        });
        Schema::create('organization_user', function (Blueprint $table) {
            $table->id();
            $table->string('organization_id');
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->enum('role', ['admin', 'outletManager', 'bookkeeper']);
            $table->boolean('active')->default(true);
            $table->timestamps();
            $table->unique(['organization_id', 'user_id']);
            $table->foreign('organization_id')->references('id')->on('organizations')->cascadeOnDelete();
        });
        Schema::create('outlets', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('organization_id');
            $table->string('code');
            $table->string('name');
            $table->string('manager_name');
            $table->boolean('active')->default(true);
            $table->timestamps();
            $table->foreign('organization_id')->references('id')->on('organizations')->cascadeOnDelete();
        });
        Schema::create('outlet_user', function (Blueprint $table) {
            $table->id();
            $table->string('outlet_id');
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->boolean('active')->default(true);
            $table->timestamps();
            $table->unique(['outlet_id', 'user_id']);
            $table->foreign('outlet_id')->references('id')->on('outlets')->cascadeOnDelete();
        });
        Schema::create('expense_categories', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('organization_id');
            $table->string('name');
            $table->string('account_code');
            $table->string('account_name');
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('active')->default(true);
            $table->timestamps();
            $table->foreign('organization_id')->references('id')->on('organizations')->cascadeOnDelete();
        });
        Schema::create('opening_floats', function (Blueprint $table) {
            $table->string('outlet_id')->primary();
            $table->bigInteger('amount_minor')->default(0);
            $table->string('source_voucher_id')->nullable();
            $table->char('effective_date_key', 8)->nullable();
            $table->timestamps();
            $table->foreign('outlet_id')->references('id')->on('outlets')->cascadeOnDelete();
        });
        Schema::create('vouchers', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('organization_id');
            $table->string('outlet_id');
            $table->char('date_key', 8);
            $table->enum('status', ['open', 'posted', 'variance'])->default('open');
            $table->bigInteger('opening_float_minor')->default(0);
            $table->bigInteger('cash_sales_minor')->default(0);
            $table->bigInteger('card_sales_minor')->default(0);
            $table->bigInteger('counted_cash_minor')->nullable();
            $table->bigInteger('cash_expenses_minor')->nullable();
            $table->bigInteger('bank_expenses_minor')->nullable();
            $table->bigInteger('total_expenses_minor')->nullable();
            $table->bigInteger('total_sales_minor')->nullable();
            $table->bigInteger('expected_cash_minor')->nullable();
            $table->bigInteger('variance_minor')->nullable();
            $table->string('variance_reason')->nullable();
            $table->text('other_variance_reason')->nullable();
            $table->foreignId('created_by')->constrained('users');
            $table->foreignId('posted_by')->nullable()->constrained('users');
            $table->timestamp('posted_at')->nullable();
            $table->timestamps();
            $table->unique(['outlet_id', 'date_key']);
            $table->foreign('organization_id')->references('id')->on('organizations')->cascadeOnDelete();
            $table->foreign('outlet_id')->references('id')->on('outlets')->cascadeOnDelete();
        });
        Schema::create('expenses', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('voucher_id');
            $table->string('category_id');
            $table->string('description');
            $table->enum('payment_method', ['cash', 'bankTransfer']);
            $table->bigInteger('amount_minor');
            $table->string('receipt_path')->nullable();
            $table->timestamps();
            $table->foreign('voucher_id')->references('id')->on('vouchers')->cascadeOnDelete();
            $table->foreign('category_id')->references('id')->on('expense_categories');
        });
        Schema::create('journals', function (Blueprint $table) {
            $table->id();
            $table->string('voucher_id');
            $table->enum('type', ['sales', 'expenses']);
            $table->unsignedInteger('line_number');
            $table->string('account_code')->nullable();
            $table->string('account_name');
            $table->bigInteger('debit_minor')->default(0);
            $table->bigInteger('credit_minor')->default(0);
            $table->timestamps();
            $table->foreign('voucher_id')->references('id')->on('vouchers')->cascadeOnDelete();
        });
        Schema::create('voucher_operations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('voucher_id');
            $table->foreignId('actor_id')->constrained('users');
            $table->json('result');
            $table->timestamps();
            $table->foreign('voucher_id')->references('id')->on('vouchers')->cascadeOnDelete();
        });
        Schema::create('audit_events', function (Blueprint $table) {
            $table->id();
            $table->string('voucher_id');
            $table->foreignId('actor_id')->nullable()->constrained('users');
            $table->string('action');
            $table->json('payload')->nullable();
            $table->timestamps();
            $table->foreign('voucher_id')->references('id')->on('vouchers')->cascadeOnDelete();
        });
        Schema::create('follow_ups', function (Blueprint $table) {
            $table->string('voucher_id')->primary();
            $table->string('outlet_id');
            $table->foreignId('flagged_by')->constrained('users');
            $table->enum('status', ['open', 'resolved']);
            $table->text('reason')->nullable();
            $table->timestamps();
            $table->foreign('voucher_id')->references('id')->on('vouchers')->cascadeOnDelete();
            $table->foreign('outlet_id')->references('id')->on('outlets')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        foreach (['follow_ups', 'audit_events', 'voucher_operations', 'journals', 'expenses', 'vouchers', 'opening_floats', 'expense_categories', 'outlet_user', 'outlets', 'organization_user', 'organizations'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
