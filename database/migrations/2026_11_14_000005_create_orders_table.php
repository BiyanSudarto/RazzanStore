<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table): void {
            $table->id();
            $table->string('public_order_code', 40)->unique();
            $table->foreignId('product_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('game_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('product_sku_snapshot', 100);
            $table->string('product_name_snapshot', 150);
            $table->string('game_name_snapshot', 140);
            $table->string('nominal_label_snapshot', 100)->nullable();
            $table->json('player_data');
            $table->string('customer_contact', 80)->nullable();
            $table->unsignedBigInteger('unit_price');
            $table->unsignedInteger('quantity')->default(1);
            $table->unsignedBigInteger('subtotal');
            $table->unsignedBigInteger('discount_amount')->default(0);
            $table->unsignedBigInteger('total_amount');
            $table->char('currency', 3)->default('IDR');
            $table->string('status', 32)->default('pending_payment')->index();
            $table->string('payment_status', 32)->default('pending')->index();
            $table->string('fulfillment_status', 32)->default('not_started');
            $table->char('tracking_token_hash', 64)->unique();
            $table->timestamp('expires_at')->index();
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'created_at']);
            $table->index(['payment_status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('orders');
    }
};
