<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('spaces', function (Blueprint $table) {
            if (!Schema::hasColumn('spaces', 'opening_hour')) {
                $table->string('opening_hour', 5)->nullable()->default('08:00');
            }
            if (!Schema::hasColumn('spaces', 'closing_hour')) {
                $table->string('closing_hour', 5)->nullable()->default('23:00');
            }
        });
    }

    public function down(): void
    {
        Schema::table('spaces', function (Blueprint $table) {
            if (Schema::hasColumn('spaces', 'opening_hour')) {
                $table->dropColumn('opening_hour');
            }
            if (Schema::hasColumn('spaces', 'closing_hour')) {
                $table->dropColumn('closing_hour');
            }
        });
    }
};
