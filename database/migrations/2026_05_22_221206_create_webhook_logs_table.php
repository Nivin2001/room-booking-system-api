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
        Schema::create('webhook_logs', function (Blueprint $table) {
    $table->id();

    $table->string('provider')->default('stripe');

    $table->string('event_id');
    $table->string('event_type');

    $table->json('payload');

    $table->timestamp('processed_at')->nullable();

    $table->timestamps();

    $table->unique('event_id');
});
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('webhook_logs');
    }
};
