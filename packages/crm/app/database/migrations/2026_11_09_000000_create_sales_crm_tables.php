<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Mirror of `packages/crm/db/migrations/0033_sales_crm.sql` for the SQLite
 * test schema. Production applies the SQL migration directly.
 *
 * Internal sales CRM: Artist prospects, campaigns, activities, contracts and
 * onboarding tasks. Never the Artist-facing Lead pipeline.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'sqlite') {
            return;
        }

        if (! Schema::hasTable('campaigns')) {
            Schema::create('campaigns', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->string('name');
                $table->string('channel')->default('meta');
                $table->string('status')->default('draft');
                $table->date('starts_on')->nullable();
                $table->date('ends_on')->nullable();
                $table->bigInteger('budget_cents')->nullable();
                $table->bigInteger('spend_cents')->default(0);
                $table->string('utm_campaign')->nullable();
                $table->text('creative_note')->nullable();
                $table->text('notes')->nullable();
                $table->timestamps();

                $table->index('status');
                $table->index('channel');
                $table->index('utm_campaign');
            });
        }

        if (! Schema::hasTable('prospect_stages')) {
            Schema::create('prospect_stages', function (Blueprint $table) {
                $table->string('key')->primary();
                $table->string('label_pt');
                $table->integer('sort_order')->unique();
                $table->boolean('is_open')->default(true);
                $table->boolean('is_won')->default(false);
                $table->boolean('is_lost')->default(false);
            });

            DB::table('prospect_stages')->insert([
                ['key' => 'novo', 'label_pt' => 'Novo', 'sort_order' => 10, 'is_open' => true, 'is_won' => false, 'is_lost' => false],
                ['key' => 'qualificado', 'label_pt' => 'Qualificado', 'sort_order' => 20, 'is_open' => true, 'is_won' => false, 'is_lost' => false],
                ['key' => 'call_agendada', 'label_pt' => 'Call agendada', 'sort_order' => 30, 'is_open' => true, 'is_won' => false, 'is_lost' => false],
                ['key' => 'call_realizada', 'label_pt' => 'Call realizada', 'sort_order' => 40, 'is_open' => true, 'is_won' => false, 'is_lost' => false],
                ['key' => 'proposta', 'label_pt' => 'Proposta', 'sort_order' => 50, 'is_open' => true, 'is_won' => false, 'is_lost' => false],
                ['key' => 'ganho', 'label_pt' => 'Ganho', 'sort_order' => 60, 'is_open' => false, 'is_won' => true, 'is_lost' => false],
                ['key' => 'perdido', 'label_pt' => 'Perdido', 'sort_order' => 70, 'is_open' => false, 'is_won' => false, 'is_lost' => true],
                ['key' => 'nutricao', 'label_pt' => 'Nutrição', 'sort_order' => 80, 'is_open' => true, 'is_won' => false, 'is_lost' => false],
            ]);
        }

        if (! Schema::hasTable('prospects')) {
            Schema::create('prospects', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->string('name');
                $table->string('whatsapp_e164')->unique();
                $table->string('whatsapp_raw')->nullable();
                $table->string('instagram')->nullable();
                $table->string('email')->nullable();
                $table->string('studio')->nullable();
                $table->string('style')->nullable();
                $table->string('city')->nullable();
                $table->string('source')->default('calculadora');
                $table->string('status')->default('backlog');
                $table->string('stage_key')->nullable();

                $table->bigInteger('revenue_monthly_cents')->nullable();
                $table->bigInteger('ticket_cents')->nullable();
                $table->integer('sessions_monthly')->nullable();
                $table->decimal('admin_hours_day', 5, 2)->nullable();
                $table->bigInteger('hidden_loss_cents')->nullable();
                $table->bigInteger('potential_revenue_cents')->nullable();
                $table->string('revenue_band')->default('unknown');
                $table->smallInteger('score')->default(0);

                $table->unsignedBigInteger('owner_id')->nullable();
                $table->uuid('campaign_id')->nullable();
                $table->timestamp('booked_call_at')->nullable();
                $table->timestamp('last_contact_at')->nullable();
                $table->timestamp('next_action_at')->nullable();
                $table->text('next_action_note')->nullable();
                $table->string('lost_reason')->nullable();
                $table->text('notes')->nullable();

                $table->uuid('artist_id')->nullable();
                $table->timestamp('handoff_at')->nullable();

                $table->integer('legacy_id')->nullable();
                $table->string('legacy_notion_id')->nullable();
                $table->string('legacy_status')->nullable();
                $table->json('import_flags')->nullable();
                $table->json('raw_payload')->nullable();

                $table->timestamps();

                $table->foreign('stage_key')->references('key')->on('prospect_stages')->nullOnDelete();
                $table->foreign('campaign_id')->references('id')->on('campaigns')->nullOnDelete();
                $table->foreign('artist_id')->references('id')->on('artists')->nullOnDelete();

                $table->unique('legacy_id');
                $table->index('status');
                $table->index('stage_key');
                $table->index('owner_id');
                $table->index('campaign_id');
                $table->index('revenue_band');
                $table->index('next_action_at');
                $table->index('created_at');
                $table->index('booked_call_at');
            });
        }

        if (! Schema::hasTable('prospect_activities')) {
            Schema::create('prospect_activities', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->uuid('prospect_id');
                $table->unsignedBigInteger('user_id')->nullable();
                $table->string('type');
                $table->string('title')->nullable();
                $table->text('body')->nullable();
                $table->timestamp('due_at')->nullable();
                $table->timestamp('completed_at')->nullable();
                $table->json('metadata')->nullable();
                $table->timestamp('created_at')->nullable();

                $table->foreign('prospect_id')->references('id')->on('prospects')->cascadeOnDelete();

                $table->index(['prospect_id', 'created_at']);
                $table->index('due_at');
                $table->index('type');
            });
        }

        if (! Schema::hasTable('prospect_contracts')) {
            Schema::create('prospect_contracts', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('prospect_id');
                $table->uuid('artist_id')->nullable();
                $table->string('plan');
                $table->bigInteger('monthly_value_cents');
                $table->bigInteger('total_value_cents');
                $table->date('start_date');
                $table->date('end_date')->nullable();
                $table->string('status')->default('draft');
                $table->timestamp('signed_at')->nullable();
                $table->unsignedTinyInteger('billing_day')->nullable();
                $table->date('next_invoice_at')->nullable();
                $table->string('invoice_status')->nullable();
                $table->string('external_ref')->nullable();
                $table->text('notes')->nullable();
                $table->timestamps();

                $table->foreign('prospect_id')->references('id')->on('prospects')->cascadeOnDelete();
                $table->foreign('artist_id')->references('id')->on('artists')->nullOnDelete();

                $table->index('status');
                $table->index('next_invoice_at');
                $table->index('artist_id');
            });
        }

        if (! Schema::hasTable('onboarding_tasks')) {
            Schema::create('onboarding_tasks', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('artist_id');
                $table->string('key');
                $table->string('title');
                $table->timestamp('due_at')->nullable();
                $table->timestamp('completed_at')->nullable();
                $table->unsignedBigInteger('owner_id')->nullable();
                $table->text('notes')->nullable();
                $table->timestamps();

                $table->unique(['artist_id', 'key']);
                $table->foreign('artist_id')->references('id')->on('artists')->cascadeOnDelete();

                $table->index('due_at');
                $table->index('completed_at');
            });
        }
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() !== 'sqlite') {
            return;
        }

        Schema::dropIfExists('onboarding_tasks');
        Schema::dropIfExists('prospect_contracts');
        Schema::dropIfExists('prospect_activities');
        Schema::dropIfExists('prospects');
        Schema::dropIfExists('prospect_stages');
        Schema::dropIfExists('campaigns');
    }
};
