<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('traffic_ga4_daily_metrics', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('business_project_id')->constrained('business_projects')->cascadeOnDelete();
            $table->date('metric_date');
            $table->string('scope', 32);
            $table->string('dimension_value', 500)->default('');
            $table->unsignedBigInteger('sessions')->default(0);
            $table->unsignedBigInteger('total_users')->default(0);
            $table->unsignedBigInteger('new_users')->default(0);
            $table->unsignedBigInteger('engaged_sessions')->default(0);
            $table->decimal('key_events', 14, 4)->default(0);
            $table->timestamps();

            $table->unique(
                ['business_project_id', 'metric_date', 'scope', 'dimension_value'],
                'traffic_ga4_project_date_scope_dim_uniq'
            );
            $table->index(['business_project_id', 'scope', 'metric_date'], 'traffic_ga4_project_scope_date_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('traffic_ga4_daily_metrics');
    }
};
