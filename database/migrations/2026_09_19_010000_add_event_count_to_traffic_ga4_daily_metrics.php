<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('traffic_ga4_daily_metrics', function (Blueprint $table): void {
            $table->unsignedBigInteger('event_count')->default(0)->after('key_events');
        });
    }

    public function down(): void
    {
        Schema::table('traffic_ga4_daily_metrics', function (Blueprint $table): void {
            $table->dropColumn('event_count');
        });
    }
};
