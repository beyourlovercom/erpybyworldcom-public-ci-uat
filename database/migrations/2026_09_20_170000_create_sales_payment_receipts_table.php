<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sales_payment_receipts', function (Blueprint $table): void {
            $table->id();
            $table->string('receipt_number', 40)->unique();
            $table->foreignId('business_project_id')->constrained('business_projects')->restrictOnDelete();
            $table->foreignId('opportunity_id')->constrained('sales_opportunities')->restrictOnDelete();
            $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedBigInteger('amount_micros');
            $table->char('currency', 3);
            $table->timestamp('received_at');
            $table->string('payment_method', 32);
            $table->string('entry_source', 32)->default('manual');
            $table->string('provider_reference', 191)->nullable();
            $table->string('evidence_reference', 2048)->nullable();
            $table->text('note')->nullable();
            $table->string('status', 24)->default('confirmed');
            $table->timestamp('voided_at')->nullable();
            $table->foreignId('voided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('void_reason')->nullable();
            $table->timestamps();

            $table->index(['business_project_id', 'received_at'], 'sales_receipt_project_received_idx');
            $table->index(['opportunity_id', 'received_at'], 'sales_receipt_opp_received_idx');
            $table->index(['company_id', 'received_at'], 'sales_receipt_company_received_idx');
            $table->index(['status', 'received_at'], 'sales_receipt_status_received_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sales_payment_receipts');
    }
};
