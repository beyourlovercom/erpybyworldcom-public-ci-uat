<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('business_project_products', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('business_project_id')->constrained('business_projects')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->string('project_product_name', 512)->nullable();
            $table->string('category', 191)->nullable();
            $table->string('status', 32)->default('active');
            $table->string('sales_status', 32)->default('active');
            $table->string('marketing_status', 32)->default('active');
            $table->string('primary_site', 512)->nullable();
            $table->timestamps();
            $table->unique(['business_project_id', 'product_id'], 'business_project_products_project_product_unique');
            $table->index(['business_project_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('business_project_products');
    }
};
