<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sales_opportunities', function (Blueprint $table): void {
            $table->id();
            $table->string('opportunity_number', 40)->unique();
            $table->foreignId('business_project_id')->constrained('business_projects')->cascadeOnDelete();
            $table->foreignId('case_id')->nullable()->constrained('cases')->nullOnDelete();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('contact_id')->nullable()->constrained('customers')->nullOnDelete();
            $table->foreignId('owner_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('title', 255);
            $table->string('status', 24)->default('open');
            $table->string('pipeline_stage', 32)->default('discovery');
            $table->char('currency', 3)->default('USD');
            $table->unsignedBigInteger('expected_value_micros')->nullable();
            $table->unsignedSmallInteger('probability_bps')->default(1000);
            $table->date('expected_close_date')->nullable();
            $table->timestamp('next_follow_up_at')->nullable();
            $table->timestamp('last_follow_up_at')->nullable();
            $table->timestamp('won_at')->nullable();
            $table->timestamp('lost_at')->nullable();
            $table->string('lost_reason_code', 64)->nullable();
            $table->text('lost_reason_note')->nullable();
            $table->json('attribution')->nullable();
            $table->timestamps();
            $table->unique(['business_project_id', 'case_id'], 'sales_opp_project_case_uniq');
            $table->index(['business_project_id', 'status', 'pipeline_stage'], 'sales_opp_project_status_stage_idx');
            $table->index(['owner_id', 'status', 'next_follow_up_at'], 'sales_opp_owner_followup_idx');
            $table->index(['company_id', 'status']);
        });

        Schema::create('sales_follow_ups', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('opportunity_id')->constrained('sales_opportunities')->cascadeOnDelete();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('channel', 32)->default('note');
            $table->string('outcome', 64)->nullable();
            $table->text('summary');
            $table->timestamp('happened_at');
            $table->timestamp('next_follow_up_at')->nullable();
            $table->timestamps();
            $table->index(['opportunity_id', 'happened_at']);
        });

        Schema::create('sales_quotes', function (Blueprint $table): void {
            $table->id();
            $table->string('quote_number', 40)->unique();
            $table->foreignId('opportunity_id')->constrained('sales_opportunities')->cascadeOnDelete();
            $table->unsignedInteger('version')->default(1);
            $table->string('status', 24)->default('draft');
            $table->char('currency', 3)->default('USD');
            $table->unsignedBigInteger('subtotal_micros')->default(0);
            $table->unsignedBigInteger('discount_micros')->default(0);
            $table->unsignedBigInteger('tax_micros')->default(0);
            $table->unsignedBigInteger('total_micros')->default(0);
            $table->date('valid_until')->nullable();
            $table->json('line_items')->nullable();
            $table->text('notes')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('accepted_at')->nullable();
            $table->timestamps();
            $table->unique(['opportunity_id', 'version'], 'sales_quote_opp_version_uniq');
            $table->index(['opportunity_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sales_quotes');
        Schema::dropIfExists('sales_follow_ups');
        Schema::dropIfExists('sales_opportunities');
    }
};
