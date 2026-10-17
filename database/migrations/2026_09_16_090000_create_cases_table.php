<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cases', function (Blueprint $table): void {
            $table->id();
            $table->string('case_number', 32)->unique();
            $table->foreignId('business_project_id')->constrained('business_projects')->cascadeOnDelete();
            $table->foreignId('company_id')->nullable()->constrained('companies')->nullOnDelete();
            $table->foreignId('owner_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('title', 255);
            $table->string('source_channel', 64);
            $table->string('source_connection_key', 191)->nullable();
            $table->string('brand_key', 64)->nullable();
            $table->string('source_site_key', 64)->nullable();
            $table->string('case_status', 32)->default('open');
            $table->string('case_stage', 32)->default('new');
            $table->string('handling_state', 32)->default('waiting');
            $table->string('identity_resolution_state', 32)->default('unresolved');
            $table->timestamp('first_inbound_at');
            $table->timestamp('last_inbound_at');
            $table->timestamp('first_action_at')->nullable();
            $table->timestamp('last_action_at')->nullable();
            $table->timestamp('attention_since_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->string('loss_reason_code', 64)->nullable();
            $table->json('attribution')->nullable();
            $table->timestamps();
            $table->index(['business_project_id', 'case_status', 'case_stage'], 'cases_project_status_stage_idx');
            $table->index(['business_project_id', 'handling_state', 'attention_since_at'], 'cases_project_handling_attention_idx');
            $table->index(['owner_id', 'case_status']);
            $table->index(['source_channel', 'last_inbound_at']);
            $table->index(['company_id', 'last_inbound_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cases');
    }
};