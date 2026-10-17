<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('content_publications', function (Blueprint $table): void {
            $table->string('draft_idempotency_key', 191)->nullable()->unique()->after('verification_resolved_by');
            $table->string('draft_provider_request_id', 191)->nullable()->after('provider');
            $table->string('provider_status', 32)->nullable()->after('external_id');
            $table->string('draft_url', 1000)->nullable()->after('provider_status');
            $table->timestamp('draft_synced_at')->nullable()->after('draft_url');
        });
    }

    public function down(): void
    {
        Schema::table('content_publications', function (Blueprint $table): void {
            $table->dropUnique(['draft_idempotency_key']);
            $table->dropColumn([
                'draft_idempotency_key',
                'draft_provider_request_id',
                'provider_status',
                'draft_url',
                'draft_synced_at',
            ]);
        });
    }
};
