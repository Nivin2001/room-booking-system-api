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
    $table->string('provider_reference')->nullable(); // checkout session id

    $table->string('payment_intent_id')->nullable();

    $table->string('status');

    $table->decimal('amount', 10, 2);
    $table->string('currency', 10)->default('USD');

    $table->timestamp('paid_at')->nullable();
    $table->timestamp('failed_at')->nullable();

    $table->timestamps();

    $table->index('provider_reference');
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
