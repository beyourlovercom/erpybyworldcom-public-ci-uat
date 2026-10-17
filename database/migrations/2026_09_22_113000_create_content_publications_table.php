<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('content_publications', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('article_id')->constrained('content_articles')->cascadeOnDelete();
            $table->foreignId('layout_snapshot_id')->constrained('content_snapshots')->cascadeOnDelete();
            $table->string('target_site', 191);
            $table->string('status', 32)->default('preview_ready');
            $table->char('preview_hash', 64);
            $table->json('preview_json');
            $table->timestamp('verification_resolved_at')->nullable();
            $table->unsignedBigInteger('verification_resolved_by')->nullable();
            $table->string('idempotency_key', 191)->nullable()->unique();
            $table->string('provider', 64)->nullable();
            $table->string('provider_request_id', 191)->nullable();
            $table->string('external_id', 191)->nullable();
            $table->string('published_url', 1000)->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamps();

            $table->unique('layout_snapshot_id', 'content_publication_layout_uniq');
            $table->index(['article_id', 'status'], 'content_publication_article_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('content_publications');
    }
};
