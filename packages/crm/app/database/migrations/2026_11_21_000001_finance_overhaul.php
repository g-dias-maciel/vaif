<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Mirror of `packages/crm/db/migrations/0047_finance_overhaul.sql` for the
 * SQLite test schema. Production applies the SQL migration directly.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'sqlite') {
            return;
        }

        if (! Schema::hasTable('finance_partners')) {
            Schema::create('finance_partners', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->string('name');
                $table->integer('share_bps')->default(0);
                $table->boolean('active')->default(true);
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('finance_distributions')) {
            Schema::create('finance_distributions', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('partner_id');
                $table->date('competence_month');
                $table->string('kind');
                $table->bigInteger('amount_cents')->default(0);
                $table->date('paid_at')->nullable();
                $table->text('notes')->nullable();
                $table->unsignedBigInteger('created_by')->nullable();
                $table->timestamps();

                $table->foreign('partner_id')->references('id')->on('finance_partners')->restrictOnDelete();
                $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
                $table->unique(['partner_id', 'competence_month', 'kind']);
                $table->index(['competence_month']);
            });
        }

        if (! Schema::hasTable('finance_months')) {
            Schema::create('finance_months', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->date('month')->unique();
                $table->bigInteger('saved_cents')->nullable();
                $table->text('notes')->nullable();
                $table->timestamp('closed_at')->nullable();
                $table->timestamps();
            });
        }

        $expenseColumns = [
            'paid_by_partner_id' => fn (Blueprint $table) => $table->uuid('paid_by_partner_id')->nullable(),
            'reimbursed_at' => fn (Blueprint $table) => $table->date('reimbursed_at')->nullable(),
            'reimbursed_distribution_id' => fn (Blueprint $table) => $table->uuid('reimbursed_distribution_id')->nullable(),
        ];

        foreach ($expenseColumns as $column => $definition) {
            if (! Schema::hasColumn('billing_expenses', $column)) {
                Schema::table('billing_expenses', function (Blueprint $table) use ($definition) {
                    $definition($table);
                });
            }
        }

        $invoiceColumns = [
            'subtotal_cents' => fn (Blueprint $table) => $table->bigInteger('subtotal_cents')->nullable(),
            'discount_cents' => fn (Blueprint $table) => $table->bigInteger('discount_cents')->default(0),
            'approved_at' => fn (Blueprint $table) => $table->timestamp('approved_at')->nullable(),
            'approved_by' => fn (Blueprint $table) => $table->unsignedBigInteger('approved_by')->nullable(),
            'sent_at' => fn (Blueprint $table) => $table->timestamp('sent_at')->nullable(),
            'delivery_status' => fn (Blueprint $table) => $table->string('delivery_status')->default('pending'),
            'delivery_error' => fn (Blueprint $table) => $table->text('delivery_error')->nullable(),
            'reminder_count' => fn (Blueprint $table) => $table->integer('reminder_count')->default(0),
            'last_reminded_at' => fn (Blueprint $table) => $table->timestamp('last_reminded_at')->nullable(),
        ];

        foreach ($invoiceColumns as $column => $definition) {
            if (! Schema::hasColumn('billing_invoices', $column)) {
                Schema::table('billing_invoices', function (Blueprint $table) use ($definition) {
                    $definition($table);
                });
            }
        }

        foreach ([
            'billing_address' => fn (Blueprint $table) => $table->text('billing_address')->nullable(),
            'tax_id' => fn (Blueprint $table) => $table->string('tax_id')->nullable(),
        ] as $column => $definition) {
            if (! Schema::hasColumn('prospects', $column)) {
                Schema::table('prospects', function (Blueprint $table) use ($definition) {
                    $definition($table);
                });
            }
        }

        $settingColumns = [
            'invoice_lead_days' => fn (Blueprint $table) => $table->smallInteger('invoice_lead_days')->default(3),
            'invoice_approval_email' => fn (Blueprint $table) => $table->string('invoice_approval_email')->nullable(),
            'invoice_terms' => fn (Blueprint $table) => $table->text('invoice_terms')->nullable(),
            'invoice_whatsapp_message' => fn (Blueprint $table) => $table->text('invoice_whatsapp_message')->nullable(),
            'dunning_whatsapp_message' => fn (Blueprint $table) => $table->text('dunning_whatsapp_message')->nullable(),
        ];

        foreach ($settingColumns as $column => $definition) {
            if (! Schema::hasColumn('billing_settings', $column)) {
                Schema::table('billing_settings', function (Blueprint $table) use ($definition) {
                    $definition($table);
                });
            }
        }

        DB::table('billing_settings')
            ->where('invoice_prefix', 'VAIF')
            ->update(['invoice_prefix' => 'INV']);

        DB::table('billing_invoices')
            ->whereNull('subtotal_cents')
            ->update(['subtotal_cents' => DB::raw('amount_cents')]);

        DB::table('billing_invoices')
            ->whereIn('status', ['sent', 'paid', 'overdue'])
            ->whereNull('sent_at')
            ->update([
                'delivery_status' => 'sent',
                'sent_at' => DB::raw('coalesce(issue_date, created_at)'),
                'approved_at' => DB::raw('coalesce(issue_date, created_at)'),
            ]);

        // Historical Guardado = 0 for months before the current one.
        $months = collect()
            ->merge(DB::table('billing_invoices')->pluck('period_start'))
            ->merge(DB::table('billing_payments')->pluck('paid_at'))
            ->merge(DB::table('billing_expenses')->pluck('competence_month'))
            ->filter()
            ->map(fn ($date) => substr((string) $date, 0, 7).'-01')
            ->unique();

        $current = now()->startOfMonth()->toDateString();

        foreach ($months as $month) {
            if ($month < $current && ! DB::table('finance_months')->where('month', $month)->exists()) {
                DB::table('finance_months')->insert([
                    'id' => (string) \Illuminate\Support\Str::uuid(),
                    'month' => $month,
                    'saved_cents' => 0,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() !== 'sqlite') {
            return;
        }

        Schema::dropIfExists('finance_months');
        Schema::dropIfExists('finance_distributions');
        Schema::dropIfExists('finance_partners');
    }
};
