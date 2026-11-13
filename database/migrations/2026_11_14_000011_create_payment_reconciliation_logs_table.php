<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_reconciliation_logs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('payment_attempt_id')->constrained()->cascadeOnDelete();
            $table->string('source', 32)->default('midtrans_status_api');
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->string('provider_status', 40)->nullable();
            $table->string('outcome', 40);
            $table->char('response_hash', 64)->nullable();
            $table->json('safe_metadata')->nullable();
            $table->timestamp('checked_at')->useCurrent();
            $table->index(['payment_attempt_id', 'checked_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_reconciliation_logs');
    }
};
