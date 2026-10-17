<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('business_locations')) {
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
        }

        $this->repairProjectCompanyColumns();
    }
    private function repairProjectCompanyColumns(): void
    {
        if (! Schema::hasColumn('business_project_companies', 'business_location_id')) {
            Schema::table('business_project_companies', function (Blueprint $table): void {
                $table->foreignId('business_location_id')->nullable()->after('owner_id')
                    ->constrained('business_locations')->nullOnDelete();
                $table->index(['business_location_id', 'relationship_status'], 'bpc_location_status_idx');
            });
        }

        $columns = [
            'market_route' => fn (Blueprint $table) => $table->string('market_route', 191)->nullable(),
            'payment_terms' => fn (Blueprint $table) => $table->string('payment_terms', 191)->nullable(),
            'credit_limit_micros' => fn (Blueprint $table) => $table->unsignedBigInteger('credit_limit_micros')->nullable(),
            'credit_currency' => fn (Blueprint $table) => $table->char('credit_currency', 3)->nullable(),
            'internal_notes' => fn (Blueprint $table) => $table->text('internal_notes')->nullable(),
            'lifetime_revenue_micros' => fn (Blueprint $table) => $table->unsignedBigInteger('lifetime_revenue_micros')->default(0),
            'last_follow_up_at' => fn (Blueprint $table) => $table->timestamp('last_follow_up_at')->nullable(),
            'last_won_at' => fn (Blueprint $table) => $table->timestamp('last_won_at')->nullable(),
        ];

        foreach ($columns as $name => $definition) {
            if (! Schema::hasColumn('business_project_companies', $name)) {
                Schema::table('business_project_companies', fn (Blueprint $table) => $definition($table));
            }
        }
    }

    public function down(): void
    {
        // Intentional no-op: this repair migration only restores schema elements
        // already owned by the preceding canonical Customer OS migration.
    }
};
