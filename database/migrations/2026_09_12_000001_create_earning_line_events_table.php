<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The event store: the append-only source of truth for every earning line.
 *
 * UNIQUE (aggregate_id, version) is what makes optimistic concurrency work — a stale writer
 * collides with it. The triggers make rule R2 ("never edited or silently deleted") hold at
 * the storage layer too, not only in the code that happens to use the table.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('earning_line_events', function (Blueprint $table): void {
            $table->id();
            $table->uuid('aggregate_id');
            $table->unsignedInteger('version');
            $table->string('event_type', 64);
            $table->json('payload');
            $table->dateTime('recorded_at', 6);

            $table->unique(['aggregate_id', 'version']);
        });

        foreach ($this->appendOnlyTriggers(Schema::getConnection()->getDriverName()) as $statement) {
            DB::unprepared($statement);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('earning_line_events'); // the triggers go with the table
    }

    /** @return list<literal-string> */
    private function appendOnlyTriggers(string $driver): array
    {
        return match ($driver) {
            'sqlite' => [
                "CREATE TRIGGER earning_line_events_no_update BEFORE UPDATE ON earning_line_events
                 BEGIN SELECT RAISE(ABORT, 'earning_line_events is append-only'); END",
                "CREATE TRIGGER earning_line_events_no_delete BEFORE DELETE ON earning_line_events
                 BEGIN SELECT RAISE(ABORT, 'earning_line_events is append-only'); END",
            ],
            'mysql', 'mariadb' => [
                "CREATE TRIGGER earning_line_events_no_update BEFORE UPDATE ON earning_line_events FOR EACH ROW
                 SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'earning_line_events is append-only'",
                "CREATE TRIGGER earning_line_events_no_delete BEFORE DELETE ON earning_line_events FOR EACH ROW
                 SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'earning_line_events is append-only'",
            ],
            default => throw new RuntimeException("No append-only triggers are defined for the $driver driver."),
        };
    }
};
