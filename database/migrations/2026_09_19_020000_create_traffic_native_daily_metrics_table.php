<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('traffic_native_daily_metrics', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('traffic_source_account_id')->constrained('traffic_source_accounts')->cascadeOnDelete();
            $table->date('metric_date');
            $table->string('scope', 32)->default('account');
            $table->string('external_object_id', 191)->default('');
            $table->string('object_name', 255)->nullable();

            $table->unsignedBigInteger('impressions')->nullable();
            $table->unsignedBigInteger('reach')->nullable();
            $table->unsignedBigInteger('engagements')->nullable();
            $table->unsignedBigInteger('link_clicks')->nullable();
            $table->unsignedBigInteger('video_views')->nullable();
            $table->unsignedBigInteger('watch_time_seconds')->nullable();
            $table->unsignedBigInteger('followers')->nullable();
            $table->bigInteger('followers_delta')->nullable();
            $table->unsignedBigInteger('spend_micros')->nullable();
            $table->json('provider_metrics')->nullable();
            $table->timestamps();

            $table->unique(
                ['traffic_source_account_id', 'metric_date', 'scope', 'external_object_id'],
                'traffic_native_account_date_scope_object_uniq'
            );
            $table->index(
                ['traffic_source_account_id', 'scope', 'metric_date'],
                'traffic_native_account_scope_date_idx'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('traffic_native_daily_metrics');
    }
};
