<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Mirror of `packages/crm/db/migrations/0020_handoff_lifecycle.sql` for the
 * SQLite test schema. Production applies the SQL migration directly; this one
 * only keeps the feature tests in sync.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->dateTime('handoff_at')->nullable();
            $table->dateTime('handoff_resolved_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->dropColumn(['handoff_at', 'handoff_resolved_at']);
        });
    }
};
