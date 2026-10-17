<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('seo_optimization_work_orders', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('business_project_id')->constrained('business_projects')->cascadeOnDelete();
            $table->foreignId('seo_content_backlog_id')->nullable()->constrained('seo_content_backlog')->nullOnDelete();
            $table->foreignId('primary_seo_keyword_id')->nullable()->constrained('seo_keywords')->nullOnDelete();
            $table->char('target_url_hash', 64);
            $table->string('target_url', 1000);
            $table->json('supporting_keywords')->nullable();
            $table->unsignedBigInteger('gsc_impressions')->default(0);
            $table->unsignedBigInteger('gsc_clicks')->default(0);
            $table->decimal('gsc_ctr', 12, 8)->default(0);
            $table->decimal('gsc_average_position', 10, 4)->nullable();
            $table->decimal('priority_score', 14, 4)->default(0);
            $table->json('recommendations')->nullable();
            $table->string('workflow_status', 32)->default('planned');
            $table->date('baseline_from')->nullable();
            $table->date('baseline_to')->nullable();
            $table->date('retest_after')->nullable();
            $table->timestamps();

            $table->unique(['business_project_id', 'target_url_hash'], 'seo_opt_work_project_url_uniq');
            $table->index(['business_project_id', 'workflow_status'], 'seo_opt_work_project_status_idx');
            $table->index(['business_project_id', 'priority_score'], 'seo_opt_work_project_score_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('seo_optimization_work_orders');
    }
};
