<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('webhook_logs', function (Blueprint $table) {
            if (!Schema::hasColumn('webhook_logs', 'attempts')) {
                $table->integer('attempts')->default(0);
            }
            if (!Schema::hasColumn('webhook_logs', 'last_error')) {
                $table->text('last_error')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('webhook_logs', function (Blueprint $table) {
            if (Schema::hasColumn('webhook_logs', 'attempts')) {
                $table->dropColumn('attempts');
            }
            if (Schema::hasColumn('webhook_logs', 'last_error')) {
                $table->dropColumn('last_error');
            }
        });
    }
};
