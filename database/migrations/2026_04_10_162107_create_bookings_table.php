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
     Schema::create('bookings', function (Blueprint $table) {
    $table->id();

    $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
    $table->foreignId('space_id')->constrained()->cascadeOnDelete();

    $table->dateTime('start_time');
    $table->dateTime('end_time');

    $table->decimal('total_amount', 10, 2);
    $table->string('currency', 10)->default('USD');

    $table->string('status');

    $table->dateTime('expires_at')->nullable();

    $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
    $table->timestamp('approved_at')->nullable();

    $table->timestamp('cancelled_at')->nullable();
    $table->timestamp('rejected_at')->nullable();

    $table->timestamps();

    $table->index(['space_id', 'start_time', 'end_time']);
});
    }


    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('bookings');
    }
};
