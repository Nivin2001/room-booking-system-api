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
        Schema::create('payment_transactions', function (Blueprint $table) {
    $table->id();

    $table->foreignId('payment_id')->constrained()->cascadeOnDelete();

    $table->string('type'); // payment, refund, partial_refund, dispute

    $table->decimal('amount', 10, 2);

    $table->string('currency', 10)->default('USD');

    $table->string('stripe_event_id')->nullable();

    $table->json('meta')->nullable();

    $table->timestamps();
});
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('payment_transactions');
    }
};
