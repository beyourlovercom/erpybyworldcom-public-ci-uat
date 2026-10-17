<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cases', function (Blueprint $table): void {
            $table->string('source_external_id', 191)->nullable()->after('source_connection_key');
            $table->string('source_payload_hash', 64)->nullable()->after('source_external_id');
            $table->string('contact_name', 191)->nullable()->after('title');
            $table->string('contact_email', 255)->nullable()->after('contact_name');
            $table->string('contact_phone', 64)->nullable()->after('contact_email');
            $table->string('company_name_snapshot', 255)->nullable()->after('contact_phone');
            $table->string('country_snapshot', 100)->nullable()->after('company_name_snapshot');
            $table->string('buyer_type', 64)->nullable()->after('country_snapshot');
            $table->text('product_interest')->nullable()->after('buyer_type');
            $table->string('quantity', 100)->nullable()->after('product_interest');
            $table->json('source_payload')->nullable()->after('attribution');
            $table->index('contact_email', 'cases_contact_email_idx');
            $table->index('contact_phone', 'cases_contact_phone_idx');
            $table->unique(
                ['business_project_id', 'source_channel', 'source_connection_key', 'source_external_id'],
                'cases_source_record_uniq'
            );
        });
    }

    public function down(): void
    {
        Schema::table('cases', function (Blueprint $table): void {
            $table->dropUnique('cases_source_record_uniq');
            $table->dropIndex('cases_contact_email_idx');
            $table->dropIndex('cases_contact_phone_idx');
            $table->dropColumn([
                'source_external_id', 'source_payload_hash', 'contact_name', 'contact_email',
                'contact_phone', 'company_name_snapshot', 'country_snapshot', 'buyer_type',
                'product_interest', 'quantity', 'source_payload',
            ]);
        });
    }
};
