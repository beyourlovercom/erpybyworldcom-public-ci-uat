<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table): void {
            $table->string('identity_status', 32)->default('unverified')->after('status');
            $table->string('scale_band', 32)->nullable()->after('identity_status');
            $table->timestamp('identity_reviewed_at')->nullable()->after('scale_band');
            $table->foreignId('identity_reviewed_by')->nullable()->after('identity_reviewed_at')->constrained('users')->nullOnDelete();
            $table->index(['identity_status', 'scale_band'], 'companies_identity_scale_idx');
        });

        Schema::create('business_locations', function (Blueprint $table): void {
            $table->id();
            $table->string('location_key', 64)->unique();
            $table->string('name', 191);
            $table->string('location_type', 32)->default('market');
            $table->char('country_code', 2)->nullable();
            $table->string('region', 191)->nullable();
            $table->string('city', 191)->nullable();
            $table->string('status', 32)->default('active');
            $table->timestamps();
            $table->index(['country_code', 'status']);
        });

        Schema::create('business_project_companies', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('business_project_id')->constrained('business_projects')->cascadeOnDelete();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('owner_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('business_location_id')->nullable()->constrained('business_locations')->nullOnDelete();
            $table->string('relationship_status', 32)->default('prospect');
            $table->string('market_route', 191)->nullable();
            $table->string('payment_terms', 191)->nullable();
            $table->unsignedBigInteger('credit_limit_micros')->nullable();
            $table->char('credit_currency', 3)->nullable();
            $table->text('internal_notes')->nullable();
            $table->string('first_source_channel', 64)->nullable();
            $table->string('last_source_channel', 64)->nullable();
            $table->unsignedInteger('inquiry_count')->default(0);
            $table->unsignedBigInteger('lifetime_revenue_micros')->default(0);
            $table->timestamp('first_inquiry_at')->nullable();
            $table->timestamp('last_inquiry_at')->nullable();
            $table->timestamp('last_follow_up_at')->nullable();
            $table->timestamp('last_won_at')->nullable();
            $table->timestamps();
            $table->unique(['business_project_id', 'company_id'], 'bpc_project_company_uniq');
            $table->index(['business_project_id', 'relationship_status'], 'bpc_project_status_idx');
            $table->index(['company_id', 'relationship_status'], 'bpc_company_status_idx');
            $table->index(['business_location_id', 'relationship_status'], 'bpc_location_status_idx');
        });

        Schema::create('company_contacts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();
            $table->string('job_title', 191)->nullable();
            $table->string('department', 191)->nullable();
            $table->boolean('is_primary')->default(false);
            $table->string('status', 32)->default('active');
            $table->timestamp('started_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->timestamps();
            $table->unique(['company_id', 'customer_id'], 'company_contacts_company_customer_uniq');
            $table->index(['company_id', 'status', 'is_primary'], 'company_contacts_status_idx');
        });

        Schema::create('company_identity_evidence', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('business_project_id')->nullable()->constrained('business_projects')->nullOnDelete();
            $table->string('evidence_type', 64);
            $table->string('source_type', 64);
            $table->string('evidence_key', 191);
            $table->json('evidence_value')->nullable();
            $table->string('review_state', 32)->default('observed');
            $table->decimal('confidence', 5, 4)->nullable();
            $table->string('source_url', 1000)->nullable();
            $table->timestamp('observed_at')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['company_id', 'evidence_type', 'source_type', 'evidence_key'], 'company_identity_evidence_uniq');
            $table->index(['company_id', 'review_state'], 'company_identity_review_idx');
            $table->index(['business_project_id', 'evidence_type'], 'company_identity_project_type_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('company_identity_evidence');
        Schema::dropIfExists('company_contacts');
        Schema::dropIfExists('business_project_companies');
        Schema::dropIfExists('business_locations');
        Schema::table('companies', function (Blueprint $table): void {
            $table->dropForeign(['identity_reviewed_by']);
            $table->dropIndex('companies_identity_scale_idx');
            $table->dropColumn(['identity_status', 'scale_band', 'identity_reviewed_at', 'identity_reviewed_by']);
        });
    }
};
