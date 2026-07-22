# Amaiza | SugarAdminCLI

[![Tests](https://github.com/bickart/SugarAdminCLI/actions/workflows/tests.yml/badge.svg)](https://github.com/bickart/SugarAdminCLI/actions/workflows/tests.yml)
![PHP](https://img.shields.io/badge/PHP-%3E%3D8.4-777bb4)
![License](https://img.shields.io/badge/License-Proprietary-lightgrey)

Console commands for every action on SugarCRM's classic Administration "Repair" page (`index.php?module=Administration&action=Upgrade`) — Quick Repair and Rebuild, Rebuild Relationships, Repair Roles, Rebuild Schedulers, and the rest — plus a growing set of maintenance and diagnostic commands with no stock Sugar UI equivalent at all (restore a soft-deleted record, purge old soft-deleted data, custom-table orphan cleanup, maintenance mode toggle, duplicate/logic-hook/schema audits, and more). Runnable headlessly over `bin/sugarcrm`, without clicking through the admin UI.

## Features

- One `amaiza:admin:repair:*` command per stock repair action (19 total), plus an `amaiza:admin:qrr` alias for the most-used one (Quick Repair and Rebuild)
- A growing set of additional `amaiza:admin:repair:*`/`amaiza:admin:maintenance:*` commands covering real maintenance gaps stock Sugar has no UI for at all
- A parallel `amaiza:admin:report:*` namespace of read-only diagnostics (duplicates, logic-hook conflicts, schema drift, table sizes, stuck jobs, Customer Journey/SugarBPM health) — a few support an explicit, confirmation-gated mutating flag
- Every command drives Sugar's own core repair file/function/scheduled-job directly (never reimplements Sugar's logic), so behavior stays in sync with core across versions
- Scriptable: chain repair steps in deploy pipelines, cron jobs, or CI without a browser session

## Requirements

| | |
|---|---|
| Sugar edition | Sugar Enterprise (ENT) |
| Sugar versions | 25.x through 26.x |
| Development | PHP 8.4+, Composer |

## Repository layout

| Path | Purpose |
|---|---|
| `crm/custom/src/amaiza/SugarAdminCLI/Console/Command/` | The command classes |
| `crm/custom/Extension/application/Ext/Console/` | Sugar Extension-framework registration of those commands |
| `tests/` | PHPUnit suite against stubbed Sugar globals/classes (no live Sugar instance required) |
| `bin/sugar-module-install.php` | Link or copy `crm/custom/` into a local Sugar instance for development |
| `bin/pack.php` | Build a distributable archive of `crm/custom/` |

## Development setup

1. `composer install`
2. `cp sugar.env.php.dist sugar.env.php` (edit `sugar_root` to point at your local Sugar instance)
3. `composer sugar:module:install && composer sugar:repair`
4. Run a command for real: `php bin/sugarcrm amaiza:admin:qrr` (from inside your Sugar root)

## Commands

| Command | Repairs |
|---|---|
| `amaiza:admin:repair:qrr` (alias `amaiza:admin:qrr`) | DB, Extensions, Vardefs, Dashlets, etc. — same as "Quick Repair and Rebuild" |
| `amaiza:admin:repair:teams:upgrade` | Creates teams for users |
| `amaiza:admin:repair:htaccess` | Rebuilds `.htaccess` |
| `amaiza:admin:repair:config` | Rebuilds `config.php` |
| `amaiza:admin:repair:sugarlogic` | Rebuilds Sugar Logic functions cache |
| `amaiza:admin:repair:relationships` | Rebuilds relationship metadata |
| `amaiza:admin:repair:schedulers` | Rebuilds out-of-the-box Scheduler Jobs |
| `amaiza:admin:repair:workflow` | Rebuilds WorkFlow cache & compiles plugins |
| `amaiza:admin:repair:js-languages` | Rebuilds JS versions of language files |
| `amaiza:admin:repair:js-groupings` | Re-concatenates JS group files |
| `amaiza:admin:repair:sprites` | Rebuilds sprite images/config |
| `amaiza:admin:repair:field-casing` | Repairs mixed-case custom table/metadata fields |
| `amaiza:admin:repair:teams` | Rebuilds private team memberships from reporting hierarchy |
| `amaiza:admin:repair:roles` | Adds new modules/ACLs to Roles |
| `amaiza:admin:repair:inbound-email` | Repairs Inbound Email accounts, re-encrypts passwords |
| `amaiza:admin:repair:xss` | Removes XSS vulnerabilities from the database |
| `amaiza:admin:repair:activities` | Repairs Calls/Meetings end dates |
| `amaiza:admin:repair:seed-users` | Enables/disables demo seed users |
| `amaiza:admin:repair:cache:clear` | Clears additional (API/other) cached resources |

Not stock Administration > Repair actions — no equivalent exists in Sugar core. Modeled on [esimonetti/toothpaste](https://github.com/esimonetti/toothpaste)'s commands (Apache-2.0), reimplemented directly against Sugar's own APIs rather than reusing that project's code:

| Command | Does |
|---|---|
| `amaiza:admin:repair:restore-record` | Restores a soft-deleted record and most of its relationships (`--module`, `--record`) |
| `amaiza:admin:repair:orphans-cleanup` | Deletes orphan rows from `_cstm`, `_audit`, and `audit_events` tables — no matching core table row (permanent SQL delete, not soft) — requires confirmation, see below |
| `amaiza:admin:repair:prune-database` | Runs Sugar's own OOTB "Prune Database" scheduled job synchronously (hard-deletes old soft-deleted records + optimizes affected tables), optionally scoped to specific tables (`--table mytable,mytable_cstm`) — requires confirmation, see below |
| `amaiza:admin:repair:missing-tables` | Creates any SQL table missing entirely, for recovering an incomplete/partial-backup instance |
| `amaiza:admin:maintenance:on` / `amaiza:admin:maintenance:off` | Toggles `maintenanceMode` — normally only settable by hand-editing `config_override.php` |
| `amaiza:admin:repair:orphaned-parent-cleanup` | Soft-deletes records whose `parent_type`/`parent_id` flex-relate points at a record that no longer exists — defaults to Tasks, Calls, Meetings, Notes, Emails (the only stock modules with this field pair), override with `--modules` |
| `amaiza:admin:repair:export-records --module X --ids A,B,C` | Exports full record data (core + `_cstm` row) to a JSON Lines file — a manual safety net, standalone or before running a destructive command |
| `amaiza:admin:repair:prune-bpm-tables --before=YYYY-MM-DD` | Prunes SugarBPM (`pmse_*`) tables via Sugar Support's own recommended CREATE=>RENAME=>INSERT procedure; `--drop-renamed-tables` permanently removes the leftover `*_delete` tables from a prior run |

`amaiza:admin:repair:orphans-cleanup` and `amaiza:admin:repair:prune-database` perform permanent, irreversible deletion with no backup by default, so both require confirmation before running: pass `--yes`/`-y` to proceed non-interactively (scripts/CI), otherwise you'll get an interactive `[y/N]` prompt. Running non-interactively without `--yes` fails with a clear error rather than silently doing nothing. `amaiza:admin:repair:orphaned-parent-cleanup` doesn't need this for its usual soft-delete path (reversible via `amaiza:admin:repair:restore-record`), but does require confirmation for one specific case: records whose `parent_type` doesn't resolve to any real module at all (data corruption, not just a missing row) get their own separate confirmation before being treated as orphaned.

All 3 of these commands additionally support `--backup-dir=PATH`: when passed, the exact rows about to be deleted are written to a timestamped JSON Lines file under that directory *before* the delete happens, using the same mechanism `amaiza:admin:repair:export-records` uses directly. Omitting it (the default) skips backing up entirely.

`amaiza:admin:repair:prune-bpm-tables` is a different tier of risk — it runs raw DDL (`CREATE TABLE`/`RENAME TABLE`/`DROP TABLE`) directly against `pmse_*` tables, bypassing the bean/ORM layer entirely, and can run for a long time against production-sized tables. Both of its actions (the prune itself and `--drop-renamed-tables`) require **interactive** confirmation with **no `--yes` bypass at all** — it can never be triggered unattended from a script or cron job. The prune also automatically pauses every Active Scheduler for its duration and resumes them afterward, even on failure.

All destructive commands that delete data support `--dry-run`, which reports what would be deleted (per-table/module counts and a total) without deleting anything and without prompting for confirmation:
- `amaiza:admin:repair:orphans-cleanup --dry-run`
- `amaiza:admin:repair:prune-database --dry-run` (reimplements just the row-selection criteria as read-only counts — the underlying Sugar core job has no preview mode of its own)
- `amaiza:admin:repair:orphaned-parent-cleanup --dry-run`
- `amaiza:admin:repair:prune-bpm-tables --before=... --dry-run` / `--drop-renamed-tables --dry-run`

## Diagnostics & reports (`amaiza:admin:report:*`)

Read-only by default; a few support an explicit, confirmation-gated mutating flag. No stock Sugar UI equivalent for any of these.

| Command | Reports |
|---|---|
| `amaiza:admin:report:blocked-record --module X --record Y` | Why a record is blocked by a Customer Journey ("Smart Guide") — linked journey/stage, current state, whether `isBlocked()`/`isBlockedByStage()` is true right now. `--unblock` / `--cancel-journey` resolve it (confirmation required) |
| `amaiza:admin:report:stale-journeys --days 30` | Customer Journeys still `in_progress` well past a reasonable running time |
| `amaiza:admin:report:stale-bpm-cases --days 30` | Real SugarBPM (`pmse_*`) cases still `IN PROGRESS` past a reasonable running time; `--case-id`/`--terminate` target and resolve one (confirmation required) |
| `amaiza:admin:report:duplicates --module X` | Likely duplicate records (find only, no merge — Sugar has no server-side merge API) |
| `amaiza:admin:report:logic-hooks --module X` | Same-priority logic hook collisions and missing files/classes/methods across `custom/modules/*/Ext/LogicHooks/` |
| `amaiza:admin:report:field-usage --module X --threshold 1` | % of records with each custom field populated, flagging underused fields as removal candidates |
| `amaiza:admin:report:dropdown-usage --module X` | Configured dropdown options no record currently uses (single-select `enum` fields only) |
| `amaiza:admin:report:schema-diff --module X` | Vardef-declared fields vs. actual DB columns, at column granularity (pairs with `amaiza:admin:repair:missing-tables`'s whole-table granularity) |
| `amaiza:admin:report:table-sizes --top 20` | Largest tables by size — useful for choosing `amaiza:admin:repair:prune-database --table` targets |
| `amaiza:admin:report:stuck-jobs --minutes 60` | Scheduled jobs stuck `running`, plus recent failures (`job_queue` table) |

See RELEASENOTES.md for what's implemented vs. pending live verification.

## Unit tests

Run locally: `composer test` (or `vendor/bin/phpunit`). Tests run against `tests/Support/SugarStubs.php`, lightweight fakes for the Sugar globals/classes each command references — no live Sugar instance required. Stub coverage verifies each command pre-seeds the correct request/global state; it is not a substitute for running a command for real against a live Sugar instance (see AGENTS.md).

## License

Copyright (C) Amaiza LLC. Proprietary — see LICENSE.txt.
