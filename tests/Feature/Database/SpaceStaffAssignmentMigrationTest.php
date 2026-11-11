<?php

namespace Tests\Feature\Database;

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\TestCase as LaravelTestCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

class SpaceStaffAssignmentMigrationTest extends LaravelTestCase
{
    public function createApplication()
    {
        $app = require dirname(__DIR__, 3).'/bootstrap/app.php';
        $app->make(Kernel::class)->bootstrap();

        return $app;
    }

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite.driver' => 'sqlite',
            'database.connections.sqlite.database' => ':memory:',
            'database.connections.sqlite.url' => null,
            'database.connections.sqlite.foreign_key_constraints' => true,
        ]);
        DB::purge('sqlite');
        DB::connection('sqlite')->statement('PRAGMA foreign_keys = ON');

        $this->createBaseSchema();
        $this->insertSyntheticRows();
    }

    protected function tearDown(): void
    {
        DB::disconnect('sqlite');
        parent::tearDown();
    }

    public function test_normal_run_backfills_staff_spaces_and_preserves_related_business_data(): void
    {
        $before = $this->businessSnapshot();

        $migration = $this->migration();
        $migration->up();

        $spaces = DB::table('spaces')->orderBy('id')->get(['id', 'created_by', 'staff_id']);
        for ($id = 1; $id <= 4; $id++) {
            $this->assertSame(2, (int) $spaces[$id - 1]->staff_id);
            $this->assertSame(2, (int) $spaces[$id - 1]->created_by);
        }
        $this->assertNull($spaces[4]->staff_id);
        $this->assertSame(1, (int) $spaces[4]->created_by);
        $this->assertSame($before, $this->businessSnapshot());
        $this->assertSpaceForeignKey('created_by', 'restrict');
        $this->assertSpaceForeignKey('staff_id', 'restrict');

        // Repeating up() must preserve the completed assignment state.
        $assignmentSnapshot = $spaces->map(fn ($space) => [$space->id, $space->created_by, $space->staff_id])->all();
        $migration->up();
        $this->assertSame($assignmentSnapshot, DB::table('spaces')->orderBy('id')->get(['id', 'created_by', 'staff_id'])
            ->map(fn ($space) => [$space->id, $space->created_by, $space->staff_id])->all());
        $this->assertSame($before, $this->businessSnapshot());
    }

    public function test_partial_run_resumes_and_does_not_overwrite_existing_staff_assignment(): void
    {
        Schema::connection('sqlite')->table('spaces', function ($table): void {
            $table->foreignId('staff_id')->nullable();
        });
        DB::table('spaces')->where('id', 1)->update(['staff_id' => 4]);
        $before = $this->businessSnapshot();

        $this->migration()->up();

        $spaces = DB::table('spaces')->orderBy('id')->get(['id', 'created_by', 'staff_id']);
        $this->assertSame(4, (int) $spaces[0]->staff_id, 'A value created before retry must remain unchanged.');
        for ($id = 2; $id <= 4; $id++) {
            $this->assertSame(2, (int) $spaces[$id - 1]->staff_id);
        }
        $this->assertNull($spaces[4]->staff_id);
        $this->assertSame($before, $this->businessSnapshot());
        $this->assertSpaceForeignKey('created_by', 'restrict');
        $this->assertSpaceForeignKey('staff_id', 'restrict');
    }

    public function test_existing_foreign_key_to_an_unexpected_table_stops_before_any_schema_change(): void
    {
        Schema::connection('sqlite')->create('departments', function ($table): void {
            $table->id();
            $table->string('name');
        });
        DB::table('departments')->insert(['id' => 1, 'name' => 'Synthetic department']);
        Schema::connection('sqlite')->table('spaces', function ($table): void {
            $table->foreignId('staff_id')->nullable()->constrained('departments')->restrictOnDelete();
        });
        $before = $this->businessSnapshot();
        $beforeForeignKeys = Schema::connection('sqlite')->getForeignKeys('spaces');

        try {
            $this->migration()->up();
            $this->fail('Migration should reject the unexpected staff_id foreign-key target.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('unexpected foreign-key target', $exception->getMessage());
        }

        $this->assertSame($before, $this->businessSnapshot());
        $this->assertSame($beforeForeignKeys, Schema::connection('sqlite')->getForeignKeys('spaces'));
    }

    public function test_restrict_foreign_keys_block_creator_and_assigned_staff_deletion(): void
    {
        $this->migration()->up();

        // Separate the roles of creator and assignee so each FK is tested independently.
        DB::table('spaces')->whereIn('id', [1, 2, 3, 4])->update(['staff_id' => 4]);
        DB::table('spaces')->where('id', 5)->update(['staff_id' => 4]);

        try {
            DB::table('users')->where('id', 2)->delete();
            $this->fail('The creator account must be restricted while its spaces exist.');
        } catch (QueryException) {
            $this->assertDatabaseHas('users', ['id' => 2]);
        }

        try {
            DB::table('users')->where('id', 4)->delete();
            $this->fail('The assigned Staff account must be restricted while spaces remain assigned.');
        } catch (QueryException) {
            $this->assertDatabaseHas('users', ['id' => 4]);
        }
    }

    private function createBaseSchema(): void
    {
        $schema = Schema::connection('sqlite');
        $schema->create('users', function ($table): void {
            $table->id();
            $table->string('name');
            $table->string('role');
        });
        $schema->create('spaces', function ($table): void {
            $table->id();
            $table->string('title');
            $table->string('status');
            $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();
        });
        $schema->create('bookings', function ($table): void {
            $table->id();
            $table->foreignId('space_id')->constrained('spaces')->restrictOnDelete();
            $table->string('status');
            $table->dateTime('start_time');
            $table->dateTime('end_time');
            $table->decimal('total_amount', 10, 2);
        });
        $schema->create('payments', function ($table): void {
            $table->id();
            $table->foreignId('booking_id')->constrained('bookings')->restrictOnDelete();
            $table->decimal('amount', 10, 2);
            $table->char('currency', 3);
            $table->string('status');
        });
    }

    private function insertSyntheticRows(): void
    {
        DB::table('users')->insert([
            ['id' => 1, 'name' => 'Synthetic Admin', 'role' => 'admin'],
            ['id' => 2, 'name' => 'Synthetic Staff Two', 'role' => 'staff'],
            ['id' => 3, 'name' => 'Synthetic Customer', 'role' => 'customer'],
            ['id' => 4, 'name' => 'Synthetic Staff Four', 'role' => 'staff'],
        ]);

        for ($id = 1; $id <= 5; $id++) {
            DB::table('spaces')->insert([
                'id' => $id,
                'title' => "Synthetic space {$id}",
                'status' => 'available',
                'created_by' => $id <= 4 ? 2 : 1,
            ]);
            DB::table('bookings')->insert([
                'id' => $id,
                'space_id' => $id,
                'status' => 'pending_payment',
                'start_time' => "2027-01-0{$id} 10:00:00",
                'end_time' => "2027-01-0{$id} 11:00:00",
                'total_amount' => '45.00',
            ]);
            DB::table('payments')->insert([
                'id' => $id,
                'booking_id' => $id,
                'amount' => '45.00',
                'currency' => 'USD',
                'status' => 'pending',
            ]);
        }
    }

    private function businessSnapshot(): array
    {
        return [
            'spaces' => DB::table('spaces')->orderBy('id')->get(['id', 'title', 'status', 'created_by'])->map(fn ($row) => (array) $row)->all(),
            'bookings' => DB::table('bookings')->orderBy('id')->get()->map(fn ($row) => (array) $row)->all(),
            'payments' => DB::table('payments')->orderBy('id')->get()->map(fn ($row) => (array) $row)->all(),
        ];
    }

    private function assertSpaceForeignKey(string $column, string $deleteRule): void
    {
        $foreignKey = collect(Schema::connection('sqlite')->getForeignKeys('spaces'))
            ->first(fn (array $key) => $key['columns'] === [$column]);

        $this->assertNotNull($foreignKey);
        $this->assertSame('users', $foreignKey['foreign_table']);
        $this->assertSame(['id'], $foreignKey['foreign_columns']);
        $this->assertSame($deleteRule, $foreignKey['on_delete']);
    }

    private function migration(): Migration
    {
        $migration = include base_path('database/migrations/2026_11_01_000001_add_staff_assignment_to_spaces_table.php');

        return $migration;
    }
}
