# Redline PoC — history of manual adjustments to an earning line

[![CI](https://github.com/wtsergo/redline-poc/actions/workflows/ci.yml/badge.svg)](https://github.com/wtsergo/redline-poc/actions/workflows/ci.yml)

Proof of concept for the Alcor code assignment. A payroll **earning line** is calculated by the
system; a payroll specialist can correct it with signed, commented, append-only **adjustments**.
Once a line has been adjusted by hand it is frozen: later system recalculations are recorded but
change nothing. The current value and the full audit history are available at any time.

The domain model is plain PHP 8.4 with no framework dependency (an architecture test enforces
it). Laravel 13 provides what it is good at around the model: dependency injection, migrations,
a query builder for the event store, Artisan for the command line and the test harness.
"Redlining" is marking corrections on a document while keeping every mark visible — rule R2.

## What it does

`php artisan payroll:demo` replays the eight steps of the assignment through the real command
bus, event store and projection, and prints the two tables the assignment expects:

```
+------+-----------------------------------------------------------------------------------------+----------+----------------------------------------------------------+-------------------------------+
| Step | Event                                                                                   | Amount   | Comment                                                  | Current value after this step |
+------+-----------------------------------------------------------------------------------------+----------+----------------------------------------------------------+-------------------------------+
| 1    | System calculates the line                                                              | —        | —                                                        | $1,000.00                     |
| 2    | Source data changes, system recalculates (no manual correction yet, so this is allowed) | —        | —                                                        | $1,050.00                     |
| 3    | Specialist adds a manual correction                                                     | −$45.55  | "Employee declined dental benefit; reversing deduction"  | $1,004.45                     |
| 4    | Source data changes again, system attempts to recalculate                               | —        | (must be ignored — line already has a manual correction) | $1,004.45                     |
| 5    | Specialist adds a second correction                                                     | +$100.10 | "Late correction: missed approved overtime bonus"        | $1,104.55                     |
| 6    | Specialist adds a third correction                                                      | −$0.10   | "Minor rounding adjustment"                              | $1,104.45                     |
| 7    | Specialist adds a fourth correction                                                     | −$0.20   | "Second minor rounding adjustment"                       | $1,104.25                     |
| 8    | Specialist adds a compensating correction, realizing step 7 was a mistake               | +$0.20   | "Correcting mistake in adjustment #4"                    | $1,104.45                     |
+------+-----------------------------------------------------------------------------------------+----------+----------------------------------------------------------+-------------------------------+

Expected final audit history for this line:
+---------------------------------+-----------+
| Entry                           | Value     |
+---------------------------------+-----------+
| System value (frozen at step 3) | $1,050.00 |
| Adjustment 1                    | −$45.55   |
| Adjustment 2                    | +$100.10  |
| Adjustment 3                    | −$0.10    |
| Adjustment 4                    | −$0.20    |
| Adjustment 5                    | +$0.20    |
| Current (new) value             | $1,104.45 |
+---------------------------------+-----------+

Stored as earning line 48b91a0f-8329-4ae6-bcb0-683ec5f3b956. Inspect it again with: php artisan payroll:history 48b91a0f-…
```

The same eight steps are also a unit test on the aggregate alone, a unit test on the handlers over
an in-memory store, an integration test over the database, and this feature test on the command.

## Quick start

Requires PHP 8.4 and Composer. SQLite is the default and needs no setup.

```bash
git clone https://github.com/wtsergo/redline-poc.git && cd redline-poc
composer setup                # composer install, .env, SQLite file, migrations
php artisan payroll:demo      # the assignment scenario, both tables
composer check                # Pint, Larastan (level max), PHPUnit + 100 % coverage gate, Infection
```

Play the roles yourself:

```bash
php artisan payroll:calculate 1000.00                       # the system calculates a new line, prints its id
php artisan payroll:calculate 1050.00 --line=<id>           # source data changed: allowed while unadjusted
php artisan payroll:adjust <id> -45.55 "Employee declined dental benefit; reversing deduction"
php artisan payroll:calculate 1200.00 --line=<id>           # now ignored — and recorded as ignored
php artisan payroll:adjust <id> 0.00 "nothing"              # refused: exit 1, domain message
php artisan payroll:adjust <id> -0.10                       # refused: the comment is mandatory
php artisan payroll:history <id>                            # audit table, comments, ignored recalculations
php artisan payroll:lines                                   # every line, from the read model
```

MySQL works with the usual `DB_CONNECTION=mysql` and `DB_*` variables; CI runs the integration and
feature suites on MySQL 8.4 as well as SQLite, because the append-only triggers are engine-specific.

## Glossary

The assignment says *correction* in the text and *adjustment* in its tables. The code says
**adjustment** everywhere; the two words mean the same thing.

## How it works

```
 write side                                                                read side
 ──────────                                                                ─────────
 payroll:calculate ─┐
 payroll:adjust    ─┼─▶ CommandBus ─▶ CalculateLine / RecalculateLine / AdjustLine handler
 payroll:demo      ─┘   (one DB tx)              │
                                                 ▼
                                  EarningLine (aggregate root)
                                  record ─▶ apply ─▶ releaseEvents()
                                                 │
                                                 ▼
                     EventStore.append(id, expectedVersion, events) ──▶ earning_line_events
                                                 │                      (append-only, UNIQUE id+version)
                                                 ▼
                                  Projector.project(event, version) ──▶ earning_lines ──▶ payroll:lines

 payroll:history ◀── GetLineHistoryHandler ◀── LineHistory::fromEvents(stream) ◀── earning_line_events
```

- **`EarningLine`** (`app/Payroll/Domain`) is the only aggregate. `calculate()` creates it,
  `recalculate()` and `adjust()` change it, and every change is expressed as one of four events:
  `LineCalculated`, `LineRecalculated`, `LineAdjusted`, `RecalculationIgnored`. State is rebuilt by
  replaying events (`reconstitute()`), which is also what the repository does on every load.
- **Commands and handlers** (`app/Payroll/Application`) are one use case each. Handlers take the
  repository and a PSR-20 clock through the constructor; they know nothing about Laravel.
- **The event store** (`app/Payroll/Infrastructure/Persistence`) is a query-builder adapter over
  `earning_line_events`. Appending is a single multi-row `INSERT` at versions
  `expectedVersion+1 … n`; a stale writer collides with `UNIQUE (aggregate_id, version)` and gets
  a `ConcurrencyConflict`. Two database triggers refuse any `UPDATE` or `DELETE` on the table.
- **The projection** (`app/Payroll/Infrastructure/Projection`) keeps one summary row per line in
  `earning_lines`. It is updated synchronously in the same transaction as the append (the command
  bus is wrapped in `TransactionalCommandBus`), it is idempotent per event, and it can be dropped
  and rebuilt from the stream.
- **Queries** never touch the aggregate: `GetLineHistory` folds the event stream into a
  `LineHistory` read model; `payroll:lines` reads the projection table.
- **Console commands** (`app/Console/Commands`) parse strings into value objects, dispatch, and
  turn any `PayrollException` into a one-line error and exit code 1.

## Design decisions

**D1 — Laravel app, framework-free core.** The brief says no framework is needed; the job is
Laravel. Both are true here: `app/Payroll/Domain` and `app/Payroll/Application` contain no
`Illuminate` import (checked by `tests/Arch/ArchitectureTest`), and their tests extend plain
`PHPUnit\Framework\TestCase` without booting the app. Laravel is the adapter layer: container
bindings in `PayrollServiceProvider`, migrations, the query builder, one Eloquent read model,
Artisan, and the testing helpers. The skeleton is stripped to console-only — no HTTP kernel,
routes, views, assets or user model.

**D2 — Lightweight, hand-rolled event sourcing.** The assignment's "expected audit history" *is*
an event stream, and the target codebase is DDD + CQRS + ES, so the domain is modelled that way.
The mechanics — record → apply → append with expected version → reconstitute — are about 150
lines and are exactly what is worth showing, so there is no event-sourcing package to hide them.
Deliberately not built: snapshots, asynchronous projections, event upcasting, sagas (see *What I
would do next*).

*The alternative I rejected:* plain object-oriented design with an `adjustments` table and a
`frozen_system_value` column on the line. It satisfies every rule with less code. I rejected it
because it throws away the natural shape of the problem (the audit history would have to be
reconstructed from two tables and a flag), because "no correction may ever be edited or silently
deleted" is a property of an append-only log rather than a discipline to apply to an `UPDATE`,
and because it would show nothing about the stack the role is for.

**D3 — Money as integer minor units.** `−45.55 + 100.10 − 0.10 − 0.20 + 0.20` is a floating-point
trap on purpose. `Money` holds an `int` of cents plus a `Currency`, parses decimal strings with at
most two decimals (more is rejected, never rounded), does arithmetic only within one currency, and
formats `$1,004.45` / `−$45.55` exactly as the assignment prints them (typographic minus U+2212;
both `-` and `−` are accepted on input). No `brick/money`: the value object is eighty lines and a
dependency-free domain is the point.

**D4 — CQRS-lite.** Commands are readonly DTOs with one handler each. A thirty-line `CommandBus`
interface with a container-backed implementation and a transactional decorator demonstrates
dependency injection without Laravel's job bus. Queries read the stream or the projection.

**D5 — PHPUnit 12, not Pest.** The brief says PHPUnit. Attributes (`#[Test]`, `#[DataProvider]`),
`final` test classes, four suites: `Unit` (pure PHP), `Integration` (SQLite in memory locally,
MySQL in CI), `Feature` (Artisan), `Arch` (reflection rules).

**D6 — One word for the concept.** *Adjustment*, everywhere in the code; *correction* only in
prose quoting the assignment.

## Assumptions

| # | Assumption | Why |
|---|---|---|
| A1 | An ignored recalculation is **recorded** as a `RecalculationIgnored` event carrying the value the system wanted, but it changes nothing and is not numbered among the adjustments. `payroll:history` lists it under the audit table. | "Traceable" beats silent: an auditor should see that source data moved after the freeze. The seven-row audit table stays byte-identical to the expected one. |
| A2 | A zero-amount adjustment is rejected. | A correction that changes nothing is a data-entry error, and it would pollute the numbering. |
| A3 | The comment is trimmed, must be non-blank, and is capped at 500 characters. | Mandatory per the brief; the cap is arbitrary and stated as such. |
| A4 | Amounts have at most two decimals; a third decimal is rejected, never rounded. | The scenario's "rounding adjustment" is a *manual* correction, which implies the system never rounds by itself. |
| A5 | One currency per line (USD in the CLI). `Money` still carries the currency and refuses cross-currency arithmetic. | Keeps the model honest without building multi-currency. |
| A6 | Who made the adjustment is not modelled. | Not in the brief. It is the first follow-up, because a real audit trail needs an actor. |
| A7 | Recalculations *before* the first adjustment replace the system value; earlier values stay in the stream but the audit table shows only the frozen one. | Matches the expected table: $1,000.00 is not listed, $1,050.00 is. |
| A8 | Concurrent writers are serialised by optimistic locking on the aggregate version; on conflict the caller reloads and retries. | Standard event-sourcing practice; demonstrated by tests, not by a retry loop. |

## Testing

158 tests, 100 % line coverage of `app/` with nothing excluded (`@codeCoverageIgnore` is
forbidden by the architecture test), Larastan at level max with no baseline, Pint clean,
Infection mutation score ≥ 95 %. All of it runs in CI on every push.

| Rule / behaviour | Where it is proven |
|---|---|
| The eight steps, value after each | `EarningLineTest`, `CommandHandlersTest`, `CommandBusTest`, `PayrollDemoCommandTest` |
| R1 many adjustments, numbered 1…n, order kept | `EarningLineTest` (1, 2, 5, 50 adjustments) |
| R2 no API to edit or delete history | `ArchitectureTest` (reflection over aggregate, repository, stores) |
| R2 storage is append-only | `DatabaseEventStoreTest`: `UPDATE` and `DELETE` fail on SQLite and MySQL |
| R3 compensating correction keeps both entries | `EarningLineTest` |
| R4 recalculation before the first adjustment replaces the value | `EarningLineTest`, `EarningLineSummaryProjectorTest` |
| R4 recalculation after it is ignored and recorded | `EarningLineTest`, `LineHistoryTest`, `PayrollCalculateCommandTest` |
| R5 current value = frozen value + Σ adjustments, always | seeded property-style loop in `EarningLineTest` and `LineHistoryTest` |
| R6 blank comment rejected, text kept verbatim | `CommentTest`, `PayrollAdjustCommandTest` (omitted and blank) |
| R7 positive and negative amounts; A2 zero rejected | `EarningLineTest`, `MoneyTest` |
| Money parsing, formatting, arithmetic, currency mismatch | `MoneyTest` |
| Event ⇄ row serialisation, corrupt rows fail loudly | `EventSerializerTest`, `DatabaseEventStoreTest` |
| Optimistic concurrency, nothing partially written | `DatabaseEventStoreTest`, `EventSourcedEarningLineRepositoryTest` |
| Projection correct after each event, idempotent | `EarningLineSummaryProjectorTest` |
| Append + projection are one transaction | `CommandBusTest` |
| Domain and application are framework-free; dependencies point inwards; domain classes final, value objects and events readonly | `ArchitectureTest` |
| Every port is bound to its adapter | `PayrollServiceProviderTest` |

```bash
composer test                       # all suites
composer test -- --testsuite=Unit   # pure PHP, ~10 ms
composer test:coverage              # pcov + scripts/coverage-gate.php (fails below 100 %)
composer infection                  # mutation testing, MSI gate in infection.json5
composer stan && composer lint
```

## What I would do next

1. **Actor on the adjustment** (`SpecialistId` in `LineAdjusted`, authorisation in the handler) —
   the first thing a real audit trail needs.
2. **Snapshots** once a line has thousands of events; today every load replays the stream, which
   is fine for eight events and wrong at eight thousand.
3. **Asynchronous projections** via a queue with the event's version as the idempotency key; the
   projector is already idempotent, so this is a wiring change.
4. **Event upcasting** for payload schema changes; the serializer's type map is the seam.
5. **HTTP API** or a UI on top of the same command bus, plus multi-currency lines if payroll
   needs them.

## AI usage

The assignment encourages AI tools, so here is what that meant in practice. I wrote an execution
plan first (decisions D1–D6, assumptions A1–A8, a rule-to-test matrix, phases with one commit
each) and then implemented it with Claude Code, reviewing every file and every test run myself.
Things the review changed:

- The first projector contract was `project(DomainEvent $event)`. That made "frozen at step 3"
  impossible to project and made replays double-count; it became `project($event, int $version)`
  with a version check, and the projector gained an idempotency test.
- A generated repository test rebuilt a line from events that had never been stored, so its
  expected version was wrong and it failed against its own store. The test, not the code, was fixed.
- The first architecture test flagged its own `dispatch()` method as a Laravel helper call and
  compared an unnormalised path with a `realpath()`. Both were test bugs.
- Larastan at level max caught three things the generated code got wrong: Laravel's console test
  helper returns `PendingCommand|int`, `DB::unprepared()` wants a literal string, and a mapped
  collection is an `array`, not a `list`.

Every commit is my own; the AI has no co-author line.

## License

MIT — see [LICENSE](LICENSE).
