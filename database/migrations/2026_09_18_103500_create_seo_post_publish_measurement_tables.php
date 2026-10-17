<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('seo_optimization_publications', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('seo_optimization_work_order_id');
            $table->foreign('seo_optimization_work_order_id', 'seo_opt_pub_work_fk')
                ->references('id')->on('seo_optimization_work_orders')->cascadeOnDelete();
            $table->string('baseline_url', 1000);
            $table->string('published_url', 1000);
            $table->unsignedBigInteger('production_post_id')->nullable();
            $table->date('publish_date');
            $table->date('retest_after');
            $table->unsignedBigInteger('baseline_impressions')->default(0);
            $table->unsignedBigInteger('baseline_clicks')->default(0);
            $table->decimal('baseline_ctr', 12, 8)->default(0);
            $table->decimal('baseline_average_position', 10, 4)->nullable();
            $table->string('status', 32)->default('monitoring');
            $table->timestamps();

            $table->unique('seo_optimization_work_order_id', 'seo_opt_pub_work_uniq');
            $table->index(['status', 'retest_after'], 'seo_opt_pub_status_retest_idx');
        });

        Schema::create('seo_optimization_measurements', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('seo_optimization_publication_id');
            $table->foreign('seo_optimization_publication_id', 'seo_opt_measure_pub_fk')
                ->references('id')->on('seo_optimization_publications')->cascadeOnDelete();
            $table->string('snapshot_label', 32);
            $table->string('url_role', 16);
            $table->string('page_url', 1000);
            $table->date('metric_from')->nullable();
            $table->date('metric_to')->nullable();
            $table->unsignedBigInteger('impressions')->default(0);
            $table->unsignedBigInteger('clicks')->default(0);
            $table->decimal('ctr', 12, 8)->default(0);
            $table->decimal('average_position', 10, 4)->nullable();
            $table->unsignedInteger('distinct_queries')->default(0);
            $table->unsignedInteger('competing_pages')->default(0);
            $table->timestamp('captured_at');
            $table->timestamps();

            $table->unique(
                ['seo_optimization_publication_id', 'snapshot_label', 'url_role'],
                'seo_opt_measure_pub_label_role_uniq'
            );
            $table->index(['snapshot_label', 'url_role'], 'seo_opt_measure_label_role_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('seo_optimization_measurements');
        Schema::dropIfExists('seo_optimization_publications');
    }
};
