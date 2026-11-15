<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Mirror of `packages/crm/db/migrations/0046_blog_content.sql` for SQLite.
 *
 * Production applies the SQL migration; this mirror exists so the feature test
 * suite can exercise the content module.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'sqlite') {
            return;
        }

        if (Schema::hasTable('artist_settings') && ! Schema::hasColumn('artist_settings', 'landing_page_slug')) {
            Schema::table('artist_settings', function (Blueprint $table) {
                $table->string('landing_page_slug')->nullable()->unique();
            });
        }

        if (! Schema::hasTable('blog_topics')) {
            Schema::create('blog_topics', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->date('publish_date');
                $table->uuid('artist_id')->nullable();
                $table->string('artist_slug')->nullable();
                $table->string('track')->default('local_guide');
                $table->string('target_keyword');
                $table->string('search_intent')->nullable();
                $table->text('angle')->nullable();
                $table->string('status')->default('planned');
                $table->text('notes')->nullable();
                $table->timestamps();

                $table->index(['publish_date', 'status']);
                $table->index(['artist_id', 'publish_date']);
            });
        }

        if (! Schema::hasTable('blog_posts')) {
            Schema::create('blog_posts', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('blog_topic_id')->nullable();
                $table->uuid('artist_id')->nullable();
                $table->string('artist_slug')->nullable();
                $table->string('track')->nullable();
                $table->string('title')->nullable();
                $table->string('slug')->nullable()->unique();
                $table->text('meta_description')->nullable();
                $table->text('excerpt')->nullable();
                $table->longText('body_md')->nullable();
                $table->text('featured_image')->nullable();
                $table->text('featured_image_alt')->nullable();
                $table->json('faq')->nullable();
                $table->json('tags')->nullable();
                $table->json('sources')->nullable();
                $table->string('category')->nullable();
                $table->string('target_keyword')->nullable();
                $table->string('search_intent')->nullable();
                $table->json('facts')->nullable();
                $table->json('ai_review')->nullable();
                $table->integer('ai_score')->nullable();
                $table->text('admin_overview')->nullable();
                $table->string('status')->default('generating');
                $table->text('feedback')->nullable();
                $table->string('model')->nullable();
                $table->string('github_commit_sha')->nullable();
                $table->string('published_url')->nullable();
                $table->unsignedBigInteger('approved_by')->nullable();
                $table->timestamp('approved_at')->nullable();
                $table->timestamp('published_at')->nullable();
                $table->timestamp('verified_at')->nullable();
                $table->timestamps();

                $table->index(['status', 'updated_at']);
                $table->index(['artist_id', 'created_at']);
                $table->index('published_at');
            });
        }

        if (! Schema::hasTable('blog_post_revisions')) {
            Schema::create('blog_post_revisions', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('blog_post_id');
                $table->integer('version')->default(1);
                $table->longText('body_md')->nullable();
                $table->json('ai_review')->nullable();
                $table->text('feedback')->nullable();
                $table->unsignedBigInteger('created_by')->nullable();
                $table->timestamp('created_at')->nullable();

                $table->index(['blog_post_id', 'version']);
            });
        }
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() !== 'sqlite') {
            return;
        }

        Schema::dropIfExists('blog_post_revisions');
        Schema::dropIfExists('blog_posts');
        Schema::dropIfExists('blog_topics');
    }
};
