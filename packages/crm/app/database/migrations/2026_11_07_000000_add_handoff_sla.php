<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Mirror of `packages/crm/db/migrations/0023_handoff_sla.sql` for the SQLite
 * test schema. Production applies the SQL migration directly.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            // Idempotent: production may have applied the SQL migration first.
            if (! Schema::hasColumn('projects', 'handoff_reminded_at')) {
                $table->dateTime('handoff_reminded_at')->nullable();
            }
            if (! Schema::hasColumn('projects', 'handoff_escalated_at')) {
                $table->dateTime('handoff_escalated_at')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->dropColumn(['handoff_reminded_at', 'handoff_escalated_at']);
        });
    }
};
