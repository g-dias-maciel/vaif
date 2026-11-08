<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Mirror of `packages/crm/db/migrations/0035_billing.sql` for the SQLite test
 * schema. Production applies the SQL migration directly.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'sqlite') {
            return;
        }

        $contractColumns = [
            'billing_mode' => fn (Blueprint $table) => $table->string('billing_mode')->default('pix'),
            'payment_method' => fn (Blueprint $table) => $table->string('payment_method')->nullable(),
            'mercado_pago_preapproval_id' => fn (Blueprint $table) => $table->string('mercado_pago_preapproval_id')->nullable(),
            'mercado_pago_customer_id' => fn (Blueprint $table) => $table->string('mercado_pago_customer_id')->nullable(),
            'next_charge_at' => fn (Blueprint $table) => $table->date('next_charge_at')->nullable(),
            'notion_page_id' => fn (Blueprint $table) => $table->string('notion_page_id')->nullable(),
        ];

        foreach ($contractColumns as $column => $definition) {
            if (! Schema::hasColumn('prospect_contracts', $column)) {
                Schema::table('prospect_contracts', function (Blueprint $table) use ($definition) {
                    $definition($table);
                });
            }
        }

        if (! Schema::hasTable('billing_settings')) {
            Schema::create('billing_settings', function (Blueprint $table) {
                $table->smallInteger('id')->primary();
                $table->string('legal_name')->nullable();
                $table->string('tax_id')->nullable();
                $table->text('address')->nullable();
                $table->string('pix_key')->nullable();
                $table->string('invoice_prefix')->default('VAIF');
                $table->smallInteger('default_due_day')->default(10);
                $table->text('payment_instructions')->nullable();
                $table->text('receipt_footer')->nullable();
                $table->timestamps();
            });

            DB::table('billing_settings')->insert([
                'id' => 1,
                'invoice_prefix' => 'VAIF',
                'default_due_day' => 10,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        if (! Schema::hasTable('billing_invoice_counters')) {
            Schema::create('billing_invoice_counters', function (Blueprint $table) {
                $table->smallInteger('year')->primary();
                $table->integer('last_number')->default(0);
            });
        }

        if (! Schema::hasTable('billing_invoices')) {
            Schema::create('billing_invoices', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->string('number')->unique();
                $table->uuid('contract_id')->nullable();
                $table->uuid('prospect_id');
                $table->date('period_start');
                $table->date('period_end');
                $table->date('issue_date');
                $table->date('due_date');
                $table->bigInteger('amount_cents');
                $table->bigInteger('paid_cents')->default(0);
                $table->string('status')->default('draft');
                $table->string('payment_method')->default('pix');
                $table->string('external_ref')->nullable();
                $table->text('pix_copy_paste')->nullable();
                $table->text('pix_qr_code')->nullable();
                $table->text('notes')->nullable();
                $table->timestamps();

                $table->foreign('contract_id')->references('id')->on('prospect_contracts')->nullOnDelete();
                $table->foreign('prospect_id')->references('id')->on('prospects')->restrictOnDelete();
                $table->unique(['contract_id', 'period_start']);
                $table->index(['prospect_id', 'created_at']);
                $table->index('contract_id');
                $table->index('status');
                $table->index('due_date');
                $table->index('period_start');
            });
        }

        if (! Schema::hasTable('billing_invoice_items')) {
            Schema::create('billing_invoice_items', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->uuid('invoice_id');
                $table->string('description');
                $table->decimal('quantity', 10, 2)->default(1);
                $table->bigInteger('unit_amount_cents');
                $table->bigInteger('total_cents');
                $table->integer('sort_order')->default(0);
                $table->timestamp('created_at')->nullable();

                $table->foreign('invoice_id')->references('id')->on('billing_invoices')->cascadeOnDelete();
                $table->index(['invoice_id', 'sort_order']);
            });
        }

        if (! Schema::hasTable('billing_payments')) {
            Schema::create('billing_payments', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('invoice_id')->nullable();
                $table->uuid('contract_id')->nullable();
                $table->uuid('prospect_id');
                $table->bigInteger('amount_cents');
                $table->string('method');
                $table->date('paid_at');
                $table->string('reference')->nullable();
                $table->string('external_ref')->nullable();
                $table->string('source')->default('manual');
                $table->text('notes')->nullable();
                $table->unsignedBigInteger('recorded_by')->nullable();
                $table->timestamps();

                $table->foreign('invoice_id')->references('id')->on('billing_invoices')->nullOnDelete();
                $table->foreign('contract_id')->references('id')->on('prospect_contracts')->nullOnDelete();
                $table->foreign('prospect_id')->references('id')->on('prospects')->cascadeOnDelete();
                $table->foreign('recorded_by')->references('id')->on('users')->nullOnDelete();
                $table->index('invoice_id');
                $table->index('contract_id');
                $table->index(['prospect_id', 'paid_at']);
                $table->index('paid_at');
            });
        }
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() !== 'sqlite') {
            return;
        }

        Schema::dropIfExists('billing_payments');
        Schema::dropIfExists('billing_invoice_items');
        Schema::dropIfExists('billing_invoices');
        Schema::dropIfExists('billing_invoice_counters');
        Schema::dropIfExists('billing_settings');
    }
};
