<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('content_articles', function (Blueprint $table): void {
            $table->id();
            $table->char('source_identity_hash', 64)->unique();
            $table->string('source_system', 64);
            $table->string('source_ref', 191);
            $table->string('project_key', 64);
            $table->string('brand_key', 64)->nullable();
            $table->string('target_site', 191);
            $table->string('language', 16)->default('en');
            $table->string('content_type', 32);
            $table->string('primary_keyword', 500)->nullable();
            $table->json('supporting_keywords')->nullable();
            $table->string('search_intent', 64)->nullable();
            $table->string('topic_cluster', 191)->nullable();
            $table->string('title_direction', 500);
            $table->string('canonical_path', 1000)->nullable();
            $table->text('content_objective')->nullable();
            $table->text('target_audience')->nullable();
            $table->json('evidence_summary')->nullable();
            $table->json('internal_link_targets')->nullable();
            $table->json('reference_sources')->nullable();
            $table->json('prohibited_claims')->nullable();
            $table->json('recommended_structure')->nullable();
            $table->unsignedInteger('recommended_word_count')->nullable();
            $table->string('authoring_mode', 16)->nullable();
            $table->string('workflow_status', 32)->default('article_base_ready');
            $table->unsignedBigInteger('writer_id')->nullable();
            $table->timestamp('claimed_at')->nullable();
            $table->timestamp('claim_expires_at')->nullable();
            $table->string('blocked_reason', 191)->nullable();
            $table->timestamps();

            $table->index(['project_key', 'workflow_status'], 'content_article_project_status_idx');
            $table->index(['writer_id', 'workflow_status'], 'content_article_writer_status_idx');
        });

        Schema::create('content_document_sources', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('article_id')->constrained('content_articles')->cascadeOnDelete();
            $table->string('provider', 32);
            $table->string('document_id', 191);
            $table->string('document_url', 1000);
            $table->timestamp('modified_time')->nullable();
            $table->string('revision_id', 191)->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('imported_at')->nullable();
            $table->char('snapshot_hash', 64)->nullable();
            $table->boolean('changed_after_submission')->default(false);
            $table->timestamps();

            $table->unique(['provider', 'document_id'], 'content_doc_provider_id_uniq');
            $table->index(['article_id', 'provider'], 'content_doc_article_provider_idx');
        });

        Schema::create('content_snapshots', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('article_id')->constrained('content_articles')->cascadeOnDelete();
            $table->string('type', 32);
            $table->unsignedInteger('version');
            $table->json('normalized_json');
            $table->char('content_hash', 64);
            $table->string('created_by_type', 32);
            $table->string('created_by_id', 191)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['article_id', 'type', 'version'], 'content_snapshot_article_type_version_uniq');
            $table->index(['article_id', 'created_at'], 'content_snapshot_article_created_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('content_snapshots');
        Schema::dropIfExists('content_document_sources');
        Schema::dropIfExists('content_articles');
    }
};
