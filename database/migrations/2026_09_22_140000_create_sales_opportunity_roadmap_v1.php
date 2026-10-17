<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales_follow_ups', function (Blueprint $table): void {
            $table->string('stage_key', 32)->nullable()->after('opportunity_id');
            $table->text('next_plan')->nullable()->after('summary');
            $table->index(['opportunity_id', 'stage_key', 'happened_at'], 'sales_followups_stage_time_idx');
        });

        Schema::create('sales_opportunity_stage_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('opportunity_id')->constrained('sales_opportunities')->cascadeOnDelete();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('from_stage', 32)->nullable();
            $table->string('to_stage', 32);
            $table->timestamp('entered_at');
            $table->string('source', 32)->default('manual');
            $table->string('reason', 191)->nullable();
            $table->timestamps();
            $table->index(['opportunity_id', 'entered_at'], 'sales_stage_events_opp_time_idx');
            $table->index(['opportunity_id', 'to_stage'], 'sales_stage_events_opp_stage_idx');
        });

        Schema::create('sales_follow_up_attachments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('follow_up_id')->constrained('sales_follow_ups')->cascadeOnDelete();
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('disk', 64);
            $table->string('path', 1024);
            $table->string('original_name', 255);
            $table->string('mime_type', 127)->nullable();
            $table->unsignedBigInteger('size_bytes')->default(0);
            $table->timestamps();
            $table->index(['follow_up_id', 'created_at'], 'sales_followup_attach_time_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sales_follow_up_attachments');
        Schema::dropIfExists('sales_opportunity_stage_events');

        Schema::table('sales_follow_ups', function (Blueprint $table): void {
            $table->dropIndex('sales_followups_stage_time_idx');
            $table->dropColumn(['stage_key', 'next_plan']);
        });
    }
};
