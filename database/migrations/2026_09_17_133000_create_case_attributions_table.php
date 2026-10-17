<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('case_attributions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('case_id')->unique()->constrained('cases')->cascadeOnDelete();
            $table->foreignId('business_project_id')->constrained('business_projects')->cascadeOnDelete();
            $table->foreignId('traffic_source_account_id')->nullable()->constrained('traffic_source_accounts')->nullOnDelete();
            $table->foreignId('traffic_campaign_id')->nullable()->constrained('traffic_campaigns')->nullOnDelete();
            $table->foreignId('landing_page_id')->nullable()->constrained('landing_pages')->nullOnDelete();
            $table->string('source_kind', 48)->default('unknown');
            $table->string('attribution_state', 32)->default('unresolved');
            $table->string('campaign_match_state', 32)->default('not_applicable');
            $table->string('landing_page_match_state', 32)->default('not_applicable');
            $table->string('match_method', 191)->nullable();
            $table->string('utm_source', 100)->nullable();
            $table->string('utm_medium', 100)->nullable();
            $table->string('utm_campaign', 191)->nullable();
            $table->string('utm_term', 191)->nullable();
            $table->string('gclid', 255)->nullable();
            $table->string('fbclid', 255)->nullable();
            $table->text('source_url')->nullable();
            $table->string('evidence_hash', 64);
            $table->json('evidence')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();
            $table->index(['business_project_id', 'attribution_state'], 'case_attr_project_state_idx');
            $table->index(['traffic_campaign_id', 'business_project_id'], 'case_attr_campaign_project_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('case_attributions');
    }
};