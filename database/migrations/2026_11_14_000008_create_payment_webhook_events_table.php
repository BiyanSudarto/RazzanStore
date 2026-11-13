<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_webhook_events', function (Blueprint $table): void {
            $table->id();
            $table->string('provider', 30)->default('midtrans');
            $table->char('event_fingerprint', 64)->unique();
            $table->foreignId('payment_attempt_id')->nullable()->constrained()->nullOnDelete();
            $table->string('provider_order_id', 80)->index();
            $table->string('provider_transaction_id', 100)->nullable()->index();
            $table->string('event_type', 60)->nullable();
            $table->string('provider_status', 40)->nullable();
            $table->char('payload_hash', 64);
            $table->boolean('signature_valid')->default(false);
            $table->string('processing_status', 32)->default('received')->index();
            $table->json('safe_metadata')->nullable();
            $table->timestamp('received_at')->useCurrent();
            $table->timestamp('processed_at')->nullable();
            $table->text('processing_error')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_webhook_events');
    }
};
