<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Mirror of `packages/crm/db/migrations/0034_copilot_reports.sql` for SQLite.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('copilot_reports')) {
            return;
        }

        Schema::create('copilot_reports', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('kind');
            $table->string('period_key');
            $table->uuid('artist_id')->nullable();
            $table->timestamp('period_start')->nullable();
            $table->timestamp('period_end')->nullable();
            $table->string('status')->default('queued');
            $table->json('facts')->nullable();
            $table->text('narrative')->nullable();
            $table->json('findings')->nullable();
            $table->string('model')->nullable();
            $table->timestamp('generated_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamps();

            $table->unique(['kind', 'period_key']);
            $table->index(['kind', 'generated_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('copilot_reports');
    }
};
