<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->unique('booking_id');
            $table->unique('provider_reference');
            $table->unique('payment_intent_id');
        });

        Schema::table('payment_transactions', function (Blueprint $table) {
            $table->unique('stripe_event_id');
        });
    }

    public function down(): void
    {
        Schema::table('payment_transactions', function (Blueprint $table) {
            $table->dropUnique(['stripe_event_id']);
        });

        Schema::table('payments', function (Blueprint $table) {
            $table->dropUnique(['booking_id']);
            $table->dropUnique(['provider_reference']);
            $table->dropUnique(['payment_intent_id']);
        });
    }
};
