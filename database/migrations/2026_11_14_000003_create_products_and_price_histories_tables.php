<?php

use Illuminate\\Database\\Migrations\\Migration;
use Illuminate\\Database\\Schema\\Blueprint;
use Illuminate\\Support\\Facades\\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('game_id')->constrained()->restrictOnDelete();
            $table->string('sku', 100)->unique();
            $table->string('provider_sku', 120)->nullable()->index();
            $table->string('name', 150);
            $table->string('description', 500)->nullable();
            $table->string('nominal_label', 100)->nullable();
            $table->unsignedBigInteger('base_cost');
            $table->unsignedBigInteger('selling_price');
            $table->char('currency', 3)->default('IDR');
            $table->boolean('is_active')->default(false)->index();
            $table->unsignedInteger('sort_order')->default(0);
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->index(['game_id', 'is_active', 'sort_order']);
        });

        Schema::create('price_histories', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->foreignId('changed_by_admin_id')->nullable()->constrained('admins')->nullOnDelete();
            $table->unsignedBigInteger('old_base_cost')->nullable();
            $table->unsignedBigInteger('new_base_cost');
            $table->unsignedBigInteger('old_selling_price')->nullable();
            $table->unsignedBigInteger('new_selling_price');
            $table->string('reason', 255)->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['product_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('price_histories');
        Schema::dropIfExists('products');
    }
};
