<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_attempts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('attempt_no');
            $table->string('provider', 30)->default('midtrans');
            $table->string('provider_order_id', 80)->unique();
            $table->string('provider_transaction_id', 100)->nullable()->unique();
            $table->string('status', 32)->default('creating')->index();
            $table->unsignedBigInteger('amount');
            $table->char('currency', 3)->default('IDR');
            $table->text('snap_token')->nullable();
            $table->text('redirect_url')->nullable();
            $table->json('provider_metadata')->nullable();
            $table->timestamp('requested_at');
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('last_reconciled_at')->nullable();
            $table->unsignedInteger('reconciliation_count')->default(0);
            $table->timestamps();

            $table->unique(['order_id', 'attempt_no']);
            $table->index(['order_id', 'status']);
            $table->index(['status', 'last_reconciled_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_attempts');
    }
};
