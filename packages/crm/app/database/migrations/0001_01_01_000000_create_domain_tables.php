<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Local-only copy of the v2 domain tables that the Artist-facing CRM reads.
     *
     * On Postgres these tables are owned by packages/crm/db (the v2 migrations)
     * and are never created by Laravel. On SQLite — used only for local
     * development/testing, since the shared Postgres isn't reachable from a
     * dev box — we recreate just enough of the shape for the CRM to run.
     *
     * The Postgres-only behaviour (exclusion constraint, RPCs, partitions) is
     * covered by packages/crm/db/tests/v2_contract.sql instead.
     */
    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'sqlite') {
            return;
        }

        Schema::create('artists', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->string('status')->default('stub');
            $table->string('timezone')->default('America/Sao_Paulo');
            $table->string('wa_session_slug')->unique();
            $table->string('onboarding_token')->nullable()->unique();
            $table->dateTime('onboarded_at')->nullable();
            $table->timestamps();
        });

        Schema::create('artist_settings', function (Blueprint $table) {
            $table->uuid('artist_id')->primary();
            $table->string('display_name')->nullable();
            $table->json('specialties')->nullable();
            $table->json('excluded_styles')->nullable();
            $table->decimal('floor_pct', 5, 2)->nullable();
            $table->string('deposit_type')->default('percent');
            $table->integer('deposit_value')->nullable();
            $table->string('pix_key')->nullable();
            $table->string('instagram_handle')->nullable();
            $table->string('whatsapp_number')->nullable();
            $table->string('telegram_group_id')->nullable();
            $table->json('working_hours')->nullable();
            $table->json('ai_active_hours')->nullable();
            $table->json('pricing_extras')->nullable();
            $table->string('currency')->default('BRL');
            $table->timestamps();

            $table->foreign('artist_id')->references('id')->on('artists')->cascadeOnDelete();
        });

        Schema::create('leads', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('artist_id');
            $table->string('name')->nullable();
            $table->string('phone_e164');
            $table->string('phone_raw')->nullable();
            $table->string('source')->nullable();
            $table->dateTime('first_seen_at')->nullable();
            $table->dateTime('last_message_at')->nullable();
            $table->dateTime('archived_at')->nullable();
            $table->dateTime('agent_blocked_at')->nullable();
            $table->dateTime('anonymized_at')->nullable();
            $table->timestamps();

            $table->unique(['artist_id', 'phone_e164']);
            $table->foreign('artist_id')->references('id')->on('artists')->cascadeOnDelete();
        });

        Schema::create('pipeline_stages', function (Blueprint $table) {
            $table->string('key')->primary();
            $table->string('label_pt');
            $table->integer('sort_order');
            $table->boolean('is_open')->default(true);
            $table->boolean('is_won')->default(false);
            $table->boolean('is_lost')->default(false);
        });

        DB::table('pipeline_stages')->insert([
            ['key' => 'novo', 'label_pt' => 'Novo', 'sort_order' => 10, 'is_open' => true, 'is_won' => false, 'is_lost' => false],
            ['key' => 'qualificando', 'label_pt' => 'Qualificando', 'sort_order' => 20, 'is_open' => true, 'is_won' => false, 'is_lost' => false],
            ['key' => 'orcamento_enviado', 'label_pt' => 'Orçamento enviado', 'sort_order' => 30, 'is_open' => true, 'is_won' => false, 'is_lost' => false],
            ['key' => 'aguardando_deposito', 'label_pt' => 'Aguardando sinal', 'sort_order' => 40, 'is_open' => true, 'is_won' => false, 'is_lost' => false],
            ['key' => 'agendado', 'label_pt' => 'Agendado', 'sort_order' => 50, 'is_open' => true, 'is_won' => true, 'is_lost' => false],
            ['key' => 'aguardando_artista', 'label_pt' => 'Aguardando artista', 'sort_order' => 60, 'is_open' => true, 'is_won' => false, 'is_lost' => false],
            ['key' => 'fechado', 'label_pt' => 'Fechado', 'sort_order' => 70, 'is_open' => false, 'is_won' => true, 'is_lost' => false],
            ['key' => 'perdido', 'label_pt' => 'Perdido', 'sort_order' => 80, 'is_open' => false, 'is_won' => false, 'is_lost' => true],
            ['key' => 'bloqueado', 'label_pt' => 'Bloqueado', 'sort_order' => 90, 'is_open' => true, 'is_won' => false, 'is_lost' => false],
        ]);

        Schema::create('projects', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('artist_id');
            $table->uuid('lead_id');
            $table->string('stage_key')->default('novo');
            $table->string('placement')->nullable();
            $table->string('body_zone')->nullable();
            $table->string('style')->nullable();
            $table->boolean('first_tattoo')->nullable();
            $table->text('meaning')->nullable();
            $table->string('tattoo_type')->nullable();
            $table->json('reference_pics')->nullable();
            $table->string('handoff_reason')->nullable();
            $table->uuid('accepted_quote_id')->nullable();
            $table->dateTime('closed_at')->nullable();
            $table->timestamps();

            $table->index(['artist_id', 'stage_key']);
            $table->foreign('artist_id')->references('id')->on('artists')->cascadeOnDelete();
            $table->foreign('lead_id')->references('id')->on('leads')->cascadeOnDelete();
        });

        Schema::create('price_book', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('artist_id');
            $table->string('placement');
            $table->string('body_zone');
            $table->integer('base_price_cents');
            $table->integer('session_duration_min');
            $table->integer('buffer_min')->default(30);
            $table->dateTime('effective_from');
            $table->dateTime('effective_to')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['artist_id', 'placement', 'body_zone']);
            $table->foreign('artist_id')->references('id')->on('artists')->cascadeOnDelete();
        });

        Schema::create('promotion_types', function (Blueprint $table) {
            $table->string('key')->primary();
            $table->string('name');
            $table->text('description')->nullable();
            $table->json('config_spec');
            $table->boolean('active')->default(true);
            $table->timestamp('created_at')->nullable();
        });

        DB::table('promotion_types')->insert([
            'key' => 'bogo_same_value',
            'name' => 'Leve 2 pelo valor de 2x',
            'description' => 'Compre 1 tatuagem e leve outra de mesmo valor ou menor.',
            'config_spec' => json_encode(['multiplier' => 2.0, 'quote_valid_days' => 7]),
            'active' => true,
        ]);

        Schema::create('promotions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('artist_id');
            $table->string('type_key');
            $table->string('name');
            $table->decimal('multiplier', 4, 2)->default(2.0);
            $table->string('audience')->default('all');
            $table->json('conditions')->nullable();
            $table->integer('quote_valid_days')->default(7);
            $table->string('status')->default('draft');
            $table->dateTime('activated_at')->nullable();
            $table->dateTime('paused_at')->nullable();
            $table->dateTime('ended_at')->nullable();
            $table->timestamps();

            $table->index(['artist_id', 'status']);
            $table->foreign('artist_id')->references('id')->on('artists')->cascadeOnDelete();
            $table->foreign('type_key')->references('key')->on('promotion_types');
        });

        Schema::create('promotion_prices', function (Blueprint $table) {
            $table->uuid('promotion_id');
            $table->string('placement');
            $table->string('body_zone');
            $table->integer('base_price_cents');
            $table->integer('offer_price_cents');

            $table->primary(['promotion_id', 'placement', 'body_zone']);
            $table->foreign('promotion_id')->references('id')->on('promotions')->cascadeOnDelete();
        });

        Schema::create('quotes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('artist_id');
            $table->uuid('project_id');
            $table->string('kind')->default('standard');
            $table->uuid('promotion_id')->nullable();
            $table->integer('base_price_cents');
            $table->integer('offered_price_cents');
            $table->decimal('discount_percent', 5, 2)->nullable();
            $table->integer('second_cap_cents')->nullable();
            $table->dateTime('valid_until')->nullable();
            $table->string('status')->default('open');
            $table->string('created_by')->default('agent');
            $table->timestamp('created_at')->nullable();
            $table->dateTime('accepted_at')->nullable();

            $table->index(['artist_id', 'status']);
            $table->foreign('artist_id')->references('id')->on('artists')->cascadeOnDelete();
            $table->foreign('project_id')->references('id')->on('projects')->cascadeOnDelete();
            $table->foreign('promotion_id')->references('id')->on('promotions');
        });

        Schema::create('payments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('artist_id');
            $table->uuid('project_id');
            $table->string('kind')->default('deposit');
            $table->integer('amount_cents');
            $table->string('currency')->default('BRL');
            $table->string('status')->default('requested');
            $table->string('method')->nullable();
            $table->string('external_ref')->nullable();
            $table->dateTime('requested_at');
            $table->dateTime('confirmed_at')->nullable();
            $table->text('notes')->nullable();

            $table->index(['artist_id', 'project_id']);
            $table->foreign('artist_id')->references('id')->on('artists')->cascadeOnDelete();
            $table->foreign('project_id')->references('id')->on('projects')->cascadeOnDelete();
        });

        Schema::create('calendar_entries', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('artist_id');
            $table->string('kind');
            $table->uuid('project_id')->nullable();
            $table->uuid('lead_id')->nullable();
            $table->dateTime('start_at');
            $table->dateTime('end_at');
            $table->boolean('active')->default(true);
            $table->string('reason')->nullable();
            $table->string('created_by')->default('agent');
            $table->timestamps();

            $table->index(['artist_id', 'start_at']);
            $table->foreign('artist_id')->references('id')->on('artists')->cascadeOnDelete();
            $table->foreign('project_id')->references('id')->on('projects')->cascadeOnDelete();
            $table->foreign('lead_id')->references('id')->on('leads')->cascadeOnDelete();
        });

        Schema::create('events', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->uuid('artist_id');
            $table->uuid('lead_id')->nullable();
            $table->uuid('project_id')->nullable();
            $table->string('actor')->default('agent');
            $table->string('type');
            $table->json('payload')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index('lead_id');
            $table->index('project_id');
            $table->foreign('artist_id')->references('id')->on('artists')->cascadeOnDelete();
        });

        Schema::create('returning_customers', function (Blueprint $table) {
            $table->uuid('artist_id');
            $table->string('phone_e164');
            $table->string('name')->nullable();
            $table->string('source')->default('imported');
            $table->dateTime('confirmed_at')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->primary(['artist_id', 'phone_e164']);
            $table->foreign('artist_id')->references('id')->on('artists')->cascadeOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('returning_customers');
        Schema::dropIfExists('events');
        Schema::dropIfExists('calendar_entries');
        Schema::dropIfExists('payments');
        Schema::dropIfExists('quotes');
        Schema::dropIfExists('promotion_prices');
        Schema::dropIfExists('promotions');
        Schema::dropIfExists('promotion_types');
        Schema::dropIfExists('price_book');
        Schema::dropIfExists('projects');
        Schema::dropIfExists('pipeline_stages');
        Schema::dropIfExists('leads');
        Schema::dropIfExists('artist_settings');
        Schema::dropIfExists('artists');
    }
};
