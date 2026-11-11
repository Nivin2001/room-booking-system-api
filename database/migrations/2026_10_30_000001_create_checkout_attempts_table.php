<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('checkout_attempts', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('payment_id')->constrained('payments')->restrictOnDelete();
            $table->char('request_key_hash', 64);
            $table->json('request_key_hashes');
            $table->char('request_fingerprint', 64);
            $table->string('stripe_idempotency_key', 255)->unique();
            $table->string('stripe_session_id')->nullable()->unique();
            $table->string('stripe_payment_intent_id')->nullable()->unique();
            $table->string('active_key')->nullable()->unique();
            $table->string('status', 32);
            $table->string('stripe_session_status', 32)->nullable();
            $table->string('stripe_payment_status', 32)->nullable();
            $table->decimal('amount', 10, 2);
            $table->string('currency', 10);
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('last_checked_at')->nullable();
            $table->string('last_error_code', 100)->nullable();
            $table->boolean('is_legacy')->default(false);
            $table->timestamps();

            $table->unique(['payment_id', 'request_key_hash']);
            $table->index(['payment_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('checkout_attempts');
    }
};
