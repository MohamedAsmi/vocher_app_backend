<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->softDeletes();
        });

        Schema::table('vouchers', function (Blueprint $table) {
            $table->softDeletes();
            $table->index(['organization_id', 'deleted_at']);
        });

        Schema::create('deletion_logs', function (Blueprint $table) {
            $table->id();
            $table->string('organization_id')->nullable();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('entity_type');
            $table->string('entity_id');
            $table->json('snapshot')->nullable();
            $table->timestamp('deleted_at');
            $table->timestamps();
            $table->index(['organization_id', 'deleted_at']);
            $table->foreign('organization_id')->references('id')->on('organizations')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('deletion_logs');
        Schema::table('vouchers', function (Blueprint $table) {
            $table->dropSoftDeletes();
        });
        Schema::table('users', function (Blueprint $table) {
            $table->dropSoftDeletes();
        });
    }
};
