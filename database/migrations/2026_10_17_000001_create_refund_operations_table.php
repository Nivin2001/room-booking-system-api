<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const STRIPE_REFUND_INDEX = 'refund_operations_stripe_refund_id_unique';
    private const ACTIVE_KEY_INDEX = 'refund_operations_active_key_unique';
    private const TRANSACTION_OPERATION_INDEX = 'payment_transactions_refund_operation_id_unique';

    public function up(): void
    {
        $this->assertSupportedDriver();

        Schema::create('refund_operations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payment_id')->constrained('payments')->restrictOnDelete();
            $table->string('source', 32);
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 32)->index();
            $table->unsignedBigInteger('amount_minor');
            $table->string('currency', 10);
            $table->string('idempotency_key', 255)->unique();
            $table->string('stripe_refund_id')->nullable();
            $table->string('active_key', 64)->nullable();
            $table->unsignedInteger('attempt_count')->default(0);
            $table->string('last_error', 1000)->nullable();
            $table->timestamp('requested_at');
            $table->timestamp('last_checked_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });

        Schema::table('payment_transactions', function (Blueprint $table) {
            $table->foreignId('refund_operation_id')
                ->nullable()
                ->constrained('refund_operations')
                ->restrictOnDelete();
        });

        $this->addUniqueNonNullIndex('refund_operations', 'stripe_refund_id', self::STRIPE_REFUND_INDEX);
        $this->addUniqueNonNullIndex('refund_operations', 'active_key', self::ACTIVE_KEY_INDEX);
        $this->addUniqueNonNullIndex('payment_transactions', 'refund_operation_id', self::TRANSACTION_OPERATION_INDEX);
    }

    public function down(): void
    {
        $this->dropUniqueIndex('payment_transactions', self::TRANSACTION_OPERATION_INDEX);
        $this->dropUniqueIndex('refund_operations', self::ACTIVE_KEY_INDEX);
        $this->dropUniqueIndex('refund_operations', self::STRIPE_REFUND_INDEX);

        Schema::table('payment_transactions', function (Blueprint $table) {
            $table->dropForeign(['refund_operation_id']);
            $table->dropColumn('refund_operation_id');
        });

        Schema::dropIfExists('refund_operations');
    }

    private function addUniqueNonNullIndex(string $table, string $column, string $index): void
    {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'mysql') {
            Schema::table($table, function (Blueprint $blueprint) use ($column, $index) {
                $blueprint->unique($column, $index);
            });

            return;
        }

        DB::statement("CREATE UNIQUE INDEX {$index} ON {$table} ({$column}) WHERE {$column} IS NOT NULL");
    }

    private function assertSupportedDriver(): void
    {
        $driver = DB::connection()->getDriverName();

        if (! in_array($driver, ['mysql', 'sqlite', 'pgsql', 'sqlsrv'], true)) {
            throw new RuntimeException("Unsupported database driver for filtered unique index: {$driver}");
        }
    }

    private function dropUniqueIndex(string $table, string $index): void
    {
        Schema::table($table, function (Blueprint $blueprint) use ($index) {
            $blueprint->dropUnique($index);
        });
    }
};
