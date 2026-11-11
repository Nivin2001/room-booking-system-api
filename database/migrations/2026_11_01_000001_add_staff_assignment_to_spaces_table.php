<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $connection = DB::connection();
        $schema = Schema::connection($connection->getName());

        $this->assertBaseStructure($schema);

        $staffColumnExists = $schema->hasColumn('spaces', 'staff_id');
        $columns = $schema->getColumns('users');
        $userIdColumn = $this->findColumn($columns, 'id');
        $creatorColumn = $this->findColumn($schema->getColumns('spaces'), 'created_by');
        $this->assertCompatibleIdTypes($connection->getDriverName(), $userIdColumn, $creatorColumn, 'spaces.created_by');

        if ($staffColumnExists) {
            $staffColumn = $this->findColumn($schema->getColumns('spaces'), 'staff_id');
            $this->assertCompatibleIdTypes($connection->getDriverName(), $userIdColumn, $staffColumn, 'spaces.staff_id');
        } else {
            $this->assertForeignIdTypeSupported($connection->getDriverName(), $userIdColumn);
        }

        // Inspect both relationships before issuing DDL. Unexpected constraints are never dropped.
        $creatorForeignKey = $this->foreignKeyForColumn($schema->getForeignKeys('spaces'), 'created_by');
        $staffForeignKey = $staffColumnExists
            ? $this->foreignKeyForColumn($schema->getForeignKeys('spaces'), 'staff_id')
            : null;

        $this->assertExpectedForeignKey($creatorForeignKey, $connection->getDatabaseName(), $connection->getDriverName(), 'created_by');
        $this->assertExpectedForeignKey($staffForeignKey, $connection->getDatabaseName(), $connection->getDriverName(), 'staff_id');
        $this->assertRestrictiveDeleteRule($creatorForeignKey, 'created_by', allowCascade: true);
        $this->assertRestrictiveDeleteRule($staffForeignKey, 'staff_id', allowCascade: false);

        // MySQL DDL is issued as separate statements and may persist if a later statement fails.
        // Each step is therefore guarded by current schema state so a later run can resume safely.
        if (! $staffColumnExists) {
            $schema->table('spaces', function (Blueprint $table): void {
                $table->foreignId('staff_id')->nullable();
            });
        }

        if ($staffForeignKey === null) {
            $schema->table('spaces', function (Blueprint $table): void {
                $table->foreign('staff_id')
                    ->references('id')
                    ->on('users')
                    ->restrictOnDelete();
            });
        }

        if ($creatorForeignKey === null) {
            $this->addForeignKey($schema, 'created_by', 'restrict');
        } elseif (strtolower($creatorForeignKey['on_delete']) === 'cascade') {
            $this->replaceForeignKey($schema, $creatorForeignKey, 'created_by');
        }

        DB::table('spaces')
            ->whereNull('staff_id')
            ->whereIn('created_by', DB::table('users')->select('id')->where('role', 'staff'))
            ->update(['staff_id' => DB::raw('created_by')]);
    }

    public function down(): void
    {
        $connection = DB::connection();
        $schema = Schema::connection($connection->getName());

        if (! $schema->hasTable('spaces') || ! $schema->hasTable('users') || ! $schema->hasColumn('spaces', 'created_by')) {
            return;
        }

        $hasStaffColumn = $schema->hasColumn('spaces', 'staff_id');
        $foreignKeys = $schema->getForeignKeys('spaces');
        $creatorForeignKey = $this->foreignKeyForColumn($foreignKeys, 'created_by');
        $staffForeignKey = $hasStaffColumn ? $this->foreignKeyForColumn($foreignKeys, 'staff_id') : null;
        $this->assertExpectedForeignKey($creatorForeignKey, $connection->getDatabaseName(), $connection->getDriverName(), 'created_by');
        $this->assertRestrictiveDeleteRule($creatorForeignKey, 'created_by', allowCascade: true);

        if ($hasStaffColumn) {
            $this->assertCompatibleIdTypes(
                $connection->getDriverName(),
                $this->findColumn($schema->getColumns('users'), 'id'),
                $this->findColumn($schema->getColumns('spaces'), 'staff_id'),
                'spaces.staff_id',
            );
            $this->assertExpectedForeignKey($staffForeignKey, $connection->getDatabaseName(), $connection->getDriverName(), 'staff_id');
            $this->assertRestrictiveDeleteRule($staffForeignKey, 'staff_id', allowCascade: false);
        }

        if ($staffForeignKey !== null) {
            $this->dropForeignKey($schema, $staffForeignKey, 'staff_id');
        }

        if ($hasStaffColumn) {
            $schema->table('spaces', fn (Blueprint $table) => $table->dropColumn('staff_id'));
        }

        if ($creatorForeignKey === null) {
            $this->addForeignKey($schema, 'created_by', 'cascade');
        } elseif (strtolower($creatorForeignKey['on_delete']) !== 'cascade') {
            $this->replaceForeignKey($schema, $creatorForeignKey, 'created_by', 'cascade');
        }
    }

    private function assertBaseStructure($schema): void
    {
        foreach (['users', 'spaces'] as $table) {
            if (! $schema->hasTable($table)) {
                throw new \RuntimeException("Cannot add staff assignment: required table [{$table}] is missing.");
            }
        }

        foreach ([['users', 'id'], ['users', 'role'], ['spaces', 'created_by']] as [$table, $column]) {
            if (! $schema->hasColumn($table, $column)) {
                throw new \RuntimeException("Cannot add staff assignment: required column [{$table}.{$column}] is missing.");
            }
        }
    }

    private function findColumn(array $columns, string $name): array
    {
        foreach ($columns as $column) {
            if (strtolower($column['name']) === strtolower($name)) {
                return $column;
            }
        }

        throw new \RuntimeException("Cannot add staff assignment: required column [{$name}] is missing.");
    }

    private function assertCompatibleIdTypes(string $driver, array $userId, array $spaceId, string $spaceColumn): void
    {
        $userType = $this->normalizeType($userId['type'] ?? $userId['type_name'] ?? '');
        $spaceType = $this->normalizeType($spaceId['type'] ?? $spaceId['type_name'] ?? '');

        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            if ($userType !== $spaceType) {
                throw new \RuntimeException("Cannot add staff assignment: [{$spaceColumn}] type does not match [users.id].");
            }

            return;
        }

        if ($driver === 'sqlite' && $this->integerAffinity($userType) && $this->integerAffinity($spaceType)) {
            return;
        }

        if ($userType !== $spaceType) {
            throw new RuntimeException("Cannot add staff assignment: [{$spaceColumn}] type does not match [users.id].");
        }
    }

    private function assertForeignIdTypeSupported(string $driver, array $userId): void
    {
        $userType = $this->normalizeType($userId['type'] ?? $userId['type_name'] ?? '');

        if (in_array($driver, ['mysql', 'mariadb'], true) && $userType !== 'bigint unsigned') {
            throw new \RuntimeException('Cannot add staff assignment: users.id is not compatible with Laravel foreignId columns.');
        }

        if ($driver === 'sqlite' && ! $this->integerAffinity($userType)) {
            throw new \RuntimeException('Cannot add staff assignment: users.id is not an integer column.');
        }
    }

    private function normalizeType(string $type): string
    {
        return strtolower(trim(preg_replace('/\s+/', ' ', $type)));
    }

    private function integerAffinity(string $type): bool
    {
        return str_contains($type, 'int');
    }

    private function foreignKeyForColumn(array $foreignKeys, string $column): ?array
    {
        $matches = array_values(array_filter($foreignKeys, function (array $foreignKey) use ($column): bool {
            $columns = array_map('strtolower', $foreignKey['columns'] ?? []);

            return in_array(strtolower($column), $columns, true);
        }));

        if (count($matches) > 1) {
            throw new \RuntimeException("Cannot add staff assignment: multiple foreign keys use [spaces.{$column}].");
        }

        if ($matches === []) {
            return null;
        }

        if (array_map('strtolower', $matches[0]['columns']) !== [strtolower($column)]) {
            throw new \RuntimeException("Cannot add staff assignment: composite foreign key on [spaces.{$column}] is unexpected.");
        }

        return $matches[0];
    }

    private function assertExpectedForeignKey(?array $foreignKey, string $database, string $driver, string $column): void
    {
        if ($foreignKey === null) {
            return;
        }

        $foreignSchema = strtolower((string) ($foreignKey['foreign_schema'] ?? ''));
        $expectedSchema = strtolower($database);
        $schemaMatches = $foreignSchema === ''
            || $foreignSchema === $expectedSchema
            || ($driver === 'sqlite' && $foreignSchema === 'main');

        if (! $schemaMatches
            || strtolower((string) $foreignKey['foreign_table']) !== 'users'
            || array_map('strtolower', $foreignKey['foreign_columns']) !== ['id']) {
            throw new \RuntimeException("Cannot add staff assignment: [spaces.{$column}] has an unexpected foreign-key target.");
        }
    }

    private function assertRestrictiveDeleteRule(?array $foreignKey, string $column, bool $allowCascade): void
    {
        if ($foreignKey === null) {
            return;
        }

        $rule = strtolower((string) $foreignKey['on_delete']);
        if ($rule === 'restrict' || $rule === 'no action' || ($allowCascade && $rule === 'cascade')) {
            return;
        }

        throw new \RuntimeException("Cannot add staff assignment: [spaces.{$column}] has unexpected ON DELETE rule [{$rule}].");
    }

    private function addForeignKey($schema, string $column, string $deleteRule): void
    {
        $schema->table('spaces', function (Blueprint $table) use ($column, $deleteRule): void {
            $foreign = $table->foreign($column)
                ->references('id')
                ->on('users');

            if ($deleteRule === 'cascade') {
                $foreign->cascadeOnDelete();
            } else {
                $foreign->restrictOnDelete();
            }
        });
    }

    private function replaceForeignKey($schema, array $foreignKey, string $column, string $deleteRule = 'restrict'): void
    {
        $this->dropForeignKey($schema, $foreignKey, $column);
        $this->addForeignKey($schema, $column, $deleteRule);
    }

    private function dropForeignKey($schema, array $foreignKey, string $column): void
    {
        $schema->table('spaces', function (Blueprint $table) use ($foreignKey, $column): void {
            if (filled($foreignKey['name'] ?? null)) {
                $table->dropForeign($foreignKey['name']);
            } else {
                $table->dropForeign([$column]);
            }
        });
    }
};
