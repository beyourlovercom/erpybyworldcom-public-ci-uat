<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('content_articles', function (Blueprint $table): void {
            $table->json('guideline_json')->nullable()->after('recommended_word_count');
        });

        Schema::create('content_writer_scopes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('project_key', 64);
            $table->string('language', 16);
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->unique(['user_id', 'project_key', 'language'], 'content_writer_scope_unique');
            $table->index(['project_key', 'language', 'active'], 'content_writer_scope_lookup_idx');
        });

        Schema::create('content_article_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('article_id')->constrained('content_articles')->cascadeOnDelete();
            $table->string('event_type', 64);
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->string('from_status', 32)->nullable();
            $table->string('to_status', 32)->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['article_id', 'created_at'], 'content_event_article_created_idx');
            $table->index(['actor_id', 'created_at'], 'content_event_actor_created_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('content_article_events');
        Schema::dropIfExists('content_writer_scopes');

        Schema::table('content_articles', function (Blueprint $table): void {
            $table->dropColumn('guideline_json');
        });
    }
};
