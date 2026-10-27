<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Local-only copy of the domain tables that the Artist-facing CRM reads.
     *
     * On Postgres these tables are owned by packages/crm/supabase (and created
     * by those migrations, not by Laravel). On SQLite — used only for local
     * development/testing, since the shared Postgres isn't reachable from a
     * dev box — we recreate just enough of the shape for the CRM to run.
     */
    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'sqlite') {
            return;
        }

        Schema::create('artists', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('nome')->nullable();
            $table->string('display_name')->nullable();
            $table->string('status')->default('live');
            $table->string('timezone')->nullable();
            $table->string('instagram_handle')->nullable();
            $table->string('whatsapp_number')->nullable();
            $table->string('wa_session_slug')->nullable();
            $table->json('specialties')->nullable();
            $table->json('nao_faco')->nullable();
            $table->decimal('floor_pct', 5, 2)->nullable();
            $table->string('deposit_type')->default('percent');
            $table->integer('deposit_value')->nullable();
            $table->string('pix_key')->nullable();
            $table->json('working_hours')->nullable();
            $table->json('ai_active_hours')->nullable();
            $table->json('pricing_extras')->nullable();
            $table->string('telegram_group_id')->nullable();
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('leads', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('artist_id');
            $table->string('nome')->nullable();
            $table->string('telefone');
            $table->string('lead_source')->nullable();
            $table->dateTime('conversation_started')->nullable();
            $table->dateTime('last_message_at')->nullable();
            $table->timestamps();

            $table->index('artist_id');
            $table->foreign('artist_id')->references('id')->on('artists')->cascadeOnDelete();
        });

        Schema::create('tattoos', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('lead_id');
            $table->uuid('artist_id');
            $table->string('placement')->nullable();
            $table->string('body_zone')->nullable();
            $table->string('style')->nullable();
            $table->boolean('primeira_tatuagem')->nullable();
            $table->string('significado')->nullable();
            $table->string('tipo_tatuagem')->nullable();
            $table->json('reference_pics')->nullable();
            $table->integer('table_price')->nullable();
            $table->integer('negotiated_price')->nullable();
            $table->decimal('discount_percent', 5, 2)->nullable();
            $table->string('contrapartida')->nullable();
            $table->integer('deposit_amount')->nullable();
            $table->string('deposit_status')->default('nao_solicitado');
            $table->string('pipeline_status')->default('novo');
            $table->string('handoff_reason')->nullable();
            $table->dateTime('booked_date')->nullable();
            $table->integer('session_duration_min')->nullable();
            $table->integer('buffer_min')->nullable();
            $table->timestamps();

            $table->index('lead_id');
            $table->index(['artist_id', 'pipeline_status']);
            $table->foreign('lead_id')->references('id')->on('leads')->cascadeOnDelete();
            $table->foreign('artist_id')->references('id')->on('artists')->cascadeOnDelete();
        });

        Schema::create('calendar', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('artist_id');
            $table->dateTime('start_at');
            $table->dateTime('end_at');
            $table->string('type')->default('available');
            $table->uuid('lead_id')->nullable();
            $table->uuid('tattoo_id')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index('artist_id');
            $table->foreign('artist_id')->references('id')->on('artists')->cascadeOnDelete();
            $table->foreign('lead_id')->references('id')->on('leads')->nullOnDelete();
            $table->foreign('tattoo_id')->references('id')->on('tattoos')->nullOnDelete();
        });

        Schema::create('events', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('lead_id')->nullable();
            $table->uuid('tattoo_id')->nullable();
            $table->uuid('artist_id');
            $table->string('event_type');
            $table->json('payload')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index('lead_id');
            $table->foreign('lead_id')->references('id')->on('leads')->cascadeOnDelete();
            $table->foreign('tattoo_id')->references('id')->on('tattoos')->cascadeOnDelete();
            $table->foreign('artist_id')->references('id')->on('artists')->cascadeOnDelete();
        });

        Schema::create('pricing', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('artist_id');
            $table->string('region')->nullable();
            $table->string('placement');
            $table->string('body_zone');
            $table->integer('table_price');
            $table->integer('session_duration_min');
            $table->integer('buffer_min')->default(30);
            $table->integer('creeps')->default(0);
            $table->boolean('active')->default(true);
            $table->timestamp('created_at')->nullable();

            $table->unique(['artist_id', 'placement', 'body_zone']);
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

        Schema::create('promotions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('artist_id');
            $table->string('type_key');
            $table->string('name');
            $table->decimal('multiplier', 4, 2)->default(2.0);
            $table->string('audience')->default('all');
            $table->json('conditions');
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

        Schema::create('promotion_quotes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('promotion_id');
            $table->uuid('lead_id');
            $table->uuid('artist_id');
            $table->string('placement');
            $table->string('body_zone');
            $table->integer('first_price');
            $table->integer('second_cap_price');
            $table->dateTime('quoted_at');
            $table->dateTime('valid_until');
            $table->string('status')->default('open');
            $table->dateTime('secured_at')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['lead_id', 'status']);
            $table->index(['promotion_id', 'status']);
            $table->foreign('promotion_id')->references('id')->on('promotions')->cascadeOnDelete();
            $table->foreign('lead_id')->references('id')->on('leads')->cascadeOnDelete();
            $table->foreign('artist_id')->references('id')->on('artists')->cascadeOnDelete();
        });

        Schema::create('returning_customers', function (Blueprint $table) {
            $table->uuid('artist_id');
            $table->string('telefone');
            $table->string('nome')->nullable();
            $table->string('source')->default('imported');
            $table->dateTime('confirmed_at')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->primary(['artist_id', 'telefone']);
            $table->foreign('artist_id')->references('id')->on('artists')->cascadeOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('returning_customers');
        Schema::dropIfExists('promotion_quotes');
        Schema::dropIfExists('promotions');
        Schema::dropIfExists('promotion_types');
        Schema::dropIfExists('pricing');
        Schema::dropIfExists('events');
        Schema::dropIfExists('calendar');
        Schema::dropIfExists('tattoos');
        Schema::dropIfExists('leads');
        Schema::dropIfExists('artists');
    }
};
