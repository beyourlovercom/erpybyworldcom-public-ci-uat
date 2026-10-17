<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('seo_keywords', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('business_project_id')->constrained('business_projects')->cascadeOnDelete();
            $table->string('keyword', 500);
            $table->string('normalized_keyword', 500);
            $table->char('normalized_hash', 64);
            $table->string('topic_cluster', 191)->nullable();
            $table->string('intent', 32)->nullable();
            $table->string('suggested_target_type', 32)->nullable();
            $table->string('suggested_action', 32)->nullable();
            $table->decimal('opportunity_score', 8, 2)->nullable();
            $table->boolean('is_competitor_term')->default(false);
            $table->string('status', 32)->default('active');
            $table->timestamps();

            $table->unique(['business_project_id', 'normalized_hash'], 'seo_kw_project_hash_uniq');
            $table->index(['business_project_id', 'suggested_action'], 'seo_kw_project_action_idx');
        });

        Schema::create('seo_keyword_evidence', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('seo_keyword_id')->constrained('seo_keywords')->cascadeOnDelete();
            $table->string('source_type', 32);
            $table->string('source_key', 191)->nullable();
            $table->unsignedBigInteger('impressions')->default(0);
            $table->unsignedBigInteger('clicks')->default(0);
            $table->decimal('conversions', 14, 4)->default(0);
            $table->decimal('cost', 16, 4)->default(0);
            $table->char('currency_code', 3)->nullable();
            $table->decimal('average_position', 10, 4)->nullable();
            $table->unsignedBigInteger('search_volume')->nullable();
            $table->decimal('competition', 10, 4)->nullable();
            $table->string('landing_page_url', 1000)->nullable();
            $table->json('dimensions')->nullable();
            $table->json('raw_payload')->nullable();
            $table->timestamps();

            $table->unique(['seo_keyword_id', 'source_type', 'source_key'], 'seo_kwe_keyword_source_key_uniq');
            $table->index('source_type');
        });

        Schema::create('seo_gsc_daily_metrics', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('seo_keyword_id')->constrained('seo_keywords')->cascadeOnDelete();
            $table->foreignId('business_project_site_id')->nullable()->constrained('business_project_sites')->nullOnDelete();
            $table->date('metric_date');
            $table->string('page_url', 1000)->nullable();
            $table->string('country', 8)->nullable();
            $table->string('device', 24)->nullable();
            $table->unsignedBigInteger('impressions')->default(0);
            $table->unsignedBigInteger('clicks')->default(0);
            $table->decimal('ctr', 12, 8)->default(0);
            $table->decimal('average_position', 10, 4)->nullable();
            $table->timestamps();

            $table->index(['metric_date', 'business_project_site_id'], 'seo_gsc_date_site_idx');
            $table->index(['seo_keyword_id', 'metric_date'], 'seo_gsc_keyword_date_idx');
        });

        Schema::create('seo_content_backlog', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('business_project_id')->constrained('business_projects')->cascadeOnDelete();
            $table->foreignId('seo_keyword_id')->nullable()->constrained('seo_keywords')->nullOnDelete();
            $table->string('topic_cluster', 191)->nullable();
            $table->string('content_type', 32);
            $table->string('priority', 16)->default('P2');
            $table->string('status', 32)->default('planned');
            $table->string('title', 500)->nullable();
            $table->string('target_url', 1000)->nullable();
            $table->text('rationale')->nullable();
            $table->timestamps();

            $table->index(['business_project_id', 'status', 'priority'], 'seo_backlog_project_status_priority_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('seo_content_backlog');
        Schema::dropIfExists('seo_gsc_daily_metrics');
        Schema::dropIfExists('seo_keyword_evidence');
        Schema::dropIfExists('seo_keywords');
    }
};
