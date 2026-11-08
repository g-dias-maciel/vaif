<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Mirror of `packages/crm/db/migrations/0034_sales_utm.sql` for the SQLite
 * test schema.
 */
return new class extends Migration
{
    private const COLUMNS = ['utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'utm_term'];

    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'sqlite') {
            return;
        }

        foreach (self::COLUMNS as $column) {
            if (! Schema::hasColumn('prospects', $column)) {
                Schema::table('prospects', function (Blueprint $table) use ($column) {
                    $table->string($column)->nullable()->after('campaign_id');
                });
            }
        }

    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() !== 'sqlite') {
            return;
        }

        foreach (self::COLUMNS as $column) {
            if (Schema::hasColumn('prospects', $column)) {
                Schema::table('prospects', function (Blueprint $table) use ($column) {
                    $table->dropColumn($column);
                });
            }
        }
    }
};
