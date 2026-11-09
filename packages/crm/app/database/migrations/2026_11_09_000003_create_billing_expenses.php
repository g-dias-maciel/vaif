<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Mirror of `packages/crm/db/migrations/0036_billing_expenses.sql` for the
 * SQLite test schema. Production applies the SQL migration directly.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'sqlite') {
            return;
        }

        if (! Schema::hasTable('billing_expenses')) {
            Schema::create('billing_expenses', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->string('description');
                $table->string('vendor')->nullable();
                $table->string('category')->default('other');
                $table->bigInteger('amount_cents');
                $table->date('competence_month');
                $table->date('due_date');
                $table->date('paid_at')->nullable();
                $table->string('status')->default('pending');
                $table->string('payment_method')->nullable();
                $table->string('recurrence')->default('one_off');
                $table->string('recurrence_key')->nullable();
                $table->text('notes')->nullable();
                $table->unsignedBigInteger('created_by')->nullable();
                $table->timestamps();

                $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
                $table->index('competence_month');
                $table->index('due_date');
                $table->index('status');
                $table->index('category');
                $table->index('recurrence_key');
                $table->unique(['recurrence_key', 'competence_month']);
            });
        }
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() !== 'sqlite') {
            return;
        }

        Schema::dropIfExists('billing_expenses');
    }
};
