<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('content_publications', function (Blueprint $table): void {
            $table->timestamp('writer_approved_at')->nullable()->after('draft_synced_at');
            $table->unsignedBigInteger('writer_approved_by')->nullable()->after('writer_approved_at');
            $table->timestamp('fact_approved_at')->nullable()->after('writer_approved_by');
            $table->unsignedBigInteger('fact_approved_by')->nullable()->after('fact_approved_at');
            $table->timestamp('seo_approved_at')->nullable()->after('fact_approved_by');
            $table->unsignedBigInteger('seo_approved_by')->nullable()->after('seo_approved_at');
            $table->string('requested_indexing', 16)->default('noindex')->after('seo_approved_by');
        });
    }

    public function down(): void
    {
        Schema::table('content_publications', function (Blueprint $table): void {
            $table->dropColumn([
                'writer_approved_at', 'writer_approved_by',
                'fact_approved_at', 'fact_approved_by',
                'seo_approved_at', 'seo_approved_by',
                'requested_indexing',
            ]);
        });
    }
};
