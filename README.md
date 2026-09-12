# Redline PoC — manual adjustments to an earning line

Proof of concept for the Alcor code assignment *"History of Manual Adjustments to an Earning
Line"*: a payroll earning line that the system calculates, and that a payroll specialist can
correct with signed, commented, append-only adjustments. Once a line has been adjusted by hand
it is frozen against system recalculation.

Work in progress — the full README (how it works, design decisions, assumptions) lands with the
final commit.

## Quick start

```bash
composer setup            # install, .env, SQLite database, migrations
php artisan payroll:demo  # replays the assignment scenario (coming soon)
composer check            # lint, static analysis, tests + coverage, mutation testing
```

Requires PHP 8.4 and Composer. SQLite is used by default; MySQL works with the usual `DB_*`
environment variables.
