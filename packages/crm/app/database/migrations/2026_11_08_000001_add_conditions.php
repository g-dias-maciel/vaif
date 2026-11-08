<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Mirror of `packages/crm/db/migrations/0028_conditions.sql` for the SQLite
 * test schema. Production applies the SQL migration directly.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('artist_settings', function (Blueprint $table) {
            if (! Schema::hasColumn('artist_settings', 'reschedule_policy_hours')) {
                $table->integer('reschedule_policy_hours')->nullable();
            }
            if (! Schema::hasColumn('artist_settings', 'cancellation_policy_hours')) {
                $table->integer('cancellation_policy_hours')->nullable();
            }
            if (! Schema::hasColumn('artist_settings', 'payment_methods')) {
                $table->json('payment_methods')->nullable();
            }
            if (! Schema::hasColumn('artist_settings', 'allow_hold_slot')) {
                $table->boolean('allow_hold_slot')->default(false);
            }
            if (! Schema::hasColumn('artist_settings', 'guarantee_price')) {
                $table->boolean('guarantee_price')->default(false);
            }
            if (! Schema::hasColumn('artist_settings', 'portfolio_url')) {
                $table->string('portfolio_url')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('artist_settings', function (Blueprint $table) {
            $table->dropColumn([
                'reschedule_policy_hours',
                'cancellation_policy_hours',
                'payment_methods',
                'allow_hold_slot',
                'guarantee_price',
                'portfolio_url',
            ]);
        });
    }
};
