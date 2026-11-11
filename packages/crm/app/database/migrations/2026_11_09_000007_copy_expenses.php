<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Mirror of `packages/crm/db/migrations/0040_copy_expenses.sql` for SQLite.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'sqlite') {
            return;
        }

        if (! Schema::hasColumn('billing_expenses', 'copied_from_id')) {
            Schema::table('billing_expenses', function (Blueprint $table) {
                $table->uuid('copied_from_id')->nullable();
                $table->unique(['copied_from_id', 'competence_month']);
                $table->index('copied_from_id');
            });
        }
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() !== 'sqlite') {
            return;
        }

        if (Schema::hasColumn('billing_expenses', 'copied_from_id')) {
            Schema::table('billing_expenses', function (Blueprint $table) {
                $table->dropColumn('copied_from_id');
            });
        }
    }
};
