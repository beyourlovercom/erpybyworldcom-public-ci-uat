<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales_opportunities', function (Blueprint $table): void {
            $table->unsignedBigInteger('receivable_amount_micros')->nullable()->after('expected_value_micros');
            $table->char('receivable_currency', 3)->nullable()->after('receivable_amount_micros');
            $table->string('receivable_source', 24)->nullable()->after('receivable_currency');
            $table->foreignId('receivable_quote_id')->nullable()->after('receivable_source')->constrained('sales_quotes')->nullOnDelete();
            $table->timestamp('receivable_confirmed_at')->nullable()->after('receivable_quote_id');
            $table->foreignId('receivable_confirmed_by')->nullable()->after('receivable_confirmed_at')->constrained('users')->nullOnDelete();
            $table->text('receivable_note')->nullable()->after('receivable_confirmed_by');
            $table->index(['business_project_id', 'receivable_currency'], 'sales_opp_project_receivable_currency_idx');
        });
    }

    public function down(): void
    {
        Schema::table('sales_opportunities', function (Blueprint $table): void {
            $table->dropIndex('sales_opp_project_receivable_currency_idx');
            $table->dropConstrainedForeignId('receivable_confirmed_by');
            $table->dropConstrainedForeignId('receivable_quote_id');
            $table->dropColumn([
                'receivable_amount_micros',
                'receivable_currency',
                'receivable_source',
                'receivable_confirmed_at',
                'receivable_note',
            ]);
        });
    }
};
