<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Mirror of `packages/crm/db/migrations/0039_notion_finance_import.sql` for the
 * SQLite test schema. The check-constraint changes are Postgres-only; SQLite
 * does not enforce them in this schema.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'sqlite') {
            return;
        }

        if (! Schema::hasColumn('billing_expenses', 'notion_page_id')) {
            Schema::table('billing_expenses', function (Blueprint $table) {
                $table->string('notion_page_id')->nullable()->unique();
            });
        }
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() !== 'sqlite') {
            return;
        }

        if (Schema::hasColumn('billing_expenses', 'notion_page_id')) {
            Schema::table('billing_expenses', function (Blueprint $table) {
                $table->dropColumn('notion_page_id');
            });
        }
    }
};
