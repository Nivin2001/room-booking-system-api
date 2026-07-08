<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
  Schema::create('payments', function (Blueprint $table) {
    $table->id();

    $table->foreignId('booking_id')->constrained()->cascadeOnDelete();

    $table->string('provider')->default('stripe');

    // Stripe identifiers
    $table->string('provider_reference')->nullable(); // checkout session id
    $table->string('payment_intent_id')->nullable();

    // Status
    $table->string('status'); // pending, paid, failed, refunded

    // Money
    $table->decimal('amount', 10, 2);
    $table->string('currency', 10)->default('USD');

    // Timestamps
    $table->timestamp('paid_at')->nullable();
    $table->timestamp('failed_at')->nullable();

    $table->timestamps();

    $table->index('provider_reference');
    $table->index('payment_intent_id');
});
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};
